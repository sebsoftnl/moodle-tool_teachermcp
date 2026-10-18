# Teacher AI Assistant (Model Context Protocol - MCP) for Moodle

Moodle Admin Tool exposing an authenticated **Model Context Protocol (MCP)** JSON-RPC 2.0 endpoint and self-service dashboard for teachers.
Allows educators to connect compatible personal AI clients to permitted course-content, assessment, grading and student-support workflows. Client configuration examples are not commercial-client certification. Start with the teacher-facing [agent usage guide](AGENTS.md), which is also supplied by the dashboard's copy/download controls.

---

## 🚀 Key Highlights & Capabilities

### 1. High-Level Didactic Workflows
- **`export_course_learning_material`**: Extracts permitted course material into bounded Markdown, including implemented Page, Book, Lesson, H5P, Glossary, assignment and attached-document paths. Unsupported or truncated extraction is reported explicitly; this is not OCR or a general PDF engine.
- **`scaffold_assessment_unit`**: End-to-end quiz builder using core `create_module()`. Creates question bank entries, instantiates the quiz in a course section, links questions to slots, and verifies fractional weights in a delegated transaction.
- **`detect_at_risk_students`**: A heuristic early-warning score using access, missed work, grades, completion and existing stored Moodle Analytics predictions. A positive stored prediction contributes the existing 25-point signal and `analytics_risk` flag. It does not train a model or establish predictive accuracy.
- **`analyze_class_understanding`**: Reports implemented attempt/score metrics, answer counts, facility percentages and unassessed responses (`needs_grading`). AI prompts can request interpretation; server-computed discrimination and misconception-clustering algorithms are currently unsupported.
- **`prepare_grading_session`**: Batch grading assistant. Bundles ungraded submissions, submitted student texts, file attachment metadata, rubric criteria, and student context into a single payload for rapid AI-assisted grading.

### 2. Complete Suite of 24 MCP Tools
1. `get_my_profile`: Teacher identity, system permissions, and environment info.
2. `get_teacher_courses`: Courses where teacher is actively enrolled with teaching/grading rights.
3. `get_course_contents`: Section tree, activity names, optional descriptions and completion-enabled flags.
4. `export_course_learning_material`: Extract permitted course content as bounded Markdown, with explicit limitations and truncation outcomes.
5. `read_activity_file`: Inspect permitted activity files and bounded text extraction from implemented text/Office formats and simple text PDFs; scanned or unsupported files are not reported as complete extraction.
6. `upload_course_asset`: Upload supplied base64 PNG, JPEG, GIF or WebP images. Remote image fetching, SVG and general document upload are not supported by this tool.
7. `manage_content_activity`: Create or update Page and Book resources with rich HTML formatting and chapters.
8. `manage_activity`: Inspect module settings; current settings updates target Page names, descriptions, content or visibility.
9. `duplicate_activity`: Duplicate activities within a course or across courses where teacher has rights.
10. `delete_activity`: Safely delete activities only after the required UI confirmation or short-lived preview `confirm_token`.
11. `get_h5p_libraries`: Inspect enabled runnable libraries in a permitted course; selected popular types include parameter examples, not a universal schema.
12. `create_h5p_activity`: Package valid content for an already installed H5P library into a hidden activity and/or Content Bank entry. Local end-to-end qualification uses H5P.Blanks 1.12.
13. `scaffold_assessment_unit`: Scaffold quizzes using multichoice, shortanswer, essay or truefalse questions. Numerical creation is currently unsupported.
14. `create_assignment`: Create `mod_assign` activities with due dates, submission settings and optional rubric descriptors in the introduction, not new native advanced rubrics.
15. `manage_question_bank`: Manage permitted questions/categories using the native bank contexts appropriate to the Moodle version.
16. `analyze_class_understanding`: Examine cohort performance, facility index, and learning gaps on quizzes.
17. `prepare_grading_session`: Batch-retrieve submissions, attachments, rubrics, and feedback contexts.
18. `grade_submission`: Submit numeric/scale grades and feedback comments (supporting individual and group grading).
19. `manage_grades`: Inspect gradebook items and update individual student grade entries.
20. `get_group_students`: List students belonging to course groups where teacher has access.
21. `manage_course_completion`: Inspect course completion criteria and individual student completion progress.
22. `detect_at_risk_students`: Compute the existing heuristic risk index and expose stored prediction signals where available.
23. `send_student_message`: Dispatch direct Moodle messages to enrolled students with contact checks and rate limiting.
24. `manage_course_calendar`: Schedule course deadlines, office hours, and review sessions on the calendar.

### 3. MCP Protocol Primitives: Prompts & Resources
- **Native Prompts (`prompts/list`, `prompts/get`)**:
  - Exposes 15 pre-built pedagogical prompt workflows for ingestion, content/H5P creation, assessment, grading preparation, calendar scheduling and student support. Retrieval returns instructions; it does not itself execute the suggested actions or prove that an external AI client can perform them.
- **Native Read-Only Resources (`resources/list`, `resources/read`)**:
  - `moodle://courses`: Real-time JSON listing of the teacher's active teaching courses.
  - `moodle://course/{course_id}/syllabus`: Dynamic Markdown course syllabus and structural outline.
  - `moodle://course/{course_id}/announcements`: Latest announcements extracted directly from the course news forum.
  - `moodle://activity/{cmid}/rubric`: Existing active grading method/title/description metadata, or the simple-grading outcome. Full native rubric criteria and levels are returned by `prepare_grading_session`, not this resource.

### 4. Admin CLI Diagnostics & Maintenance
- **Health Check Doctor (`cli/mcp_doctor.php`)**:
  ```bash
  php admin/tool/teachermcp/cli/mcp_doctor.php
  ```
  Runs eight diagnostic groups covering prerequisites, tables, token/OAuth statistics, built-in catalog integrity, protocol metadata, HTTPS and in-process initialization/discovery. Missing built-ins or failed prerequisites produce a nonzero exit without contradictory OK output. It does not qualify HTTP/stdio transports, teaching workflows or external clients. MCP initialization reports the installed plugin release; the protocol remains `2024-11-05`.
- **Scheduled Maintenance Task (`cli/cleanup.php` / Moodle Cron)**:
   The default schedule is daily at 03:15, configurable in Moodle. Cleanup purges expired authorization codes and updates expired tokens; it is not a database-index optimization command.

---

## 🔒 Security Architecture & Multi-Tenant Isolation

1. **Authentication & Token Storage**:
   - MCP endpoint requests use a cryptographically random Bearer token (`mcp_...`), obtained manually or through the OAuth 2.0 PKCE flow (RFC 7636).
   - The server token table persists SHA-256 hashes, not plaintext tokens. The local OAuth bridge cache contains bearer credentials and must be protected as sensitive per-user data.
   - Tokens can be set with maximum validity windows and revoked instantly.
2. **Strict Contextual Capability Enforcement**:
   - All tools verify capabilities via Moodle's native access control (`require_capability()`).
   - If a teacher lacks grading rights in Course B, calls for that course fail with a permission exception.
3. **Course & Group Isolation**:
   - Enforces `is_enrolled()` for student data access.
   - Enforces `SEPARATEGROUPS` mode and `groups_get_activity_allowed_groups()` so teachers cannot access students or submissions from groups they do not manage.
4. **Audit Trail & Logging**:
   - Executed tool successes and execution failures emit `\tool_teachermcp\event\tool_executed` metadata without arguments, result payloads or bearer values. The configured Moodle logstore controls persistence.
5. **Rate Limiting & Safety Guardrails**:
   - Direct messaging (`send_student_message`) includes configurable per-hour rate limits and checks recipient contact preferences.
   - Destructive operations (`delete_activity`) require explicit UI confirmation or a short-lived preview `confirm_token`.

---

## 📦 Installation & Configuration

### 1. Requirements
- Plugin minimum: Moodle 4.5 and PHP 8.1. Use the PHP version and extensions supported by the chosen Moodle release; the local PHP8.1 compatibility boundary is not a production recommendation.
- Enable the extensions required by Moodle and the used features, including cURL and ZIP/XML support for transport/content packaging.
- Node.js >= 22.11.0 for the optional stdio bridge. The PHP HTTP endpoint does not require a commercial AI service.

### 2. Installation
1. Place the plugin code into `admin/tool/teachermcp`:
   ```bash
   git clone <repository_url> admin/tool/teachermcp
   ```
2. Run database upgrade via Moodle CLI:
   ```bash
   sudo -u www-data php admin/cli/upgrade.php --non-interactive
   ```
3. Purge caches:
   ```bash
   sudo -u www-data php admin/cli/purge_caches.php
   ```

### 3. Web Server Configuration (Authorization Header)
Moodle endpoints require the `Authorization` header to be passed to PHP:

- **Apache** (`.htaccess` or virtual host config):
  ```apache
  SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
  ```
- **Nginx** (`fastcgi_params`):
  ```nginx
  fastcgi_param HTTP_AUTHORIZATION $http_authorization;
  ```

### 4. Capabilities
- `tool/teachermcp:connectagent`: Allows teachers to create agent connections and use MCP endpoints (granted to editingteacher and manager by default).
- `tool/teachermcp:manageall`: Allows site administrators to view all site connections and configure settings.

### 5. External MCP tool providers
Teacher MCP can safely discover tools supplied by independently installed Moodle plugins. These tools are disabled by default, require the global Teacher MCP enablement plus a provider-owned setting, and need an exact qualified token grant. They remain subject to the same token checks, schema validation, request and response limits, rate limiting, and audit logging as built-in tools.

Provider contract, security obligations, and enablement requirements are documented in [docs/developer/extending-teachermcp.md](docs/developer/extending-teachermcp.md).

---

## 🛠️ Connecting AI Clients

### Option 1: Local stdio bridge with OAuth 2.0 PKCE
Download or install `mcp-bridge.mjs` locally and replace the example path with its actual absolute path. OAuth requires site-enabled client registration, browser consent and the local callback. A compatible stdio client can use this configuration shape (for example, `claude_desktop_config.json`):
```json
{
  "mcpServers": {
    "moodle": {
      "command": "node",
      "args": [
        "/path/to/moodle/admin/tool/teachermcp/bridge/mcp-bridge.mjs",
        "https://your-moodle.example.com/admin/tool/teachermcp/endpoint.php",
        "--oauth"
      ]
    }
  }
}
```

### Option 2: Manual Bearer Token
1. Go to **User Profile > AI Agent (MCP)** (`/admin/tool/teachermcp/my_agent.php`).
2. Generate a new agent connection and copy the secret token.
3. Configure your client to send:
   - Endpoint: `https://your-moodle.example.com/admin/tool/teachermcp/endpoint.php`
   - Header: `Authorization: Bearer <your_token>`

For a stdio-only client, pass that token as the bridge's final argument instead of `--oauth`. Keep the client configuration private. Manual mode never switches silently to OAuth.

### Recovery and result handling

Explicit invalid/expired credentials return401; valid-user capability denial returns403; throttling returns429 with `Retry-After`. Tool-level denials can be HTTP200 with `result.isError=true`, so inspect the JSON-RPC result rather than HTTP status alone.

In OAuth mode, an explicit invalid/expired credential rejection removes only that endpoint's cached credential and closes the bridge with a correlated error and nonzero exit. Restart once for fresh authorization. Requests are not replayed automatically. Permission/throttle/server errors and ambiguous legacy401 responses do not automatically erase valid cache entries. After an uncertain write outcome, inspect Moodle before issuing another write.

## Verification and Current Limits

Local verification covers pinned Moodle4.5.0/PHP8.1 and a Moodle5.3/PHP8.4 checkout, with native teacher/student browser flows, HTTP and local stdio clients. It does not certify commercial clients, native Windows execution, untested Moodle/PHP combinations or production readiness. See [testing and qualification](docs/developer/testing.md).

Creation is hidden by default; explicit publication requires permission. Read-only completion inspection does not weaken course/group/report checks. Provider API v1 and exact qualified grants remain unchanged.

Numerical-question creation and server-computed discrimination/misconception clustering remain currently unsupported. Implementing or permanently retiring them requires a separate owner decision; this stabilization makes neither decision nor a delivery promise. Assignment rubric descriptors are distinct from reading an already configured native rubric. Stored Moodle prediction inputs are distinct from new or validated predictive models.
