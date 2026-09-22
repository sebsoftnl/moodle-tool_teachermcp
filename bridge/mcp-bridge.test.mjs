import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import { getTokenFilePath, saveTokenStore } from './mcp-bridge.mjs';

test('saveTokenStore creates a private Unix token cache', () => {
    const temporaryHome = fs.mkdtempSync(path.join(os.tmpdir(), 'teachermcp-bridge-'));
    const originalHome = process.env.HOME;

    try {
        process.env.HOME = temporaryHome;
        const tokenFilePath = getTokenFilePath();
        saveTokenStore(tokenFilePath, {
            endpoint: {access_token: 'test-token'},
        });

        if (process.platform !== 'win32') {
            assert.equal(fs.statSync(path.dirname(tokenFilePath)).mode & 0o777, 0o700);
            assert.equal(fs.statSync(tokenFilePath).mode & 0o777, 0o600);
        }
        assert.deepEqual(JSON.parse(fs.readFileSync(tokenFilePath, 'utf8')), {
            endpoint: {access_token: 'test-token'},
        });
    } finally {
        if (originalHome === undefined) {
            delete process.env.HOME;
        } else {
            process.env.HOME = originalHome;
        }
        fs.rmSync(temporaryHome, {recursive: true, force: true});
    }
});
