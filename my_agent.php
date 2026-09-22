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

$systemcontext = context_system::instance();

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$tokenid = optional_param('tokenid', 0, PARAM_INT);

$pageurl = new moodle_url('/admin/tool/teachermcp/my_agent.php');
if ($courseid) {
    $pageurl->param('courseid', $courseid);
}

$PAGE->set_url($pageurl);
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('dashboard_title', 'tool_teachermcp'));
$PAGE->set_heading(get_string('dashboard_title', 'tool_teachermcp'));

$newtoken = null;

// Handle actions.
if ($action === 'create' && confirm_sesskey()) {
    $agentname = optional_param('agentname', '', PARAM_TEXT);
    if (empty($agentname)) {
        $agentname = 'My AI Agent (' . userdate(time(), '%d %b %Y') . ')';
    }
    $validity = optional_param('validity', 90, PARAM_INT);
    $maxvalidity = (int)get_config('tool_teachermcp', 'max_token_validity') ?: 90;
    if ($validity <= 0 || $validity > $maxvalidity) {
        throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
    }

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
} else if ($action === 'revoke' && confirm_sesskey() && $tokenid) {
    \tool_teachermcp\auth::revoke_token($tokenid, $USER->id);
    \core\notification::info(get_string('token_revoked', 'tool_teachermcp'));
    redirect($pageurl);
}

// Fetch active tokens.
$tokens = \tool_teachermcp\auth::get_user_tokens($USER->id);
$tokenlist = [];
$sampletoken = $newtoken ?: 'YOUR_BEARER_TOKEN_HERE';

foreach ($tokens as $t) {
    $tokenlist[] = [
        'id' => (int)$t->id,
        'name' => $t->name,
        'lastchars' => $t->tokenlastchars,
        'status_active' => ($t->status == 1),
        'lastaccess' => $t->lastaccess ? userdate($t->lastaccess) : get_string('never', 'tool_teachermcp'),
        'validuntil' => $t->timeexpires ? userdate($t->timeexpires) : get_string('never', 'tool_teachermcp'),
        'revokelink' => new moodle_url($pageurl, ['action' => 'revoke', 'tokenid' => $t->id, 'sesskey' => sesskey()]),
    ];
}

$endpointurl = $CFG->wwwroot . '/admin/tool/teachermcp/endpoint.php';

$downloadbridgeurl = (new moodle_url('/admin/tool/teachermcp/download.php', ['type' => 'bridge']))->out(false);
$bridgepathsample = '<PAD_NAAR>/mcp-bridge.mjs';

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

// Teacher courses for contextualized prompt samples.
$usercourses = enrol_get_all_users_courses($USER->id, true);
$courseoptions = [];
$firstcourseid = $courseid ?: 0;
$firstcoursename = get_string('course');

foreach ($usercourses as $uc) {
    if (!$firstcourseid) {
        $firstcourseid = (int)$uc->id;
        $firstcoursename = $uc->fullname;
    }
    $courseoptions[] = [
        'id' => (int)$uc->id,
        'fullname' => $uc->fullname,
        'selected' => ($courseid && (int)$uc->id === (int)$courseid),
    ];
}

$prompta = (object)[
    'courseid' => $firstcourseid,
    'coursename' => $firstcoursename,
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

$promptlibrary = [
    [
        'category' => get_string('prompt_cat_content', 'tool_teachermcp'),
        'icon' => 'fa-file-alt',
        'badge' => get_string('prompt_badge_content', 'tool_teachermcp'),
        'items' => [
            [
                'title' => get_string('prompt_page_title', 'tool_teachermcp'),
                'description' => get_string('prompt_page_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_page_text', 'tool_teachermcp', $prompta),
            ],
            [
                'title' => get_string('prompt_book_title', 'tool_teachermcp'),
                'description' => get_string('prompt_book_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_book_text', 'tool_teachermcp', $prompta),
            ],
            [
                'title' => get_string('prompt_image_title', 'tool_teachermcp'),
                'description' => get_string('prompt_image_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_image_text', 'tool_teachermcp', $prompta),
            ],
        ],
    ],
    [
        'category' => get_string('prompt_cat_h5p', 'tool_teachermcp'),
        'icon' => 'fa-cubes',
        'badge' => get_string('prompt_badge_h5p', 'tool_teachermcp'),
        'items' => [
            [
                'title' => get_string('prompt_h5p_inspect_title', 'tool_teachermcp'),
                'description' => get_string('prompt_h5p_inspect_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_h5p_inspect_text', 'tool_teachermcp', $prompta),
            ],
            [
                'title' => get_string('prompt_h5p_flashcards_title', 'tool_teachermcp'),
                'description' => get_string('prompt_h5p_flashcards_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_h5p_flashcards_text', 'tool_teachermcp', $prompta),
            ],
            [
                'title' => get_string('prompt_h5p_quiz_title', 'tool_teachermcp'),
                'description' => get_string('prompt_h5p_quiz_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_h5p_quiz_text', 'tool_teachermcp', $prompta),
            ],
        ],
    ],
    [
        'category' => get_string('prompt_cat_context', 'tool_teachermcp'),
        'icon' => 'fa-brain',
        'badge' => get_string('prompt_badge_context', 'tool_teachermcp'),
        'items' => [
            [
                'title' => get_string('prompt_ingest_title', 'tool_teachermcp'),
                'description' => get_string('prompt_ingest_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_ingest_text', 'tool_teachermcp', $prompta),
            ],
            [
                'title' => get_string('prompt_files_title', 'tool_teachermcp'),
                'description' => get_string('prompt_files_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_files_text', 'tool_teachermcp', $prompta),
            ],
        ],
    ],
    [
        'category' => get_string('prompt_cat_assessment', 'tool_teachermcp'),
        'icon' => 'fa-tasks',
        'badge' => get_string('prompt_badge_assessment', 'tool_teachermcp'),
        'items' => [
            [
                'title' => get_string('prompt_scaffold_title', 'tool_teachermcp'),
                'description' => get_string('prompt_scaffold_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_scaffold_text', 'tool_teachermcp', $prompta),
            ],
            [
                'title' => get_string('prompt_assignment_title', 'tool_teachermcp'),
                'description' => get_string('prompt_assignment_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_assignment_text', 'tool_teachermcp', $prompta),
            ],
        ],
    ],

    [
        'category' => get_string('prompt_cat_calendar', 'tool_teachermcp'),
        'icon' => 'fa-calendar-alt',
        'badge' => get_string('prompt_badge_calendar', 'tool_teachermcp'),
        'items' => [
            [
                'title' => get_string('prompt_calendar_title', 'tool_teachermcp'),
                'description' => get_string('prompt_calendar_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_calendar_text', 'tool_teachermcp', $prompta),
            ],
        ],
    ],
    [
        'category' => get_string('prompt_cat_analytics', 'tool_teachermcp'),
        'icon' => 'fa-user-shield',
        'badge' => get_string('prompt_badge_analytics', 'tool_teachermcp'),
        'items' => [
            [
                'title' => get_string('prompt_atrisk_title', 'tool_teachermcp'),
                'description' => get_string('prompt_atrisk_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_atrisk_text', 'tool_teachermcp', $prompta),
            ],
            [
                'title' => get_string('prompt_analytics_title', 'tool_teachermcp'),
                'description' => get_string('prompt_analytics_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_analytics_text', 'tool_teachermcp', $prompta),
            ],
        ],
    ],
    [
        'category' => get_string('prompt_cat_grading', 'tool_teachermcp'),
        'icon' => 'fa-pencil-ruler',
        'badge' => get_string('prompt_badge_grading', 'tool_teachermcp'),
        'items' => [
            [
                'title' => get_string('prompt_grading_title', 'tool_teachermcp'),
                'description' => get_string('prompt_grading_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_grading_text', 'tool_teachermcp', $prompta),
            ],
        ],
    ],
    [
        'category' => get_string('prompt_cat_maintenance', 'tool_teachermcp'),
        'icon' => 'fa-exclamation-triangle',
        'badge' => get_string('prompt_badge_maintenance', 'tool_teachermcp'),
        'items' => [
            [
                'title' => get_string('prompt_delete_title', 'tool_teachermcp'),
                'description' => get_string('prompt_delete_desc', 'tool_teachermcp'),
                'prompt' => get_string('prompt_delete_text', 'tool_teachermcp', $prompta),
            ],
        ],
    ],
];

$downloadskillurl = (new moodle_url('/admin/tool/teachermcp/download.php', ['type' => 'skill']))->out(false);
$agentsmdpath = __DIR__ . '/AGENTS.md';
$systempromptcontent = file_exists($agentsmdpath) ? file_get_contents($agentsmdpath) : '';

echo $OUTPUT->header();

$templatedata = [
    'endpoint_url' => $endpointurl,
    'download_bridge_url' => $downloadbridgeurl,
    'download_skill_url' => $downloadskillurl,
    'system_prompt_content' => $systempromptcontent,
    'has_tokens' => !empty($tokenlist),
    'tokens' => $tokenlist,
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
    'chatgpt_step3_text' => get_string('chatgpt_step3', 'tool_teachermcp', $endpointurl),
    'mcp_resources' => \tool_teachermcp\local\resource_registry::get_resources(),
    'mcp_resource_templates' => \tool_teachermcp\local\resource_registry::get_templates(),
];

echo $OUTPUT->render_from_template('tool_teachermcp/my_agent', $templatedata);

echo $OUTPUT->footer();
