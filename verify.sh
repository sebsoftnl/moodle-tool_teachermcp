#!/usr/bin/env bash
set -euo pipefail

root=$(cd "$(dirname "$0")" && pwd)
cd "$root"

php_files=()
while IFS= read -r -d '' file; do
    php_files+=("$file")
done < <(find . -name '*.php' -print0)

for file in "${php_files[@]}"; do
    php -l "$file" >/dev/null
done
printf 'PHP syntax: %s files passed\n' "${#php_files[@]}"

python3 - <<'PY'
import re
import xml.etree.ElementTree as ET
from pathlib import Path

root = Path('.')
ET.parse(root / 'db' / 'install.xml')
print('XMLDB install.xml: parsed')

def strings(path):
    return set(re.findall(r"\$string\['([^']+)'\]", path.read_text(encoding='utf-8')))

en = strings(root / 'lang' / 'en' / 'tool_teachermcp.php')
nl = strings(root / 'lang' / 'nl' / 'tool_teachermcp.php')
assert en == nl, f'Language key mismatch: only EN={sorted(en-nl)}, only NL={sorted(nl-en)}'
print(f'Language parity: {len(en)} EN/NL keys matched')

server = (root / 'classes' / 'server.php').read_text(encoding='utf-8')
assert 'use stdClass;' in server
assert 'stdClass $token' in server
base_tool = (root / 'classes' / 'tools' / 'base_tool.php').read_text(encoding='utf-8')
assert "is_enrolled($coursecontext, $USER->id, '', true)" in base_tool
assert "error_not_enrolled" in base_tool
upload = (root / 'classes' / 'tools' / 'upload_course_asset.php').read_text(encoding='utf-8')
assert 'error_remote_image_fetch_disabled' in upload
assert '$curl->get($imageurl)' not in upload
privacy = (root / 'classes' / 'privacy' / 'provider.php').read_text(encoding='utf-8')
assert 'get_mcp_limit' in server
assert 'max_mcp_batch_size' in server
assert 'format_tool_result_for_response' in server
assert 'log_tool_execution' in server
assert "enforce_mcp_request_rate_limit" in (root / 'endpoint.php').read_text(encoding='utf-8')
assert "max_mcp_request_bytes" in (root / 'settings.php').read_text(encoding='utf-8')
assert "max_mcp_response_bytes" in (root / 'settings.php').read_text(encoding='utf-8')
assert "mcp_requests_per_minute" in (root / 'settings.php').read_text(encoding='utf-8')
assert 'tool_teachermcp_assets' in privacy
assert 'get_file_by_id' in privacy

testfiles = sorted((root / 'tests').glob('*_test.php'))
expected_testfiles = {
    'access_test.php', 'assessment_tools_test.php', 'auth_test.php',
    'content_tools_test.php', 'oauth_server_test.php', 'privacy_test.php',
    'provider_discovery_test.php', 'server_test.php', 'tool_registry_test.php',
}
assert {testfile.name for testfile in testfiles} == expected_testfiles, testfiles
testmethods = []
for testfile in testfiles:
    content = testfile.read_text(encoding='utf-8')
    testmethods.extend(re.findall(r'^    public function (test_[A-Za-z0-9_]+)\(', content, re.MULTILINE))
assert testmethods and len(testmethods) == len(set(testmethods)), testmethods
assert not (root / 'tests' / 'mcp_test.php').exists()
assert not any("array_column($tools, 'name')" in testfile.read_text(encoding='utf-8') for testfile in testfiles)
assert 'generate_token_record' in (root / 'tests' / 'auth_test.php').read_text(encoding='utf-8')
assert 'error_remote_image_fetch_disabled' in (root / 'tests' / 'content_tools_test.php').read_text(encoding='utf-8')
assert 'tool_teachermcp_assets' in (root / 'tests' / 'privacy_test.php').read_text(encoding='utf-8')
provider_test = (root / 'tests' / 'provider_discovery_test.php').read_text(encoding='utf-8')
assert 'provider_registry::discover' in provider_test
assert 'get_plugins_with_function' in (root / 'classes' / 'local' / 'provider_registry.php').read_text(encoding='utf-8')
registry_test = (root / 'tests' / 'tool_registry_test.php').read_text(encoding='utf-8')
assert 'requires_an_exact_qualified_token_grant' in registry_test
registry_source = (root / 'classes' / 'local' / 'tool_registry.php').read_text(encoding='utf-8')
assert 'external_tool_adapter' in registry_source
assert 'is_valid_external_schema' in registry_source
server_source = (root / 'classes' / 'server.php').read_text(encoding='utf-8')
assert 'get_grantable_external_tool_names' in server_source
assert "additionalProperties'] ?? true" in server_source
oauth_source = (root / 'classes' / 'oauth_server.php').read_text(encoding='utf-8')
assert 'get_grantable_external_tool_names' in oauth_source
assert '$externalgrants[$item] = true' in oauth_source
manual_token_page = (root / 'my_agent.php').read_text(encoding='utf-8')
assert "optional_param_array('externaltools'" in manual_token_page
print(f'Split test layout: {len(testfiles)} files, {len(testmethods)} unique test methods')
print('Remediation static policy checks: passed')
PY

node --check bridge/mcp-bridge.mjs
node --test bridge/mcp-bridge.test.mjs
printf 'Node bridge syntax and tests: passed\n'

python3 - <<'PY'
import http.server
import json
import os
import subprocess
import tempfile
import threading
from pathlib import Path

class Handler(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        length = int(self.headers['Content-Length'])
        request = json.loads(self.rfile.read(length))
        response = {'jsonrpc': '2.0', 'id': request['id'], 'result': {'ok': True}}
        encoded = json.dumps(response).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)
    def log_message(self, *_):
        pass

server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
try:
    endpoint = f'http://127.0.0.1:{server.server_port}/endpoint.php'
    request = '{"jsonrpc":"2.0","id":1,"method":"ping"}\n'
    result = subprocess.run(
        ['node', 'bridge/mcp-bridge.mjs', endpoint, 'test-token'],
        input=request,
        text=True,
        capture_output=True,
        check=True,
        cwd=Path('.'),
        timeout=15,
    )
    assert json.loads(result.stdout)['result']['ok'] is True
    print('Bridge bearer smoke test: passed')
finally:
    server.shutdown()
PY
