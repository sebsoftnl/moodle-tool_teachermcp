# Teacher MCP remediation copy

**Historical material, retained below.** This describes an earlier remediation snapshot, not the current repository, release or verification status. Use the [README](README.md) and [testing guide](docs/developer/testing.md) for current instructions. The original observations below have not been rewritten as current results.

This directory is a standalone fixed copy of the reviewed source. It is not a Git repository and has not been deployed.

Changes implemented

- Added the missing `stdClass` import in the MCP server.
- Made module-scoped tools reject suspended course enrolments, matching course-scoped access checks.
- Made OAuth scopes real token permissions. Supported scopes are `teachermcp`, `all`, `read`, `write`, `grade`, `message`, and `delete`. `teachermcp` and `all` grant the full existing teacher-tool set. Restricted scopes are stored on the token and enforced by the existing tool-permission filter.
- Fixed administrator token revocation to pass the actual manager user ID.
- Removed the broken “unlimited” validity selector from the user interface.
- Disabled remote image URL fetching. Uploads now require `file_content_base64`, eliminating server-side fetch/SSRF behavior.
- Added per-platform token-cache paths for the Node bridge. Unix/macOS cache files and directories are forced to mode 0600/0700. Windows uses LocalAppData and its per-user NTFS ACL model.
- Converted invalid bridge-startup URL failures into a controlled error path and exported the cache helpers for Node tests.
- Added privacy tracking for files created by the upload tool, including Page and Book file areas. Privacy exports and deletion now operate only on recorded MCP-created files, rather than every file in those standard Moodle areas.
- Added missing privacy metadata fields.
- Added the missing OAuth-code foreign key upgrade and an asset-tracking table upgrade. Plugin version is now `2026091904` (`v1.4.2-beta`).
- Required a course and normal course capability for H5P library inventory.
- Corrected MCP audit event CRUD values for read, update-like and delete tools.
- Repaired clear stale assertions in the test suite, split the former `mcp_test.php` monolith into seven domain-focused PHPUnit files, and retained regressions for restricted OAuth scopes, suspended module access, remote URL rejection, and the bridge cache mode.
- Added bounded MCP transport controls: configurable request-body, batch, and response-output budgets plus a per-token-and-source-IP request limit. Tool successes and failures now emit data-minimised audit events; events retain no arguments or tool results.
- Added an external-provider API slice. Installed Moodle components can declare versioned providers through `*_teachermcp_get_tool_providers()` in `lib.php`; discovery validates qualified `component.local_id` declarations and fails closed. Valid external tools are adapted into the existing MCP catalogue and transport, remain disabled until global plus provider-owned admin settings are enabled, and require exact qualified token grants. The immutable provider context excludes bearer tokens, raw headers and raw payloads. Per-provider admin UI and OAuth grant issuance are not included yet.

Test layout

- `tests/auth_test.php`: token lifecycle and connection-cache invalidation.
- `tests/oauth_server_test.php`: dynamic client registration, PKCE, scope grants, reuse and token limits.
- `tests/server_test.php`: tool catalogue, schema validation, feature toggles and token permission filters.
- `tests/access_test.php`: course/module enrolment and separate-group isolation.
- `tests/content_tools_test.php`: content, files, H5P and activity deletion.
- `tests/assessment_tools_test.php`: question bank, quiz, assessment scaffolding and grading.
- `tests/privacy_test.php`: privacy metadata, context discovery and deletion.
- `tests/provider_discovery_test.php`: public external-provider contract and fail-closed discovery validation.

Verification performed

- `node --test bridge/mcp-bridge.test.mjs`: passed.
- `node --check bridge/mcp-bridge.mjs`: passed.
- PHP syntax, XML parsing, language parity, static policy checks and a bridge mock smoke test are run by `verify.sh`.
- A real Moodle PHPUnit/upgrade run still needs a configured Moodle test instance. The available Moodle checkout does not include `config.php` or `vendor/bin/phpunit`.

Run local checks

    ./verify.sh

For full Moodle verification, install this directory as `admin/tool/teachermcp` in a configured Moodle 4.5+ checkout, run the plugin upgrade, initialise PHPUnit, then run the plugin test group.
