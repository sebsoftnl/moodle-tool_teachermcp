# Changelog

All notable changes to the **Teacher MCP Agent Integration (`tool_teachermcp`)** plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.4.5-beta] - 2026-09-22

### Security & Hardening
- **Subprocess Execution Hardening in Desktop Bridge (`bridge/mcp-bridge.mjs`) [SEC-01]**:
  - *Change*: Replaced `child_process.exec` using string template interpolation (`start "" "${url}"`) with `child_process.spawn` using discrete parameter arrays without invoking an intermediate shell.
  - *Reason*: Eliminates the risk of command injection or unexpected behavior if shell metacharacters or quotes are present in URLs opened by the browser launcher on Windows and Unix systems.
- **Granular Backup & Restore Capability Enforcement (`classes/tools/duplicate_activity.php`) [SEC-02]**:
  - *Change*: Added explicit capability checks `require_capability('moodle/backup:backupactivity', $sourcecontext)` and `require_capability('moodle/restore:restoreactivity', $targetcontext)`.
  - *Reason*: Adheres to Moodle defense-in-depth security best practices. While `backup::MODE_IMPORT` excludes user data, teachers invoking cross-course activity duplication must possess explicit backup and restore capabilities in addition to `moodle/course:manageactivities`.
- **Cross-Platform Test Permissions (`bridge/mcp-bridge.test.mjs`)**:
  - *Change*: Gated POSIX octal permission assertions (`0o700` and `0o600`) behind a platform check `if (process.platform !== 'win32')`.
  - *Reason*: Windows NTFS file systems do not map POSIX permission mode bits directly, causing legitimate bridge tests to fail when run on Windows developer environments.

### Added
- **Automated Scheduled Maintenance Task (`db/tasks.php`, `classes/task/cleanup_task.php`)**:
  - *Change*: Added daily scheduled task `\tool_teachermcp\task\cleanup_task` running at 03:15 AM.
  - *Reason*: Prevents unbounded database table growth. Automatically purges expired OAuth 2.1 PKCE authorization codes (`timeexpires < :now`), removes consumed/revoked codes older than 24 hours, and marks expired active tokens as revoked (`status = 0`).
- **Database Performance Indexes (`db/install.xml`, `db/upgrade.php`)**:
  - *Change*: Added non-unique `timeexpires_idx` index on the `timeexpires` field in both `tool_teachermcp_tokens` and `tool_teachermcp_oauth_codes` (upgrade savepoint `2026091907`).
  - *Reason*: Guarantees high-performance queries for the cleanup scheduled task and token validation checks without full-table scans.
- **MCP Native Prompts Protocol (`classes/local/prompt_registry.php`, `classes/server.php`)**:
  - *Change*: Implemented the MCP `prompts/list` and `prompts/get` protocol methods and advertised `'prompts'` capability during MCP `initialize`.
  - *Reason*: Exposes 16 pedagogical prompt templates (Course Ingestion, H5P Flashcards/Quiz Builder, Question Scaffolding, Assignment/Rubric Scaffolding, Course Announcements, Calendar Scheduling, Early Warning Detection, Rubric Grading) directly to AI desktop assistants (Claude Desktop, Cursor, Codex). Teachers can select them natively inside their AI client's prompt menu or copy them directly from the dashboard.
- **Teacher Token Role Presets (`my_agent.php`, `templates/my_agent.mustache`, `classes/server.php`)**:
  - *Change*: Added scope presets during manual token creation in the Teacher Dashboard: *Full Co-Pilot* (`all`), *Grading Assistant* (`read`, `grade`), *Curriculum Designer* (`read`, `write`), and *Read-Only Observer / Tutor* (`read`).
  - *Reason*: Adheres to the principle of least privilege by allowing teachers to constrain agent connections according to the intended use case.
- **MCP Dynamic Resources Reference Card (`my_agent.php`, `templates/my_agent.mustache`)**:
  - *Change*: Added a dedicated "Resources" tab in the Teacher Agent Dashboard showing standardized URIs (`moodle://courses`, `moodle://course/{course_id}/syllabus`, etc.) with copyable URI templates and descriptions.
  - *Reason*: Highlights the complete MCP triad (Tools, Prompts, Resources) and enables teachers to easily copy dynamic resource URIs for their custom AI system prompts.
- **Self-Describing Discovery Endpoint (`endpoint.php`)**:
  - *Change*: Expanded the HTTP GET discovery response on `/admin/tool/teachermcp/endpoint.php` to include real-time server capability statistics (`tools.count`, `prompts.count`, `resources.static_count`, `resources.template_count`).
  - *Reason*: Allows external MCP clients, monitoring tools, and healthcheck probes to dynamically inspect protocol capabilities and counts via simple HTTP GET requests.
- **Admin CLI Diagnostics & Cleanup Tools (`cli/mcp_doctor.php`, `cli/cleanup.php`)**:
  - *Change*: Added terminal CLI health check script `cli/mcp_doctor.php` (with 8 automated verification steps including an end-to-end JSON-RPC dry-run) and manual maintenance runner `cli/cleanup.php`.
  - *Reason*: Enables system administrators and DevOps engineers to diagnose environment health, database integrity, extension requirements, and test the JSON-RPC execution pipeline directly via CLI.
- **Comprehensive Unit Tests (`tests/auth_test.php`, `tests/server_test.php`, `tests/content_tools_test.php`, `tests/tools_extended_test.php`)**:
  - *Change*: Added `tests/tools_extended_test.php` and extended test cases covering cleanup task execution, prompt retrieval, resource discovery and dynamic URI readers, assignment creation, forum interactions, calendar scheduling, and all 25 built-in tools.
  - *Reason*: Achieved 100% class coverage across all 43 PHP classes in the plugin.

### Fixed
- **Missing `moodle_exception` Language Strings (`lang/en/tool_teachermcp.php`, `lang/nl/tool_teachermcp.php`)**:
  - *Change*: Added 7 missing localized exception strings with complete English/Dutch parity:
    - `error_invalid_token`
    - `error_expired_token`
    - `error_invalid_client`
    - `error_unsupported_response_type`
    - `error_unsupported_grant_type`
    - `error_invalid_grant`
    - `error_registration_disabled`
  - *Reason*: In Moodle, throwing a `moodle_exception` with an unregistered language key triggers a fatal `coding_exception` fallback (*"Cannot find string in identifier"*). Localizing these keys ensures clean, user-friendly error messages and adheres to Moodle internationalization guidelines.
- **Undeclared Global Scope Variables (`classes/tools/manage_grades.php`, `classes/tools/manage_course_completion.php`)**:
  - *Change*: Added `$USER` to `global $DB, $CFG, $USER;` inside the `execute()` methods.
  - *Reason*: Under PHP 8.0+, accessing `$USER->id` without a global declaration treats `$USER` as an uninitialized local variable, throwing a `PHP Warning: Attempt to read property "id" on null` when checking teacher group permissions.

---

## [1.4.4-beta] - 2026-09-19

### Added
- **Extensible External MCP Tool Providers (`classes/local/provider_registry.php`, `classes/local/tool_registry.php`)**:
  - Added discovery registry allowing 3rd-party Moodle plugins to expose tools through Teacher MCP via standard Moodle callbacks without core modifications.
- **Privacy API Implementation for Uploaded Assets (`classes/tools/upload_course_asset.php`, `classes/privacy/provider.php`)**:
  - Tracked generated files and assets in `tool_teachermcp_assets` for full GDPR compliance and data erasure routines.

---

## [1.4.2-beta] - 2026-09-18

### Added
- Initial public release of Teacher MCP Agent Integration for Moodle 4.5+ LTS.
- RFC 6749, RFC 7636 (PKCE), RFC 8252 (OAuth for Native Apps), and RFC 8414 (Authorization Server Metadata) implementation.
- Model Context Protocol JSON-RPC 2.0 endpoint with 23+ built-in pedagogical tools.
- English (`en`) and Dutch (`nl`) translations.
