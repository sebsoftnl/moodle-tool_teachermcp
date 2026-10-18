import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import test from 'node:test';
import {saveTokenStore} from './mcp-bridge.mjs';
import {withEndpoint, withBridgeHome, runBridge, parseReplies} from './test-client.mjs';

const bridgeUrl = new URL('./mcp-bridge.mjs', import.meta.url).href;
const request = (id, method = 'ping') => ({jsonrpc: '2.0', id, method});
const cacheKey = endpoint => createHash('sha256').update(endpoint).digest('hex').slice(0, 12);
const seed = endpoint => ({
    [cacheKey(endpoint)]: {access_token: 'stale-fixture-token', endpoint, time: 1},
    other: {access_token: 'other-fixture-token', endpoint: 'http://127.0.0.1:2/endpoint.php', time: 2, extra: {keep: true}},
});
const rejection = reason => ({jsonrpc: '2.0', id: null,
    error: {code: -32000, message: 'Credential rejected', data: {reason}}});
const noAuthorization = () => assert.fail('This process must not open authorization');

async function authorize(authorization, error) {
    const source = new URL(authorization);
    const callback = new URL(source.searchParams.get('redirect_uri'));
    assert.equal(callback.protocol, 'http:');
    assert.ok(['localhost', '127.0.0.1'].includes(callback.hostname));
    callback.search = new URLSearchParams({state: source.searchParams.get('state'),
        ...(error ? {error} : {code: 'recovery-code'})}).toString();
    const response = await fetch(callback, {signal: AbortSignal.timeout(2500)});
    await response.text();
    return response.status;
}

function completed(result, code) {
    assert.equal(result.timedOut, false);
    assert.equal(result.code, code);
    assert.equal(result.stderrHasSecrets, false);
    assert.equal(result.unexpectedAuthorization, false);
    assert.throws(() => process.kill(result.pid, 0), {code: 'ESRCH'});
}

for (const reason of ['invalid_token', 'expired_token']) {
    test(`OAuth ${reason} removes only the rejected cache entry after a correlated error`, async () => {
        let dispatches = 0;
        await withEndpoint(({request: incoming, response}) => {
            dispatches++;
            assert.equal(incoming.headers.authorization, 'Bearer stale-fixture-token');
            response.writeHead(401).end(JSON.stringify(rejection(reason)));
        }, async endpoint => {
            await withBridgeHome(async fixture => {
                const initialCache = seed(endpoint);
                const messages = [request('rejected', 'write_fixture')];
                const result = await fixture.run({endpoint, messages, oauth: true, initialCache, onAuthorize: noAuthorization});
                completed(result, 1);
                const reply = parseReplies(result.stdout, messages)[0];
                assert.equal(reply.error.data.reason, reason);
                assert.equal(reply.error.data.httpStatus, 401);
                assert.equal(result.authorizationSeen, false);
                const store = JSON.parse(fs.readFileSync(fixture.cachePath, 'utf8'));
                assert.equal(Object.hasOwn(store, cacheKey(endpoint)), false);
                assert.equal(JSON.stringify(store.other) === JSON.stringify(initialCache.other), true);
                assert.equal(result.cacheEntries, 1);
            });
        });
        assert.equal(dispatches, 1, 'Rejected requests must not be replayed');
    });
}

test('explicit restart authorizes once in the same HOME and a third start reuses the new cache', async () => {
    let authorizations = 0;
    let exchanges = 0;
    const calls = [];
    await withEndpoint(({request: incoming, response, body}) => {
        if (incoming.url === '/oauth/token.php') {
            exchanges++;
            assert.equal(new URLSearchParams(body).get('code'), 'recovery-code');
            response.end(JSON.stringify({access_token: 'fresh-fixture-token', token_type: 'Bearer'}));
            return;
        }
        const message = JSON.parse(body);
        calls.push(message.id);
        if (incoming.headers.authorization === 'Bearer stale-fixture-token') {
            response.writeHead(401).end(JSON.stringify(rejection('invalid_token')));
        } else {
            assert.equal(incoming.headers.authorization, 'Bearer fresh-fixture-token');
            response.end(JSON.stringify({jsonrpc: '2.0', id: message.id, result: {ok: true}}));
        }
    }, async endpoint => {
        await withBridgeHome(async fixture => {
            const initialCache = seed(endpoint);
            const first = await fixture.run({endpoint, messages: [request('failed-write', 'write_fixture')],
                initialCache, oauth: true, onAuthorize: noAuthorization});
            completed(first, 1);
            assert.equal(first.authorizationSeen, false);
            const secondMessages = [request('new-request')];
            const second = await fixture.run({endpoint, messages: secondMessages, oauth: true,
                onAuthorize: async url => { authorizations++; assert.equal(await authorize(url), 200); }});
            completed(second, 0);
            assert.deepEqual(parseReplies(second.stdout, secondMessages)[0].result, {ok: true});
            const store = JSON.parse(fs.readFileSync(fixture.cachePath, 'utf8'));
            assert.equal(store[cacheKey(endpoint)].access_token === 'fresh-fixture-token', true);
            assert.equal(JSON.stringify(store.other) === JSON.stringify(initialCache.other), true);
            const thirdMessages = [request('reused')];
            const third = await fixture.run({endpoint, messages: thirdMessages, oauth: true, onAuthorize: noAuthorization});
            completed(third, 0);
            assert.equal(third.cacheUnchanged, true);
            assert.equal(third.authorizationSeen, false);
            assert.deepEqual(parseReplies(third.stdout, thirdMessages)[0].result, {ok: true});
        });
    });
    assert.equal(authorizations, 1);
    assert.equal(exchanges, 1);
    assert.deepEqual(calls, ['failed-write', 'new-request', 'reused']);
});

for (const [label, status, reason] of [
    ['missing-token', 401, 'missing_token'], ['forbidden', 403, 'forbidden'],
    ['rate-limited', 429, 'rate_limited'], ['server-error', 500, 'internal_error'],
    ['ambiguous', 401, null], ['timeout', null, null], ['forged-success', 200, 'invalid_token'],
]) {
    test(`${label} cannot authorize OAuth cache invalidation`, async () => {
        const messages = [request('kept')];
        await withEndpoint(({response}) => {
            if (status === null) return;
            const body = status === 200 ? {jsonrpc: '2.0', id: 'kept', error: {
                code: -32000, message: 'Application error', data: {reason, httpStatus: 401, outcomeUncertain: true},
            }} : reason ? rejection(reason) : {error: 'invalid token'};
            if (status === 500) body.error.code = -32603;
            response.writeHead(status).end(JSON.stringify(body));
        }, async endpoint => {
            const result = await runBridge({endpoint, messages, initialCache: seed(endpoint), oauth: true,
                onAuthorize: noAuthorization, startupOptions: {requestTimeoutMs: 100}, timeout: 2000});
            completed(result, 0);
            assert.equal(result.cacheUnchanged, true);
            assert.equal(result.cacheEntries, 2);
            assert.equal(result.authorizationSeen, false);
            assert.equal(parseReplies(result.stdout, messages).length, 1);
        });
    });
}

test('ambiguous401 gives actionable guidance without deleting cache', async () => {
    const messages = [request('legacy')];
    await withEndpoint(({response}) => response.writeHead(401).end('unclassified rejection'), async endpoint => {
        const result = await runBridge({endpoint, messages, initialCache: seed(endpoint), oauth: true, onAuthorize: noAuthorization});
        completed(result, 0);
        assert.equal(result.cacheUnchanged, true);
        assert.match(parseReplies(result.stdout, messages)[0].error.message, /check.*credential|replace.*credential|replacement bearer/i);
    });
});

for (const reason of ['invalid_token', 'expired_token']) {
    test(`manual ${reason} never uses or changes an OAuth cache`, async () => {
        const messages = [request('manual')];
        await withEndpoint(({request: incoming, response}) => {
            assert.equal(incoming.headers.authorization, 'Bearer manual-fixture-token');
            response.writeHead(401).end(JSON.stringify(rejection(reason)));
        }, async endpoint => {
            const result = await runBridge({endpoint, messages, token: 'manual-fixture-token', initialCache: seed(endpoint)});
            completed(result, 0);
            assert.equal(result.cacheUnchanged, true);
            assert.equal(parseReplies(result.stdout, messages)[0].error.data.reason, reason);
        });
    });
}

test('missing manual bearer fails before translation or authorization I/O', async () => {
    let requests = 0;
    const server = http.createServer((incoming, response) => { requests++; response.end('{}'); });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        const endpoint = `http://127.0.0.1:${server.address().port}/endpoint.php`;
        const result = await runBridge({endpoint, token: '', messages: [request('missing')], initialCache: seed(endpoint),
            startupOptions: {oauthTimeoutMs: 100}, timeout: 2000});
        completed(result, 1);
        assert.equal(result.stdout, '');
        assert.equal(result.cacheUnchanged, true);
        assert.match(result.stderr, /bearer.*--oauth|--oauth.*bearer/i);
        assert.equal(requests, 0);
    } finally {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
});

for (const [label, makeText] of [
    ['invalid-json', () => '{'], ['null-root', () => 'null'], ['array-root', () => '[]'], ['scalar-root', () => '42'],
    ['invalid-token-type', endpoint => JSON.stringify({...seed(endpoint), [cacheKey(endpoint)]: {access_token: 42}})],
]) {
    test(`malformed ${label} authorizes once and becomes a reusable cache`, async () => {
        let authorizations = 0;
        await withEndpoint(({request: incoming, response, body}) => {
            if (incoming.url === '/oauth/token.php') response.end(JSON.stringify({access_token: 'fresh-fixture-token'}));
            else {
                const message = JSON.parse(body);
                response.end(JSON.stringify({jsonrpc: '2.0', id: message.id, result: {ok: true}}));
            }
        }, async endpoint => {
            await withBridgeHome(async fixture => {
                fs.mkdirSync(path.dirname(fixture.cachePath), {recursive: true, mode: 0o700});
                fs.writeFileSync(fixture.cachePath, makeText(endpoint), {mode: 0o600});
                const result = await fixture.run({endpoint, messages: [request('fresh')], oauth: true,
                    onAuthorize: async url => { authorizations++; assert.equal(await authorize(url), 200); }});
                completed(result, 0);
                assert.equal(authorizations, 1);
                const store = JSON.parse(fs.readFileSync(fixture.cachePath, 'utf8'));
                assert.equal(store[cacheKey(endpoint)]?.access_token === 'fresh-fixture-token', true);
                if (label === 'invalid-token-type') assert.equal(JSON.stringify(store.other) === JSON.stringify(seed(endpoint).other), true);
                const reused = await fixture.run({endpoint, messages: [request('reused')], oauth: true, onAuthorize: noAuthorization});
                completed(reused, 0);
                assert.equal(reused.cacheUnchanged, true);
            });
        });
    });
}

for (const mode of ['denied', 'timeout']) {
    test(`reconnect ${mode} preserves the remaining endpoint and does not retry`, async () => {
        let authorizations = 0;
        let calls = 0;
        await withEndpoint(({response}) => { calls++; response.writeHead(401).end(JSON.stringify(rejection('invalid_token'))); },
            async endpoint => {
                await withBridgeHome(async fixture => {
                    const first = await fixture.run({endpoint, messages: [request('reject')], initialCache: seed(endpoint),
                        oauth: true, onAuthorize: noAuthorization});
                    completed(first, 1);
                    const before = fs.readFileSync(fixture.cachePath, 'utf8');
                    const second = await fixture.run({endpoint, messages: [request('not-dispatched')], oauth: true,
                        startupOptions: {oauthTimeoutMs: 1200}, timeout: 3000,
                        onAuthorize: async url => {
                            authorizations++;
                            if (mode === 'denied') assert.equal(await authorize(url, 'access_denied'), 400);
                        }});
                    completed(second, 1);
                    assert.equal(second.cacheUnchanged, true);
                    assert.equal(fs.readFileSync(fixture.cachePath, 'utf8') === before, true);
                });
            });
        assert.equal(authorizations, 1);
        assert.equal(calls, 1);
    });
}

test('concurrent rejections have one recovery owner and cancel remaining work without replay', async () => {
    const pending = new Map();
    const seen = [];
    await withEndpoint(({response, body}) => {
        const message = JSON.parse(body);
        seen.push(message.id);
        pending.set(message.id, response);
        if (pending.size === 3) {
            pending.get('first').writeHead(401).end(JSON.stringify(rejection('invalid_token')));
            pending.get('second').writeHead(401).end(JSON.stringify(rejection('expired_token')));
        }
    }, async endpoint => {
        await withBridgeHome(async fixture => {
            const result = await fixture.run({endpoint, messages: ['first', 'second', 'slow'].map(id => request(id)),
                initialCache: seed(endpoint), oauth: true, onAuthorize: noAuthorization,
                startupOptions: {requestTimeoutMs: 500}, timeout: 2000});
            completed(result, 1);
            const replies = result.stdout.trim().split('\n').filter(Boolean).flatMap(line => JSON.parse(line));
            assert.equal(replies.length, 1);
            assert.ok(['first', 'second'].includes(replies[0].id));
            assert.equal(replies[0].error.data.httpStatus, 401);
            assert.equal(result.cacheEntries, 1);
        });
    });
    assert.deepEqual(seen.sort(), ['first', 'second', 'slow']);
});

test('a replacement credential written before rejection processing survives byte-for-byte', async () => {
    await withBridgeHome(async fixture => {
        let replacement;
        let endpoint;
        await withEndpoint(({request: incoming, response, body}) => {
            const message = JSON.parse(body);
            if (incoming.headers.authorization === 'Bearer stale-fixture-token') {
                const store = JSON.parse(fs.readFileSync(fixture.cachePath, 'utf8'));
                store[cacheKey(endpoint)].access_token = 'replacement-fixture-token';
                replacement = JSON.stringify(store, null, 2);
                fs.writeFileSync(fixture.cachePath, replacement);
                response.writeHead(401).end(JSON.stringify(rejection('invalid_token')));
            } else {
                assert.equal(incoming.headers.authorization, 'Bearer replacement-fixture-token');
                response.end(JSON.stringify({jsonrpc: '2.0', id: message.id, result: {ok: true}}));
            }
        }, async url => {
            endpoint = url;
            const first = await fixture.run({endpoint, messages: [request('old')], initialCache: seed(endpoint),
                oauth: true, onAuthorize: noAuthorization});
            completed(first, 1);
            assert.equal(fs.readFileSync(fixture.cachePath, 'utf8') === replacement, true);
            const second = await fixture.run({endpoint, messages: [request('replacement')], oauth: true,
                onAuthorize: noAuthorization});
            completed(second, 0);
            assert.equal(second.cacheUnchanged, true);
        });
    });
});

for (const frame of [[request('one'), {method: 'notice'}, request('two')], {method: 'notice'}]) {
    test(`OAuth rejection handles ${Array.isArray(frame) ? 'mixed batch' : 'notification'} without fabricated replies`, async () => {
        await withEndpoint(({response}) => response.writeHead(401).end(JSON.stringify(rejection('invalid_token'))), async endpoint => {
            const result = await runBridge({endpoint, messages: [frame], initialCache: seed(endpoint),
                oauth: true, onAuthorize: noAuthorization});
            completed(result, 1);
            assert.equal(result.cacheEntries, 1);
            if (Array.isArray(frame)) assert.equal(parseReplies(result.stdout, [frame]).length, 2);
            else assert.equal(result.stdout, '');
        });
    });
}

function faultScript(home, mode) {
    const script = path.join(home, 'cache-fault.mjs');
    fs.writeFileSync(script, `
        import fs from 'node:fs';
        import {startBridge as run, getTokenFilePath} from ${JSON.stringify(bridgeUrl)};
        export async function startBridge(options) {
            const cache = getTokenFilePath(); const read = fs.readFileSync; let reads = 0;
            const mode = ${JSON.stringify(mode)};
            fs.readFileSync = (file, ...args) => {
                if (file === cache && (++reads > (mode === 'read-startup' ? 0 : 1)) && mode.startsWith('read')) {
                    throw Object.assign(new Error('RAW_CACHE_CANARY'), {code: 'EACCES'});
                }
                return read(file, ...args);
            };
            if (mode === 'rename') fs.renameSync = () => { throw new Error('RAW_CACHE_CANARY'); };
            await run(options);
        }`, {mode: 0o600});
    return script;
}

for (const mode of ['read-startup', 'read-recovery', 'rename']) {
    test(`cache ${mode} failure is safe and preserves existing credentials`, async () => {
        await withEndpoint(({response}) => response.writeHead(401).end(JSON.stringify(rejection('invalid_token'))), async endpoint => {
            await withBridgeHome(async fixture => {
                const result = await fixture.run({endpoint, messages: [request('failure')], initialCache: seed(endpoint),
                    oauth: true, onAuthorize: noAuthorization, script: faultScript(fixture.home, mode), startupOptions: {},
                    sensitiveValues: ['RAW_CACHE_CANARY']});
                completed(result, 1);
                assert.equal(result.cacheUnchanged, true);
                assert.equal(result.cacheEntries, 2);
                assert.match(result.stderr, /cache/i);
                if (mode !== 'read-startup') {
                    assert.equal(parseReplies(result.stdout, [request('failure')])[0].error.data.reason, 'invalid_token');
                    assert.match(result.stderr, /manual/i);
                }
            });
        });
    });
}

for (const operation of ['write', 'rename']) {
    test(`atomic cache save preserves the old store on ${operation} failure and removes temporary files`, async () => {
        await withBridgeHome(async fixture => {
            const store = seed('http://127.0.0.1:1/endpoint.php');
            fs.mkdirSync(path.dirname(fixture.cachePath), {recursive: true, mode: 0o700});
            const before = JSON.stringify(store, null, 2);
            fs.writeFileSync(fixture.cachePath, before, {mode: 0o600});
            const originalWrite = fs.writeFileSync;
            const originalRename = fs.renameSync;
            try {
                if (operation === 'write') fs.writeFileSync = (file, value, options) => {
                    originalWrite(file, 'partial', options);
                    throw new Error('Injected partial write');
                };
                else fs.renameSync = () => { throw new Error('Injected rename failure'); };
                assert.throws(() => saveTokenStore(fixture.cachePath, {replacement: {access_token: 'replacement'}}), /Injected/);
            } finally {
                fs.writeFileSync = originalWrite;
                fs.renameSync = originalRename;
            }
            assert.equal(fs.readFileSync(fixture.cachePath, 'utf8') === before, true, 'Failed save must not truncate the old store');
            assert.deepEqual(fs.readdirSync(path.dirname(fixture.cachePath)), ['tokens.json']);
        });
    });
}
