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
 * Dedicated UI confirmation page for teacher manual deletion of an activity (N3).
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

$cmid = required_param('cmid', PARAM_INT);

list($course, $cm) = get_course_and_cm_from_cmid($cmid);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('moodle/course:manageactivities', $context);

$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
$pageurl = new moodle_url('/admin/tool/teachermcp/confirm_delete.php', ['cmid' => $cmid]);

// Cancel action.
if (optional_param('cancel', 0, PARAM_BOOL)) {
    redirect($courseurl);
}

$modname = $cm->modname;
$modulenameformatted = get_string('pluginname', $modname);
$activitytitle = $cm->name;

// Handle POST confirmation.
if (data_submitted() && confirm_sesskey()) {
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        course_delete_module($cm->id);
        rebuild_course_cache($course->id, true);

        \core\notification::success(get_string('activity_deleted_success', 'tool_teachermcp', $activitytitle));
        redirect($courseurl);
    }
}

// Page setup.
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('confirm_delete_title', 'tool_teachermcp'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

$recyclebinenabled = class_exists('\tool_recyclebin\course_bin') && \tool_recyclebin\course_bin::is_enabled();

// Boost UI rendering.
echo html_writer::start_div('container-fluid my-4');
echo html_writer::start_div('row justify-content-center');
echo html_writer::start_div('col-md-8');

echo html_writer::start_div('card border-danger shadow-sm');
echo html_writer::start_div('card-header bg-danger text-white');
echo html_writer::tag('h4', get_string('confirm_delete_heading', 'tool_teachermcp'), ['class' => 'mb-0']);
echo html_writer::end_div();

echo html_writer::start_div('card-body');

// Warning alert.
echo html_writer::div(
    get_string('confirm_delete_warning', 'tool_teachermcp'),
    'alert alert-danger'
);

// Recycle bin status alert.
if ($recyclebinenabled) {
    echo html_writer::div(
        get_string('recyclebin_active_notice', 'tool_teachermcp'),
        'alert alert-info'
    );
} else {
    echo html_writer::div(
        get_string('recyclebin_inactive_warning', 'tool_teachermcp'),
        'alert alert-warning'
    );
}

// Activity details table.
echo html_writer::start_tag('dl', ['class' => 'row mt-3 mb-4']);

echo html_writer::tag('dt', get_string('activity_name', 'tool_teachermcp'), ['class' => 'col-sm-3']);
echo html_writer::tag('dd', s($activitytitle), ['class' => 'col-sm-9 font-weight-bold']);

echo html_writer::tag('dt', get_string('activity_type', 'tool_teachermcp'), ['class' => 'col-sm-3']);
echo html_writer::tag('dd', s($modulenameformatted) . ' (' . s($modname) . ')', ['class' => 'col-sm-9']);

echo html_writer::tag('dt', get_string('course'), ['class' => 'col-sm-3']);
echo html_writer::tag('dd', s($course->fullname), ['class' => 'col-sm-9']);

echo html_writer::tag('dt', get_string('recyclebin_status', 'tool_teachermcp'), ['class' => 'col-sm-3']);
echo html_writer::tag(
    'dd',
    $recyclebinenabled
        ? html_writer::tag('span', get_string('enabled', 'core'), ['class' => 'badge badge-success'])
        : html_writer::tag('span', get_string('disabled', 'core'), ['class' => 'badge badge-secondary']),
    ['class' => 'col-sm-9']
);

echo html_writer::end_tag('dl');

// Confirmation Form.
echo html_writer::start_tag('form', ['action' => $pageurl->out(false), 'method' => 'post', 'class' => 'd-flex justify-content-between']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'value' => (int)$cm->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => '1']);

echo html_writer::tag(
    'a',
    get_string('cancel'),
    ['href' => $courseurl->out(false), 'class' => 'btn btn-secondary']
);

echo html_writer::tag(
    'button',
    get_string('confirm_delete_button', 'tool_teachermcp'),
    ['type' => 'submit', 'class' => 'btn btn-danger font-weight-bold']
);

echo html_writer::end_tag('form');

echo html_writer::end_div(); // card-body.
echo html_writer::end_div(); // card.
echo html_writer::end_div(); // col-md-8.
echo html_writer::end_div(); // row.
echo html_writer::end_div(); // container-fluid.

echo $OUTPUT->footer();
