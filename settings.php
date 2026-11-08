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
 * Settings configuration for tool_teachermcp.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $category = 'tool_teachermcp_category';
    $ADMIN->add('tools', new admin_category($category, get_string('pluginname', 'tool_teachermcp')));
    $settings = new admin_settingpage('tool_teachermcp', get_string('settings_title', 'tool_teachermcp'));
    $advanced = new admin_settingpage('tool_teachermcp_advanced', get_string('settings_advanced', 'tool_teachermcp'));
    $settings->add(new admin_setting_heading(
        'tool_teachermcp/settings_intro',
        '',
        get_string('settings_intro', 'tool_teachermcp') . html_writer::div(
            html_writer::link(new moodle_url('/admin/tool/teachermcp/index.php'), get_string('manage_tokens', 'tool_teachermcp')) .
            html_writer::link(
                new moodle_url('/admin/settings.php', ['section' => 'tool_teachermcp_advanced']),
                get_string('settings_advanced', 'tool_teachermcp')
            ),
            'tool_teachermcp_actions d-flex flex-wrap'
        )
    ));
    $settings->add(new admin_setting_heading(
        'tool_teachermcp/settings_actions',
        get_string('settings_actions', 'tool_teachermcp'),
        ''
    ));
    foreach (['enable_write_tools', 'enable_grade_tools', 'enable_messaging_tool'] as $name) {
        $settings->add(new admin_setting_configcheckbox(
            'tool_teachermcp/' . $name,
            get_string('setting_' . $name, 'tool_teachermcp'),
            get_string('setting_' . $name . '_desc', 'tool_teachermcp'),
            1
        ));
    }
    $settings->add(new admin_setting_heading(
        'tool_teachermcp/settings_deletion',
        get_string('settings_deletion', 'tool_teachermcp'),
        ''
    ));
    foreach (['allow_delete_questions' => 0, 'enable_delete_tool' => 1, 'delete_requires_ui_confirmation' => 1] as $name => $default) {
        $settings->add(new admin_setting_configcheckbox(
            'tool_teachermcp/' . $name,
            get_string('setting_' . $name, 'tool_teachermcp'),
            get_string('setting_' . $name . '_desc', 'tool_teachermcp'),
            $default
        ));
    }
    $settings->hide_if('tool_teachermcp/allow_delete_questions', 'tool_teachermcp/enable_write_tools');
    $settings->hide_if('tool_teachermcp/delete_requires_ui_confirmation', 'tool_teachermcp/enable_delete_tool');
    $settings->add(new admin_setting_heading(
        'tool_teachermcp/settings_connections',
        get_string('settings_connections', 'tool_teachermcp'),
        ''
    ));
    $settings->add(new admin_setting_configcheckbox(
        'tool_teachermcp/oauth_registration_enabled',
        get_string('setting_oauth_registration_enabled', 'tool_teachermcp'),
        get_string('setting_oauth_registration_enabled_desc', 'tool_teachermcp'),
        0
    ));

    foreach (['max_token_validity' => 90, 'max_oauth_tokens_per_client' => 5] as $name => $default) {
        $settings->add(new \tool_teachermcp\local\bounded_integer(
            'tool_teachermcp/' . $name,
            get_string('setting_' . $name, 'tool_teachermcp'),
            get_string('setting_' . $name . '_desc', 'tool_teachermcp'),
            $default,
            1
        ));
    }

    $advanced->add(new admin_setting_heading(
        'tool_teachermcp/advanced_intro',
        '',
        get_string('settings_advanced_intro', 'tool_teachermcp') . html_writer::div(
            html_writer::link(
                new moodle_url('/admin/settings.php', ['section' => 'tool_teachermcp']),
                get_string('settings_title', 'tool_teachermcp')
            )
        )
    ));
    foreach (
        [
        'message_ratelimit_per_hour' => [50, 1, null],
        'mcp_requests_per_minute' => [60, 1, 600],
        'max_mcp_request_bytes' => [1048576, 1024, 10485760],
        'max_mcp_response_bytes' => [262144, 1024, 5242880],
        'max_mcp_batch_size' => [10, 1, 50],
        ] as $name => [$default, $minimum, $maximum]
    ) {
        $description = get_string('setting_' . $name . '_desc', 'tool_teachermcp');
        if ($name === 'message_ratelimit_per_hour' && (string)get_config('tool_teachermcp', 'enable_messaging_tool') === '0') {
            $description .= ' ' . get_string('setting_message_limit_inactive', 'tool_teachermcp');
        }
        $advanced->add(new \tool_teachermcp\local\bounded_integer(
            'tool_teachermcp/' . $name,
            get_string('setting_' . $name, 'tool_teachermcp'),
            $description,
            $default,
            $minimum,
            $maximum
        ));
    }
    $advanced->add(new admin_setting_heading(
        'tool_teachermcp/settings_integrations',
        get_string('settings_integrations', 'tool_teachermcp'),
        ''
    ));
    $advanced->add(new admin_setting_configtextarea(
        'tool_teachermcp/allowed_cors_origins',
        get_string('setting_allowed_cors_origins', 'tool_teachermcp'),
        get_string('setting_allowed_cors_origins_desc', 'tool_teachermcp'),
        '',
        PARAM_RAW,
        60,
        3
    ));
    $advanced->add(new admin_setting_configcheckbox(
        'tool_teachermcp/enable_external_tools',
        get_string('setting_enable_external_tools', 'tool_teachermcp'),
        get_string('setting_enable_external_tools_desc', 'tool_teachermcp'),
        0
    ));

    $ADMIN->add($category, $settings);
    $ADMIN->add($category, $advanced);
    $ADMIN->add($category, new admin_externalpage(
        'tool_teachermcp_manage',
        get_string('manage_tokens', 'tool_teachermcp'),
        new moodle_url('/admin/tool/teachermcp/index.php')
    ));
}
