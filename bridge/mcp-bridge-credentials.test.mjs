/*
 * This file is part of Moodle - http://moodle.org/
 *
 * Moodle is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Moodle is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * TeacherMCP protected manual bridge credential tests.
 *
 * @copyright 2026 Sebsoft BV
 * @author Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import test from 'node:test';
import {withEndpoint, parseReplies} from './test-client.mjs';
import {spawn} from 'node:child_process';

const script = fileURLToPath(new URL('./mcp-bridge.mjs', import.meta.url));
const secret = 'manual-secret-fixture';

function withToken(action) {
    const home = fs.mkdtempSync(path.join(os.tmpdir(), 'manual-token-'));
    try {
        const filename = path.join(home, 'bearer token.txt');
        fs.writeFileSync(filename, secret + '\n', {mode: 0o600});
        return action(filename);
    } finally {
        fs.rmSync(home, {recursive: true, force: true});
    }
}

test('manual CLI rejects raw tokens, missing sources and conflicting authentication without leaking secrets', () => {
    for (const flags of [[secret], [], ['--token-file'], ['--oauth', secret],
        ['--oauth', '--token-file', '/missing'], ['--token-file', '/missing', secret]]) {
        const result = spawnSync(process.execPath, [script, 'http://127.0.0.1:1/endpoint.php', ...flags],
            {encoding: 'utf8', input: '', timeout: 3000});
        assert.equal(result.status, 1);
        assert.match(result.stderr, /--token-file|authentication/);
        assert.equal(result.stdout, '');
        assert.ok(!result.stderr.includes(secret));
    }
});

test('manual token file rejects unsafe permissions, non-files, empty files and multiline values', () => {
    withToken(filename => {
        for (const [content, mode] of [[secret, 0o644], ['', 0o600], [secret + '\nother', 0o600]]) {
            fs.writeFileSync(filename, content);
            fs.chmodSync(filename, mode);
            const result = spawnSync(process.execPath, [script, 'http://127.0.0.1:1/endpoint.php', '--token-file', filename],
                {encoding: 'utf8', input: '', timeout: 3000});
            assert.equal(result.status, 1);
            assert.match(result.stderr, /manual token file/);
            assert.ok(!result.stderr.includes(secret));
        }
        const directory = spawnSync(process.execPath,
            [script, 'http://127.0.0.1:1/endpoint.php', '--token-file', path.dirname(filename)],
            {encoding: 'utf8', input: '', timeout: 3000});
        assert.equal(directory.status, 1);
        assert.match(directory.stderr, /manual token file/);
    });
});

test('generated manual configuration never interpolates the newly created bearer token', () => {
    const php = fs.readFileSync(new URL('../my_agent.php', import.meta.url), 'utf8');
    const configurations = php.slice(php.indexOf('// Prepare client configurations.'), php.indexOf('$oauthmetadata ='));
    assert.ok(!configurations.includes('$sampletoken'));
    assert.ok(!configurations.includes('$newtoken'));
    assert.match(configurations, /--token-file/);
    assert.ok(!php.includes("$sampletoken = $newtoken"));
    const executable = process.env.PHP_BINARY || 'php';
    const probe = spawnSync(executable, ['-r', `
        function get_string($key, $component) { return '/private/' . $key; }
        $bridgepathsample = '/bridge.mjs'; $endpointurl = 'https://moodle.example/endpoint.php';
        $langparam = '--lang=en'; $newtoken = '${secret}';
        ${configurations}
        echo json_encode([$claudedesktopconfig, $codexcursorconfig]);
    `], {encoding: 'utf8'});
    if (probe.error?.code === 'ENOENT') return; // PHP execution is supplemental to the Node-only regression gate.
    assert.equal(probe.status, 0, probe.stderr);
    const [bridgeJson, httpJson] = JSON.parse(probe.stdout);
    const config = JSON.parse(bridgeJson).mcpServers.moodle;
    assert.deepEqual(config.args, ['/bridge.mjs', 'https://moodle.example/endpoint.php',
        '--token-file', '/private/bridge_token_path_placeholder']);
    assert.ok(!(bridgeJson + httpJson).includes(secret));
});

test('manual protected token file forwards stdin without placing the token in argv or output', async () => {
    const home = fs.mkdtempSync(path.join(os.tmpdir(), 'manual-forward-'));
    try {
        const filename = path.join(home, 'bearer token.txt');
        fs.writeFileSync(filename, secret + '\n', {mode: 0o600});
        const message = {jsonrpc: '2.0', id: 6, method: 'ping'};
        await withEndpoint(({request, response}) => {
            assert.equal(request.headers.authorization, `Bearer ${secret}`);
            response.end(JSON.stringify({jsonrpc: '2.0', id: 6, result: {ok: true}}));
        }, async endpoint => {
            const child = spawn(process.execPath, [script, endpoint, '--token-file', filename],
                {stdio: ['pipe', 'pipe', 'pipe'], timeout: 5000});
            assert.ok(!child.spawnargs.join(' ').includes(secret));
            let stdout = '', stderr = '';
            child.stdout.on('data', chunk => { stdout += chunk; });
            child.stderr.on('data', chunk => { stderr += chunk; });
            const closed = new Promise((resolve, reject) => {
                child.once('error', reject);
                child.once('close', (code, signal) => resolve({code, signal}));
            });
            child.stdin.end(JSON.stringify(message) + '\n');
            const result = await closed;
            assert.equal(result.signal, null);
            assert.equal(result.code, 0, stderr);
            assert.deepEqual(parseReplies(stdout, [message])[0].result, {ok: true});
            assert.ok(!(stdout + stderr).includes(secret));
        });
    } finally {
        fs.rmSync(home, {recursive: true, force: true});
    }
});
