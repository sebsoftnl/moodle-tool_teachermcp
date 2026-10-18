import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';
import {withEndpoint, parseReplies} from './test-client.mjs';

const source = fileURLToPath(new URL('./mcp-bridge.mjs', import.meta.url));
const variants = {normal: 'normal', spaced: 'with spaces', unicode: 'caf\u00e9'};

async function withScript(folder, action) {
    const home = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'teachermcp-entrypoint-')));
    try {
        const directory = path.join(home, folder);
        fs.mkdirSync(directory);
        const script = path.join(directory, 'mcp-bridge.mjs');
        fs.copyFileSync(source, script);
        await action(script, home);
    } finally {
        fs.rmSync(home, {recursive: true, force: true});
        assert.equal(fs.existsSync(home), false);
    }
}

async function runNode(args, home, input = '', extraEnv = {}) {
    const child = spawn(process.execPath, args, {
        env: {...process.env, HOME: home, USERPROFILE: home, XDG_CONFIG_HOME: path.join(home, '.config'),
            LOCALAPPDATA: path.join(home, 'AppData/Local'), HTTP_PROXY: '', HTTPS_PROXY: '', ALL_PROXY: '',
            NO_PROXY: '*', ...extraEnv},
        stdio: ['pipe', 'pipe', 'pipe'], timeout: 5000, killSignal: 'SIGKILL',
    });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', chunk => { stdout += chunk.toString(); });
    child.stderr.on('data', chunk => { stderr += chunk.toString(); });
    const closed = new Promise((resolve, reject) => {
        child.once('error', reject);
        child.once('close', (code, signal) => resolve({code, signal}));
    });
    try {
        child.stdin.end(input);
        const result = await closed;
        assert.throws(() => process.kill(child.pid, 0), {code: 'ESRCH'});
        assert.equal(result.signal, null, 'The CLI must finish without being killed by the timeout');
        return {...result, stdout, stderr};
    } finally {
        if (child.exitCode === null && child.signalCode === null) {
            child.kill('SIGKILL');
            await closed;
        }
    }
}

for (const [label, folder] of Object.entries(variants)) {
    test(`CLI ${label} path forwards one request and returns its result`, async () => {
        await withScript(folder, async (script, home) => {
            let received = 0;
            const message = {jsonrpc: '2.0', id: 'path-probe', method: 'ping'};
            await withEndpoint(({request, response, body}) => {
                received++;
                assert.equal(request.headers.authorization, 'Bearer entrypoint-fixture-token');
                assert.deepEqual(JSON.parse(body), message);
                response.end(JSON.stringify({jsonrpc: '2.0', id: message.id, result: {ok: true}}));
            }, async endpoint => {
                const result = await runNode([script, endpoint, 'entrypoint-fixture-token'], home,
                    JSON.stringify(message) + '\n');
                assert.equal(result.code, 0, result.stderr);
                assert.equal(received, 1, 'A launched CLI must reach its endpoint exactly once');
                assert.deepEqual(parseReplies(result.stdout, [message])[0].result, {ok: true});
            });
        });
    });

    test(`CLI ${label} path rejects missing and invalid endpoints clearly`, async () => {
        await withScript(folder, async (script, home) => {
            const missing = await runNode([script], home);
            assert.equal(missing.code, 1, 'Missing endpoint must fail rather than silently skip startup');
            assert.match(missing.stderr, /Missing endpoint argument/);
            assert.equal(missing.stdout, '');
            for (const endpoint of ['not-a-url', 'file:///tmp/endpoint.php']) {
                const invalid = await runNode([script, endpoint, 'entrypoint-fixture-token'], home);
                assert.equal(invalid.code, 1);
                assert.match(invalid.stderr, /Invalid URL|HTTP or HTTPS/);
                assert.equal(invalid.stdout, '');
            }
        });
    });
}

for (const [label, argv] of [
    ['absent', ['node']],
    ['unrelated', ['node', '/importer.mjs', 'http://127.0.0.1:1/endpoint.php', '--oauth']],
]) {
    test(`helper import with ${label} CLI arguments has no startup I/O`, async () => {
        await withScript('import probe', async (script, home) => {
            const probe = `
                import fs from 'node:fs';
                import http from 'node:http';
                import childProcess from 'node:child_process';
                import {syncBuiltinESMExports} from 'node:module';
                import {pathToFileURL} from 'node:url';
                const calls = {fetch:0, listener:0, browser:0, cache:0};
                const forbid = kind => () => { calls[kind]++; throw new Error('Unexpected import I/O: ' + kind); };
                globalThis.fetch = forbid('fetch');
                http.createServer = forbid('listener');
                childProcess.spawn = forbid('browser');
                fs.existsSync = forbid('cache');
                syncBuiltinESMExports();
                process.argv = JSON.parse(process.env.QA_IMPORT_ARGV);
                const loaded = await import(pathToFileURL(process.env.QA_IMPORT_PATH).href);
                const exports = Object.fromEntries(['getBrowserLauncher','getTokenFilePath','saveTokenStore','validateEndpoint']
                    .map(name => [name, typeof loaded[name]]));
                console.log(JSON.stringify({calls, exports}));`;
            const result = await runNode(['--input-type=module', '-e', probe], home, '', {
                QA_IMPORT_PATH: script, QA_IMPORT_ARGV: JSON.stringify(argv),
            });
            assert.equal(result.code, 0, result.stderr);
            assert.equal(result.stderr, '');
            const observed = JSON.parse(result.stdout);
            assert.deepEqual(observed.calls, {fetch: 0, listener: 0, browser: 0, cache: 0});
            assert.deepEqual(Object.values(observed.exports), ['function', 'function', 'function', 'function']);
        });
    });
}
