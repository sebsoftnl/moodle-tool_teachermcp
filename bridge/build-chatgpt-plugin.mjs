import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const usage = 'Usage: node bridge/build-chatgpt-plugin.mjs --endpoint <Moodle URL> --output <new directory>';
const pluginName = 'moodle-teacher-mcp';

function parseArguments(args) {
    if (args.length !== 4) throw new Error(usage);
    const options = new Map();
    for (let index = 0; index < args.length; index += 2) {
        const flag = args[index];
        const value = args[index + 1];
        if (!['--endpoint', '--output'].includes(flag) || options.has(flag) || !value || value.startsWith('--')) {
            throw new Error(usage);
        }
        options.set(flag, value);
    }
    const endpointError = 'Endpoint must be HTTPS (or loopback HTTP), without credentials, query parameters or a fragment.';
    let endpoint;
    try {
        endpoint = new URL(options.get('--endpoint'));
    } catch {
        throw new Error(endpointError);
    }
    const loopback = ['localhost', '127.0.0.1', '[::1]'].includes(endpoint.hostname);
    if ((endpoint.protocol !== 'https:' && !(endpoint.protocol === 'http:' && loopback))
            || endpoint.username || endpoint.password || endpoint.search || endpoint.hash) {
        throw new Error(endpointError);
    }
    return {endpoint: endpoint.href, output: path.resolve(options.get('--output'))};
}

function buildPackage({endpoint, output}) {
    try {
        fs.mkdirSync(output, {mode: 0o700});
    } catch (error) {
        if (error.code === 'EEXIST') throw new Error('Output directory already exists; choose a new directory.');
        throw error;
    }
    try {
        const plugin = path.join(output, 'plugins', pluginName);
        const marketplace = path.join(output, '.agents/plugins/marketplace.json');
        fs.mkdirSync(path.join(plugin, '.codex-plugin'), {recursive: true});
        fs.mkdirSync(path.dirname(marketplace), {recursive: true});
        const writeJson = (filename, value) => fs.writeFileSync(filename, JSON.stringify(value, null, 2) + '\n',
            {encoding: 'utf8', flag: 'wx', mode: 0o600});
        writeJson(path.join(plugin, '.codex-plugin/plugin.json'), {
            name: pluginName,
            version: '0.1.0',
            description: 'Connect local ChatGPT desktop conversations to teacher-authorized Moodle tools through OAuth.',
            license: 'GPL-3.0-or-later',
            mcpServers: './.mcp.json',
            requires_local_executor: true,
        });
        writeJson(path.join(plugin, '.mcp.json'), {
            mcpServers: {moodle: {
                command: process.execPath,
                args: ['./mcp-bridge.mjs', endpoint, '--oauth'],
                cwd: './',
                startup_timeout_sec: 240,
            }},
        });
        fs.copyFileSync(fileURLToPath(new URL('./mcp-bridge.mjs', import.meta.url)),
            path.join(plugin, 'mcp-bridge.mjs'), fs.constants.COPYFILE_EXCL);
        writeJson(marketplace, {
            name: 'moodle-teacher-mcp-local',
            interface: {displayName: 'Moodle Teacher MCP (Local)'},
            plugins: [{
                name: pluginName,
                source: {source: 'local', path: './plugins/moodle-teacher-mcp'},
                policy: {installation: 'AVAILABLE', authentication: 'ON_INSTALL'},
                category: 'Productivity',
            }],
        });
        return {output, plugin, marketplace};
    } catch (error) {
        fs.rmSync(output, {recursive: true, force: true});
        throw error;
    }
}

try {
    const args = process.argv.slice(2);
    if (args.length === 1 && args[0] === '--help') {
        console.log(usage);
    } else {
        console.log(JSON.stringify(buildPackage(parseArguments(args)), null, 2));
    }
} catch (error) {
    console.error(error.message);
    process.exitCode = 1;
}
