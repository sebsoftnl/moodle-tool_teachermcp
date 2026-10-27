<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * English language strings for tool_teachermcp.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Teacher MCP Agent Integration';
$string['teachermcp:connectagent'] = 'Connect and manage personal AI Agent via Model Context Protocol (MCP)';
$string['teachermcp:manageall'] = 'Manage all teacher MCP agent connections across site';

$string['dashboard_title'] = 'Teacher AI Agent Assistant (MCP)';
$string['dashboard_desc'] = 'Connect your local or cloud AI assistant (Claude Desktop, Codex, ChatGPT, Cursor) directly to your Moodle teacher account through the Model Context Protocol.';

$string['tokens_header'] = 'Active Agent Connections';
$string['create_token'] = 'Generate New Agent Connection';
$string['agent_name'] = 'Agent Name / Device';
$string['agent_name_help'] = 'E.g. "Work Laptop Claude", "Home Desktop Codex"';
$string['token_created'] = 'Agent token generated successfully. Copy it now, as it will never be displayed again!';
$string['token_revoked'] = 'Agent connection revoked successfully.';
$string['token_revoke_failed'] = 'The agent connection could not be revoked. It may not exist or you may not have permission.';
$string['validuntil'] = 'Valid until';
$string['lastaccess'] = 'Last active';
$string['never'] = 'Never';
$string['status'] = 'Status';
$string['status_active'] = 'Active';
$string['status_revoked'] = 'Revoked';
$string['actions'] = 'Actions';
$string['revoke'] = 'Revoke Access';
$string['copy_token'] = 'Copy Token';
$string['token_warning'] = 'Keep this bearer token secret! It allows your agent to perform teacher actions on your behalf according to your Moodle permissions.';

$string['tab_claude'] = 'Claude';
$string['tab_codex'] = 'HTTP clients';
$string['tab_chatgpt'] = 'ChatGPT';
$string['tab_resources'] = 'Resources';
$string['tab_bridge'] = 'Stdio Bridge (Node.js)';

$string['config_instructions'] = 'Paste the configuration below into your agent settings file:';
$string['endpoint_url'] = 'Moodle MCP Endpoint URL';

// Setup & Tokens
$string['active_tools_count'] = '{$a} Active MCP Tools';
$string['copied'] = 'Copied!';
$string['copy_failed'] = 'Copy failed. Select the text and copy it manually.';
$string['copy'] = 'Copy';
$string['copy_config'] = 'Copy Configuration';
$string['copy_claude_config'] = 'Copy Claude Config';
$string['copy_codex_config'] = 'Copy Codex/Cursor Config';
$string['validity_period'] = 'Validity period';
$string['validity_days'] = '{$a} days';
$string['token_validity_error'] = 'Choose a validity from 1 to {$a} days. No token was created.';
$string['validity_30'] = '30 days';
$string['validity_90'] = '90 days (recommended)';
$string['validity_180'] = '180 days (one semester)';
$string['validity_365'] = '1 year';
$string['validity_unlimited'] = 'Unlimited / No expiration';
$string['no_tokens_found'] = 'You have not generated any active agent connections yet.';
$string['confirm_revoke'] = 'Are you sure you want to revoke this agent connection? The connected client will immediately lose access.';
$string['back_to_course'] = 'Return to course';
$string['course_filter_label'] = 'Personalize prompts for course:';
$string['course_filter_all'] = 'Select a course to personalize example prompts';

// Guides
$string['how_to_connect'] = 'Connect your AI assistant';
$string['claude_desktop_title'] = 'Local bridge: manual bearer token';
$string['claude_desktop_desc'] = 'Create a token with the required permission preset, then use this configuration. Replace YOUR_BEARER_TOKEN_HERE if no new token is shown. Keep the token private; this mode does not use OAuth.';
$string['codex_cursor_title'] = 'HTTP client configuration';
$string['codex_cursor_desc'] = 'For clients that support this HTTP MCP endpoint and a manually configured bearer header:';
$string['remote_app_intro'] = 'Use a remote MCP connection with browser sign-in. No local installation, bridge script or manually generated token is needed.';
$string['remote_app_requirements'] = 'This Moodle site must be reachable by the app over public HTTPS. Ask your administrator to enable OAuth client registration if needed. Enter your Moodle password only on the Moodle sign-in page, never in chat or a client-secret field.';
$string['remote_app_cached'] = 'An existing authorization may skip sign-in or consent. Verify access with an actual get_my_profile call; a tool list alone is not proof of a working connection.';
$string['copy_endpoint'] = 'Copy server URL';
$string['open_claude_connectors'] = 'Open Claude connectors';
$string['open_chatgpt_plugins'] = 'Open ChatGPT plugins';
$string['open_claude_app'] = 'Open Claude app';
$string['open_chatgpt_app'] = 'Open ChatGPT app';
$string['claude_app_link_help'] = 'The desktop button opens a new chat in the installed app, not the connector settings. Then go to Customize > Connectors. If the app does not open, use the web button.';
$string['chatgpt_app_link_help'] = 'The desktop button opens a new local chat in the installed app, not the plugin directory. Then choose Work or Codex > Plugins. If the app does not open, use the web button.';
$string['cli_setup_title'] = 'Claude Code and Codex CLI';
$string['cli_setup_desc'] = 'Both CLIs can launch local stdio MCP servers. Register the downloaded bridge as the server command, using the Node command and arguments shown above. These JSON examples are for desktop clients, not CLI syntax; follow the CLI documentation for registration. CLI-specific end-to-end setup has not been verified here.';
$string['claude_cli_docs'] = 'Claude Code CLI setup';
$string['codex_cli_docs'] = 'Codex CLI setup';
$string['http_config_cli_note'] = 'This JSON example is for compatible HTTP clients such as Cursor. Codex CLI uses config.toml or codex mcp add instead. See Local bridge for CLI documentation.';
$string['claude_app_title'] = 'Connect the Claude app';
$string['claude_app_step1'] = 'Open Customize > Connectors. Choose Add > Custom > Web, or Add custom connector.';
$string['claude_app_step2'] = 'Name the connector and paste the server URL shown above.';
$string['claude_app_step3'] = 'Choose OAuth / Sign in now and Register automatically. Leave request headers empty. Do not choose Claude\'s published identity for this server.';
$string['claude_app_step4'] = 'Select Add, then Connect if shown. If prompted, sign in to Moodle with your teacher account and review the requested permissions.';
$string['claude_app_step5'] = 'Start a new chat. Under + > Connectors, enable this Moodle connector and ask it to call get_my_profile and get_teacher_courses.';
$string['claude_app_workspace'] = 'In a managed workspace, an administrator may need to add the connector before teachers can connect their own Moodle accounts.';
$string['chatgpt_title'] = 'Connect ChatGPT with a remote MCP server';
$string['chatgpt_step1'] = 'Open Plugins in ChatGPT web. Select + > Add custom MCP server.';
$string['chatgpt_step2'] = 'Enter a name and paste the server URL shown above under Connection.';
$string['chatgpt_step3'] = 'Choose OAuth and dynamic client registration (DCR) if offered. Do not select CIMD, enter a Moodle token as a client secret, or disable authentication.';
$string['chatgpt_step4'] = 'Review the warning and select Create as a plugin. Complete Moodle sign-in and consent if prompted, then review the discovered tools and install the plugin.';
$string['chatgpt_step5'] = 'Start a new chat, type @ and select the plugin. Select it again when a follow-up needs another tool call.';
$string['chatgpt_remote_access'] = 'Availability depends on your plan and workspace permissions. Full remote read/write access is currently documented for Business and Enterprise/Edu; Pro may be limited to reads. Set up on the web and use the connection in desktop only if the app exposes it.';

// MCP Tool descriptions.
$string['tool_get_my_profile_desc'] = 'Get current teacher user profile, capabilities, and system preferences.';
$string['tool_get_teacher_courses_desc'] = 'List all courses where the authenticated teacher has teaching or editing roles.';
$string['tool_export_course_learning_material_desc'] = 'Extract complete substantive learning material (Page bodies, Book chapters, H5P interactives, Lesson texts, Glossary terms, Assignment briefs, and attached source files) as Markdown for LLM context.';
$string['tool_get_course_contents_desc'] = 'Retrieve sections, activities, quizzes, assignments, and resources for a specific course.';
$string['tool_scaffold_assessment_unit_desc'] = 'Assemble end-to-end quiz including questions, answer options, quiz module settings, and section placement.';
$string['tool_detect_at_risk_students_desc'] = 'Compute early-warning risk index based on days inactive, overdue assignments, failing grades, and missing completions.';
$string['tool_analyze_class_understanding_desc'] = 'Analyze class performance, question difficulty (facility index), error patterns, and learning gaps on a completed quiz.';
$string['tool_prepare_grading_session_desc'] = 'Prepare batch grading session with submitted texts, file attachments metadata, rubric criteria, and student context.';
$string['tool_manage_activity_desc'] = 'Create or update settings, descriptions, or parameters of a course module (quiz, assign, page, etc.).';
$string['tool_grade_submission_desc'] = 'Review student submissions, submit numeric or scale grades, and provide feedback comments.';
$string['tool_manage_grades_desc'] = 'View course gradebook items and update individual student grade entries.';
$string['tool_get_group_students_desc'] = 'List students belonging to course groups and groupings where the teacher has access.';
$string['tool_manage_course_completion_desc'] = 'Inspect and modify activity and course completion criteria and view student progress.';
$string['tool_send_student_message_desc'] = 'Send a direct message to an enrolled student via the Moodle messaging system.';
$string['tool_manage_question_bank_desc'] = 'View questions, categories, and manage question bank items for quizzes.';
$string['tool_duplicate_activity_desc'] = 'Duplicate an activity or resource within the same course or into another course where teacher has rights.';
$string['tool_read_activity_file_desc'] = 'Inspect attached source files in an activity, download them, or extract their full textual contents (PDF, DOCX, PPTX, ODT, TXT, CSV) for LLM analysis.';

// File Handling
$string['download_source_file'] = 'Download source file';
$string['download_forbidden'] = 'You do not have permission to download this file.';

// Risk detection flags
$string['flag_inactivity'] = 'No course activity in {$a} days';
$string['flag_overdue_assignments'] = '{$a} overdue or missed assignments';
$string['flag_low_grade'] = 'Low course grade average ({$a})';
$string['flag_incomplete_activities'] = '{$a} required activities incomplete';
$string['flag_analytics_risk'] = 'High dropout risk predicted by Moodle Analytics ({$a}%)';
$string['error_unauthorized'] = 'Unauthorized access: valid session or active agent bearer token required.';
$string['error_invalid_scope'] = 'The requested OAuth scope is not supported.';
$string['error_remote_image_fetch_disabled'] = 'Remote image URLs are disabled. Provide file_content_base64 instead.';
$string['setting_max_mcp_request_bytes'] = 'Maximum MCP request size';
$string['setting_max_mcp_request_bytes_desc'] = 'Maximum accepted JSON-RPC request body size in bytes. Default: 1048576 (1 MiB). Values are capped at 10485760 (10 MiB).';
$string['setting_max_mcp_batch_size'] = 'Maximum MCP batch size';
$string['setting_max_mcp_batch_size_desc'] = 'Maximum JSON-RPC calls accepted in one batch. Default: 10. Values are capped at 50.';
$string['setting_max_mcp_response_bytes'] = 'Maximum MCP response size';
$string['setting_max_mcp_response_bytes_desc'] = 'Maximum tool response size returned to an agent in bytes. Larger results are truncated with an explicit notice. Default: 262144 (256 KiB), maximum: 5242880 (5 MiB).';
$string['setting_mcp_requests_per_minute'] = 'MCP requests per minute';
$string['setting_mcp_requests_per_minute_desc'] = 'Maximum authenticated MCP requests per minute for each token and source IP address. Default: 60, maximum: 600.';

// Prompt Library
$string['prompt_library_title'] = 'Teacher Prompt Library (Ready to Use)';
$string['prompt_library_desc'] = 'Copy-paste these pedagogical prompts into your connected AI agent (Claude, Cursor, Codex, ChatGPT) to instantly trigger complete teaching workflows:';
$string['prompt_cat_context'] = 'Course Ingestion & Lesson Prep';
$string['prompt_cat_assessment'] = 'Quiz & Question Assembly';
$string['prompt_cat_analytics'] = 'Student Progress & Risk Signals';
$string['prompt_cat_grading'] = 'Grading & Rubric Feedback';

$string['prompt_badge_context'] = 'Context & Ingestion';
$string['prompt_badge_assessment'] = 'Assessment';
$string['prompt_badge_analytics'] = 'Early Warning';
$string['prompt_badge_grading'] = 'Grading';

$string['prompt_ingest_title'] = 'Load Complete Course Material into AI Memory';
$string['prompt_ingest_desc'] = 'Ingests all pages, chapters, H5P interactive activities, assignments, and attached documents into the agent context.';
$string['prompt_ingest_text'] = 'Use "export_course_learning_material" for course ID {$a->courseid} ("{$a->coursename}"). Give me a summary of the 5 key concepts, explain the required knowledge per section, and list any attached source files you extracted.';

$string['prompt_h5p_title'] = 'Analyze H5P & Interactive Modules';
$string['prompt_h5p_desc'] = 'Inspect questions, theory, and dialogs across interactive video, presentation, and book modules.';
$string['prompt_h5p_text'] = 'Use "export_course_learning_material" for course ID {$a->courseid}. Inspect the H5P activities and files, and verify if all core learning goals are adequately assessed.';

$string['prompt_files_title'] = 'Extract & Inspect Attached Source Documents';
$string['prompt_files_desc'] = 'Read and analyze attached PDFs, Word documents, and slide presentations directly from course activities.';
$string['prompt_files_text'] = 'Inspect the attached documents in course ID {$a->courseid} using "read_activity_file" or "export_course_learning_material". Formulate 5 high-level exam questions based strictly on the theory in the uploaded document.';

$string['prompt_scaffold_title'] = 'Scaffold Complete Assessment Unit';
$string['prompt_scaffold_desc'] = 'Creates a ready-to-use quiz with 5 multiple-choice questions, feedback explanations, and timing constraints.';
$string['prompt_scaffold_text'] = 'Use "scaffold_assessment_unit" to create a formative quiz of 5 multiple-choice questions in course ID {$a->courseid}, section 2. Base questions on the course material, include didactic feedback for distractors, and keep the quiz hidden for teacher review.';

$string['prompt_assignment_title'] = 'Scaffold Assignment with Evaluation Rubric';
$string['prompt_assignment_desc'] = 'Creates a rich assignment (mod_assign) with instructions, due date, submission rules, and rubric criteria.';
$string['prompt_assignment_text'] = 'Use "create_assignment" to create an assignment in course ID {$a->courseid}, section 3 titled "Critical Essay on AI Ethics". Include comprehensive instructions, set the due date 14 days from now, allow online text and PDF submissions, and embed rubric evaluation criteria for Argumentation (50%) and Clarity (50%).';



$string['prompt_calendar_title'] = 'Schedule Course Review Session or Office Hours';
$string['prompt_calendar_desc'] = 'Schedules a deadline or live review session onto the Moodle course calendar.';
$string['prompt_calendar_text'] = 'Use "manage_course_calendar" with action="create_event" in course ID {$a->courseid}. Schedule a 60-minute "Midterm Exam Review Session" for tomorrow at 14:00 with meeting notes and preparation guidelines.';

$string['prompt_atrisk_title'] = 'Identify Students at Academic Risk';
$string['prompt_atrisk_desc'] = 'Calculates risk signals from inactivity, overdue tasks, and failing scores.';
$string['prompt_atrisk_text'] = 'Run "detect_at_risk_students" for course ID {$a->courseid}. Which students are inactive or have missing work? Draft a motivating check-in message for the 3 students with highest risk that I can send via "send_student_message".';

$string['prompt_analytics_title'] = 'Post-Quiz Error and Misconception Analysis';
$string['prompt_analytics_desc'] = 'Examines error patterns, difficult questions, and learning gaps across the cohort.';
$string['prompt_analytics_text'] = 'Run "analyze_class_understanding" for quiz CMID [enter_cmid]. Which questions had a facility index below 50% and what misconceptions were common? Provide a 10-minute recap lesson plan for the next lecture.';

$string['prompt_grading_title'] = 'Prepare Batch Grading Session';
$string['prompt_grading_desc'] = 'Fetches ungraded submissions, attached files, rubric criteria, and proposes draft evaluations.';
$string['prompt_grading_text'] = 'Prepare ungraded submissions for assignment CMID [enter_cmid] using "prepare_grading_session". Evaluate the submitted work against the rubric and propose grades with constructive feedback for "grade_submission".';

// OAuth 2.0 PKCE Integration.
$string['oauth_authorize_title'] = 'Connect AI Agent (OAuth 2.0)';
$string['oauth_prompt'] = 'The application <strong>{$a}</strong> is requesting permission to connect to your Moodle teacher account via the Model Context Protocol (MCP).';
$string['oauth_account_scope_notice'] = 'This connection will only be granted access to courses and data where your account has active teaching permissions.';
$string['oauth_permissions_header'] = 'Requested permissions (currently enabled tools):';
$string['oauth_perm_read'] = 'Read course content, learner information and teaching reports';
$string['oauth_perm_write'] = 'Create and update course content, activities, questions and calendar events';
$string['oauth_perm_grade'] = 'Review submissions and record grades and feedback';
$string['oauth_perm_message'] = 'Send messages to students';
$string['oauth_perm_delete'] = 'Delete course activities, subject to confirmation';
$string['oauth_perm_external'] = 'Use the external tool: {$a}';
$string['oauth_perm_none'] = 'None of the requested tools are currently enabled. The requested permissions still apply if those tools are enabled later.';
$string['oauth_perm_courses'] = 'Read teaching courses, sections, and activities';
$string['oauth_perm_content'] = 'Export course texts, books, H5P modules, and glossary entries';
$string['oauth_perm_files'] = 'Read and analyze attached PDFs, documents, and presentations';
$string['oauth_perm_assessment'] = 'Scaffold quizzes, draft question banks, and duplicate activities';
$string['oauth_perm_grading'] = 'Prepare grading sessions, review student submissions, and suggest feedback';
$string['oauth_allow'] = 'Authorize Connection';
$string['oauth_deny'] = 'Cancel & Deny';
$string['oauth_security_footer'] = 'You can revoke this connection at any time from your Moodle Teacher MCP dashboard.';
$string['label_unverified_client'] = 'Unverified Application';
$string['desc_unverified_client'] = 'This application has not been verified by the site administrator. Ensure you trust this software before authorizing access.';
$string['oauth_redirect_destination'] = 'Tokens will be returned to: {$a}';
$string['oauth_tab_title'] = 'Local bridge';
$string['oauth_setup_title'] = 'Local bridge: OAuth sign-in';
$string['oauth_setup_desc'] = 'For desktop or stdio clients that run a local program. Start the downloaded bridge with --oauth to open Moodle sign-in and consent when needed. It uses the pre-registered local client; dynamic registration and a manually generated bearer token are not required.';
$string['oauth_endpoints_title'] = 'OAuth 2.0 Server Endpoints';
$string['oauth_auth_endpoint'] = 'Authorization Endpoint';
$string['oauth_token_endpoint'] = 'Token Endpoint';
$string['oauth_metadata_endpoint'] = 'Discovery Metadata';
$string['claude_oauth_bridge_desc'] = 'Add this configuration to your claude_desktop_config.json with the --oauth flag:';
$string['claude_manual_bridge_desc'] = 'Add this configuration to claude_desktop_config.json with your manual token, not the --oauth flag:';
$string['bridge_path_placeholder'] = '/ABSOLUTE/PATH/TO/mcp-bridge.mjs';
$string['download_bridge_title'] = '1. Download the bridge script to your computer';
$string['download_bridge_desc'] = 'Install Node.js 22.11 or newer and save mcp-bridge.mjs locally. In the configuration below, replace the example script path with its actual absolute path on your computer:';
$string['download_bridge_btn'] = 'Download mcp-bridge.mjs';

// Bridge script (Node.js) strings for AMOS translation.
$string['bridge_missing_endpoint'] = 'Error: Missing endpoint argument.';
$string['bridge_usage'] = 'Usage: node mcp-bridge.mjs <endpoint_url> <bearer_token|--oauth> [--lang=en|nl]';
$string['bridge_auth_failed'] = 'Authentication failed';
$string['bridge_invalid_code'] = 'Invalid authorization code or state mismatch.';
$string['bridge_auth_success'] = 'Authentication successful!';
$string['bridge_connected_desc'] = 'Your AI assistant has been successfully connected to your Moodle Teacher MCP account.';
$string['bridge_close_tab'] = 'You can now safely close this browser tab and return to your AI app.';
$string['bridge_token_fetch_error'] = 'Error retrieving token';
$string['bridge_browser_opening'] = '[Moodle MCP OAuth] Opening browser for authorization:';
$string['bridge_auth_error'] = '[Moodle MCP OAuth] Authorization failed:';
$string['bridge_comm_error'] = 'Bridge communication error:';

// Content activities and media prompts.
$string['prompt_cat_content'] = 'Course Content & Media';
$string['prompt_badge_content'] = 'Pages & Books';
$string['prompt_page_title'] = 'Create Rich Content Page with Illustrations';
$string['prompt_page_desc'] = 'Drafts a responsive HTML page with headings, callouts, and embedded diagrams.';
$string['prompt_page_text'] = 'Using "manage_content_activity", create a Page in course ID {$a->courseid} in section 1 titled "Introduction to Neural Networks". Include structured headings, a callout box summarizing key takeaways, and clean formatting.';
$string['prompt_book_title'] = 'Build Multi-Chapter Structured Book';
$string['prompt_book_desc'] = 'Creates a Moodle Book activity with multiple subchapters for progressive reading.';
$string['prompt_book_text'] = 'Create a Book in course ID {$a->courseid} section 2 titled "Software Architecture Patterns". Create Chapter 1: Introduction, Chapter 2: Layered Architecture, and Chapter 3: Microservices.';
$string['prompt_image_title'] = 'Upload & Embed Generated Image Asset';
$string['prompt_image_desc'] = 'Saves an LLM-generated diagram or photo into course assets and embeds it.';
$string['prompt_image_text'] = 'Upload the provided diagram to course ID {$a->courseid} as "cloud_deployment_topology.png" using "upload_course_asset". Then create a Page in section 3 with the image embedded inside.';

// H5P interactive activities prompts.
$string['prompt_cat_h5p'] = 'Interactive H5P Activities';
$string['prompt_badge_h5p'] = 'H5P Builder';
$string['prompt_h5p_inspect_title'] = 'Inspect Installed H5P Libraries & Schemas';
$string['prompt_h5p_inspect_desc'] = 'Lists all available H5P content types and their exact JSON schemas.';
$string['prompt_h5p_inspect_text'] = 'Run "get_h5p_libraries" with include_schema_examples=true. Which interactive content types are installed on this Moodle site and what is the parameter schema for Flashcards and Multiple Choice?';
$string['prompt_h5p_flashcards_title'] = 'Build Interactive H5P Flashcards Activity';
$string['prompt_h5p_flashcards_desc'] = 'Deploys a standalone H5P Flashcard set to the course and Content Bank.';
$string['prompt_h5p_flashcards_text'] = 'Use "create_h5p_activity" to build an interactive Flashcard set in course ID {$a->courseid}, section 1 titled "Biology Terminology Flashcards" with library "H5P.Flashcards". Add 5 definition cards covering Cell, Mitochondria, Ribosome, DNA, and Osmosis.';
$string['prompt_h5p_quiz_title'] = 'Build Interactive H5P Multiple Choice Quiz';
$string['prompt_h5p_quiz_desc'] = 'Deploys an interactive H5P question with rich didactic feedback.';
$string['prompt_h5p_quiz_text'] = 'Use "create_h5p_activity" to deploy an interactive "H5P.MultiChoice" in course ID {$a->courseid}, section 2 titled "Check Your Understanding". Include question text, 4 answer choices, didactic feedback per choice, and enable the solutions button.';

// Course maintenance and safety prompts.
$string['prompt_cat_maintenance'] = 'Course Maintenance & Safety';
$string['prompt_badge_maintenance'] = 'Dangerous Actions';
$string['prompt_delete_title'] = 'Safely Delete Outdated Activity (Two-Step Confirmation)';
$string['prompt_delete_desc'] = 'Uses the dangerous delete_activity tool with safe preview before permanent removal.';
$string['prompt_delete_text'] = 'Inspect activity cmid [enter_cmid] with "delete_activity". Show me the activity details and consequences. Do not delete until I explicitly give confirmation; then use the UI confirmation flow or the preview-issued confirm_token when UI confirmation is disabled.';

// Agent Skill UI strings.
$string['agent_skill_title'] = 'AI Agent System Prompt & Skills';
$string['agent_skill_subtitle'] = 'Copy this prompt or download the skill specification into Claude Desktop, Custom GPT, or Cursor to instruct your AI agent on how to use all tools effectively.';
$string['copy_skill_prompt'] = 'Copy Agent Instructions';
$string['download_skill_file'] = 'Download Skill (.md)';
$string['tab_agent_instructions'] = 'Agent instructions';

// Admin settings and security messages.
$string['manage_tokens'] = 'Manage Agent Connections';
$string['setting_max_token_validity'] = 'Maximum token validity (days)';
$string['setting_max_token_validity_desc'] = 'Maximum allowed validity in days when generating agent tokens. Non-positive values default to 90 days.';
$string['setting_oauth_registration_enabled'] = 'Enable Dynamic OAuth Client Registration';
$string['setting_oauth_registration_enabled_desc'] = 'Allow AI clients to dynamically register via RFC 7591 dynamic client registration endpoint.';
$string['setting_max_oauth_tokens_per_client'] = 'Maximum active OAuth tokens per client';
$string['setting_max_oauth_tokens_per_client_desc'] = 'Maximum number of concurrent active OAuth tokens allowed per user for a single OAuth client. Oldest active tokens are automatically revoked upon exceeding this limit.';
$string['setting_message_ratelimit_per_hour'] = 'Direct message rate limit (per hour)';
$string['setting_message_ratelimit_per_hour_desc'] = 'Maximum number of direct messages an agent can send per hour per token/user.';
$string['setting_allowed_cors_origins'] = 'Allowed CORS Origins';
$string['setting_allowed_cors_origins_desc'] = 'List of origins allowed to make cross-origin requests to the MCP endpoint (one per line, e.g. https://chatgpt.com).';
$string['setting_allow_delete_questions'] = 'Allow question deletion';
$string['setting_allow_delete_questions_desc'] = 'If enabled, AI agents and teachers can delete questions from the question bank using MCP tools (subject to permissions and confirmation token).';
$string['error_delete_questions_disabled'] = 'Deleting questions via MCP tools is disabled by the site administrator.';
$string['error_invalid_confirmation_token'] = 'The confirmation token is invalid or has been tampered with.';
$string['error_confirmation_token_expired'] = 'The confirmation token has expired. Please request a new confirmation token.';
$string['message_subject_from'] = 'Message from {$a}';
$string['error_no_capability'] = 'You do not have the required capability to perform this action.';
$string['error_no_group_access'] = 'You do not have access to this student or group.';
$string['error_not_enrolled'] = 'Student is not actively enrolled in this course.';
$string['error_cannot_message_user'] = 'Cannot send message to this user due to contact restrictions.';
$string['error_rate_limited'] = 'Rate limit exceeded. Please try again later.';
$string['error_invalid_request'] = 'Invalid request parameters.';
$string['error_invalid_user'] = 'Invalid target user.';
$string['error_invalid_action'] = 'Unsupported action: {$a}';
$string['error_item_not_found'] = 'Item not found.';
$string['error_invalid_params'] = 'Invalid parameters: {$a}';
$string['error_grade_locked'] = 'Grade item is locked.';
$string['error_grade_out_of_range'] = 'Grade is out of allowed range.';
$string['error_invalid_token'] = 'Invalid or revoked agent authentication token.';
$string['error_expired_token'] = 'Agent authentication token has expired.';
$string['error_invalid_client'] = 'Invalid client authentication or unknown client.';
$string['error_unsupported_response_type'] = 'The requested response type is not supported.';
$string['error_unsupported_grant_type'] = 'The authorization grant type is not supported.';
$string['error_invalid_grant'] = 'The provided authorization grant is invalid, expired, or already used.';
$string['error_registration_disabled'] = 'Dynamic client registration is disabled by the site administrator.';
$string['event_token_created'] = 'Teacher MCP token created';
$string['event_token_revoked'] = 'Teacher MCP token revoked';
$string['event_tool_executed'] = 'Teacher MCP tool executed';
$string['task_cleanup'] = 'Clean up expired Teacher MCP authorization codes and tokens';

// Admin dashboard strings (L7).
$string['stat_active_tokens'] = 'Active Agent Connections';
$string['stat_total_tokens'] = 'Total Generated Tokens';
$string['btn_teacher_dashboard'] = 'Go to Teacher Dashboard';
$string['col_teacher'] = 'Teacher';
$string['col_agent_name'] = 'Agent Label';
$string['col_token'] = 'Token';
$string['col_created'] = 'Created';
$string['col_last_active'] = 'Last Active';
$string['col_status'] = 'Status';
$string['col_actions'] = 'Actions';
$string['btn_revoke'] = 'Revoke';
$string['no_tokens_site'] = 'No agent connections have been created on this Moodle site yet.';

// Blok 5 error strings.
$string['error_params_too_deep'] = 'Parameters exceed maximum nesting depth of 20 levels.';
$string['error_invalid_image_format'] = 'Invalid image format. Only PNG, JPEG, GIF, and WEBP images are allowed.';
$string['error_file_too_large'] = 'File size exceeds the maximum allowed upload limit.';
$string['error_invalid_url_scheme'] = 'Invalid URL scheme. Only secure HTTPS URLs are allowed.';
$string['error_fetch_url'] = 'Could not fetch image from remote URL.';
$string['error_invalid_base64'] = 'Invalid base64 payload provided.';
$string['error_library_not_found'] = 'Requested H5P library was not found or is disabled on this site.';
$string['error_create_zip'] = 'Failed to create temporary H5P zip archive.';
$string['error_h5p_save_failed'] = 'Could not save H5P content to the requested destination. No activity or Content Bank item was created.';

// Blok 5: Delete safeguards, UI confirmation and tool scopes.
$string['setting_enable_external_tools'] = 'Enable externally contributed MCP tools';
$string['setting_enable_external_tools_desc'] = 'Allows independently installed Moodle plugins to publish validated Teacher MCP tools. Each provider tool remains hidden until both this setting and its provider-owned enable setting are enabled.';
$string['setting_enable_write_tools'] = 'Enable authoring and write tools';
$string['setting_enable_write_tools_desc'] = 'Allows AI agents to create and modify course content, activities, question bank items, and completion criteria.';
$string['setting_enable_grade_tools'] = 'Enable grading tools';
$string['setting_enable_grade_tools_desc'] = 'Allows AI agents to assist with submission grading and rubric assessments.';
$string['setting_enable_messaging_tool'] = 'Enable student messaging tool';
$string['setting_enable_messaging_tool_desc'] = 'Allows AI agents to send direct messages to students on behalf of the teacher.';
$string['setting_enable_delete_tool'] = 'Enable activity deletion tool';
$string['setting_enable_delete_tool_desc'] = 'Allows AI agents to request deletion of course activities. When disabled, the delete_activity tool cannot be executed.';
$string['setting_delete_requires_ui_confirmation'] = 'Require browser UI confirmation for activity deletion';
$string['setting_delete_requires_ui_confirmation_desc'] = 'When enabled (recommended), AI agents cannot autonomously delete activities. They must provide the teacher with a link to confirm the deletion manually in Moodle.';
$string['confirm_delete_title'] = 'Confirm Activity Deletion';
$string['confirm_delete_heading'] = 'Permanently Delete Activity';
$string['confirm_delete_warning'] = 'You are about to permanently delete this activity. All associated student submissions, grades, and completion records will be removed. This action cannot be undone unless the recycle bin is enabled.';
$string['confirm_delete_button'] = 'Permanently Delete Activity';
$string['activity_name'] = 'Activity';
$string['activity_type'] = 'Activity type';
$string['recyclebin_status'] = 'Recycle bin';
$string['recyclebin_active_notice'] = 'Course recycle bin is ACTIVE. Deleted activities can be restored within the retention period.';
$string['recyclebin_inactive_warning'] = 'Course recycle bin is NOT active. This activity and all its data will be permanently destroyed.';
$string['activity_deleted_success'] = 'Activity "{$a}" has been permanently deleted.';
$string['delete_requires_ui_confirmation_desc'] = 'Automated deletion by AI agents is blocked by site policy. The teacher must confirm this deletion manually using the confirmation URL.';
$string['error_tool_disabled'] = 'Tool "{$a}" is disabled by the site administrator.';
$string['error_tool_not_permitted'] = 'Tool "{$a}" is not permitted for this agent token.';
$string['error_tool_not_found'] = 'Tool "{$a}" not found.';
$string['error_group_access_denied'] = 'You do not have access to this group or separate groups mode restricts viewing this group.';

$string['external_tool_grants'] = 'External MCP tool grants';
$string['external_tool_grants_desc'] = 'Select each independently provided tool this token may use. These grants are in addition to the built-in Teacher MCP tools.';

// Privacy metadata strings (M5).
$string['privacy:metadata:tool_teachermcp_tokens'] = 'Authentication tokens issued for teacher AI agent connections.';
$string['privacy:metadata:tool_teachermcp_tokens:userid'] = 'The ID of the user who owns the token.';
$string['privacy:metadata:tool_teachermcp_tokens:name'] = 'A descriptive label or name for the token.';
$string['privacy:metadata:tool_teachermcp_tokens:clientid'] = 'The OAuth client ID that requested or owns the token.';
$string['privacy:metadata:tool_teachermcp_tokens:tokenlastchars'] = 'The final eight characters used to identify the token.';
$string['privacy:metadata:tool_teachermcp_tokens:lastip'] = 'The IP address from which the token was last used.';
$string['privacy:metadata:tool_teachermcp_tokens:lastaccess'] = 'The timestamp of the most recent API access with this token.';
$string['privacy:metadata:tool_teachermcp_tokens:timeexpires'] = 'The time at which the token expires.';
$string['privacy:metadata:tool_teachermcp_tokens:timecreated'] = 'The time when the token was generated.';
$string['privacy:metadata:tool_teachermcp_tokens:timemodified'] = 'The time when the token was last modified.';
$string['privacy:metadata:tool_teachermcp_tokens:status'] = 'Whether the token is active or revoked.';

$string['privacy:metadata:tool_teachermcp_oauth_codes'] = 'Temporary authorization codes issued during OAuth 2.0 PKCE authentication.';
$string['privacy:metadata:tool_teachermcp_oauth_codes:clientid'] = 'The OAuth client ID requesting authorization.';
$string['privacy:metadata:tool_teachermcp_oauth_codes:userid'] = 'The user granting authorization to the client.';
$string['privacy:metadata:tool_teachermcp_oauth_codes:redirecturi'] = 'The redirect URI used during the authorization request.';
$string['privacy:metadata:tool_teachermcp_oauth_codes:scope'] = 'The permission scopes requested by the client.';
$string['privacy:metadata:tool_teachermcp_oauth_codes:timecreated'] = 'The time when the authorization code was issued.';
$string['privacy:metadata:tool_teachermcp_oauth_codes:timeexpires'] = 'The expiration time of the authorization code.';

$string['privacy:metadata:course_assets'] = 'Course asset files uploaded by the user via MCP tools.';
$string['privacy:metadata:tool_teachermcp_assets'] = 'Tracks files created by Teacher MCP so that they can be exported and deleted correctly.';
$string['privacy:metadata:tool_teachermcp_assets:userid'] = 'The user who uploaded the asset.';
$string['privacy:metadata:tool_teachermcp_assets:contextid'] = 'The Moodle context containing the asset.';
$string['privacy:metadata:tool_teachermcp_assets:fileid'] = 'The Moodle file record for the asset.';
$string['privacy:metadata:tool_teachermcp_assets:timecreated'] = 'The time when the asset was created.';

// Privacy export subcontext labels.
$string['tokens'] = 'Access tokens';
$string['oauth_codes'] = 'OAuth authorization codes';
$string['course_assets'] = 'Course assets';
$string['revoked'] = 'Revoked';

// Token role presets and MCP resources.
$string['scope_preset'] = 'Permission Role Preset';
$string['scope_preset_help'] = 'Presets restrict MCP actions; Moodle permissions and site settings still apply. Read-only access includes completion reports.';
$string['scope_preset_full'] = 'Full Co-Pilot (Read, Author, Grade, Messages, Delete)';
$string['scope_preset_grading'] = 'Grading Assistant (Read submissions, Post grades & feedback)';
$string['scope_preset_curriculum'] = 'Curriculum Designer (Read materials, Create pages, quizzes & activities)';
$string['scope_preset_readonly'] = 'Read-Only Observer / Tutor (Ingest syllabus & course contents only)';
$string['mcp_resources_title'] = 'MCP Read-Only Dynamic Resources';
$string['mcp_resources_desc'] = 'Standardized read-only URIs that AI agents can inspect to obtain real-time course structure, announcements, and evaluation rubrics.';
$string['prompt_cat_communication'] = 'Course Communication';
$string['prompt_badge_communication'] = 'Forums & Messages';
$string['prompt_cat_calendar'] = 'Course Scheduling';
$string['prompt_badge_calendar'] = 'Calendar & Deadlines';
