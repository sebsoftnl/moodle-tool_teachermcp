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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Confirm removal of inactive connection history without revoking active access.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_login();

$allusers = optional_param('allusers', false, PARAM_BOOL);
$courseid = optional_param('courseid', 0, PARAM_INT);
if ($allusers) {
    admin_externalpage_setup('tool_teachermcp_manage');
}
$count = \tool_teachermcp\local\token_history::count($allusers);
$pageurl = new moodle_url('/admin/tool/teachermcp/clear_history.php', ['allusers' => (int)$allusers]);
$returnurl = new moodle_url('/admin/tool/teachermcp/' . ($allusers ? 'index.php' : 'my_agent.php'));
if (!$allusers && $courseid) {
    $pageurl->param('courseid', $courseid);
    $returnurl->param('courseid', $courseid);
}

if (data_submitted()) {
    require_sesskey();
    if (required_param('confirm', PARAM_BOOL)) {
        $removed = \tool_teachermcp\local\token_history::clear($allusers);
        redirect(
            $returnurl,
            get_string('clear_history_success', 'tool_teachermcp', $removed),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

if (!$allusers) {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_pagelayout('report');
}
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('clear_history', 'tool_teachermcp'));
$PAGE->set_heading(get_string('clear_history', 'tool_teachermcp'));
echo $OUTPUT->header();
if ($count) {
    $confirmurl = new moodle_url($pageurl, ['confirm' => 1]);
    $button = new single_button($confirmurl, get_string('clear_history', 'tool_teachermcp'), 'post');
    echo $OUTPUT->confirm(get_string(
        $allusers ? 'clear_history_confirm_all' : 'clear_history_confirm_own',
        'tool_teachermcp',
        $count
    ), $button, $returnurl);
} else {
    echo $OUTPUT->notification(get_string('clear_history_empty', 'tool_teachermcp'), 'info');
    echo $OUTPUT->single_button($returnurl, get_string('continue'), 'get');
}
echo $OUTPUT->footer();
