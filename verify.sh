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

testfiles = sorted((root / 'tests').rglob('*_test.php'))
required_testfiles = {
    'access_test.php', 'assessment_tools_test.php', 'auth_test.php',
    'content_tools_test.php', 'oauth_server_test.php', 'privacy_test.php',
    'provider_discovery_test.php', 'server_test.php', 'tool_registry_test.php', 'tools_extended_test.php',
}
discovered_testfiles = {testfile.relative_to(root / 'tests').as_posix() for testfile in testfiles}
missing_testfiles = required_testfiles - discovered_testfiles
assert not missing_testfiles, f'Missing original regression files: {sorted(missing_testfiles)}'
testmethods = []
for testfile in testfiles:
    content = testfile.read_text(encoding='utf-8')
    methods = re.findall(r'^    public function (test_[A-Za-z0-9_]+)\(', content, re.MULTILINE)
    testmethods.extend(f'{testfile.relative_to(root / "tests").as_posix()}::{method}' for method in methods)
assert testmethods and len(testmethods) == len(set(testmethods)), testmethods
print(f'Split test layout: {len(testfiles)} files, {len(testmethods)} unique test methods')
PY

for file in bridge/*.mjs; do
    node --check "$file"
done
node --test bridge/*.test.mjs
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

printf 'Standalone checks passed. Run the Moodle PHPUnit suite for plugin behaviour coverage.\n'
