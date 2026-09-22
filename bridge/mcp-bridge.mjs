/**
 * Teacher MCP Agent - Claude Desktop & CLI Stdio Bridge.
 * Forwards JSON-RPC stdio calls from Claude Desktop / CLI directly to Moodle endpoint.
 *
 * Usage:
 *   node mcp-bridge.mjs <MOODLE_ENDPOINT_URL> <BEARER_TOKEN>
 * Or with seamless browser OAuth:
 *   node mcp-bridge.mjs <MOODLE_ENDPOINT_URL> --oauth [--lang=en|nl]
 *
 * @copyright 2026 Sebsoft / Jeremy van Lier
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import readline from 'readline';
import http from 'http';
import crypto from 'crypto';
import fs from 'fs';
import path from 'path';
import os from 'os';
import { spawn } from 'child_process';

// Default English fallback strings (managed and localized via standard Moodle AMOS).
let I18N = /* __MOODLE_I18N_INJECT_START__ */ {
    missing_endpoint: 'Error: Missing endpoint argument.',
    usage: 'Usage: node mcp-bridge.mjs <endpoint_url> <bearer_token|--oauth> [--lang=en|nl]',
    auth_failed: 'Authentication failed',
    invalid_code: 'Invalid authorization code or state mismatch.',
    auth_success: 'Authentication successful!',
    connected_desc: 'Claude Desktop has been successfully connected to your Moodle Teacher MCP account.',
    close_tab: 'You can now safely close this browser tab and return to Claude.',
    token_fetch_error: 'Error retrieving token',
    browser_opening: '[Moodle MCP OAuth] Opening browser for authorization:',
    auth_error: '[Moodle MCP OAuth] Authorization failed:',
    comm_error: 'Bridge communication error:'
} /* __MOODLE_I18N_INJECT_END__ */;

const args = process.argv.slice(2);
const endpoint = args.find(arg => !arg.startsWith('--'));

const langFlag = args.find(arg => arg.startsWith('--lang='));
const specifiedLang = langFlag ? langFlag.split('=')[1].toLowerCase() : null;

const isOAuth = args.includes('--oauth');
const nonFlagArgs = args.filter(arg => !arg.startsWith('--'));
const tokenArg = nonFlagArgs[1]; // First non-flag is endpoint, second is token.

export function getTokenFilePath() {
    if (process.platform === 'win32') {
        const base = process.env.LOCALAPPDATA || path.join(os.homedir(), 'AppData', 'Local');
        return path.join(base, 'MoodleTeacherMCP', 'tokens.json');
    }
    if (process.platform === 'darwin') {
        return path.join(os.homedir(), 'Library', 'Application Support', 'MoodleTeacherMCP', 'tokens.json');
    }
    const base = process.env.XDG_CONFIG_HOME || path.join(os.homedir(), '.config');
    return path.join(base, 'moodle-teachermcp', 'tokens.json');
}

export function saveTokenStore(tokenFilePath, store) {
    fs.mkdirSync(path.dirname(tokenFilePath), {recursive: true, mode: 0o700});
    fs.writeFileSync(tokenFilePath, JSON.stringify(store, null, 2), {encoding: 'utf8', mode: 0o600});
    if (process.platform !== 'win32') {
        fs.chmodSync(tokenFilePath, 0o600);
    }
}

export function validateEndpoint(endpointUrl) {
    const url = new URL(endpointUrl);
    if (!['http:', 'https:'].includes(url.protocol)) {
        throw new Error('Endpoint URL must use HTTP or HTTPS.');
    }
    return url;
}

if (!endpoint && import.meta.url === `file://${process.argv[1]}`) {
    console.error(I18N.missing_endpoint);
    console.error(I18N.usage);
    process.exit(1);
}

function getBaseUrl(endpointUrl) {
    const url = validateEndpoint(endpointUrl);
    // e.g. https://domain.nl/admin/tool/teachermcp/endpoint.php -> https://domain.nl/admin/tool/teachermcp
    const pathname = url.pathname.replace(/\/endpoint\.php$/, '');
    return `${url.origin}${pathname}`;
}

/**
 * Fetch latest AMOS-translated strings from Moodle server.
 */
async function loadStringsFromMoodle(baseUrl, lang) {
    try {
        const queryLang = lang ? `?lang=${encodeURIComponent(lang)}` : '';
        const url = `${baseUrl}/oauth/strings.php${queryLang}`;
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 2500);
        const res = await fetch(url, { signal: controller.signal });
        clearTimeout(timeoutId);

        if (res.ok) {
            const remoteStrings = await res.json();
            if (remoteStrings && typeof remoteStrings === 'object') {
                I18N = { ...I18N, ...remoteStrings };
            }
        }
    } catch (e) {
        // Fallback silently to pre-bundled / injected strings.
    }
}

function openBrowser(url) {
    const platform = process.platform;
    if (platform === 'win32') {
        spawn('cmd.exe', ['/c', 'start', '""', url], { windowsVerbatimArguments: true, stdio: 'ignore' });
    } else if (platform === 'darwin') {
        spawn('open', [url], { stdio: 'ignore' });
    } else {
        spawn('xdg-open', [url], { stdio: 'ignore' });
    }
}

function base64Url(buffer) {
    return buffer.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

async function performOAuthFlow(endpointUrl) {
    const tokenFilePath = getTokenFilePath();
    const endpointHash = crypto.createHash('sha256').update(endpointUrl).digest('hex').substring(0, 12);

    // Check the private per-platform token cache.
    if (fs.existsSync(tokenFilePath)) {
        try {
            const cached = JSON.parse(fs.readFileSync(tokenFilePath, 'utf8'));
            if (cached[endpointHash] && cached[endpointHash].access_token) {
                return cached[endpointHash].access_token;
            }
        } catch (e) {
            // Re-authenticate on an invalid cache.
        }
    }

    const baseUrl = getBaseUrl(endpointUrl);
    const authorizeUrl = `${baseUrl}/oauth/authorize.php`;
    const tokenUrl = `${baseUrl}/oauth/token.php`;

    // Generate PKCE code verifier and challenge.
    const codeVerifier = base64Url(crypto.randomBytes(32));
    const codeChallenge = base64Url(crypto.createHash('sha256').update(codeVerifier).digest());
    const state = base64Url(crypto.randomBytes(16));

    return new Promise((resolve, reject) => {
        // Create temporary callback server on localhost with random port.
        const server = http.createServer(async (req, res) => {
            const reqUrl = new URL(req.url, `http://localhost:${server.address().port}`);
            if (reqUrl.pathname !== '/callback') {
                res.writeHead(404);
                res.end();
                return;
            }

            const code = reqUrl.searchParams.get('code');
            const retState = reqUrl.searchParams.get('state');
            const error = reqUrl.searchParams.get('error');

            if (error || !code || retState !== state) {
                res.writeHead(400, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end(`<h3>${I18N.auth_failed}</h3><p>${error || I18N.invalid_code}</p>`);
                server.close();
                reject(new Error(error || 'State mismatch or missing code.'));
                return;
            }

            try {
                // Exchange code for token.
                const redirectUri = `http://localhost:${server.address().port}/callback`;
                const tokenResp = await fetch(tokenUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        grant_type: 'authorization_code',
                        code: code,
                        redirect_uri: redirectUri,
                        client_id: 'claude-desktop',
                        code_verifier: codeVerifier
                    }).toString()
                });

                if (!tokenResp.ok) {
                    const err = await tokenResp.text();
                    throw new Error(`Token exchange error: ${err}`);
                }

                const tokenData = await tokenResp.json();
                const accessToken = tokenData.access_token;

                // Save to local cache file.
                let store = {};
                if (fs.existsSync(tokenFilePath)) {
                    try { store = JSON.parse(fs.readFileSync(tokenFilePath, 'utf8')); } catch (e) {}
                }
                store[endpointHash] = {
                    access_token: accessToken,
                    endpoint: endpointUrl,
                    time: Date.now()
                };
                saveTokenStore(tokenFilePath, store);

                res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end(`
                    <div style="font-family: system-ui, sans-serif; max-width: 500px; margin: 50px auto; padding: 24px; border: 1px solid #ddd; border-radius: 8px; text-align: center;">
                        <h2 style="color: #28a745; margin-bottom: 8px;">✓ ${I18N.auth_success}</h2>
                        <p style="color: #555;">${I18N.connected_desc}</p>
                        <p style="font-size: 0.9em; color: #888;">${I18N.close_tab}</p>
                    </div>
                `);

                server.close();
                resolve(accessToken);
            } catch (err) {
                res.writeHead(500, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end(`<h3>${I18N.token_fetch_error}</h3><p>${err.message}</p>`);
                server.close();
                reject(err);
            }
        });

        server.listen(0, '127.0.0.1', () => {
            const port = server.address().port;
            const redirectUri = `http://localhost:${port}/callback`;
            const authUrl = new URL(authorizeUrl);
            authUrl.searchParams.set('client_id', 'claude-desktop');
            authUrl.searchParams.set('redirect_uri', redirectUri);
            authUrl.searchParams.set('response_type', 'code');
            authUrl.searchParams.set('state', state);
            authUrl.searchParams.set('code_challenge', codeChallenge);
            authUrl.searchParams.set('code_challenge_method', 'S256');

            console.error(`${I18N.browser_opening} ${authUrl.toString()}`);
            openBrowser(authUrl.toString());
        });
    });
}

async function startBridge() {
    if (endpoint) {
        await loadStringsFromMoodle(getBaseUrl(endpoint), specifiedLang);
    }

    let activeToken = tokenArg;

    if (!activeToken || isOAuth) {
        try {
            activeToken = await performOAuthFlow(endpoint);
        } catch (e) {
            console.error(I18N.auth_error, e.message);
            process.exit(1);
        }
    }

    const rl = readline.createInterface({
        input: process.stdin,
        output: process.stdout,
        terminal: false
    });

    rl.on('line', async (line) => {
        const trimmed = line.trim();
        if (!trimmed) return;

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${activeToken}`
                },
                body: trimmed
            });

            if (!response.ok) {
                const errText = await response.text();
                console.error(`HTTP error ${response.status}: ${errText}`);
                return;
            }

            const json = await response.text();
            process.stdout.write(json + '\n');
        } catch (err) {
            console.error(I18N.comm_error, err.message);
        }
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    startBridge().catch((error) => {
        console.error(I18N.comm_error, error.message);
        process.exit(1);
    });
}
