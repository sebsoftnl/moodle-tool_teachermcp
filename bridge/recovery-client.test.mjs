import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import * as client from './test-client.mjs';

const endpoint = 'http://127.0.0.1:1/endpoint.php';
function ownedHome() {
    assert.equal(typeof client.withBridgeHome, 'function', 'Restart tests need an owned persistent HOME factory');
    return client.withBridgeHome;
}

async function withProgram(source, action) {
    const directory = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'teachermcp-restart-probe-')));
    try {
        const script = path.join(directory, 'probe.mjs');
        fs.writeFileSync(script, source, {mode: 0o600});
        await action(script);
    } finally {
        fs.rmSync(directory, {recursive: true, force: true});
        assert.equal(fs.existsSync(directory), false);
    }
}

test('owned HOME persists cache and state across sequential starts, then is removed', async () => {
    const withHome = ownedHome();
    let home;
    await withProgram(`
        import fs from 'node:fs'; import path from 'node:path';
        const file = path.join(process.env.HOME, 'starts');
        const count = fs.existsSync(file) ? Number(fs.readFileSync(file, 'utf8')) : 0;
        fs.writeFileSync(file, String(count + 1));
        console.log(JSON.stringify({starts: count + 1})); process.stdin.resume();`, async script => {
        await withHome(async fixture => {
            home = fixture.home;
            const initialCache = {left: {access_token: 'left-fixture'}, right: {access_token: 'right-fixture'}};
            const first = await fixture.run({endpoint, script, messages: [], initialCache});
            assert.equal(first.code, 0);
            assert.equal(fs.existsSync(home), true);
            assert.equal(fs.existsSync(fixture.cachePath), true);
            assert.deepEqual(JSON.parse(first.stdout), {starts: 1});
            const second = await fixture.run({endpoint, script, messages: []});
            assert.equal(second.code, 0);
            assert.deepEqual(JSON.parse(second.stdout), {starts: 2});
            assert.equal(second.cacheEntries, 2);
            assert.equal(second.cacheUnchanged, true);
            for (const result of [first, second]) assert.throws(() => process.kill(result.pid, 0), {code: 'ESRCH'});
        });
    });
    assert.equal(fs.existsSync(home), false);
});

test('owned HOME is removed when its session action fails', async () => {
    const withHome = ownedHome();
    let home;
    await assert.rejects(withHome(async fixture => {
        home = fixture.home;
        throw new Error('owned fixture failure');
    }), /owned fixture failure/);
    assert.equal(fs.existsSync(home), false);
});

test('restart fixture observes malformed cache without concealing child results', async () => {
    const withHome = ownedHome();
    await withProgram('process.stdin.resume();', async script => {
        await withHome(async fixture => {
            fs.mkdirSync(path.dirname(fixture.cachePath), {recursive: true, mode: 0o700});
            fs.writeFileSync(fixture.cachePath, '{"broken":', {mode: 0o600});
            const result = await fixture.run({endpoint, script, messages: []});
            assert.equal(result.code, 0);
            assert.equal(result.cacheValid, false);
            assert.equal(result.cacheEntries, 0);
            assert.equal(result.cacheUnchanged, true);
        });
    });
});

test('restart fixture clears stale authorization capture without removing other HOME state', async () => {
    const withHome = ownedHome();
    await withProgram(`
        import fs from 'node:fs'; import path from 'node:path';
        const marker = path.join(process.env.HOME, 'first-start');
        if (!fs.existsSync(marker)) {
            fs.writeFileSync(marker, '1');
            fs.writeFileSync(process.env.QA_AUTHORIZATION_FILE, process.env.QA_BRIDGE_ENDPOINT);
            console.log(JSON.stringify({first: true}));
        } else console.log(JSON.stringify({stale: fs.existsSync(process.env.QA_AUTHORIZATION_FILE)}));
        process.stdin.resume();`, async script => {
        await withHome(async fixture => {
            const first = await fixture.run({endpoint, script, messages: []});
            assert.equal(first.code, 0);
            assert.equal(fs.existsSync(path.join(fixture.home, 'authorization.url')), true);
            const second = await fixture.run({endpoint, script, messages: []});
            assert.equal(second.code, 0);
            assert.deepEqual(JSON.parse(second.stdout), {stale: false});
        });
    });
});

test('manual-mode fixture installs an owned failing launcher instead of exposing the real browser', async () => {
    await withProgram(`
        import fs from 'node:fs'; import path from 'node:path'; import {spawn} from 'node:child_process';
        const file = path.join(process.env.PATH.split(path.delimiter)[0], process.platform === 'darwin' ? 'open' : 'xdg-open');
        if (!fs.existsSync(file)) console.log(JSON.stringify({present: false}));
        else {
            const child = spawn(file, [process.env.QA_BRIDGE_ENDPOINT], {stdio: 'ignore', shell: false});
            child.once('close', code => console.log(JSON.stringify({present: true, code})));
        }
        process.stdin.resume();`, async script => {
        const result = await client.runBridge({endpoint, script, messages: []});
        assert.equal(result.code, 0);
        assert.deepEqual(JSON.parse(result.stdout), {present: true, code: 1});
        assert.equal(fs.existsSync(result.home), false);
    });
});
