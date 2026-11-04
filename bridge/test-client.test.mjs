import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {runBridge, parseReplies} from './test-client.mjs';

const endpoint = 'http://127.0.0.1:1/endpoint.php';

async function withProgram(source, action) {
    const directory = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'teachermcp-client-probe-')));
    try {
        const script = path.join(directory, 'probe.mjs');
        fs.writeFileSync(script, source, {mode: 0o600});
        await action(script);
    } finally {
        fs.rmSync(directory, {recursive: true, force: true});
        assert.equal(fs.existsSync(directory), false);
    }
}

test('stdio fixture matches duplicate request IDs by occurrence and rejects surplus or missing replies', () => {
    const messages = [[{id: 'same'}, {id: 'same'}, {id: null}]];
    const replies = [{jsonrpc: '2.0', id: 'same', result: 1},
        {jsonrpc: '2.0', id: 'same', result: 2}, {jsonrpc: '2.0', id: null, result: 3}];
    assert.deepEqual(parseReplies(JSON.stringify(replies), messages), replies);
    assert.throws(() => parseReplies(JSON.stringify([...replies, replies[0]]), messages), /Duplicate reply ID/);
    assert.throws(() => parseReplies(JSON.stringify(replies.slice(1)), messages), /Missing JSON-RPC replies/);
});

test('stdio fixture passes raw input and internal startup options to an imported runner', async () => {
    await withProgram(`
        import readline from 'node:readline';
        export async function startBridge(options) {
            const lines = readline.createInterface({input: process.stdin});
            for await (const line of lines) {
                process.stdout.write(JSON.stringify({line, budget: options.requestTimeoutMs}) + '\\n');
            }
        }`, async script => {
        const result = await runBridge({endpoint, script, messages: [], input: 'not JSON\n',
            startupOptions: {requestTimeoutMs: 25}});
        assert.equal(result.code, 0);
        assert.notEqual(result.stdout, '', 'Internal startup options must invoke the exported runner');
        assert.deepEqual(JSON.parse(result.stdout), {line: 'not JSON', budget: 25});
        assert.equal(fs.existsSync(result.home), false);
    });
});

test('stdio fixture seeds and compares cache contents before removing its private HOME', async () => {
    await withProgram('process.stdin.resume();', async script => {
        const initialCache = {
            first: {access_token: 'first-fixture-token', endpoint, time: 1},
            second: {access_token: 'second-fixture-token', endpoint: 'http://127.0.0.1:2/endpoint.php', time: 2},
        };
        const result = await runBridge({endpoint, script, messages: [], initialCache});
        assert.equal(result.code, 0);
        assert.equal(result.cacheEntries, 2, 'The owned cache must be populated before startup');
        assert.equal(result.cacheUnchanged, true, 'Cache comparison must happen before HOME removal');
        assert.equal(fs.existsSync(result.home), false);
    });
});

test('stdio fixture detects raw stderr secrets before redacting returned diagnostics', async () => {
    await withProgram('process.stderr.write(process.env.QA_BRIDGE_TOKEN + " response-canary\\n"); process.stdin.resume();',
        async script => {
            const result = await runBridge({endpoint, script, messages: [], sensitiveValues: ['response-canary']});
            assert.equal(result.code, 0);
            assert.equal(result.stderrHasSecrets, true, 'Post-redaction output must not hide an actual raw leak');
            assert.equal(result.stderr.includes('stdio-fixture-token'), false);
            assert.equal(result.stderr.includes('response-canary'), false);
            assert.equal(fs.existsSync(result.home), false);
        });
});

const launcherProbe = `
    import {spawn} from 'node:child_process';
    const child = spawn(process.platform === 'darwin' ? 'open' : 'xdg-open',
        [process.env.QA_BRIDGE_ENDPOINT], {stdio: 'ignore'});
    let failed = false;
    child.once('error', error => { failed = true; console.log(JSON.stringify({spawnError: error.code})); });
    child.once('close', code => { if (!failed) console.log(JSON.stringify({launcherExit: code})); });
    process.stdin.resume();`;

for (const [label, launcherSource, expected] of [
    ['nonzero', '#!/bin/sh\nexit 7\n', {launcherExit: 7}],
    ['missing', null, {spawnError: 'ENOENT'}],
]) {
    test(`stdio fixture can model an owned ${label} launcher without opening a real browser`, async () => {
        await withProgram(launcherProbe, async script => {
            const result = await runBridge({endpoint, script, messages: [], oauth: true,
                onAuthorize: () => {}, launcherSource});
            assert.equal(result.code, 0);
            assert.deepEqual(JSON.parse(result.stdout), expected);
        });
    });
}

test('authorization fixture exposes only its owned process and HOME for cancellation', async () => {
    await withProgram(launcherProbe + '\nsetInterval(() => {}, 1000);', async script => {
        let observed = false;
        const result = await runBridge({endpoint, script, messages: [], oauth: true,
            onAuthorize: (url, context) => {
                assert.ok(context, 'Authorization callback must receive the owned process context');
                assert.ok(Number.isInteger(context.pid) && fs.existsSync(context.home));
                observed = true;
                process.kill(context.pid, 'SIGTERM');
            }});
        assert.equal(observed, true);
        assert.equal(result.timedOut, false);
        assert.equal(result.signal, 'SIGTERM');
        assert.equal(fs.existsSync(result.home), false);
    });
});
