import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import test from 'node:test';
import {withEndpoint, runBridge, parseReplies} from './test-client.mjs';

const request = (id, method = 'ping') => ({jsonrpc: '2.0', id, method});
const nativeError = (code, reason, extra = {}) => ({jsonrpc: '2.0', id: null,
    error: {code, message: 'RAW_BODY_CANARY stdio-fixture-token', data: {reason}}, ...extra});
const sensitiveValues = ['RAW_BODY_CANARY'];

function completed(result) {
    assert.equal(result.timedOut, false, 'The bridge deadline must finish before the fixture watchdog');
    assert.equal(result.code, 0, result.stderr);
}

function failure(reply, id, reason, httpStatus) {
    assert.equal(reply.id, id);
    assert.equal(reply.jsonrpc, '2.0');
    assert.equal(reply.error.code, -32000);
    assert.deepEqual(reply.error.data, {reason, httpStatus, outcomeUncertain: true});
    assert.match(reply.error.message, /may have completed|uncertain/i);
    assert.doesNotMatch(JSON.stringify(reply), /RAW_BODY_CANARY|stdio-fixture-token/);
}

for (const [status, code, reason] of [
    [400, -32600, 'invalid_request'], [401, -32000, 'missing_token'],
    [401, -32000, 'invalid_token'], [401, -32000, 'expired_token'],
    [403, -32000, 'forbidden'], [413, -32600, 'request_too_large'],
    [429, -32000, 'rate_limited'], [500, -32603, 'internal_error'],
]) {
    test(`HTTP ${status}/${reason} produces a sanitized ID-correlated outcome`, async () => {
        const messages = [request(7)];
        await withEndpoint(({response}) => response.writeHead(status).end(JSON.stringify(nativeError(code, reason))),
            async endpoint => {
                const result = await runBridge({endpoint, messages, sensitiveValues});
                completed(result);
                failure(parseReplies(result.stdout, messages)[0], 7, reason, status);
                assert.equal(result.stderrHasSecrets, false);
            });
    });
}

for (const [label, status, body] of [
    ['ambiguous-401', 401, 'RAW_BODY_CANARY invalid_token'],
    ['unknown-status', 503, JSON.stringify(nativeError(-32603, 'internal_error'))],
    ['wrong-status', 403, JSON.stringify(nativeError(-32000, 'invalid_token'))],
    ['wrong-code', 401, JSON.stringify(nativeError(-32603, 'invalid_token'))],
    ['wrong-id', 401, JSON.stringify(nativeError(-32000, 'invalid_token', {id: 7}))],
    ['two-outcomes', 401, JSON.stringify(nativeError(-32000, 'invalid_token', {result: {}}))],
]) {
    test(`HTTP ${label} cannot invent trusted credential metadata`, async () => {
        const messages = [request(7)];
        await withEndpoint(({response}) => response.writeHead(status, {
            'www-authenticate': 'Bearer error="invalid_token"',
        }).end(body), async endpoint => {
            const result = await runBridge({endpoint, messages, sensitiveValues});
            completed(result);
            failure(parseReplies(result.stdout, messages)[0], 7, 'http_error', status);
            assert.equal(result.stderrHasSecrets, false);
        });
    });
}

test('HTTP failure never logs raw response bodies or credentials before redaction', async () => {
    await withEndpoint(({response}) => response.writeHead(500).end('RAW_BODY_CANARY stdio-fixture-token'), async endpoint => {
        const result = await runBridge({endpoint, messages: [request(1)], sensitiveValues});
        completed(result);
        assert.equal(result.stderrHasSecrets, false, 'Raw diagnostics must be safe before the fixture redacts them');
    });
});

for (const [label, body] of [
    ['non-json', 'RAW_BODY_CANARY'],
    ['wrong-id', JSON.stringify({jsonrpc: '2.0', id: 8, result: {}})],
    ['wrong-id-type', JSON.stringify({jsonrpc: '2.0', id: '7', result: {}})],
    ['wrong-shape', JSON.stringify([{jsonrpc: '2.0', id: 7, result: {}}])],
    ['missing-outcome', JSON.stringify({jsonrpc: '2.0', id: 7})],
    ['two-outcomes', JSON.stringify({jsonrpc: '2.0', id: 7, result: {}, error: {code: -1, message: 'bad'}})],
    ['bad-error', JSON.stringify({jsonrpc: '2.0', id: 7, error: {code: 'bad', message: 'bad'}})],
    ['null', 'null'], ['empty', ''],
]) {
    test(`successful HTTP with ${label} reply yields one correlated invalid-response error`, async () => {
        const messages = [request(7)];
        await withEndpoint(({response}) => response.end(body), async endpoint => {
            const result = await runBridge({endpoint, messages, sensitiveValues});
            completed(result);
            failure(parseReplies(result.stdout, messages)[0], 7, 'invalid_response', 200);
            assert.equal(result.stderrHasSecrets, false);
        });
    });
}

test('HTTP failure fans out only to requests in a mixed batch', async () => {
    const batch = [request('a'), {method: 'notice'}, request('a'), request(null)];
    let received = 0;
    await withEndpoint(({response, body}) => {
        received++;
        assert.deepEqual(JSON.parse(body), batch);
        response.writeHead(503).end('unavailable');
    }, async endpoint => {
        const result = await runBridge({endpoint, messages: [batch]});
        completed(result);
        const replies = parseReplies(result.stdout, [batch]);
        assert.ok(Array.isArray(JSON.parse(result.stdout)));
        for (const reply of replies) failure(reply, reply.id, 'http_error', 503);
        assert.equal(replies.length, 3);
    });
    assert.equal(received, 1);
});

test('invalid batch response cannot leak partial or duplicate upstream outcomes', async () => {
    const batch = [request(1), request(2), {method: 'notice'}];
    for (const replies of [
        [{jsonrpc: '2.0', id: 1, result: {}}],
        [{jsonrpc: '2.0', id: 1, result: {}}, {jsonrpc: '2.0', id: 1, result: {}}],
    ]) {
        await withEndpoint(({response}) => response.end(JSON.stringify(replies)), async endpoint => {
            const result = await runBridge({endpoint, messages: [batch]});
            completed(result);
            for (const reply of parseReplies(result.stdout, [batch])) failure(reply, reply.id, 'invalid_response', 200);
        });
    }
});

test('native whole-batch rejection is projected onto request IDs without changing its RPC error', async () => {
    const batch = [request(1), {method: 'notice'}, request('two')];
    const error = {code: -32600, message: 'Batch size exceeds configured limit.', data: {fixture: true}};
    await withEndpoint(({response}) => response.end(JSON.stringify({jsonrpc: '2.0', id: null, error})), async endpoint => {
        const result = await runBridge({endpoint, messages: [batch]});
        completed(result);
        assert.deepEqual(parseReplies(result.stdout, [batch]), [1, 'two'].map(id => ({jsonrpc: '2.0', id, error})));
    });
});

for (const [input, code] of [['{', -32700], ['42', -32600], ['{}', -32600], ['[]', -32600],
    ['{"id":true,"method":"ping"}', -32600]]) {
    test(`invalid input ${input} is rejected before HTTP dispatch`, async () => {
        let received = 0;
        await withEndpoint(({response}) => { received++; response.end('{}'); }, async endpoint => {
            const result = await runBridge({endpoint, messages: [], input: input + '\n'});
            completed(result);
            assert.equal(received, 0, 'Invalid framing must not reach the endpoint');
            const reply = JSON.parse(result.stdout);
            assert.equal(reply.id, null);
            assert.equal(reply.error.code, code);
        });
    });
}

test('invalid batch members are rejected without changing valid opaque arguments', async () => {
    const valid = {id: 'kept', method: 'fixture', params: {opaque: 'false'}};
    const notice = {method: 'notice'};
    const input = JSON.stringify([valid, 42, notice, []]) + '\n';
    await withEndpoint(({response, body}) => {
        assert.deepEqual(JSON.parse(body), [valid, notice]);
        response.end(JSON.stringify([{jsonrpc: '2.0', id: 'kept', result: valid.params}]));
    }, async endpoint => {
        const result = await runBridge({endpoint, messages: [], input});
        completed(result);
        const replies = JSON.parse(result.stdout);
        assert.equal(replies.length, 3);
        assert.deepEqual(replies.find(reply => reply.id === 'kept').result, valid.params);
        assert.equal(replies.filter(reply => reply.id === null && reply.error?.code === -32600).length, 2);
    });
});

for (const [status, body] of [[202, ''], [200, ' \n '], [200, '{"jsonrpc":"2.0","id":null,"result":{}}'],
    [503, 'RAW_BODY_CANARY stdio-fixture-token']]) {
    test(`notification-only ${status}/${body.length} emits no stdout bytes`, async () => {
        const messages = [[{method: 'first'}, {method: 'second'}]];
        await withEndpoint(({response}) => response.writeHead(status).end(body), async endpoint => {
            const result = await runBridge({endpoint, messages, sensitiveValues});
            completed(result);
            assert.equal(result.stdout, '');
            assert.equal(result.stderrHasSecrets, false);
            if (status === 503 || body.includes('jsonrpc')) assert.ok(result.stderr.trim());
        });
    });
}

test('an applied write followed by connection loss is uncertain and is never replayed', async () => {
    const messages = [request('write', 'write_fixture')];
    let applied = 0;
    await withEndpoint(({response}) => { applied++; response.destroy(); }, async endpoint => {
        const result = await runBridge({endpoint, messages});
        completed(result);
        failure(parseReplies(result.stdout, messages)[0], 'write', 'network_error', null);
    });
    assert.equal(applied, 1, 'The bridge must not replay a possibly completed operation');
});

for (const afterHeaders of [false, true]) {
    test(`request deadline bounds a stalled ${afterHeaders ? 'body' : 'header'} without watchdog termination`, async () => {
        const messages = [request('stall')];
        let received = 0;
        await withEndpoint(({response}) => {
            received++;
            if (afterHeaders) { response.writeHead(200); response.flushHeaders(); response.write('{'); }
        }, async endpoint => {
            const result = await runBridge({endpoint, messages, startupOptions: {requestTimeoutMs: 100}, timeout: 2000});
            completed(result);
            failure(parseReplies(result.stdout, messages)[0], 'stall', 'request_timeout', afterHeaders ? 200 : null);
        });
        assert.equal(received, 1);
    });
}

test('one timed-out request does not steal or cancel a simultaneous healthy result', async () => {
    const messages = [request('stall'), request('healthy')];
    await withEndpoint(({response, body}) => {
        const message = JSON.parse(body);
        if (message.id === 'healthy') response.end(JSON.stringify({jsonrpc: '2.0', id: message.id, result: {ok: true}}));
    }, async endpoint => {
        const result = await runBridge({endpoint, messages, startupOptions: {requestTimeoutMs: 100}, timeout: 2000});
        completed(result);
        const replies = parseReplies(result.stdout, messages);
        failure(replies.find(reply => reply.id === 'stall'), 'stall', 'request_timeout', null);
        assert.deepEqual(replies.find(reply => reply.id === 'healthy').result, {ok: true});
    });
});

test('redirect responses do not resend a possible write', async () => {
    const messages = [request('redirect')];
    let received = 0;
    await withEndpoint(({request: incoming, response}) => {
        received++;
        if (incoming.url === '/endpoint.php') response.writeHead(307, {location: '/redirected'}).end();
        else response.end(JSON.stringify({jsonrpc: '2.0', id: 'redirect', result: {}}));
    }, async endpoint => {
        const result = await runBridge({endpoint, messages});
        completed(result);
        assert.equal(received, 1);
        failure(parseReplies(result.stdout, messages)[0], 'redirect', 'http_error', 307);
    });
});

for (const status of [403, 429, 500, 'timeout']) {
    test(`${status} preserves both cached credentials and an adjacent successful call`, async () => {
        const messages = [request('failure'), request('healthy')];
        await withEndpoint(({request: incoming, response, body}) => {
            assert.equal(incoming.headers.authorization, 'Bearer cached-fixture-token');
            const message = JSON.parse(body);
            if (message.id === 'healthy') response.end(JSON.stringify({jsonrpc: '2.0', id: message.id, result: {ok: true}}));
            else if (status !== 'timeout') response.writeHead(status).end('failure');
        }, async endpoint => {
            const key = createHash('sha256').update(endpoint).digest('hex').slice(0, 12);
            const initialCache = {
                [key]: {access_token: 'cached-fixture-token', endpoint, time: 1},
                unrelated: {access_token: 'other-fixture-token', endpoint: 'http://127.0.0.1:2/endpoint.php', time: 2},
            };
            const result = await runBridge({endpoint, messages, initialCache, oauth: true,
                onAuthorize: () => assert.fail('A valid cached credential must not reopen authorization'),
                startupOptions: {requestTimeoutMs: 100}, timeout: 2000});
            completed(result);
            const replies = parseReplies(result.stdout, messages);
            failure(replies.find(reply => reply.id === 'failure'), 'failure',
                status === 'timeout' ? 'request_timeout' : 'http_error', status === 'timeout' ? null : status);
            assert.deepEqual(replies.find(reply => reply.id === 'healthy').result, {ok: true});
            assert.equal(result.cacheEntries, 2);
            assert.equal(result.cacheUnchanged, true);
            assert.equal(result.stderrHasSecrets, false);
        });
    });
}
