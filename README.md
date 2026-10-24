# Teacher AI Assistant (Model Context Protocol - MCP) for Moodle

Moodle Admin Tool exposing an authenticated **Model Context Protocol (MCP)** JSON-RPC 2.0 endpoint and self-service dashboard for teachers.
Allows educators to connect personal AI agents (**Claude Desktop**, **Codex**, **Cursor**, **ChatGPT**) through compatible MCP connections to carry out teaching workflows, bring course materials into LLM context, assemble assessments, identify students who may need support, analyze quiz performance, build interactive H5P modules, and streamline grading.

See the teacher-facing [agent usage guide](AGENTS.md) for workflows and examples, also available through the dashboard's copy/download controls.

---

## 🚀 Key Highlights & Capabilities

### 1. High-Level Didactic Workflows
- **`export_course_learning_material`**: Extracts substantive course material (Page texts, Book chapters, Lessons in linked-list order, **H5P activity text**, Glossary terms, assignment briefs, and supported attached documents) into clean, structured Markdown for LLM context.
- **`scaffold_assessment_unit`**: End-to-end quiz builder using core `create_module()`. Creates question bank entries, instantiates the quiz in a course section, links questions to slots, and verifies fractional weights in a delegated transaction.
- **`detect_at_risk_students`**: Early-warning analytics. Evaluates active student enrollments, days since last course access, overdue assignments (accounting for individual extensions and user overrides), low aggregate course grades, incomplete activities, and stored Moodle Analytics predictions to produce an actionable risk index.
- **`analyze_class_understanding`**: Post-assessment diagnostics. Analyzes finished quiz attempts (`\mod_quiz\quiz_attempt::FINISHED`), question details resolved through Moodle's quiz structure, question difficulty (facility index), correct/partial/wrong answer counts, and unassessed responses (`needs_grading`) to highlight areas for review.
- **`prepare_grading_session`**: Batch grading assistant. Bundles ungraded submissions, submitted student texts, file attachment metadata, rubric criteria, and student context into a single payload for rapid AI-assisted grading.

### 2. Complete Suite of 24 MCP Tools
1. `get_my_profile`: Teacher identity, system permissions, and environment info.
2. `get_teacher_courses`: Courses where teacher is actively enrolled with teaching/grading rights.
3. `get_course_contents`: Section tree, activity names, optional descriptions, and completion-tracking flags.
4. `export_course_learning_material`: Export course learning material as structured Markdown for LLM ingestion.
5. `read_activity_file`: Inspect attached source documents, get download links, and extract text (PDF, Word DOCX, PPTX, ODT, TXT, CSV).
6. `upload_course_asset`: Upload PNG, JPEG, GIF or WebP images into course, Page or Book file areas for embedding.
7. `manage_content_activity`: Create or update Page and Book resources with rich HTML formatting and chapters.
8. `manage_activity`: Inspect module settings; update Page names, descriptions, or visibility.
9. `duplicate_activity`: Duplicate activities within a course or across courses where teacher has rights.
10. `delete_activity`: Safely delete activities only after the required UI confirmation or short-lived preview `confirm_token`.
11. `get_h5p_libraries`: Inspect installed H5P content types (e.g. Flashcards, Multiple Choice, True/False, Accordion), versions, and example parameters for selected types.
12. `create_h5p_activity`: Build interactive H5P activities (e.g. Flashcards, Multiple Choice, Interactive Video) and Content Bank entries using installed content libraries and valid parameters.
13. `scaffold_assessment_unit`: Scaffold quizzes with multichoice, shortanswer, essay, truefalse or numerical questions and course-section placement. Numerical answers support absolute tolerance and partial credit through Moodle's native grading.
14. `create_assignment`: Create `mod_assign` activities with due/cutoff dates, submission settings (online text, file uploads, maximum file count), and optional rubric descriptions in the assignment instructions.
15. `manage_question_bank`: Browse and manage permitted questions and categories in Moodle's Question Bank.
16. `analyze_class_understanding`: Examine cohort performance (average, highest and lowest attempt scores), facility index, and question-level learning gaps on quizzes.
17. `prepare_grading_session`: Batch-retrieve submissions, attachments, rubrics, and feedback contexts.
18. `grade_submission`: Submit numeric/scale grades and feedback comments (supporting individual and group grading).
19. `manage_grades`: Inspect gradebook items and update individual student grade entries.
20. `get_group_students`: List students belonging to course groups where teacher has access.
21. `manage_course_completion`: Inspect course completion criteria and individual student completion progress.
22. `detect_at_risk_students`: Compute an early-warning risk index from activity, grades, completion, and Moodle Analytics signals.
23. `send_student_message`: Dispatch direct Moodle messages to enrolled students with contact checks and rate limiting.
24. `manage_course_calendar`: Schedule course deadlines, office hours, and review sessions on the calendar.

### 3. MCP Protocol Primitives: Prompts & Resources
- **Native Prompts (`prompts/list`, `prompts/get`)**:
  - Exposes 15 pre-built pedagogical prompt workflows for course ingestion, Page/Book authoring, H5P creation, quiz scaffolding, grading preparation, calendar scheduling, and student support (at-risk checks and draft check-in messages). Use them through MCP prompt discovery in clients that support it.
- **Native Read-Only Resources (`resources/list`, `resources/read`)**:
  - `moodle://courses`: Real-time JSON listing of the teacher's active teaching courses.
  - `moodle://course/{course_id}/syllabus`: Dynamic Markdown course syllabus and structural outline.
  - `moodle://course/{course_id}/announcements`: Latest announcements extracted directly from the course news forum.
  - `moodle://activity/{cmid}/rubric`: Active grading method and rubric title/description. Full native criteria and scoring levels are available through `prepare_grading_session`.

### 4. Admin CLI Diagnostics & Maintenance
- **Health Check Doctor (`cli/mcp_doctor.php`)**:
  ```bash
  php admin/tool/teachermcp/cli/mcp_doctor.php
  ```
  Runs eight diagnostic groups covering PHP runtime, required extensions, database tables, token/OAuth statistics, tool catalog integrity, protocol metadata, HTTPS, and in-process JSON-RPC checks.
- **Scheduled Maintenance Task (`cli/cleanup.php` / Moodle Cron)**:
  Runs daily at 03:15 by default (configurable in Moodle), or on demand via CLI, to purge expired OAuth codes and mark expired agent tokens as revoked.

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
   - Executed tool successes and execution failures emit `\tool_teachermcp\event\tool_executed` metadata (tool name, success/failure, token ID, and course ID when available), without arguments, result payloads or bearer values. The configured Moodle logstore controls persistence.
5. **Rate Limiting & Safety Guardrails**:
   - Direct messaging (`send_student_message`) includes configurable per-hour rate limits and checks recipient contact preferences.
   - Destructive operations (`delete_activity`) require explicit UI confirmation or a short-lived preview `confirm_token`.

---

## 📦 Installation & Configuration

### 1. Requirements
- Moodle 4.5 or later, with the PHP version supported by that Moodle release (plugin minimum: PHP 8.1).
- Enable the extensions required by Moodle and the used features, including cURL and ZIP/XML support for transport/content packaging.
- Node.js >= 22.11.0 for the optional local stdio bridge.

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

### Option 1: Claude Desktop (OAuth 2.0 PKCE via the local bridge)
Download `mcp-bridge.mjs` from the teacher dashboard, save it locally, and add the following to `claude_desktop_config.json`. Replace the example script path with its actual absolute path. An administrator must enable OAuth client registration; the bridge opens Moodle's consent page in your browser.
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

### Option 2: Codex / Cursor / HTTP Clients (Bearer Token)
1. Go to **User Profile > AI Agent (MCP)** (`/admin/tool/teachermcp/my_agent.php`).
2. Generate a new agent connection and copy the secret token.
3. In a client that supports manual bearer authentication, configure:
   - Endpoint: `https://your-moodle.example.com/admin/tool/teachermcp/endpoint.php`
   - Header: `Authorization: Bearer <your_token>`

For a stdio-only client, pass that token as the bridge's final argument instead of `--oauth`. Keep the client configuration private. Manual mode never switches silently to OAuth.

### Option 3: ChatGPT (Remote MCP)
ChatGPT uses a remote MCP connection rather than the desktop configuration above. Follow [OpenAI's MCP connection guide](https://developers.openai.com/api/docs/mcp) for the required transport, endpoint reachability, and OAuth setup. A hosted endpoint or supported private tunnel is needed; the manual bearer-header instructions above are not a ChatGPT setup procedure.

### Recovery and result handling

Explicit invalid/expired credentials return401; valid-user capability denial returns403; throttling returns429 with `Retry-After`. Tool-level denials can be HTTP200 with `result.isError=true`, so inspect the JSON-RPC result rather than HTTP status alone.

In OAuth mode, an explicit invalid/expired credential rejection removes only that endpoint's cached credential and closes the bridge with a correlated error and nonzero exit. Restart once for fresh authorization. Requests are not replayed automatically. Permission/throttle/server errors and ambiguous legacy401 responses do not automatically erase valid cache entries. After an uncertain write outcome, inspect Moodle before issuing another write.

## Compatibility and Current Limits

Local verification covers pinned Moodle 4.5.0/PHP 8.1 and Moodle 5.3/PHP 8.4 checkouts, with teacher/student browser, HTTP, and local stdio workflows. These local results do not yet verify end-to-end operation in each named AI client. See [testing and qualification](docs/developer/testing.md) for the tested configurations and coverage.

Activities are created hidden by default for teacher review. H5P authoring requires an installed library and valid parameters for that content type. File extraction has size and format limits and reports partial or unsupported results; it does not provide OCR. Asset uploads support the listed raster image formats, not remote URL fetching or general document uploads.

Numerical questions support finite numeric answers, nonnegative absolute tolerances, native credit fractions and feedback in quiz scaffolding and question-bank creation/read/update. Omitted tolerance defaults to zero; updates preserve omitted numerical answers and existing native units, hints and files. Unit authoring and calculated questions are not included. See the [numerical authoring contract](AGENTS.md#numerical-questions).

Server-side question-discrimination/misconception-clustering algorithms and native advanced-rubric creation are not currently available. Existing native rubric reading and stored Moodle Analytics prediction inputs are supported; the risk score is heuristic rather than a validated predictive model.
