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
    \tool_teachermcp\auth::revoke_token($tokenid, $USER->id);
    \core\notification::info(get_string('token_revoked', 'tool_teachermcp'));
    redirect(new moodle_url('/admin/tool/teachermcp/index.php'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'tool_teachermcp'));

// Summary metrics.
$totaltokens = $DB->count_records('tool_teachermcp_tokens');
$activetokens = $DB->count_records('tool_teachermcp_tokens', ['status' => 1]);

echo html_writer::start_div('row mb-4');
echo html_writer::start_div('col-md-4');
echo html_writer::div(
    html_writer::tag('h3', $activetokens, ['class' => 'text-success font-weight-bold mb-0']) .
    html_writer::tag('small', get_string('stat_active_tokens', 'tool_teachermcp'), ['class' => 'text-muted text-uppercase']),
    'card card-body text-center shadow-sm'
);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4');
echo html_writer::div(
    html_writer::tag('h3', $totaltokens, ['class' => 'text-primary font-weight-bold mb-0']) .
    html_writer::tag('small', get_string('stat_total_tokens', 'tool_teachermcp'), ['class' => 'text-muted text-uppercase']),
    'card card-body text-center shadow-sm'
);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4');
$myurl = new moodle_url('/admin/tool/teachermcp/my_agent.php');
echo html_writer::div(
    html_writer::link($myurl, '<i class="fa fa-user-circle mr-1"></i> ' . get_string('btn_teacher_dashboard', 'tool_teachermcp'), ['class' => 'btn btn-outline-primary btn-block my-2']),
    'card card-body text-center shadow-sm justify-content-center'
);
echo html_writer::end_div();
echo html_writer::end_div();

// L7: Retrieve user name fields using \core_user\fields.
$userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false);

$alltokens = $DB->get_records_sql("
    SELECT t.*, {$userfields->selects}
      FROM {tool_teachermcp_tokens} t
      JOIN {user} u ON u.id = t.userid
  ORDER BY t.timecreated DESC
");

if ($alltokens) {
    $table = new html_table();
    $table->head = [
        get_string('col_teacher', 'tool_teachermcp'),
        get_string('col_agent_name', 'tool_teachermcp'),
        get_string('col_token', 'tool_teachermcp'),
        get_string('col_created', 'tool_teachermcp'),
        get_string('col_last_active', 'tool_teachermcp'),
        get_string('col_status', 'tool_teachermcp'),
        get_string('col_actions', 'tool_teachermcp'),
    ];
    $table->attributes['class'] = 'table table-hover table-striped shadow-sm';

    foreach ($alltokens as $t) {
        $userlink = html_writer::link(new moodle_url('/user/profile.php', ['id' => $t->userid]), fullname($t));
        $statusbadge = ($t->status == 1)
            ? html_writer::span(get_string('status_active', 'tool_teachermcp'), 'badge badge-success')
            : html_writer::span(get_string('status_revoked', 'tool_teachermcp'), 'badge badge-secondary');

        $revokelink = '';
        if ($t->status == 1) {
            $rurl = new moodle_url('/admin/tool/teachermcp/index.php', ['action' => 'revoke', 'tokenid' => $t->id, 'sesskey' => sesskey()]);
            $revokelink = html_writer::link(
                $rurl,
                '<i class="fa fa-ban"></i> ' . get_string('btn_revoke', 'tool_teachermcp'),
                [
                    'class' => 'btn btn-sm btn-outline-danger',
                    'onclick' => "return confirm('" . addslashes_js(get_string('confirm_revoke', 'tool_teachermcp')) . "');",
                ]
            );
        }

        $table->data[] = [
            $userlink,
            s($t->name),
            '<code>...' . s($t->tokenlastchars) . '</code>',
            userdate($t->timecreated),
            $t->lastaccess ? userdate($t->lastaccess) : get_string('never', 'tool_teachermcp'),
            $statusbadge,
            $revokelink,
        ];
    }

    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('no_tokens_site', 'tool_teachermcp'), 'info');
}

echo $OUTPUT->footer();
