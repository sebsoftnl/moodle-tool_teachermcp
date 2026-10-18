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
import { pathToFileURL } from 'node:url';

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
const isMainModule = process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href;
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
    const directory = path.dirname(tokenFilePath);
    fs.mkdirSync(directory, {recursive: true, mode: 0o700});
    const temporary = fs.mkdtempSync(path.join(directory, '.tokens-'));
    try {
        const file = path.join(temporary, 'tokens.json');
        fs.writeFileSync(file, JSON.stringify(store, null, 2), {encoding: 'utf8', mode: 0o600});
        fs.renameSync(file, tokenFilePath);
    } finally {
        fs.rmSync(temporary, {recursive: true, force: true});
    }
}

export function validateEndpoint(endpointUrl) {
    const url = new URL(endpointUrl);
    if (!['http:', 'https:'].includes(url.protocol)) {
        throw new Error('Endpoint URL must use HTTP or HTTPS.');
    }
    return url;
}

if (!endpoint && isMainModule) {
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
export async function loadStringsFromMoodle(baseUrl, lang, options = {}) {
    const controller = new AbortController();
    const signal = options.signal ? AbortSignal.any([controller.signal, options.signal]) : controller.signal;
    let onAbort;
    const aborted = new Promise((resolve, reject) => {
        onAbort = () => reject(new Error('Translation request cancelled.'));
        if (signal.aborted) onAbort();
        else signal.addEventListener('abort', onAbort, {once: true});
    });
    const timer = setTimeout(() => controller.abort(), options.translationTimeoutMs ?? 2500);
    try {
        const queryLang = lang ? `?lang=${encodeURIComponent(lang)}` : '';
        const url = `${baseUrl}/oauth/strings.php${queryLang}`;
        const fetchImpl = options.fetchImpl ?? fetch;
        const body = (async () => {
            const response = await fetchImpl(url, {signal});
            if (!response.ok) {
                await response.body?.cancel();
                return null;
            }
            return response.json();
        })();
        const remoteStrings = await Promise.race([body, aborted]);
        if (!signal.aborted && remoteStrings && typeof remoteStrings === 'object') {
            I18N = {...I18N, ...remoteStrings};
        }
    } catch {
        return;
    } finally {
        clearTimeout(timer);
        signal.removeEventListener('abort', onAbort);
    }
}

export function getBrowserLauncher(url, platform = process.platform) {
    const target = validateEndpoint(url);
    if (target.username || target.password) {
        throw new Error('Endpoint URL must not contain credentials.');
    }
    const safeUrl = target.href;
    if (platform === 'win32') {
        return {
            command: 'explorer.exe',
            args: [safeUrl],
        };
    }
    if (platform === 'darwin') {
        return {command: 'open', args: [safeUrl]};
    }
    return {command: 'xdg-open', args: [safeUrl]};
}

function openBrowser(url, spawnImpl = spawn) {
    const launcher = getBrowserLauncher(url);
    return spawnImpl(launcher.command, launcher.args, {shell: false, stdio: 'ignore'});
}

function base64Url(buffer) {
    return buffer.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

class BridgeStartupError extends Error {}

function readTokenStore(filename, allowMalformed = false) {
    let text;
    try {
        text = fs.readFileSync(filename, 'utf8');
    } catch (error) {
        if (error.code === 'ENOENT') return {};
        throw new BridgeStartupError('Could not read the OAuth credential cache.');
    }
    let store;
    try {
        store = JSON.parse(text);
    } catch {
        if (allowMalformed) return {};
        throw new BridgeStartupError('The OAuth credential cache is malformed.');
    }
    if (isObject(store)) return store;
    if (allowMalformed) return {};
    throw new BridgeStartupError('The OAuth credential cache is malformed.');
}

function invalidateCachedToken(endpointUrl, rejectedToken) {
    const filename = getTokenFilePath();
    const store = readTokenStore(filename);
    const key = crypto.createHash('sha256').update(endpointUrl).digest('hex').substring(0, 12);
    if (store[key]?.access_token !== rejectedToken) return;
    delete store[key];
    saveTokenStore(filename, store);
}

export async function performOAuthFlow(endpointUrl, options = {}) {
    const tokenFilePath = getTokenFilePath();
    const endpointHash = crypto.createHash('sha256').update(endpointUrl).digest('hex').substring(0, 12);

    const cached = readTokenStore(tokenFilePath, true);
    const entry = cached[endpointHash];
    if (isObject(entry) && typeof entry.access_token === 'string' && entry.access_token.length > 0) return entry.access_token;

    const baseUrl = getBaseUrl(endpointUrl);
    const authorizeUrl = `${baseUrl}/oauth/authorize.php`;
    const tokenUrl = `${baseUrl}/oauth/token.php`;

    const codeVerifier = base64Url(crypto.randomBytes(32));
    const codeChallenge = base64Url(crypto.createHash('sha256').update(codeVerifier).digest());
    const state = base64Url(crypto.randomBytes(16));

    return new Promise((resolve, reject) => {
        const controller = new AbortController();
        const lifetime = options.oauthTimeoutMs ?? 180000;
        const deadline = Date.now() + lifetime;
        let server;
        let launcher;
        let launcherClosed = Promise.resolve();
        let activeResponse;
        let redirectUri;
        let settled = false;
        let exchanging = false;
        const onCancel = () => { void finish(new BridgeStartupError('OAuth authorization cancelled.'), null, 503); };
        const onServerError = () => { void finish(new BridgeStartupError('Could not start the OAuth callback listener.')); };
        const finish = (error, accessToken, status = 500) => {
            if (settled) return Promise.resolve();
            settled = true;
            clearTimeout(timer);
            options.signal?.removeEventListener('abort', onCancel);
            controller.abort();
            const cleanup = async () => {
                let responseFinished = Promise.resolve();
                if (activeResponse && !activeResponse.destroyed && !activeResponse.writableEnded) {
                    responseFinished = new Promise(done => {
                        activeResponse.once('finish', done);
                        activeResponse.once('close', done);
                    });
                    activeResponse.writeHead(status, {'Content-Type': 'text/html; charset=utf-8', 'Connection': 'close'});
                    activeResponse.end(error ? `<h3>${I18N.auth_failed}</h3><p>${error.message}</p>` : `
                    <div style="font-family: system-ui, sans-serif; max-width: 500px; margin: 50px auto; padding: 24px; border: 1px solid #ddd; border-radius: 8px; text-align: center;">
                        <h2 style="color: #28a745; margin-bottom: 8px;">✓ ${I18N.auth_success}</h2>
                        <p style="color: #555;">${I18N.connected_desc}</p>
                        <p style="font-size: 0.9em; color: #888;">${I18N.close_tab}</p>
                    </div>
                    `);
                }
                const serverClosed = server?.listening
                    ? new Promise((done, failed) => server.close(problem => problem ? failed(problem) : done()))
                    : Promise.resolve();
                if (launcher?.pid && launcher.exitCode === null && launcher.signalCode === null) launcher.kill('SIGKILL');
                await responseFinished;
                server?.closeAllConnections();
                await Promise.all([serverClosed, launcherClosed]);
                server?.removeListener('error', onServerError);
            };
            return cleanup().then(() => error ? reject(error) : resolve(accessToken),
                () => reject(new BridgeStartupError('OAuth resource cleanup failed.')));
        };
        const expire = () => finish(new BridgeStartupError('OAuth authorization timed out.'), null, 504);
        const timer = setTimeout(() => { void expire(); }, lifetime);
        if (options.signal?.aborted) { onCancel(); return; }
        options.signal?.addEventListener('abort', onCancel, {once: true});

        const handleCallback = async (req, res) => {
            const reqUrl = new URL(req.url, 'http://localhost');
            if (reqUrl.pathname !== '/callback') {
                res.writeHead(404, {'Connection': 'close'}).end();
                return;
            }
            if (settled || exchanging) {
                res.writeHead(409, {'Connection': 'close'}).end('Authorization is already being processed.');
                return;
            }
            activeResponse = res;
            if (Date.now() >= deadline) { await expire(); return; }
            const code = reqUrl.searchParams.get('code');
            const retState = reqUrl.searchParams.get('state');
            const error = reqUrl.searchParams.get('error');

            if (error || !code || retState !== state) {
                await finish(new BridgeStartupError('OAuth authorization was denied or the callback was invalid.'), null, 400);
                return;
            }

            exchanging = true;
            try {
                const fetchImpl = options.fetchImpl ?? fetch;
                const tokenResp = await fetchImpl(tokenUrl, {
                    method: 'POST', redirect: 'manual', signal: controller.signal,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        grant_type: 'authorization_code',
                        code: code,
                        redirect_uri: redirectUri,
                        client_id: 'claude-desktop',
                        code_verifier: codeVerifier
                    }).toString()
                });
                if (settled) return;
                if (!tokenResp.ok) {
                    await tokenResp.body?.cancel();
                    throw new BridgeStartupError('OAuth token exchange failed.');
                }

                const tokenData = await tokenResp.json();
                if (settled) return;
                if (Date.now() >= deadline) { await expire(); return; }
                const accessToken = tokenData?.access_token;
                if (typeof accessToken !== 'string' || accessToken.length === 0) {
                    throw new BridgeStartupError('OAuth token response did not contain a valid credential.');
                }
                const store = readTokenStore(tokenFilePath, true);
                store[endpointHash] = {
                    access_token: accessToken,
                    endpoint: endpointUrl,
                    time: Date.now()
                };
                if (Date.now() >= deadline) { await expire(); return; }
                try {
                    saveTokenStore(tokenFilePath, store);
                } catch {
                    throw new BridgeStartupError('Could not save the OAuth credential cache.');
                }
                await finish(null, accessToken, 200);
            } catch (error) {
                if (!settled) await finish(error instanceof BridgeStartupError
                    ? error : new BridgeStartupError('OAuth token exchange failed.'));
            }
        };

        try {
            const createServer = options.createServer ?? http.createServer;
            server = createServer((req, res) => {
                handleCallback(req, res).catch(() => { void finish(new BridgeStartupError('OAuth callback failed.')); });
            });
            server.on('error', onServerError);
            server.listen(0, '127.0.0.1', () => {
                if (settled) return;
                redirectUri = `http://localhost:${server.address().port}/callback`;
                const authUrl = new URL(authorizeUrl);
                authUrl.searchParams.set('client_id', 'claude-desktop');
                authUrl.searchParams.set('redirect_uri', redirectUri);
                authUrl.searchParams.set('response_type', 'code');
                authUrl.searchParams.set('state', state);
                authUrl.searchParams.set('code_challenge', codeChallenge);
                authUrl.searchParams.set('code_challenge_method', 'S256');
                console.error(I18N.browser_opening);
                try {
                    launcher = openBrowser(authUrl.toString(), options.spawnImpl ?? spawn);
                    launcherClosed = new Promise(done => launcher.once('close', done));
                    const launchFailed = () => { void finish(new BridgeStartupError('Could not open the authorization browser.')); };
                    launcher.once('error', launchFailed);
                    launcher.once('exit', (code, signal) => { if (code !== 0 || signal) launchFailed(); });
                } catch {
                    void finish(new BridgeStartupError('Could not open the authorization browser.'));
                }
            });
        } catch {
            onServerError();
        }
    });
}

function rpcError(id, code, message, data) {
    const error = {code, message};
    if (data !== undefined) error.data = data;
    return {jsonrpc: '2.0', id, error};
}

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isRequestId(value) {
    return value === null || typeof value === 'string' || (typeof value === 'number' && Number.isFinite(value));
}

function isReply(value) {
    return isObject(value) && value.jsonrpc === '2.0' && Object.hasOwn(value, 'id') && isRequestId(value.id)
        && Object.hasOwn(value, 'result') !== Object.hasOwn(value, 'error')
        && (!Object.hasOwn(value, 'error') || (isObject(value.error)
            && Number.isInteger(value.error.code) && typeof value.error.message === 'string'));
}

function repliesMatch(value, ids, batch) {
    if (Array.isArray(value) !== batch) return false;
    const replies = batch ? value : [value];
    if (replies.length !== ids.length) return false;
    const remaining = new Map();
    for (const id of ids) remaining.set(id, (remaining.get(id) || 0) + 1);
    for (const reply of replies) {
        if (!isReply(reply) || !remaining.get(reply.id)) return false;
        remaining.set(reply.id, remaining.get(reply.id) - 1);
    }
    return true;
}

function httpFailureReason(status, text) {
    let reply;
    try {
        reply = JSON.parse(text);
    } catch {
        return 'http_error';
    }
    if (!isReply(reply) || reply.id !== null || !Object.hasOwn(reply, 'error')) return 'http_error';
    const contracts = {
        400: [-32600, ['invalid_request']],
        401: [-32000, ['missing_token', 'invalid_token', 'expired_token']],
        403: [-32000, ['forbidden']],
        413: [-32600, ['request_too_large']],
        429: [-32000, ['rate_limited']],
        500: [-32603, ['internal_error']],
    };
    const contract = contracts[status];
    const reason = reply.error.data?.reason;
    return contract && reply.error.code === contract[0] && contract[1].includes(reason) ? reason : 'http_error';
}

export async function forwardRequest(line, options) {
    const trimmed = line.trim();
    if (!trimmed) return null;
    let frame;
    try {
        frame = JSON.parse(trimmed);
    } catch {
        return rpcError(null, -32700, 'Parse error');
    }
    const batch = Array.isArray(frame);
    if ((!batch && !isObject(frame)) || (batch ? frame.length === 0 : Object.keys(frame).length === 0)) {
        return rpcError(null, -32600, 'Invalid Request');
    }

    const forwarded = [];
    const ids = [];
    const localErrors = [];
    for (const message of batch ? frame : [frame]) {
        if (!isObject(message) || (Object.hasOwn(message, 'id') && !isRequestId(message.id))) {
            localErrors.push(rpcError(null, -32600, 'Invalid Request'));
        } else {
            forwarded.push(message);
            if (Object.hasOwn(message, 'id')) ids.push(message.id);
        }
    }
    const pack = replies => replies.length === 0 ? null : batch ? replies : replies[0];
    if (forwarded.length === 0) return pack(localErrors);
    const failed = (reason, httpStatus) => {
        options.onTransportFailure?.({reason, httpStatus});
        const guidance = httpStatus === 401 && reason === 'http_error'
            ? ' Check the endpoint and configured credential; provide a replacement bearer token explicitly.' : '';
        const message = `Bridge request failed (${reason}).${guidance} The operation may have completed and was not retried.`;
        if (ids.length === 0) console.error(I18N.comm_error, message);
        return pack([...localErrors, ...ids.map(id => rpcError(id, -32000, message,
            {reason, httpStatus, outcomeUncertain: true}))]);
    };

    const controller = new AbortController();
    const signal = options.signal ? AbortSignal.any([controller.signal, options.signal]) : controller.signal;
    const timer = setTimeout(() => controller.abort(), options.requestTimeoutMs ?? 60000);
    let httpStatus = null;
    try {
        const fetchImpl = options.fetchImpl ?? fetch;
        const response = await fetchImpl(options.endpoint, {
            method: 'POST', redirect: 'manual', signal,
            headers: {'Content-Type': 'application/json', 'Authorization': `Bearer ${options.token}`},
            body: localErrors.length ? JSON.stringify(batch ? forwarded : forwarded[0]) : trimmed,
        });
        httpStatus = response.status;
        const text = await response.text();
        if (!response.ok) return failed(httpFailureReason(httpStatus, text), httpStatus);
        if (ids.length === 0) {
            return text.trim() ? failed('invalid_response', httpStatus) : pack(localErrors);
        }
        let reply;
        try {
            reply = JSON.parse(text);
        } catch {
            return failed('invalid_response', httpStatus);
        }
        if (batch && isReply(reply) && reply.id === null && reply.error?.code === -32600) {
            return pack([...localErrors, ...ids.map(id => ({...reply, id}))]);
        }
        if (!repliesMatch(reply, ids, batch)) return failed('invalid_response', httpStatus);
        return pack([...localErrors, ...(batch ? reply : [reply])]);
    } catch {
        return failed(controller.signal.aborted ? 'request_timeout' : 'network_error', httpStatus);
    } finally {
        clearTimeout(timer);
    }
}

export async function startBridge(options = {}) {
    const endpointUrl = options.endpoint ?? endpoint;
    const oauth = options.oauth ?? isOAuth;
    const language = options.lang ?? specifiedLang;
    const controller = new AbortController();
    const signal = options.signal ? AbortSignal.any([controller.signal, options.signal]) : controller.signal;
    let rl;
    let stopped = false;
    let recovering = false;
    const stop = () => {
        if (stopped) return;
        stopped = true;
        process.exitCode = 1;
        controller.abort();
        rl?.close();
    };
    process.once('SIGTERM', stop);
    process.once('SIGINT', stop);
    process.stdin.on('error', stop);
    process.stdout.on('error', stop);
    process.stderr.on('error', stop);
    signal.addEventListener('abort', stop, {once: true});
    try {
        let baseUrl;
        try {
            baseUrl = getBaseUrl(endpointUrl);
        } catch {
            throw new BridgeStartupError('Invalid URL: endpoint must use HTTP or HTTPS.');
        }
        let activeToken = options.token ?? tokenArg;
        if (!oauth && !activeToken) throw new BridgeStartupError('Provide a bearer token or explicitly select --oauth.');
        await loadStringsFromMoodle(baseUrl, language, {...options, signal});
        if (signal.aborted) throw new BridgeStartupError('Bridge startup cancelled.');
        if (oauth) activeToken = await performOAuthFlow(endpointUrl, {...options, signal});
        if (signal.aborted) throw new BridgeStartupError('Bridge startup cancelled.');

        rl = readline.createInterface({input: process.stdin, output: process.stdout, terminal: false});
        rl.on('error', stop);
        const pending = new Set();
        const inputClosed = new Promise(resolve => rl.once('close', resolve));
        rl.on('line', line => {
            const trimmed = line.trim();
            if (stopped || recovering || !trimmed) return;
            const operation = (async () => {
                try {
                    let transportFailure;
                    const json = await forwardRequest(trimmed, {...options, endpoint: endpointUrl, token: activeToken, signal,
                        onTransportFailure: failure => { transportFailure = failure; }});
                    const recoveryOwner = oauth && !stopped && !recovering && transportFailure?.httpStatus === 401
                        && ['invalid_token', 'expired_token'].includes(transportFailure.reason);
                    if (recoveryOwner) recovering = true;
                    if (!stopped && (!recovering || recoveryOwner) && json !== null) {
                        await new Promise((resolve, reject) => process.stdout.write(JSON.stringify(json) + '\n',
                            error => error ? reject(error) : resolve()));
                    }
                    if (recoveryOwner && !stopped) {
                        try {
                            invalidateCachedToken(endpointUrl, activeToken);
                        } catch {
                            console.error(I18N.comm_error, 'The rejected OAuth cache could not be updated; correct it manually.');
                        } finally {
                            stop();
                        }
                    }
                } catch {
                    stop();
                }
            })();
            pending.add(operation);
            operation.then(() => pending.delete(operation));
        });
        await inputClosed;
        await Promise.all(pending);
    } catch (error) {
        process.exitCode = 1;
        console.error(I18N.comm_error, error instanceof BridgeStartupError ? error.message : 'Bridge startup failed.');
    } finally {
        process.removeListener('SIGTERM', stop);
        process.removeListener('SIGINT', stop);
        process.stdin.removeListener('error', stop);
        process.stdout.removeListener('error', stop);
        process.stderr.removeListener('error', stop);
        signal.removeEventListener('abort', stop);
        controller.abort();
        rl?.removeListener('error', stop);
        rl?.close();
    }
}

if (isMainModule) {
    startBridge().catch(() => {
        console.error(I18N.comm_error, 'Bridge startup failed.');
        process.exit(1);
    });
}
