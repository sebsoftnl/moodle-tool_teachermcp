# Teacher AI Assistant (Model Context Protocol - MCP) for Moodle

Moodle Admin Tool exposing an authenticated **Model Context Protocol (MCP)** JSON-RPC 2.0 endpoint and self-service dashboard for teachers.
Allows educators to connect personal AI agents (**Claude Desktop**, **Codex**, **Cursor**, **ChatGPT**) to execute complete didactic workflows, ingest entire course materials into LLM context, assemble assessments, identify struggling students, analyze quiz psychometrics, build interactive H5P modules, and streamline grading.

---

## 🚀 Key Highlights & Capabilities

### 1. High-Level Didactic Workflows
- **`export_course_learning_material`**: Extracts the full substantive body of a course (Page texts, Book chapters, Lesson modules in linked-list order, **H5P interactive modules**, Glossary terms, Assignment briefs, and attached documents) into clean, structured Markdown.
- **`scaffold_assessment_unit`**: End-to-end quiz builder using core `create_module()`. Creates question bank entries, instantiates the quiz in a course section, links questions to slots, and verifies fractional weights in a delegated transaction.
- **`detect_at_risk_students`**: Predictive early-warning analytics. Scans student enrollments, days since last course access, missed assignment deadlines (accounting for extensions and overrides), failing gradebook averages, incomplete activities, and Moodle Analytics to produce an actionable risk index.
- **`analyze_class_understanding`**: Post-assessment diagnostic psychometrics. Analyzes finished quiz attempts (`\mod_quiz\quiz_attempt::FINISHED`), question versions, question difficulty (facility index), error distributions, and tracks unassessed responses (`needs_grading`).
- **`prepare_grading_session`**: Batch grading assistant. Bundles ungraded submissions, submitted student texts, file attachment metadata, rubric criteria, and student context into a single payload for rapid AI-assisted grading.

### 2. Complete Suite of 24 MCP Tools
1. `get_my_profile`: Teacher identity, system permissions, and environment info.
2. `get_teacher_courses`: Courses where teacher is actively enrolled with teaching/grading rights.
3. `get_course_contents`: Section tree, activity names, descriptions, and completion rules.
4. `export_course_learning_material`: Extract complete substantive course content as Markdown for LLM ingestion.
5. `read_activity_file`: Inspect attached source documents, download files, or extract complete text (PDF, Word DOCX, PPTX, ODT, TXT, CSV).
6. `upload_course_asset`: Upload images and educational media assets directly to course file areas.
7. `manage_content_activity`: Create or update Page and Book resources with rich HTML formatting and chapters.
8. `manage_activity`: Inspect or update module settings, names, descriptions, or visibility.
9. `duplicate_activity`: Duplicate activities within a course or across courses where teacher has rights.
10. `delete_activity`: Safely delete activities only after the required UI confirmation or short-lived preview `confirm_token`.
11. `get_h5p_libraries`: Inspect installed H5P content types and parameter schemas.
12. `create_h5p_activity`: Deploy standalone H5P interactive activities (Flashcards, Multiple Choice, Interactive Video) directly to courses.
13. `scaffold_assessment_unit`: Scaffold complete quizzes, question items, options, and section placement.
14. `create_assignment`: Build complete `mod_assign` activities with due dates, submission settings, and optional grading rubric criteria.
15. `manage_question_bank`: Browse categories, questions, types, and manage question bank items within course contexts.
16. `analyze_class_understanding`: Examine cohort performance, facility index, and learning gaps on quizzes.
17. `prepare_grading_session`: Batch-retrieve submissions, attachments, rubrics, and feedback contexts.
18. `grade_submission`: Submit numeric/scale grades and feedback comments (supporting individual and group grading).
19. `manage_grades`: Inspect gradebook items and update individual student grade entries.
20. `get_group_students`: List students belonging to course groups where teacher has access.
21. `manage_course_completion`: Inspect course completion criteria and individual student completion progress.
22. `detect_at_risk_students`: Compute early-warning dropout risk index based on activity and grades.
23. `send_student_message`: Dispatch direct Moodle messages to enrolled students with contact checks and rate limiting.
24. `manage_course_calendar`: Schedule course deadlines, office hours, and review sessions on the calendar.

### 3. MCP Protocol Primitives: Prompts & Resources
- **Native Prompts (`prompts/list`, `prompts/get`)**:
  - Exposes 15 pre-built pedagogical prompt workflows directly inside AI assistant menus (Claude Desktop, Cursor, Codex) for syllabus ingestion, H5P creation, quiz scaffolding, rubric generation, calendar scheduling, and early warning intervention.
- **Native Read-Only Resources (`resources/list`, `resources/read`)**:
  - `moodle://courses`: Real-time JSON listing of the teacher's active teaching courses.
  - `moodle://course/{course_id}/syllabus`: Dynamic Markdown course syllabus and structural outline.
  - `moodle://course/{course_id}/announcements`: Latest announcements extracted directly from the course news forum.
  - `moodle://activity/{cmid}/rubric`: Detailed evaluation rubric and scoring guide definition for assignments.

### 4. Admin CLI Diagnostics & Maintenance
- **Health Check Doctor (`cli/mcp_doctor.php`)**:
  ```bash
  php admin/tool/teachermcp/cli/mcp_doctor.php
  ```
  Runs automated 7-step diagnostics covering PHP runtime, required extensions, database tables, token/OAuth stats, tool catalog integrity, prompt/resource status, and HTTPS security.
- **Scheduled Maintenance Task (`cli/cleanup.php` / Moodle Cron)**:
  Runs daily at 03:15 AM (or on-demand via CLI) to purge expired OAuth codes, clean inactive authorization tokens, and optimize database indexing.

---

## 🔒 Security Architecture & Multi-Tenant Isolation

1. **Authentication & Token Storage**:
   - Every API request is authenticated via a cryptographically random Bearer token (`mcp_...`) or standard OAuth 2.0 PKCE flow (RFC 7636).
   - Plaintext tokens are never stored; only SHA-256 hashes are persisted in `{tool_teachermcp_tokens}`.
   - Tokens can be set with maximum validity windows and revoked instantly.
2. **Strict Contextual Capability Enforcement**:
   - All tools verify capabilities via Moodle's native access control (`require_capability()`).
   - If a teacher lacks grading rights in Course B, calls for that course fail with a permission exception.
3. **Course & Group Isolation**:
   - Enforces `is_enrolled()` for student data access.
   - Enforces `SEPARATEGROUPS` mode and `groups_get_activity_allowed_groups()` so teachers cannot access students or submissions from groups they do not manage.
4. **Audit Trail & Logging**:
   - Every tool call triggers a `\tool_teachermcp\event\tool_executed` event in Moodle's standard logstore (`mdl_logstore_standard_log`), recording tool name, execution status, course ID, and token ID without logging sensitive payloads.
5. **Rate Limiting & Safety Guardrails**:
   - Direct messaging (`send_student_message`) includes configurable per-hour rate limits and checks recipient contact preferences.
   - Destructive operations (`delete_activity`) require explicit UI confirmation or a short-lived preview `confirm_token`.

---

## 📦 Installation & Configuration

### 1. Requirements
- Moodle 4.5+ LTS
- PHP 8.1 or later (with `curl`, `json`, `zip`, `zlib` extensions)
- Node.js >= 22.11.0 (for Grunt toolchain and stdio bridge)

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

### Option 1: Claude Desktop (1-Click OAuth 2.0 PKCE)
Add the following to `claude_desktop_config.json`:
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

### Option 2: Cursor / Codex / Custom GPT (Bearer Token)
1. Go to **User Profile > AI Agent (MCP)** (`/admin/tool/teachermcp/my_agent.php`).
2. Generate a new agent connection and copy the secret token.
3. Configure your client to send:
   - Endpoint: `https://your-moodle.example.com/admin/tool/teachermcp/endpoint.php`
   - Header: `Authorization: Bearer <your_token>`
