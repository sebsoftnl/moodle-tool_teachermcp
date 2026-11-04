import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const defaultScript = fileURLToPath(new URL('./mcp-bridge.mjs', import.meta.url));

export async function withEndpoint(handler, action) {
    const failures = [];
    const server = http.createServer(async (request, response) => {
        response.setHeader('content-type', 'application/json');
        if (request.url.startsWith('/oauth/strings.php')) {
            response.end('{}');
            return;
        }
        try {
            const chunks = [];
            for await (const chunk of request) chunks.push(chunk);
            await handler({request, response, body: Buffer.concat(chunks).toString()});
        } catch (error) {
            failures.push(error);
            response.writeHead(500).end(JSON.stringify({fixture_error: error.message}));
        }
    });
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });
    try {
        const result = await action(`http://127.0.0.1:${server.address().port}/endpoint.php`);
        assert.equal(failures.length, 0, failures.map(error => error.message).join('\n'));
        return result;
    } finally {
        server.closeAllConnections();
        await new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
    }
}

export function parseReplies(stdout, messages) {
    const requests = messages.flatMap(message => Array.isArray(message) ? message : [message]);
    const ids = requests.filter(request => Object.hasOwn(request, 'id')).map(request => request.id);
    const expected = new Map();
    for (const id of ids) expected.set(id, (expected.get(id) || 0) + 1);
    const lines = stdout.split(/\r?\n/).filter(line => line.trim() !== '');
    if (expected.size === 0) assert.equal(lines.length, 0, 'Notifications must not receive a reply');
    const replies = [];
    const seen = new Map();
    for (const line of lines) {
        const parsed = JSON.parse(line);
        for (const reply of Array.isArray(parsed) ? parsed : [parsed]) {
            assert.ok(reply && typeof reply === 'object', 'A JSON-RPC reply must be an object');
            assert.equal(reply.jsonrpc, '2.0');
            assert.ok(expected.has(reply.id), 'Unexpected reply ID');
            assert.ok((seen.get(reply.id) || 0) < expected.get(reply.id), 'Duplicate reply ID');
            assert.ok(Object.hasOwn(reply, 'result') !== Object.hasOwn(reply, 'error'), 'Reply needs exactly one outcome');
            seen.set(reply.id, (seen.get(reply.id) || 0) + 1);
            replies.push(reply);
        }
    }
    assert.equal(replies.length, ids.length, 'Missing JSON-RPC replies');
    return replies;
}

export function assertExchange(result, messages) {
    assert.equal(result.timedOut, false, 'Bridge exchange failed by timeout');
    assert.equal(result.code, 0, `Bridge exit was unsuccessful: ${result.stderr}`);
    const replies = parseReplies(result.stdout, messages);
    for (const reply of replies) {
        assert.ok(!Object.hasOwn(reply, 'error') && !reply.result?.isError, 'JSON-RPC request failed');
    }
    return replies;
}

function parseFixtureCache(text) {
    try {
        const store = JSON.parse(text);
        return store !== null && typeof store === 'object' && !Array.isArray(store) ? store : null;
    } catch {
        return null;
    }
}

export async function withBridgeHome(action) {
    const home = fs.mkdtempSync(path.join(os.tmpdir(), 'teachermcp-stdio-'));
    const bin = path.join(home, 'bin');
    const cache = process.platform === 'darwin'
        ? path.join(home, 'Library/Application Support/MoodleTeacherMCP/tokens.json')
        : process.platform === 'win32' ? path.join(home, 'AppData/Local/MoodleTeacherMCP/tokens.json')
            : path.join(home, '.config/moodle-teachermcp/tokens.json');
    try {
        fs.mkdirSync(bin);
        return await action({home, cachePath: cache, run: options => runBridgeInHome(options, {home, bin, cache})});
    } finally {
        fs.rmSync(home, {recursive: true, force: true});
    }
}

export async function runBridge(options) {
    return withBridgeHome(fixture => fixture.run(options));
}

async function runBridgeInHome({endpoint, messages, script = defaultScript, token = 'stdio-fixture-token',
    oauth = false, onAuthorize, timeout = 10000, input, startupOptions = null, initialCache = null,
    sensitiveValues = [], launcherSource}, {home, bin, cache}) {
    const url = new URL(endpoint);
    assert.ok(['localhost', '127.0.0.1', '[::1]'].includes(url.hostname), 'Fixture endpoint must be loopback');
    assert.ok(['http:', 'https:'].includes(url.protocol));
    if (oauth) assert.equal(typeof onAuthorize, 'function', 'OAuth fixtures require an injected local launcher');
    const capture = path.join(home, 'authorization.url');
    const unexpected = path.join(home, 'unexpected-launcher');
    const launcher = path.join(bin, process.platform === 'darwin' ? 'open' : 'xdg-open');
    let cacheBefore;
    const secrets = new Set([token, ...sensitiveValues]);
    let watcher;
    let child;
    let timer;
    let forceTimer;
    let closed;
    let authorization;
    let authorizationError;
    let authorizationSeen = false;
    let timedOut = false;
    try {
        for (const file of [capture, unexpected, launcher]) fs.rmSync(file, {force: true});
        if (initialCache !== null) {
            fs.mkdirSync(path.dirname(cache), {recursive: true, mode: 0o700});
            fs.writeFileSync(cache, JSON.stringify(initialCache, null, 2), {mode: 0o600});
        }
        cacheBefore = fs.existsSync(cache) ? fs.readFileSync(cache, 'utf8') : null;
        for (const entry of Object.values(parseFixtureCache(cacheBefore) ?? {})) {
            if (typeof entry?.access_token === 'string') secrets.add(entry.access_token);
        }
        if (!oauth) fs.writeFileSync(launcher,
            '#!/bin/sh\nprintf 1 > "$QA_UNEXPECTED_LAUNCHER_FILE"\nexit 1\n', {mode: 0o755});
        if (oauth) {
            assert.notEqual(process.platform, 'win32', 'Injected launcher fixtures are qualified on the local Unix runtimes only');
            if (launcherSource !== null) {
                fs.writeFileSync(launcher, launcherSource
                    ?? '#!/bin/sh\nprintf \'%s\' "$1" > "$QA_AUTHORIZATION_FILE"\n', {mode: 0o755});
            }
            watcher = fs.watch(home, () => {
                if (authorizationSeen || !fs.existsSync(capture)) return;
                const value = fs.readFileSync(capture, 'utf8');
                if (!value) return;
                authorizationSeen = true;
                const parsed = new URL(value);
                for (const name of ['state', 'code_challenge']) {
                    const secret = parsed.searchParams.get(name);
                    if (secret) secrets.add(secret);
                }
                authorization = Promise.resolve().then(() => onAuthorize(value, {pid: child.pid, home})).catch(error => {
                    authorizationError = error;
                    child.kill('SIGTERM');
                });
            });
        }
        const wrapper = startupOptions === null
            ? 'import {pathToFileURL} from "node:url"; process.argv=["node",process.env.QA_BRIDGE_SCRIPT,process.env.QA_BRIDGE_ENDPOINT,process.env.QA_BRIDGE_OAUTH==="1"?"--oauth":process.env.QA_BRIDGE_TOKEN]; await import(pathToFileURL(process.argv[1]).href);'
            : 'import {pathToFileURL} from "node:url"; process.argv=["node","qa-importer"]; const bridge=await import(pathToFileURL(process.env.QA_BRIDGE_SCRIPT).href); await bridge.startBridge({...JSON.parse(process.env.QA_BRIDGE_OPTIONS),endpoint:process.env.QA_BRIDGE_ENDPOINT,token:process.env.QA_BRIDGE_TOKEN,oauth:process.env.QA_BRIDGE_OAUTH==="1"});';
        child = spawn(process.execPath, ['--input-type=module', '-e', wrapper], {
            env: {...process.env, HOME: home, USERPROFILE: home, XDG_CONFIG_HOME: path.join(home, '.config'),
                LOCALAPPDATA: path.join(home, 'AppData/Local'),
                PATH: oauth && launcherSource === null ? bin : `${bin}${path.delimiter}${process.env.PATH}`,
                HTTP_PROXY: '', HTTPS_PROXY: '', ALL_PROXY: '', NO_PROXY: '*',
                QA_BRIDGE_SCRIPT: script, QA_BRIDGE_ENDPOINT: endpoint, QA_BRIDGE_TOKEN: token,
                QA_BRIDGE_OAUTH: oauth ? '1' : '0', QA_AUTHORIZATION_FILE: capture,
                QA_BRIDGE_OPTIONS: JSON.stringify(startupOptions), QA_UNEXPECTED_LAUNCHER_FILE: unexpected},
            stdio: ['pipe', 'pipe', 'pipe'],
        });
        let stdout = '';
        let stderr = '';
        child.stdout.on('data', chunk => { stdout += chunk.toString(); });
        child.stderr.on('data', chunk => { stderr += chunk.toString(); });
        closed = new Promise((resolve, reject) => {
            child.once('error', reject);
            child.once('close', (code, signal) => resolve({code, signal}));
        });
        timer = setTimeout(() => {
            timedOut = true;
            child.kill('SIGTERM');
            forceTimer = setTimeout(() => child.kill('SIGKILL'), 500);
        }, timeout);
        child.stdin.end(input ?? messages.map(message => JSON.stringify(message)).join('\n') + '\n');
        const result = await closed;
        if (authorization) await authorization;
        if (authorizationError) throw authorizationError;
        const cacheAfter = fs.existsSync(cache) ? fs.readFileSync(cache, 'utf8') : null;
        const store = parseFixtureCache(cacheAfter);
        let cacheEntries = 0;
        if (store !== null) {
            cacheEntries = Object.keys(store).length;
            for (const entry of Object.values(store)) {
                if (typeof entry?.access_token === 'string') secrets.add(entry.access_token);
            }
        }
        const stderrHasSecrets = [...secrets].some(secret => secret && stderr.includes(secret));
        for (const secret of secrets) if (secret) stderr = stderr.replaceAll(secret, '[REDACTED]');
        return {...result, stdout, stderr, timedOut, home, pid: child.pid, cacheEntries,
            cacheUnchanged: cacheBefore === cacheAfter, cacheValid: cacheAfter === null || store !== null,
            stderrHasSecrets, unexpectedAuthorization: fs.existsSync(unexpected), authorizationSeen};
    } finally {
        clearTimeout(timer);
        clearTimeout(forceTimer);
        watcher?.close();
        if (child && child.exitCode === null && child.signalCode === null) {
            child.kill('SIGKILL');
            if (closed) await closed;
        }
    }
}
