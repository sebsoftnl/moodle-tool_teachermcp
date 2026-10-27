import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const helperUrl = new URL('./test-client.mjs', import.meta.url);
async function client() {
    assert.ok(fs.existsSync(helperUrl), 'The isolated stdio client fixture must exist');
    return import(helperUrl.href);
}

const messages = [
    {jsonrpc: '2.0', id: 1, method: 'initialize', params: {protocolVersion: '2024-11-05'}},
    {jsonrpc: '2.0', method: 'notifications/initialized'},
    {jsonrpc: '2.0', id: 'list', method: 'tools/list'},
    {jsonrpc: '2.0', id: 3, method: 'tools/call', params: {name: 'fixture', arguments: {value: 42}}},
];

test('normal bridge path preserves initialize/list/call IDs and emits no notification reply', async () => {
    const {withEndpoint, runBridge, parseReplies} = await client();
    const seen = [];
    await withEndpoint(({request, response, body}) => {
        const rpc = JSON.parse(body);
        seen.push(rpc.method);
        assert.equal(request.headers.authorization, 'Bearer stdio-fixture-token');
        if (!Object.hasOwn(rpc, 'id')) {
            response.writeHead(202).end();
            return;
        }
        const result = rpc.method === 'initialize' ? {protocolVersion: '2024-11-05'}
            : rpc.method === 'tools/list' ? {tools: [{name: 'fixture'}]}
                : {content: [{type: 'text', text: '{"value":42}'}]};
        response.end(JSON.stringify({jsonrpc: '2.0', id: rpc.id, result}));
    }, async endpoint => {
        const result = await runBridge({endpoint, messages});
        assert.equal(result.code, 0, result.stderr);
        assert.equal(result.timedOut, false);
        const replies = parseReplies(result.stdout, messages);
        assert.equal(replies.length, 3);
        assert.deepEqual(JSON.parse(replies.find(reply => reply.id === 3).result.content[0].text), {value: 42});
        assert.equal(fs.existsSync(result.home), false);
        assert.throws(() => process.kill(result.pid, 0), {code: 'ESRCH'});
    });
    assert.deepEqual([...seen].sort(), messages.map(message => message.method).sort());
});

test('stdio client gate rejects a wrong reply ID from the actual bridge fixture', async () => {
    const {withEndpoint, runBridge, assertExchange} = await client();
    const request = [{jsonrpc: '2.0', id: 1, method: 'ping'}];
    let requested = false;
    await withEndpoint(({response}) => {
        requested = true;
        response.end(JSON.stringify({jsonrpc: '2.0', id: 999, result: {}}));
    }, async endpoint => {
        const result = await runBridge({endpoint, messages: request});
        assert.equal(requested, true);
        assert.throws(() => assertExchange(result, request), /reply ID|failed|exit/i);
        assert.equal(fs.existsSync(result.home), false);
    });
});

test('stdio client gate rejects non-JSON stdout and duplicate replies', async () => {
    const {withEndpoint, runBridge, parseReplies, assertExchange} = await client();
    const request = [{jsonrpc: '2.0', id: 1, method: 'ping'}];
    await withEndpoint(({response}) => response.end('unexpected fixture text'), async endpoint => {
        const result = await runBridge({endpoint, messages: request});
        assert.throws(() => assertExchange(result, request), /JSON|failed|exit/i);
    });
    assert.throws(() => parseReplies('unexpected stdout\n', request), /JSON/i);
    const reply = JSON.stringify({jsonrpc: '2.0', id: 1, result: {}});
    assert.throws(() => parseReplies(`${reply}\n${reply}\n`, request), /duplicate/i);
    assert.throws(() => parseReplies('[]\n', [{jsonrpc: '2.0', method: 'notifications/initialized'}]), /notification/i);
});

test('stdio client terminates a stalled child and removes its private HOME', async () => {
    const {withEndpoint, runBridge} = await client();
    let requested = false;
    await withEndpoint(() => { requested = true; }, async endpoint => {
        const result = await runBridge({endpoint, messages: [{jsonrpc: '2.0', id: 1, method: 'ping'}], timeout: 1000});
        assert.equal(requested, true, 'The bridge must reach the stalled endpoint before the deadline');
        assert.equal(result.timedOut, true);
        assert.notEqual(result.code, 0);
        assert.equal(fs.existsSync(result.home), false);
        assert.throws(() => process.kill(result.pid, 0), {code: 'ESRCH'});
    });
});

test('injected launcher completes local OAuth without opening a real browser or using real caches', async () => {
    const {withEndpoint, runBridge, parseReplies} = await client();
    let authorized = false;
    await withEndpoint(({request, response, body}) => {
        if (request.url === '/oauth/token.php') {
            const form = new URLSearchParams(body);
            assert.equal(form.get('code'), 'synthetic-auth-code');
            assert.ok(form.get('code_verifier').length >= 43);
            response.end(JSON.stringify({access_token: 'stdio-fixture-token', token_type: 'Bearer'}));
            return;
        }
        const rpc = JSON.parse(body);
        assert.equal(request.headers.authorization, 'Bearer stdio-fixture-token');
        response.end(JSON.stringify({jsonrpc: '2.0', id: rpc.id, result: {ok: true}}));
    }, async endpoint => {
        const requests = [{jsonrpc: '2.0', id: 1, method: 'ping'}];
        const result = await runBridge({endpoint, messages: requests, oauth: true, onAuthorize: async authorization => {
            const url = new URL(authorization);
            assert.equal(url.searchParams.get('code_challenge_method'), 'S256');
            const callback = new URL(url.searchParams.get('redirect_uri'));
            assert.equal(callback.hostname, 'localhost');
            callback.search = new URLSearchParams({code: 'synthetic-auth-code', state: url.searchParams.get('state')}).toString();
            const response = await fetch(callback);
            assert.equal(response.status, 200);
            const html = await response.text();
            assert.doesNotMatch(html, /Claude|ChatGPT|Codex|Cursor/,
                'The shared bridge callback must not claim a particular AI client connected.');
            assert.match(html, /Your AI assistant/);
            assert.match(html, /return to your AI app/);
            authorized = true;
        }});
        assert.equal(result.code, 0, result.stderr);
        assert.equal(result.timedOut, false);
        assert.equal(authorized, true);
        assert.equal(parseReplies(result.stdout, requests)[0].result.ok, true);
        assert.equal(result.cacheEntries, 1);
        assert.equal(fs.existsSync(result.home), false);
    });
});
