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

$string['dashboard_title'] = 'Teacher AI assistant';
$string['dashboard_desc'] = 'Connect an AI assistant, choose a course task, or manage its access to Moodle.';
$string['dashboard_sections'] = 'Dashboard sections';
$string['dashboard_connect'] = 'Connect';
$string['dashboard_tasks'] = 'Use with a course';
$string['dashboard_connections'] = 'Manage connections';
$string['other_connection_methods'] = 'Other connection methods';
$string['other_connection_methods_desc'] = 'For local bridge or manually configured HTTP clients. Claude and ChatGPT remote connections above do not need a manually generated token.';
$string['technical_reference'] = 'Technical reference';
$string['use_course'] = 'Use course';
$string['preview_prompt'] = 'Preview prompt';
$string['dashboard_course_unavailable'] = 'The requested course is not available to your teaching account. Choose a course below; no task has been prepared for the unavailable course.';
$string['dashboard_no_courses'] = 'No teaching courses are available to this account. You can still connect your assistant and manage connections.';
$string['workspace_setup'] = 'Workspace access and owner setup';
$string['official_setup_guide'] = 'Current official setup guide';
$string['copy_connection_check'] = 'Copy connection-check prompt';
$string['preview_connection_check'] = 'Preview the read-only check';
$string['connection_help'] = 'Connection requirements and troubleshooting';
$string['connection_refresh_help'] = 'After a plugin update, refresh its tools in the AI app and review or publish changes if your workspace requires it. Start a new chat. If the desktop app still shows old definitions, fully quit and reopen it, then check again; do not delete unrelated connections or clear credentials.';
$string['connection_expiry_help'] = 'If access expires, reconnect and sign in to Moodle again. This server does not issue refresh tokens. Revoke unwanted access from Manage connections below.';
$string['connection_check_prompt'] = 'Use the Moodle connection to call get_my_profile and get_teacher_courses. Tell me which Moodle account is connected and which teaching courses it can access. Do not create, edit, grade, message or delete anything. Report any missing tools or authorization errors instead of assuming the connection works.';
$string['resource_uri'] = 'Resource URI';
$string['resource_name'] = 'Resource';

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
$string['active_tools_count'] = '{$a} tools enabled by site policy; this is not a live connection check.';
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
$string['confirm_revoke'] = 'Are you sure you want to revoke this agent connection? The connected client will immediately lose access.';
$string['back_to_course'] = 'Return to course';
$string['course_filter_label'] = 'Course';
$string['course_filter_all'] = 'Select a course to personalize example prompts';

// Guides
$string['how_to_connect'] = 'Connect your AI assistant';
$string['claude_desktop_title'] = 'Local bridge: manual bearer token';
$string['claude_desktop_desc'] = 'Create a token with the required permission preset, then use this configuration. Replace YOUR_BEARER_TOKEN_HERE if no new token is shown. Keep the token private; this mode does not use OAuth.';
$string['codex_cursor_title'] = 'HTTP client configuration';
$string['codex_cursor_desc'] = 'For clients that support this HTTP MCP endpoint and a manually configured bearer header:';
$string['remote_app_intro'] = 'Choose your app and sign in with your Moodle account. No local installation or manually generated token is needed.';
$string['remote_app_requirements'] = 'This Moodle site must be reachable by the app over public HTTPS. Ask your administrator to enable OAuth client registration if needed. Enter your Moodle password only on the Moodle sign-in page, never in chat or a client-secret field.';
$string['remote_app_cached'] = 'An existing authorization may skip sign-in or consent. Verify access with an actual get_my_profile call; a tool list alone is not proof of a working connection.';
$string['copy_endpoint'] = 'Copy server URL';
$string['open_claude_connectors'] = 'Open Claude connectors';
$string['open_chatgpt_plugins'] = 'Open ChatGPT plugins';
$string['open_claude_app'] = 'Open Claude app';
$string['open_chatgpt_app'] = 'Open ChatGPT app';
$string['claude_app_link_help'] = 'The desktop button opens a chat, not settings. Open Customize > Connectors there, or use the web button.';
$string['chatgpt_app_link_help'] = 'The desktop button opens a chat, not plugin settings. Open Plugins there, or use the web button.';
$string['cli_setup_title'] = 'Claude Code and Codex CLI';
$string['cli_setup_desc'] = 'Both CLIs can launch local stdio MCP servers. Register the downloaded bridge as the server command, using the Node command and arguments shown above. These JSON examples are for desktop clients, not CLI syntax; follow the CLI documentation for registration. CLI-specific end-to-end setup has not been verified here.';
$string['claude_cli_docs'] = 'Claude Code CLI setup';
$string['codex_cli_docs'] = 'Codex CLI setup';
$string['http_config_cli_note'] = 'This JSON example is for compatible HTTP clients such as Cursor. Codex CLI uses config.toml or codex mcp add instead. See Local bridge for CLI documentation.';
$string['claude_app_title'] = 'Connect the Claude app';
$string['claude_app_step1'] = 'For a personal connector, open Customize > Connectors > + Add > Add custom connector. In a workspace, use an owner-added connector or follow the owner setup below.';
$string['claude_app_step2'] = 'Enter a name and the server URL above, then Continue. Review the detected authentication settings and choose Continue again.';
$string['claude_app_step3'] = 'Choose OAuth / Sign in now and Register automatically. Leave request headers empty. Do not choose Claude\'s published identity for this server.';
$string['claude_app_step4'] = 'Select Add, then Connect if shown. If prompted, sign in to Moodle with your teacher account and review the requested permissions.';
$string['claude_app_step5'] = 'Start a new chat and enable Moodle under + > Connectors. Paste the connection-check prompt below to verify access.';
$string['claude_app_workspace'] = 'Team owners, and authorised Enterprise owners or custom roles, add the server through Organization settings > Connectors > Add > Custom > Web. Enter the name and URL, continue through authentication review, and choose Sign in now with Register automatically. Members then open Customize > Connectors and select Connect for their own Moodle account.';
$string['chatgpt_title'] = 'Connect ChatGPT with a remote MCP server';
$string['chatgpt_step1'] = 'Open Plugins in ChatGPT web. Select + > Add custom MCP server.';
$string['chatgpt_step2'] = 'Enter a name and paste the server URL shown above under Connection.';
$string['chatgpt_step3'] = 'Choose OAuth and dynamic client registration (DCR) if offered. Do not select CIMD, enter a Moodle token as a client secret, or disable authentication.';
$string['chatgpt_step4'] = 'Review the warning and select Create as a plugin. Complete Moodle sign-in and consent if prompted, then review the discovered tools and install the plugin.';
$string['chatgpt_step5'] = 'Start a new chat, type @ and select the plugin. Paste the connection-check prompt below. Select the plugin again when a follow-up needs another tool call.';
$string['chatgpt_remote_access'] = 'Availability and read/write access depend on your plan and workspace permissions. A workspace owner or authorised administrator may need to create, approve or publish the connection. Check the current official requirements below. Set up on the web; use the desktop app only if it exposes the connection.';

// MCP Tool descriptions.
$string['tool_get_my_profile_desc'] = 'Get current teacher user profile, capabilities, and system preferences.';
$string['tool_get_teacher_courses_desc'] = 'List all courses where the authenticated teacher has teaching or editing roles.';
$string['tool_export_course_learning_material_desc'] = 'Extract complete substantive learning material (Page bodies, Book chapters, H5P interactives, Lesson texts, Glossary terms, Assignment briefs, and attached source files) as Markdown for LLM context.';
$string['tool_get_course_contents_desc'] = 'Retrieve sections, activities, quizzes, assignments, and resources for a specific course.';
$string['tool_scaffold_assessment_unit_desc'] = 'Assemble end-to-end quiz including questions, answer options, quiz module settings, and section placement.';
$string['tool_detect_at_risk_students_desc'] = 'Compute early-warning risk index based on days inactive, overdue assignments, failing grades, and missing completions.';
$string['tool_analyze_class_understanding_desc'] = 'Analyze class performance, question difficulty (facility index), error patterns, and learning gaps on a completed quiz.';
$string['tool_prepare_grading_session_desc'] = 'Prepare batch grading session with submitted texts, file attachments metadata, rubric criteria, and student context.';
$string['tool_manage_activity_desc'] = 'Inspect basic activity settings; edit Page names/descriptions or change visibility for Page, Assignment, Quiz, H5P and Book. Other settings require Moodle.';
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
$string['flag_low_grade_threshold'] = 'Course grade {$a->grade} is below the applicable warning threshold {$a->threshold}; see the native grade range and percentage.';
$string['flag_incomplete_activities'] = '{$a} required activities incomplete';
$string['flag_analytics_risk'] = 'High dropout risk predicted by Moodle Analytics ({$a}%)';
$string['error_unauthorized'] = 'Unauthorized access: valid session or active agent bearer token required.';
$string['error_invalid_scope'] = 'The requested OAuth scope is not supported.';
$string['error_remote_image_fetch_disabled'] = 'Remote image URLs are disabled. Provide file_content_base64 instead.';
$string['setting_max_mcp_request_bytes'] = 'Maximum request size (bytes)';
$string['setting_max_mcp_request_bytes_desc'] = 'Limits incoming requests, including file uploads. 1 MiB = 1048576 bytes. Supported range: 1024 to 10485760 bytes.';
$string['setting_max_mcp_batch_size'] = 'Maximum MCP batch size';
$string['setting_max_mcp_batch_size_desc'] = 'Number of calls accepted in a single batch, from 1 to 50.';
$string['setting_max_mcp_response_bytes'] = 'Maximum response size (bytes)';
$string['setting_max_mcp_response_bytes_desc'] = 'Larger tool results are truncated with a notice. 256 KiB = 262144 bytes. Supported range: 1024 to 5242880 bytes.';
$string['setting_mcp_requests_per_minute'] = 'MCP requests per minute';
$string['setting_mcp_requests_per_minute_desc'] = 'Requests allowed per minute for each token and source IP address, from 1 to 600. Further requests are temporarily rejected.';

// Prompt Library
$string['prompt_library_title'] = 'Course prompt examples';
$string['prompt_library_desc'] = 'Choose a task, preview or copy its prompt, and paste it into your connected assistant. Review the proposed details before making changes.';
$string['prompt_supplied_arguments'] = 'Use these supplied task details; ask for any remaining requirements: {$a}';
$string['prompt_cat_context'] = 'Understand course material';
$string['prompt_cat_assessment'] = 'Prepare assessments';
$string['prompt_cat_analytics'] = 'Review student progress';
$string['prompt_cat_grading'] = 'Prepare grading';

$string['prompt_ingest_title'] = 'Summarise course material';
$string['prompt_ingest_desc'] = 'Summarises accessible material and identifies drafts, restrictions and extraction limits.';
$string['prompt_ingest_text'] = 'Use "export_course_learning_material" for course ID {$a->courseid} ("{$a->coursename}"). Summarise five key concepts from the material actually returned. Keep hidden-content and availability labels, and report unsupported or truncated material. This is a teacher view, not proof of student access or permanent AI memory.';

$string['prompt_files_title'] = 'Review a course document';
$string['prompt_files_desc'] = 'Reads a chosen supported document and proposes questions grounded in its extracted text.';
$string['prompt_files_text'] = 'Find the document I want to review in course ID {$a->courseid} using "get_course_contents". Ask which document if unclear. Read it with "read_activity_file" or "export_course_learning_material", report any unsupported or incomplete extraction, and propose five questions grounded only in the returned text.';

$string['prompt_scaffold_title'] = 'Draft a quiz';
$string['prompt_scaffold_desc'] = 'Creates a hidden quiz after confirming the topic, questions and destination.';
$string['prompt_scaffold_text'] = 'For course ID {$a->courseid}, inspect the available sections with "get_course_contents". Ask for the topic, question count and target section if I have not supplied them. Use "scaffold_assessment_unit" to create a quiz with feedback grounded in the course material. Keep it hidden and give me its link for review.';

$string['prompt_assignment_title'] = 'Draft an assignment';
$string['prompt_assignment_desc'] = 'Creates instructions and submission settings; optional criteria are text, not a native rubric.';
$string['prompt_assignment_text'] = 'Prepare an assignment for course ID {$a->courseid}. Inspect sections with "get_course_contents" and ask for the topic, destination, due date and submission requirements if unspecified. Use "create_assignment" with at least one submission type enabled. Put agreed assessment descriptors in the introduction, not a claimed native rubric. Keep the assignment hidden for review.';
$string['prompt_calendar_title'] = 'Schedule a course event';
$string['prompt_calendar_desc'] = 'Adds an event after checking its date, time zone and duration.';
$string['prompt_calendar_text'] = 'Help schedule an event in course ID {$a->courseid}. Ask for any missing title, date, time, time zone and duration. Summarise the details for confirmation, then use "manage_course_calendar" with action="create_event". Do not invent dates or times.';

$string['prompt_atrisk_title'] = 'Review students who may need support';
$string['prompt_atrisk_desc'] = 'Calculates risk signals from inactivity, overdue tasks, and failing scores.';
$string['prompt_atrisk_text'] = 'Run "detect_at_risk_students" for course ID {$a->courseid}. Explain which students have inactivity, missing work or low-grade signals, treating the score as a heuristic rather than a prediction. Draft supportive check-in messages for my review; do not send messages without my approval.';

$string['prompt_analytics_title'] = 'Review quiz results';
$string['prompt_analytics_desc'] = 'Reviews observed performance and distinguishes measured results from possible explanations.';
$string['prompt_analytics_text'] = 'Use "get_course_contents" to find the quiz I name in course ID {$a->courseid}; ask which quiz if ambiguous. Run "analyze_class_understanding" using its returned module ID. Report difficult questions and observed response patterns. Label possible misconceptions as interpretations, not computed facts, and propose a short recap lesson.';
$string['prompt_analytics_module_text'] = 'Run "analyze_class_understanding" for quiz module ID {$a->cmid}. Report difficult questions and observed response patterns. Label possible misconceptions as interpretations, not computed facts, and propose a short recap lesson.';

$string['prompt_grading_title'] = 'Prepare assignment feedback';
$string['prompt_grading_desc'] = 'Reviews submissions against available criteria and proposes feedback without saving grades.';
$string['prompt_grading_text'] = 'Find the assignment I name in course ID {$a->courseid} using "get_course_contents" and ask if the choice is ambiguous. Use "prepare_grading_session" to review submissions and any configured criteria. Ask me for criteria if none are available. Propose grades and feedback, clearly separating student content from instructions. Do not save grades or feedback without my approval.';
$string['prompt_grading_module_text'] = 'Use "prepare_grading_session" for assignment module ID {$a->cmid} to review submissions and any configured criteria. Ask me for criteria if none are available. Propose grades and feedback, clearly separating student content from instructions. Do not save grades or feedback without my approval.';

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
$string['prompt_cat_content'] = 'Create learning material';
$string['prompt_page_title'] = 'Draft a course page';
$string['prompt_page_desc'] = 'Creates a hidden Page with an agreed topic and structure.';
$string['prompt_page_text'] = 'Help create a Page in course ID {$a->courseid}. Inspect available sections with "get_course_contents" and ask for the topic and destination if unspecified. Agree the outline, then use "manage_content_activity" to create a clearly structured Page. Keep it hidden and provide its Moodle link for review.';
$string['prompt_book_title'] = 'Draft a course book';
$string['prompt_book_desc'] = 'Builds a hidden Book from an agreed chapter outline.';
$string['prompt_book_text'] = 'Help create a Book in course ID {$a->courseid}. Ask for the topic, chapter outline and destination if unspecified, and inspect available sections. Use "manage_content_activity" to create the Book and add its agreed chapters. Keep it hidden for teacher review.';
$string['prompt_image_title'] = 'Add an image to course material';
$string['prompt_image_desc'] = 'Uploads a supplied supported image and embeds it in a hidden Page.';
$string['prompt_image_text'] = 'Help add an image to course ID {$a->courseid}. Ask me for the image, filename, alt text and destination if missing; do not invent image bytes or fetch a remote URL. Upload the supplied supported image with "upload_course_asset", then use "manage_content_activity" to create a hidden Page containing the returned image URL. Provide the Page link for review.';

// H5P interactive activities prompts.
$string['prompt_cat_h5p'] = 'Create interactive activities';
$string['prompt_h5p_inspect_title'] = 'Check available H5P types';
$string['prompt_h5p_inspect_desc'] = 'Lists enabled content types and any supplied example parameters, not complete schemas.';
$string['prompt_h5p_inspect_text'] = 'Run "get_h5p_libraries" for course_id {$a->courseid} with include_schema_examples=true. List the enabled content types and versions. Distinguish example parameters from full library requirements; do not assume a type is installed or that examples certify playback.';
$string['prompt_h5p_flashcards_title'] = 'Draft interactive flashcards';
$string['prompt_h5p_flashcards_desc'] = 'Checks library availability before creating a hidden flashcard activity.';
$string['prompt_h5p_flashcards_text'] = 'For course ID {$a->courseid}, first use "get_h5p_libraries" to check whether H5P.Flashcards is enabled. If unavailable, report that and ask before choosing another type. Ask for the topic, cards and target section if missing. Use valid parameters for the installed library with "create_h5p_activity" and keep the activity hidden. Provide its link for a teacher playback check before publication.';
$string['prompt_h5p_quiz_title'] = 'Draft an interactive question';
$string['prompt_h5p_quiz_desc'] = 'Checks H5P availability before creating a hidden multiple-choice activity.';
$string['prompt_h5p_quiz_text'] = 'For course ID {$a->courseid}, first check H5P.MultiChoice with "get_h5p_libraries". If unavailable, ask before choosing an alternative. Confirm the topic, answers, feedback and target section. Use "create_h5p_activity" with valid parameters for the installed library, keep it hidden and provide a playback link. Packaging success alone does not prove the interaction works.';

// Course maintenance and safety prompts.
$string['prompt_cat_maintenance'] = 'Maintain a course';
$string['prompt_delete_title'] = 'Review an activity for deletion';
$string['prompt_delete_desc'] = 'Previews the target and consequences; deletion requires explicit confirmation.';
$string['prompt_delete_text'] = 'Find the activity I name in course ID {$a->courseid} using "get_course_contents". Ask which one if ambiguous. Obtain a preview with "delete_activity" and show its name, course and consequences. Do not delete until I explicitly confirm. Then use the Moodle confirmation page or the preview-issued confirm_token when browser confirmation is disabled.';
$string['prompt_delete_module_text'] = 'Obtain a preview with "delete_activity" for activity module ID {$a->cmid} and show its name, course and consequences. Do not delete until I explicitly confirm. Then use the Moodle confirmation page or the preview-issued confirm_token when browser confirmation is disabled.';

// Agent Skill UI strings.
$string['agent_skill_title'] = 'Optional agent usage guide';
$string['agent_skill_subtitle'] = 'Copy or download this tool and safety guide if your client accepts additional instructions. It is not required to authorise a connection.';
$string['copy_skill_prompt'] = 'Copy Agent Instructions';
$string['download_skill_file'] = 'Download Skill (.md)';
$string['tab_agent_instructions'] = 'Agent instructions';

// Admin settings and security messages.
$string['manage_tokens'] = 'Manage Agent Connections';
$string['settings_title'] = 'Teacher MCP settings';
$string['settings_intro'] = 'These site-wide rules apply to AI connections. Teacher permissions and individual connection grants still apply.';
$string['settings_actions'] = 'Allowed actions';
$string['settings_deletion'] = 'Deletion safeguards';
$string['settings_connections'] = 'Connections';
$string['settings_advanced'] = 'Advanced settings';
$string['settings_advanced_intro'] = 'Technical limits and integration options. Keep the defaults unless your deployment needs different limits.';
$string['settings_integrations'] = 'Integration options';
$string['setting_integer_minimum'] = 'Enter a whole number of at least {$a}.';
$string['setting_integer_range'] = 'Enter a whole number from {$a->minimum} to {$a->maximum}.';
$string['setting_message_limit_inactive'] = 'Currently inactive: student messaging is disabled on the main settings page.';
$string['connection_history'] = 'Connection history';
$string['connection_history_help'] = 'Expired and revoked connections no longer grant access.';
$string['connection_details'] = 'Access details';
$string['registration_disabled_notice'] = 'Registration of new AI apps is disabled on this site. If your app asks to register automatically, ask your administrator to enable AI app registration first. Already registered apps and the local bridge can still connect.';
$string['connection_expires'] = 'Expires';
$string['connection_granted_actions'] = 'Granted actions';
$string['connection_no_grants'] = 'No grants for currently registered tools.';
$string['connection_grants_help'] = 'Shown for tools currently registered on this site. Site settings and Moodle permissions still apply. Expired or revoked connections cannot be used.';
$string['status_expired'] = 'Expired';
$string['clear_history'] = 'Clear inactive history';
$string['clear_history_confirm_own'] = 'Remove {$a} expired or revoked connections from your history? Active connections and Moodle audit logs will not be changed.';
$string['clear_history_confirm_all'] = 'Remove {$a} expired or revoked connections from history across this site? Active connections and Moodle audit logs will not be changed.';
$string['clear_history_empty'] = 'There is no inactive connection history to clear.';
$string['clear_history_success'] = '{$a} inactive connection records removed.';
$string['event_token_history_cleared'] = 'Inactive Teacher MCP connection history cleared';
$string['no_active_connections'] = 'No active agent connections.';
$string['setting_max_token_validity'] = 'Maximum token validity (days)';
$string['setting_max_token_validity_desc'] = 'Longest lifetime for a newly issued connection token. Enter at least 1 day; existing tokens keep their expiry dates.';
$string['setting_oauth_registration_enabled'] = 'Allow AI app registration';
$string['setting_oauth_registration_enabled_desc'] = 'Enable when connecting an AI app that registers itself through OAuth. Turning this off does not remove existing client registrations.';
$string['setting_max_oauth_tokens_per_client'] = 'Maximum active OAuth tokens per client';
$string['setting_max_oauth_tokens_per_client_desc'] = 'Connections allowed for each user and OAuth app. Issuing a new token at the limit revokes the oldest active token.';
$string['setting_message_ratelimit_per_hour'] = 'Direct message rate limit (per hour)';
$string['setting_message_ratelimit_per_hour_desc'] = 'Messages allowed per hour for each agent token. Applies only when student messaging is enabled.';
$string['setting_allowed_cors_origins'] = 'Allowed browser origins (CORS)';
$string['setting_allowed_cors_origins_desc'] = 'Leave empty for server-to-server connections. For browser-based integrations, enter each allowed origin on its own line, for example https://learning.example.org.';
$string['setting_allow_delete_questions'] = 'Allow question deletion';
$string['setting_allow_delete_questions_desc'] = 'Allow question-bank deletion through MCP, subject to teacher permissions and a confirmation token. Requires authoring tools.';
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
$string['error_activity_settings_restricted'] = 'Cannot apply these settings to module {$a}. Pages support name, description and visibility changes. Assignment, Quiz, H5P and Book support visibility-only updates: send only cmid, action and visible. Use Moodle for other settings.';
$string['h5p_no_matching_libraries'] = 'No enabled, runnable H5P libraries match this filter. Remove or change library_filter to inspect available content types.';
$string['h5p_no_available_libraries'] = 'No enabled, runnable H5P libraries are available on this site. Visit Site Administration > H5P > Manage H5P content types to install or enable content types.';
$string['error_item_not_found'] = 'Item not found.';
$string['error_invalid_params'] = 'Invalid parameters: {$a}';
$string['error_assignment_submission_required'] = 'At least one submission type must be enabled: online text or file uploads. Offline/oral assignments are not currently supported by this tool.';
$string['error_grade_locked'] = 'Grade item is locked.';
$string['error_feedback_files_disabled'] = 'Feedback files are disabled or unavailable for this assignment. Enable Feedback files in Moodle before attaching files. No grade, feedback or files were changed.';
$string['error_feedback_files_invalid'] = 'Feedback files must be a list of unique, safe filenames and valid base64 content. Do not use paths, duplicate names or missing fields. The entire grading request was rejected.';
$string['error_feedback_file_too_large'] = 'A feedback file exceeds the applicable upload limit of {$a} bytes. The entire grading request was rejected.';
$string['error_feedback_files_not_saved'] = 'Moodle did not persist every requested feedback file in the expected grade file area. The grading operation was rolled back; verify that this assignment supports ordinary feedback files.';
$string['error_grade_overridden'] = 'The gradebook grade is overridden. No assignment grade or feedback was changed. Review the override in Moodle before regrading.';
$string['error_grade_not_saved'] = 'Moodle did not persist the requested assignment grade for every affected student. The grading operation was rolled back. Check grading workflow and marker permissions.';
$string['warning_gradebook_differs'] = 'The assignment grade and effective gradebook grade differ, or a gradebook override is active. Inspect the returned gradebook values; do not assume the student-facing grade changed.';
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
$string['setting_enable_external_tools_desc'] = 'Allow tools supplied by other installed Moodle plugins. Each provider must also be enabled in its own settings.';
$string['setting_enable_write_tools'] = 'Enable authoring and write tools';
$string['setting_enable_write_tools_desc'] = 'Allow course authoring tools, including activity settings and question-bank tools. Turning this off also hides their read actions.';
$string['setting_enable_grade_tools'] = 'Enable grading tools';
$string['setting_enable_grade_tools_desc'] = 'Allow submission review, grading preparation and grade updates within the teacher\'s Moodle permissions.';
$string['setting_enable_messaging_tool'] = 'Enable student messaging tool';
$string['setting_enable_messaging_tool_desc'] = 'Allows AI agents to send direct messages to students on behalf of the teacher.';
$string['setting_enable_delete_tool'] = 'Enable activity deletion tool';
$string['setting_enable_delete_tool_desc'] = 'Allows AI agents to request deletion of course activities. When disabled, the delete_activity tool cannot be executed.';
$string['setting_delete_requires_ui_confirmation'] = 'Confirm activity deletion in Moodle';
$string['setting_delete_requires_ui_confirmation_desc'] = 'Recommended: the teacher confirms deletion on a Moodle page. When off, the tool still requires a preview and a short-lived confirmation token.';
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
$string['scope_preset'] = 'Allowed actions';
$string['scope_preset_help'] = 'Presets restrict MCP actions; Moodle permissions and site settings still apply. Read-only access includes completion reports.';
$string['scope_preset_full'] = 'All standard tools';
$string['scope_preset_grading'] = 'Read and grade';
$string['scope_preset_curriculum'] = 'Read and author course content';
$string['scope_preset_readonly'] = 'Read only';
$string['mcp_resources_title'] = 'MCP Read-Only Dynamic Resources';
$string['mcp_resources_desc'] = 'Standardized read-only URIs that AI agents can inspect to obtain real-time course structure, announcements, and evaluation rubrics.';
$string['prompt_cat_communication'] = 'Course Communication';
$string['prompt_cat_calendar'] = 'Schedule course events';
