import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {getTokenFilePath, saveTokenStore, performOAuthFlow, loadStringsFromMoodle, forwardRequest} from './mcp-bridge.mjs';
import {withEndpoint, runBridge, parseReplies} from './test-client.mjs';

const messages = [{jsonrpc: '2.0', id: 'startup', method: 'ping'}];
const initialCache = {unrelated: {access_token: 'other-fixture-token', endpoint: 'http://127.0.0.1:2/endpoint.php', time: 1}};
const sensitiveValues = ['RAW_OAUTH_CANARY', 'fresh-fixture-token'];
const callbackUrl = (authorization, extra = {}) => {
    const url = new URL(authorization);
    const callback = new URL(url.searchParams.get('redirect_uri'));
    callback.search = new URLSearchParams({code: 'fixture-code', state: url.searchParams.get('state'), ...extra}).toString();
    return callback;
};
const callbackRequest = url => fetch(url, {signal: AbortSignal.timeout(2500)});

function failedStartup(result) {
    assert.equal(result.timedOut, false, 'Startup must settle before the fixture watchdog');
    assert.equal(result.code, 1, 'Startup failure must close with a nonzero exit');
    assert.equal(result.stdout, '');
    assert.equal(result.stderrHasSecrets, false);
    assert.equal(result.stderr.includes('code_challenge='), false, 'Authorization URLs must not be logged');
    assert.equal(result.cacheUnchanged, true);
}

async function withServer(handler, action) {
    const failures = [];
    const server = http.createServer(async (request, response) => {
        try { await handler(request, response); }
        catch (error) { failures.push(error); response.destroy(); }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        await action(`http://127.0.0.1:${server.address().port}/endpoint.php`);
        assert.equal(failures.length, 0, 'The owned HTTP fixture must not fail internally');
    } finally {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
}

test('translation body stalls cannot prevent bridge startup', async () => {
    await withServer(async (request, response) => {
        if (request.url.startsWith('/oauth/strings.php')) {
            response.writeHead(200, {'content-type': 'application/json'});
            response.flushHeaders();
            response.write('{');
            return;
        }
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        const message = JSON.parse(Buffer.concat(chunks).toString());
        response.end(JSON.stringify({jsonrpc: '2.0', id: message.id, result: {ok: true}}));
    }, async endpoint => {
        const result = await runBridge({endpoint, messages, startupOptions: {translationTimeoutMs: 100}, timeout: 2000});
        assert.equal(result.timedOut, false, 'The translation budget must cover JSON body consumption');
        assert.equal(result.code, 0);
        assert.deepEqual(parseReplies(result.stdout, messages)[0].result, {ok: true});
    });
});

test('OAuth consent timeout closes its callback listener without changing cache', async () => {
    let callback;
    await withEndpoint(() => assert.fail('No token or RPC request is allowed before consent'), async endpoint => {
        const result = await runBridge({endpoint, messages, oauth: true, initialCache, sensitiveValues,
            startupOptions: {oauthTimeoutMs: 1200}, timeout: 3000,
            onAuthorize: authorization => { callback = new URL(new URL(authorization).searchParams.get('redirect_uri')); }});
        failedStartup(result);
        assert.ok(callback);
        await assert.rejects(fetch(callback, {signal: AbortSignal.timeout(300)}));
    });
});

for (const [label, params] of [['denied', {error: 'RAW_OAUTH_CANARY'}], ['wrong-state', {state: 'wrong-state'}]]) {
    test(`OAuth ${label} has a safe error page and no token exchange`, async () => {
        let exchanges = 0;
        let callback;
        await withEndpoint(({response}) => { exchanges++; response.writeHead(500).end(); }, async endpoint => {
            const result = await runBridge({endpoint, messages, oauth: true, initialCache, sensitiveValues,
                startupOptions: {oauthTimeoutMs: 1200}, timeout: 3000,
                onAuthorize: async authorization => {
                    const response = await callbackRequest(callbackUrl(authorization, params));
                    callback = {status: response.status, safe: !(await response.text()).includes('RAW_OAUTH_CANARY')};
                }});
            failedStartup(result);
            assert.deepEqual(callback, {status: 400, safe: true});
        });
        assert.equal(exchanges, 0);
    });
}

for (const [label, status, body] of [
    ['http-error', 500, 'RAW_OAUTH_CANARY fresh-fixture-token'],
    ['invalid-json', 200, 'RAW_OAUTH_CANARY'],
    ['missing-token', 200, '{"token_type":"Bearer"}'],
    ['empty-token', 200, '{"access_token":""}'],
    ['wrong-token-type', 200, '{"access_token":42}'],
]) {
    test(`OAuth ${label} cannot save a credential or expose the response body`, async () => {
        let exchanges = 0;
        let rpcCalls = 0;
        let callback;
        await withEndpoint(({request, response, body: requestBody}) => {
            if (request.url === '/oauth/token.php') {
                exchanges++;
                assert.equal(new URLSearchParams(requestBody).get('code'), 'fixture-code');
                response.writeHead(status).end(body);
            } else {
                rpcCalls++;
                const message = JSON.parse(requestBody);
                response.end(JSON.stringify({jsonrpc: '2.0', id: message.id, result: {}}));
            }
        }, async endpoint => {
            const result = await runBridge({endpoint, messages, oauth: true, initialCache, sensitiveValues,
                startupOptions: {oauthTimeoutMs: 1200}, timeout: 3000,
                onAuthorize: async authorization => {
                    const response = await callbackRequest(callbackUrl(authorization));
                    callback = {status: response.status, safe: !(await response.text()).includes('RAW_OAUTH_CANARY')};
                }});
            failedStartup(result);
            assert.deepEqual(callback, {status: 500, safe: true});
        });
        assert.equal(exchanges, 1);
        assert.equal(rpcCalls, 0);
    });
}

for (const [label, launcherSource] of [['missing', null], ['nonzero', '#!/bin/sh\nexit 7\n']]) {
    test(`OAuth handles ${label} launcher failure without waiting for its consent deadline`, async () => {
        await withEndpoint(() => assert.fail('Failed launcher must not issue a request'), async endpoint => {
            const result = await runBridge({endpoint, messages, oauth: true, initialCache, sensitiveValues,
                launcherSource, startupOptions: {oauthTimeoutMs: 1200}, timeout: 2000,
                onAuthorize: () => assert.fail('A failed launcher must not authorize')});
            failedStartup(result);
        });
    });
}

test('one OAuth deadline covers delayed consent and a stalled token body', async () => {
    let exchanges = 0;
    let authorizedAt;
    await withEndpoint(({request, response}) => {
        assert.equal(request.url, '/oauth/token.php');
        exchanges++;
        response.writeHead(200);
        response.flushHeaders();
        response.write('{');
    }, async endpoint => {
        const result = await runBridge({endpoint, messages, oauth: true, initialCache, sensitiveValues,
            startupOptions: {oauthTimeoutMs: 1200}, timeout: 3000,
            onAuthorize: async authorization => {
                authorizedAt = Date.now();
                await new Promise(resolve => setTimeout(resolve, 300));
                try {
                    const response = await callbackRequest(callbackUrl(authorization));
                    await response.text();
                } catch (error) {
                    assert.match(error.message, /fetch failed|aborted|timeout/i);
                }
            }});
        failedStartup(result);
        assert.equal(exchanges, 1);
        assert.ok(Date.now() - authorizedAt < 1350, 'Token exchange must not reset the consent deadline');
    });
});

test('duplicate OAuth callbacks cause only one token exchange and cache write', async () => {
    let exchanges = 0;
    let firstResponse;
    let markTokenStarted;
    const tokenStarted = new Promise(resolve => { markTokenStarted = resolve; });
    const token = JSON.stringify({access_token: 'fresh-fixture-token', token_type: 'Bearer'});
    let statuses;
    await withEndpoint(({request, response, body}) => {
        if (request.url === '/oauth/token.php') {
            exchanges++;
            if (exchanges === 1) { firstResponse = response; markTokenStarted(); }
            else response.end(token);
        } else {
            const message = JSON.parse(body);
            response.end(JSON.stringify({jsonrpc: '2.0', id: message.id, result: {ok: true}}));
        }
    }, async endpoint => {
        const result = await runBridge({endpoint, messages, oauth: true, initialCache, sensitiveValues,
            startupOptions: {oauthTimeoutMs: 1500}, timeout: 3000,
            onAuthorize: async authorization => {
                const callback = callbackUrl(authorization);
                const first = callbackRequest(callback);
                await Promise.race([tokenStarted, first.then(() => { throw new Error('Expected a pending token exchange'); })]);
                let second;
                try { second = await callbackRequest(callback); }
                finally { firstResponse.end(token); }
                const completed = await first;
                statuses = [completed.status, second.status];
                await Promise.all([completed.text(), second.text()]);
            }});
        assert.equal(result.timedOut, false);
        assert.equal(result.code, 0);
        assert.equal(result.stderrHasSecrets, false);
        assert.deepEqual(statuses, [200, 409]);
        assert.equal(exchanges, 1);
        assert.equal(result.cacheEntries, 2);
        assert.deepEqual(parseReplies(result.stdout, messages)[0].result, {ok: true});
    });
});

test('explicit startup cancellation closes gracefully with no cached grant', async () => {
    await withEndpoint(() => assert.fail('Cancelled startup must not issue requests'), async endpoint => {
        const result = await runBridge({endpoint, messages, oauth: true, initialCache, sensitiveValues,
            startupOptions: {oauthTimeoutMs: 1200}, timeout: 3000,
            onAuthorize: (authorization, context) => process.kill(context.pid, 'SIGTERM')});
        failedStartup(result);
        assert.equal(result.signal, null);
    });
});

async function observeSettlement(promise, timeout = 1600) {
    let timer;
    try {
        return await Promise.race([
            promise.then(value => ({ok: true, value}), error => ({ok: false, error})),
            new Promise(resolve => { timer = setTimeout(() => resolve({watchdog: true}), timeout); }),
        ]);
    } finally { clearTimeout(timer); }
}

async function withPrivateEnvironment(action) {
    const home = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'teachermcp-oauth-unit-')));
    const keys = ['HOME', 'USERPROFILE', 'XDG_CONFIG_HOME', 'LOCALAPPDATA', 'PATH'];
    const before = Object.fromEntries(keys.map(key => [key, process.env[key]]));
    const originalCreate = http.createServer;
    const originalError = console.error;
    const servers = [];
    const logs = [];
    const makeServer = (...args) => { const server = originalCreate(...args); servers.push(server); return server; };
    const bin = path.join(home, 'bin');
    fs.mkdirSync(bin);
    fs.writeFileSync(path.join(bin, process.platform === 'darwin' ? 'open' : 'xdg-open'), '#!/bin/sh\nexit 0\n', {mode: 0o755});
    Object.assign(process.env, {HOME: home, USERPROFILE: home, XDG_CONFIG_HOME: path.join(home, '.config'),
        LOCALAPPDATA: path.join(home, 'AppData/Local'), PATH: bin});
    http.createServer = makeServer;
    console.error = (...values) => { logs.push(values.map(String).join(' ')); };
    try { await action({home, logs, makeServer}); }
    finally {
        for (const server of servers) {
            server.closeAllConnections();
            if (server.listening) await new Promise(resolve => server.close(resolve));
        }
        http.createServer = originalCreate;
        console.error = originalError;
        for (const key of keys) {
            if (before[key] === undefined) delete process.env[key];
            else process.env[key] = before[key];
        }
        fs.rmSync(home, {recursive: true, force: true});
        assert.equal(fs.existsSync(home), false);
    }
}

test('OAuth listen failure is handled without exposing its exception', async () => {
    await withPrivateEnvironment(async ({makeServer, logs}) => {
        let invoked = false;
        const outcome = await observeSettlement(performOAuthFlow('http://127.0.0.1:1/endpoint.php', {
            oauthTimeoutMs: 100,
            createServer: handler => {
                invoked = true;
                const server = makeServer(handler);
                server.listen = () => { server.emit('error', new Error('RAW_OAUTH_CANARY')); return server; };
                return server;
            },
        }));
        assert.equal(invoked, true);
        assert.equal(outcome.watchdog, undefined);
        assert.equal(outcome.ok, false);
        assert.equal(outcome.error.message.includes('RAW_OAUTH_CANARY'), false);
        assert.equal(logs.some(line => line.includes('RAW_OAUTH_CANARY')), false);
    });
});

test('OAuth denial terminates and reaps its still-running owned launcher', async () => {
    await withPrivateEnvironment(async ({makeServer}) => {
        let launcher;
        let closed;
        let reaped = false;
        let callbackWork = Promise.resolve();
        try {
            const outcome = await observeSettlement(performOAuthFlow('http://127.0.0.1:1/endpoint.php', {
                oauthTimeoutMs: 1000, createServer: makeServer,
                spawnImpl: (command, args) => {
                    launcher = spawn(process.execPath, ['-e', 'setInterval(() => {}, 1000)'], {stdio: 'ignore'});
                    closed = new Promise(resolve => launcher.once('close', () => { reaped = true; resolve(); }));
                    callbackWork = callbackRequest(callbackUrl(args[0], {error: 'access_denied'})).then(response => response.text());
                    return launcher;
                },
            }));
            assert.equal(Boolean(launcher), true);
            assert.equal(outcome.watchdog, undefined);
            assert.equal(outcome.ok, false);
            await callbackWork;
            assert.equal(reaped, true, 'OAuth must await launcher closure before rejecting');
            assert.throws(() => process.kill(launcher.pid, 0), {code: 'ESRCH'});
        } finally {
            if (launcher && !reaped) { launcher.kill('SIGKILL'); await closed; }
            await callbackWork;
        }
    });
});

test('a token body completing after the OAuth deadline cannot update cache', async () => {
    await withPrivateEnvironment(async ({makeServer}) => {
        const cache = getTokenFilePath();
        saveTokenStore(cache, initialCache);
        const before = fs.readFileSync(cache, 'utf8');
        let completeBody;
        let callbackWork = Promise.resolve();
        let launcher;
        let closed;
        try {
            const outcome = await observeSettlement(performOAuthFlow('http://127.0.0.1:1/endpoint.php', {
                oauthTimeoutMs: 250, createServer: makeServer,
                fetchImpl: async () => ({ok: true, json: () => new Promise(resolve => { completeBody = resolve; })}),
                spawnImpl: (command, args) => {
                    launcher = spawn(process.execPath, ['-e', ''], {stdio: 'ignore'});
                    closed = new Promise(resolve => launcher.once('close', resolve));
                    callbackWork = callbackRequest(callbackUrl(args[0])).then(response => response.text());
                    return launcher;
                },
            }));
            assert.equal(typeof completeBody, 'function');
            assert.equal(outcome.watchdog, undefined);
            assert.equal(outcome.ok, false);
            completeBody({access_token: 'late-fixture-token'});
            await new Promise(resolve => setImmediate(resolve));
            assert.equal(fs.readFileSync(cache, 'utf8') === before, true, 'Late token data must not be persisted');
        } finally {
            completeBody?.({access_token: 'late-fixture-token'});
            if (launcher && launcher.exitCode === null && launcher.signalCode === null) launcher.kill('SIGKILL');
            if (closed) await closed;
            await callbackWork;
        }
    });
});

test('late translation JSON cannot replace active fallback strings', async () => {
    await withEndpoint(({response}) => response.writeHead(503).end(), async endpoint => {
        await withPrivateEnvironment(async ({logs}) => {
            let completeBody;
            try {
                const outcome = await observeSettlement(loadStringsFromMoodle(endpoint.replace('/endpoint.php', ''), null, {
                    translationTimeoutMs: 50,
                    fetchImpl: async () => ({ok: true, json: () => new Promise(resolve => { completeBody = resolve; })}),
                }));
                assert.equal(typeof completeBody, 'function');
                assert.equal(outcome.watchdog, undefined);
                completeBody({comm_error: 'RAW_OAUTH_CANARY'});
                await new Promise(resolve => setImmediate(resolve));
                await forwardRequest('{"method":"notice"}', {endpoint, token: 'fixture',
                    fetchImpl: async () => ({ok: false, status: 503, text: async () => ''})});
                assert.equal(logs.some(line => line.includes('RAW_OAUTH_CANARY')), false);
            } finally {
                completeBody?.({});
                await loadStringsFromMoodle(endpoint.replace('/endpoint.php', ''), null, {
                    translationTimeoutMs: 50,
                    fetchImpl: async () => ({ok: true, json: async () => ({comm_error: 'Bridge communication error:'})}),
                });
            }
        });
    });
});
