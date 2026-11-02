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
 * Site Administration overview for tool_teachermcp.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('tool_teachermcp_manage');

$systemcontext = context_system::instance();
require_capability('tool/teachermcp:manageall', $systemcontext);

$action = optional_param('action', '', PARAM_ALPHA);
$tokenid = optional_param('tokenid', 0, PARAM_INT);

if ($action === 'revoke' && confirm_sesskey() && $tokenid) {
    if (\tool_teachermcp\auth::revoke_token($tokenid, $USER->id)) {
        \core\notification::info(get_string('token_revoked', 'tool_teachermcp'));
    } else {
        \core\notification::error(get_string('token_revoke_failed', 'tool_teachermcp'));
    }
    redirect(new moodle_url('/admin/tool/teachermcp/index.php'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'tool_teachermcp'));
echo html_writer::start_div('tool_teachermcp_admin container-fluid');

echo html_writer::div(
    html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'tool_teachermcp']),
        get_string('settings_title', 'tool_teachermcp'), ['class' => 'btn btn-secondary']) .
    html_writer::link(new moodle_url('/admin/tool/teachermcp/my_agent.php'),
        get_string('btn_teacher_dashboard', 'tool_teachermcp'), ['class' => 'btn btn-outline-secondary']),
    'tool_teachermcp_actions d-flex flex-wrap mb-4');

$userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false);

$alltokens = $DB->get_records_sql("
    SELECT t.*, {$userfields->selects}
      FROM {tool_teachermcp_tokens} t
      JOIN {user} u ON u.id = t.userid
  ORDER BY t.timecreated DESC
");

$tables = [];
$counts = ['active' => 0, 'history' => 0];
foreach (['active', 'history'] as $kind) {
    $table = new html_table();
    $table->head = [
        get_string('col_teacher', 'tool_teachermcp'),
        get_string('col_agent_name', 'tool_teachermcp'),
        get_string('col_token', 'tool_teachermcp'),
        get_string('col_created', 'tool_teachermcp'),
        get_string('col_last_active', 'tool_teachermcp'),
        get_string('col_status', 'tool_teachermcp'),
    ];
    if ($kind === 'active') {
        $table->head[] = get_string('col_actions', 'tool_teachermcp');
    }
    $table->attributes['class'] = 'table table-hover table-striped';
    $tables[$kind] = $table;
}
$now = time();

foreach ($alltokens as $t) {
    $active = \tool_teachermcp\local\token_history::is_active($t, $now);
    $kind = $active ? 'active' : 'history';
    $counts[$kind]++;
    $userlink = html_writer::link(new moodle_url('/user/profile.php', ['id' => $t->userid]), fullname($t));
    $statusbadge = html_writer::span(
        get_string('status_' . \tool_teachermcp\local\token_history::status($t, $now), 'tool_teachermcp'),
        $active ? 'badge badge-success' : 'badge badge-secondary');

    $row = [
        $userlink,
        s($t->name),
        '<code class="tool_teachermcp_token_suffix">...' . s($t->tokenlastchars) . '</code>',
        userdate($t->timecreated),
        $t->lastaccess ? userdate($t->lastaccess) : get_string('never', 'tool_teachermcp'),
        $statusbadge,
    ];
    if ($active) {
        $rurl = new moodle_url('/admin/tool/teachermcp/index.php', ['action' => 'revoke', 'tokenid' => $t->id, 'sesskey' => sesskey()]);
        $row[] = html_writer::link($rurl,
            '<i class="fa fa-ban" aria-hidden="true"></i> ' . get_string('revoke', 'tool_teachermcp'), [
                'class' => 'btn btn-sm btn-outline-danger',
                'onclick' => "return confirm('" . addslashes_js(get_string('confirm_revoke', 'tool_teachermcp')) . "');",
            ]);
    }
    $tables[$kind]->data[] = $row;
    $detailscell = new html_table_cell($OUTPUT->render_from_template('tool_teachermcp/connection_details',
        \tool_teachermcp\local\connection_details::get($t)));
    $detailscell->colspan = $active ? 7 : 6;
    $detailsrow = new html_table_row([$detailscell]);
    $detailsrow->attributes['class'] = 'tool_teachermcp_details_row';
    $tables[$kind]->data[] = $detailsrow;
}

echo $OUTPUT->heading(get_string('tokens_header', 'tool_teachermcp') . ' (' . $counts['active'] . ')', 3);
if ($tables['active']->data) {
    echo html_writer::div(html_writer::table($tables['active']), 'table-responsive', ['tabindex' => 0]);
} else {
    echo $OUTPUT->notification(get_string('no_active_connections', 'tool_teachermcp'), 'info');
}
if ($tables['history']->data) {
    echo html_writer::start_tag('details', ['class' => 'tool_teachermcp_history mt-4']);
    echo html_writer::tag('summary', get_string('connection_history', 'tool_teachermcp') .
        ' (' . $counts['history'] . ')', ['class' => 'h5']);
    echo html_writer::tag('p', get_string('connection_history_help', 'tool_teachermcp'), ['class' => 'text-muted mt-2']);
    echo html_writer::link(new moodle_url('/admin/tool/teachermcp/clear_history.php', ['allusers' => 1]),
        get_string('clear_history', 'tool_teachermcp'), ['class' => 'btn btn-outline-secondary mb-3']);
    echo html_writer::div(html_writer::table($tables['history']), 'table-responsive', ['tabindex' => 0]);
    echo html_writer::end_tag('details');
}

echo html_writer::end_div(); // tool_teachermcp_admin.
echo $OUTPUT->footer();
