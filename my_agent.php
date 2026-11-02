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
 * Teacher Self-Service Dashboard for AI Agent MCP connection.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();

// H1: Verify user has connectagent capability in system or at least one course.
if (!\tool_teachermcp\auth::user_can_connect($USER->id)) {
    throw new moodle_exception('error_no_capability', 'tool_teachermcp');
}

$requestedcourseid = optional_param('courseid', 0, PARAM_INT);
$coursecontext = \tool_teachermcp\local\dashboard_courses::get($requestedcourseid);
$selectedcourse = $coursecontext['selected'];
$courseid = $selectedcourse['id'] ?? 0;
$client = optional_param('client', 'claude', PARAM_ALPHA);
if (!in_array($client, ['claude', 'chatgpt'], true)) {
    $client = 'claude';
}
$action = optional_param('action', '', PARAM_ALPHA);
$tokenid = optional_param('tokenid', 0, PARAM_INT);

$pageurl = new moodle_url('/admin/tool/teachermcp/my_agent.php');
if ($courseid) {
    $pageurl->param('courseid', $courseid);
}
if ($client === 'chatgpt') {
    $pageurl->param('client', $client);
}

$PAGE->set_url($pageurl);
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('dashboard_title', 'tool_teachermcp'));
$PAGE->set_heading(fullname($USER));
$PAGE->navbar->ignore_active();
$PAGE->navbar->add(get_string('preferences'), new moodle_url('/user/preferences.php'));
$PAGE->navbar->add(get_string('dashboard_title', 'tool_teachermcp'));

$newtoken = null;
$maxvalidity = (int)get_config('tool_teachermcp', 'max_token_validity');
if ($maxvalidity <= 0) {
    $maxvalidity = 90;
}
$validityoptions = [];
$standardvalidities = [30, 90, 180, 365];
foreach ($standardvalidities as $period) {
    $days = min($period, $maxvalidity);
    $labelkey = in_array($days, $standardvalidities, true) ? 'validity_' . $days : 'validity_days';
    $validityoptions[$days] = [
        'value' => $days,
        'label' => get_string($labelkey, 'tool_teachermcp', $days),
        'selected' => $days === min(90, $maxvalidity),
    ];
}

// Handle actions.
if ($action === 'create' && confirm_sesskey()) {
    $agentname = optional_param('agentname', '', PARAM_TEXT);
    if (empty($agentname)) {
        $agentname = 'My AI Agent (' . userdate(time(), '%d %b %Y') . ')';
    }
    $validity = optional_param('validity', 90, PARAM_INT);
    if ($validity <= 0 || $validity > $maxvalidity) {
        \core\notification::error(get_string('token_validity_error', 'tool_teachermcp', $maxvalidity));
    } else {
        $scopepreset = optional_param('scope_preset', 'full', PARAM_ALPHA);
        $permissions = [];
        switch ($scopepreset) {
            case 'grading':
                $permissions['scopes'] = ['read', 'grade'];
                break;
            case 'curriculum':
                $permissions['scopes'] = ['read', 'write'];
                break;
            case 'readonly':
                $permissions['scopes'] = ['read'];
                break;
            case 'full':
            default:
                $permissions['all_teacher_tools'] = true;
                break;
        }

        $requestedexternaltools = optional_param_array('externaltools', [], PARAM_RAW_TRIMMED);
        $grantableexternaltools = \tool_teachermcp\server::get_grantable_external_tool_names();
        $selectedexternaltools = [];
        foreach ($requestedexternaltools as $toolname) {
            if (is_string($toolname) && in_array($toolname, $grantableexternaltools, true)) {
                $selectedexternaltools[$toolname] = true;
            }
        }
        if (!empty($selectedexternaltools)) {
            $permissions = array_merge($permissions, $selectedexternaltools);
        }

        $newtoken = \tool_teachermcp\auth::generate_token($USER->id, $agentname, $validity, null, $permissions);
        \core\notification::success(get_string('token_created', 'tool_teachermcp'));
    }
} else if ($action === 'revoke' && confirm_sesskey() && $tokenid) {
    if (\tool_teachermcp\auth::revoke_token($tokenid, $USER->id)) {
        \core\notification::info(get_string('token_revoked', 'tool_teachermcp'));
    } else {
        \core\notification::error(get_string('token_revoke_failed', 'tool_teachermcp'));
    }
    redirect($pageurl);
}

$tokens = \tool_teachermcp\auth::get_user_tokens($USER->id);
$tokenlist = [];
$historylist = [];
$now = time();
$sampletoken = $newtoken ?: 'YOUR_BEARER_TOKEN_HERE';

foreach ($tokens as $t) {
    $active = \tool_teachermcp\local\token_history::is_active($t, $now);
    $connection = [
        'id' => (int)$t->id,
        'name' => $t->name,
        'lastchars' => $t->tokenlastchars,
        'status_active' => $active,
        'status_label' => get_string('status_' . \tool_teachermcp\local\token_history::status($t, $now), 'tool_teachermcp'),
        'lastaccess' => $t->lastaccess ? userdate($t->lastaccess) : get_string('never', 'tool_teachermcp'),
        'validuntil' => $t->timeexpires ? userdate($t->timeexpires) : get_string('never', 'tool_teachermcp'),
        'revokelink' => (new moodle_url($pageurl, [
            'action' => 'revoke', 'tokenid' => $t->id, 'sesskey' => sesskey(),
        ]))->out(false),
    ];
    if ($active) {
        $tokenlist[] = $connection;
    } else {
        $historylist[] = $connection;
    }
}

$endpointurl = $CFG->wwwroot . '/admin/tool/teachermcp/endpoint.php';

$downloadbridgeurl = (new moodle_url('/admin/tool/teachermcp/download.php', ['type' => 'bridge']))->out(false);
$bridgepathsample = get_string('bridge_path_placeholder', 'tool_teachermcp');

$currentlang = current_language();
$langparam = '--lang=' . clean_param($currentlang, PARAM_SAFEDIR);

// Prepare client configurations.
$claudedesktopoauthconfig = json_encode([
    'mcpServers' => [
        'moodle' => [
            'command' => 'node',
            'args' => [
                $bridgepathsample,
                $endpointurl,
                '--oauth',
                $langparam,
            ],
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$claudedesktopconfig = json_encode([
    'mcpServers' => [
        'moodle' => [
            'command' => 'node',
            'args' => [
                $bridgepathsample,
                $endpointurl,
                $sampletoken,
            ],
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$codexcursorconfig = json_encode([
    'mcpServers' => [
        'moodle' => [
            'url' => $endpointurl,
            'headers' => [
                'Authorization' => 'Bearer ' . $sampletoken,
            ],
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$oauthmetadata = \tool_teachermcp\oauth_server::get_metadata();

$courseoptions = [];
foreach ($coursecontext['courses'] as $course) {
    $courseoptions[] = [
        'id' => $course['id'],
        'fullname' => $course['fullname'],
        'selected' => $course['id'] === $courseid,
    ];
}

$prompta = (object)[
    'courseid' => $courseid,
    'coursename' => $selectedcourse['fullname'] ?? '',
];

$server = new \tool_teachermcp\server();
$activetoolscount = count($server->get_tools());
$externaltooloptions = [];
foreach (\tool_teachermcp\server::get_grantable_external_tool_names() as $externaltoolname) {
    $tool = $server->get_tools()[$externaltoolname] ?? null;
    if ($tool === null) {
        continue;
    }
    $externaltooloptions[] = [
        'name' => $externaltoolname,
        'description' => $tool->get_description(),
    ];
}

$promptlibrary = [];
if ($selectedcourse !== null) {
    foreach ([
        'context' => ['ingest', 'files'],
        'content' => ['page', 'book', 'image'],
        'assessment' => ['scaffold', 'assignment'],
        'h5p' => ['h5p_inspect', 'h5p_flashcards', 'h5p_quiz'],
        'analytics' => ['atrisk', 'analytics'],
        'grading' => ['grading'],
        'calendar' => ['calendar'],
        'maintenance' => ['delete'],
    ] as $category => $names) {
        $items = [];
        foreach ($names as $name) {
            $items[] = [
                'id' => 'prompt-' . $name,
                'title' => get_string('prompt_' . $name . '_title', 'tool_teachermcp'),
                'description' => get_string('prompt_' . $name . '_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_' . $name . '_text', 'tool_teachermcp', $prompta),
            ];
        }
        $promptlibrary[] = ['category' => get_string('prompt_cat_' . $category, 'tool_teachermcp'),
            'id' => 'prompts-' . $category, 'items' => $items, 'count' => count($items)];
    }
}
$courseformurl = new moodle_url('/admin/tool/teachermcp/my_agent.php');
$courseformurl->set_anchor('teacher-prompts');

$downloadskillurl = (new moodle_url('/admin/tool/teachermcp/download.php', ['type' => 'skill']))->out(false);
$agentsmdpath = __DIR__ . '/AGENTS.md';
$systempromptcontent = file_exists($agentsmdpath) ? file_get_contents($agentsmdpath) : '';

echo $OUTPUT->header();

$templatedata = [
    'validity_options' => array_values($validityoptions),
    'selected_client' => $client,
    'client_claude' => $client === 'claude',
    'client_chatgpt' => $client === 'chatgpt',
    'manual_open' => $action === 'create',
    'manual_form_url' => $pageurl->out(false),
    'agent_name_placeholder' => get_string('agent_name_help', 'tool_teachermcp'),
    'course_form_url' => $courseformurl->out(false),
    'selected_course' => $selectedcourse,
    'invalid_course' => $requestedcourseid !== 0 && $selectedcourse === null,
    'connection_check_prompt' => get_string('connection_check_prompt', 'tool_teachermcp'),
    'endpoint_url' => $endpointurl,
    'download_bridge_url' => $downloadbridgeurl,
    'download_skill_url' => $downloadskillurl,
    'system_prompt_content' => $systempromptcontent,
    'has_tokens' => !empty($tokenlist),
    'tokens' => $tokenlist,
    'show_actions' => true,
    'history' => $historylist ? ['tokens' => $historylist, 'count' => count($historylist), 'show_actions' => false] : null,
    'clear_history_url' => (new moodle_url('/admin/tool/teachermcp/clear_history.php',
        $courseid ? ['courseid' => $courseid] : []))->out(false),
    'sesskey' => sesskey(),
    'new_token' => $newtoken,
    'config_claude_oauth' => $claudedesktopoauthconfig,
    'config_claude' => $claudedesktopconfig,
    'config_codex' => $codexcursorconfig,
    'oauth_auth_url' => $oauthmetadata['authorization_endpoint'],
    'oauth_token_url' => $oauthmetadata['token_endpoint'],
    'oauth_meta_url' => $CFG->wwwroot . '/admin/tool/teachermcp/oauth/metadata.php',
    'return_url' => $courseid ? new moodle_url('/course/view.php', ['id' => $courseid]) : null,
    'prompt_categories' => $promptlibrary,
    'has_courses' => !empty($courseoptions),
    'courses' => $courseoptions,
    'has_external_tools' => !empty($externaltooloptions),
    'external_tools' => $externaltooloptions,
    'tools_count_badge' => get_string('active_tools_count', 'tool_teachermcp', $activetoolscount),
    'selected_course_id' => $courseid,
    'mcp_resources' => \tool_teachermcp\local\resource_registry::get_resources(),
    'mcp_resource_templates' => \tool_teachermcp\local\resource_registry::get_templates(),
];

echo $OUTPUT->render_from_template('tool_teachermcp/my_agent', $templatedata);

echo $OUTPUT->footer();
