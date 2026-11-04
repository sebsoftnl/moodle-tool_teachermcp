import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';
import {withEndpoint, withBridgeHome, parseReplies} from './test-client.mjs';

const builder = fileURLToPath(new URL('./build-chatgpt-plugin.mjs', import.meta.url));
const bridge = fileURLToPath(new URL('./mcp-bridge.mjs', import.meta.url));
const endpoint = 'https://moodle.example.org/admin/tool/teachermcp/endpoint.php';
const pluginName = 'moodle-teacher-mcp';
const readJson = filename => JSON.parse(fs.readFileSync(filename, 'utf8'));

function build(args) {
    return spawnSync(process.execPath, [builder, ...args], {encoding: 'utf8', timeout: 10000});
}

async function withOutput(action) {
    const root = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'teachermcp-package-')));
    try {
        await action(path.join(root, 'package with spaces caf\u00e9'), root);
    } finally {
        fs.rmSync(root, {recursive: true, force: true});
        assert.equal(fs.existsSync(root), false);
    }
}

test('package CLI explicitly declares its MCP server in the desktop manifest', async () => {
    await withOutput(output => {
        const result = build(['--endpoint', endpoint, '--output', output]);
        assert.equal(result.status, 0, 'Package generation must succeed: ' + result.stderr);
        const plugin = path.join(output, 'plugins', pluginName);
        assert.ok(fs.existsSync(path.join(plugin, '.codex-plugin/plugin.json')),
            'The desktop package must explicitly declare its MCP configuration through .codex-plugin/plugin.json');
        const manifest = readJson(path.join(plugin, '.codex-plugin/plugin.json'));
        assert.equal(manifest.name, pluginName);
        assert.equal(manifest.requires_local_executor, true);
        assert.equal(manifest.mcpServers, './.mcp.json');
        const mcp = readJson(path.join(plugin, manifest.mcpServers));
        assert.equal(mcp.mcpServers.moodle.cwd, './',
            'The host requires a plugin-relative cwd without placeholder expansion');
        assert.deepEqual(mcp.mcpServers, {moodle: {
            command: process.execPath,
            args: ['./mcp-bridge.mjs', endpoint, '--oauth'], cwd: './',
            startup_timeout_sec: 240,
        }});
        assert.deepEqual(fs.readFileSync(path.join(plugin, 'mcp-bridge.mjs')), fs.readFileSync(bridge));
        const marketplace = readJson(path.join(output, '.agents/plugins/marketplace.json'));
        assert.equal(marketplace.name, 'moodle-teacher-mcp-local');
        assert.equal(marketplace.plugins.length, 1);
        const entry = marketplace.plugins[0];
        assert.equal(entry.name, manifest.name);
        assert.deepEqual(entry.source, {source: 'local', path: './plugins/moodle-teacher-mcp'});
        assert.deepEqual(entry.policy, {installation: 'AVAILABLE', authentication: 'ON_INSTALL'});
        assert.equal(entry.category, 'Productivity');
        assert.equal(path.resolve(output, entry.source.path), plugin);
        assert.deepEqual(fs.readdirSync(plugin).sort(), ['.codex-plugin', '.mcp.json', 'mcp-bridge.mjs']);
        assert.equal(JSON.parse(result.stdout).output, output);
    });
});

test('package CLI rejects invalid or secret-bearing endpoints before creating output', async () => {
    for (const invalid of ['not-a-url', 'file:///tmp/endpoint.php', 'http://moodle.example.org/endpoint.php',
        'https://teacher:secret@moodle.example.org/endpoint.php', endpoint + '?token=secret', endpoint + '#secret']) {
        await withOutput(output => {
            const result = build(['--endpoint', invalid, '--output', output]);
            assert.equal(result.status, 1);
            assert.match(result.stderr, /Endpoint must/);
            assert.equal(result.stderr.includes('secret'), false);
            assert.equal(fs.existsSync(output), false);
        });
    }
});

test('package CLI accepts loopback HTTP without adding credentials', async () => {
    for (const host of ['localhost', '127.0.0.1', '[::1]']) {
        await withOutput(output => {
            const result = build(['--endpoint', `http://${host}:8153/endpoint.php`, '--output', output]);
            assert.equal(result.status, 0, result.stderr);
            assert.equal(readJson(path.join(output, 'plugins', pluginName, '.mcp.json')).mcpServers.moodle.args[2], '--oauth');
        });
    }
});

test('package CLI refuses to overwrite an existing directory', async () => {
    await withOutput(output => {
        fs.mkdirSync(output);
        fs.writeFileSync(path.join(output, 'keep.txt'), 'Existing content');
        const result = build(['--endpoint', endpoint, '--output', output]);
        assert.equal(result.status, 1);
        assert.match(result.stderr, /Output directory already exists/);
        assert.deepEqual(fs.readdirSync(output), ['keep.txt']);
        assert.equal(fs.readFileSync(path.join(output, 'keep.txt'), 'utf8'), 'Existing content');
    });
});

test('package CLI validates arguments and provides help', async () => {
    const help = build(['--help']);
    assert.equal(help.status, 0, help.stderr);
    assert.match(help.stdout, /--endpoint.*--output/);
    await withOutput(output => {
        for (const args of [[], ['--endpoint', endpoint], ['--output', output],
            ['--endpoint', endpoint, '--output', output, '--token', 'secret'],
            ['--endpoint', endpoint, '--endpoint', endpoint, '--output', output]]) {
            const result = build(args);
            assert.equal(result.status, 1);
            assert.match(result.stderr, /Usage:/);
            assert.equal(result.stderr.includes('secret'), false);
            assert.equal(fs.existsSync(output), false);
        }
    });
});

test('relocated package launches its declared stdio command and returns authenticated replies', async () => {
    const messages = [
        {jsonrpc: '2.0', id: 1, method: 'initialize', params: {protocolVersion: '2024-11-05', capabilities: {}}},
        {jsonrpc: '2.0', id: 2, method: 'tools/list'},
        {jsonrpc: '2.0', id: 3, method: 'tools/call', params: {name: 'package_probe', arguments: {}}},
    ];
    let received = 0;
    await withEndpoint(({request, response, body}) => {
        assert.equal(request.headers.authorization, 'Bearer package-fixture-token');
        const message = JSON.parse(body);
        received++;
        const result = message.method === 'initialize'
            ? {protocolVersion: '2024-11-05', capabilities: {tools: {}}, serverInfo: {name: 'fixture', version: '1'}}
            : message.method === 'tools/list' ? {tools: [{name: 'package_probe', inputSchema: {type: 'object'}}]}
                : {content: [{type: 'text', text: 'package reached fixture'}], isError: false};
        response.end(JSON.stringify({jsonrpc: '2.0', id: message.id, result}));
    }, async localEndpoint => {
        await withOutput(async (output, root) => {
            const result = build(['--endpoint', localEndpoint, '--output', output]);
            assert.equal(result.status, 0, result.stderr);
            const cached = path.join(root, 'installed cache');
            fs.cpSync(path.join(output, 'plugins', pluginName), cached, {recursive: true});
            fs.rmSync(output, {recursive: true});
            const manifest = readJson(path.join(cached, '.codex-plugin/plugin.json'));
            const server = readJson(path.join(cached, manifest.mcpServers)).mcpServers.moodle;
            await withBridgeHome(async ({home, cachePath}) => {
                const key = crypto.createHash('sha256').update(localEndpoint).digest('hex').substring(0, 12);
                fs.mkdirSync(path.dirname(cachePath), {recursive: true});
                fs.writeFileSync(cachePath, JSON.stringify({[key]: {access_token: 'package-fixture-token'}}), {mode: 0o600});
                const cwd = path.resolve(cached, server.cwd);
                assert.ok(fs.existsSync(cwd), 'The declared working directory must resolve in the installed package');
                const child = spawn(server.command, server.args, {
                    cwd, env: {...process.env, HOME: home, USERPROFILE: home,
                        XDG_CONFIG_HOME: path.join(home, '.config'), LOCALAPPDATA: path.join(home, 'AppData/Local'),
                        HTTP_PROXY: '', HTTPS_PROXY: '', ALL_PROXY: '', NO_PROXY: '*'},
                    stdio: ['pipe', 'pipe', 'pipe'], timeout: 10000, killSignal: 'SIGKILL',
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
                    child.stdin.end(messages.map(message => JSON.stringify(message)).join('\n') + '\n');
                    const exit = await closed;
                    assert.equal(exit.signal, null, 'Generated package must finish without timeout');
                    assert.equal(exit.code, 0, stderr);
                    const replies = parseReplies(stdout, messages);
                    assert.equal(replies.find(reply => reply.id === 2).result.tools[0].name, 'package_probe');
                    assert.equal(replies.find(reply => reply.id === 3).result.content[0].text, 'package reached fixture');
                    assert.equal(stderr.includes('package-fixture-token'), false);
                    assert.throws(() => process.kill(child.pid, 0), {code: 'ESRCH'});
                } finally {
                    if (child.exitCode === null && child.signalCode === null) {
                        child.kill('SIGKILL');
                        await closed;
                    }
                }
            });
        });
    });
    assert.equal(received, 3);
});
