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
    $settings = new admin_settingpage('tool_teachermcp', get_string('pluginname', 'tool_teachermcp'));

    // M4: Maximum token validity in days (default 90).
    $settings->add(new admin_setting_configtext(
        'tool_teachermcp/max_token_validity',
        get_string('setting_max_token_validity', 'tool_teachermcp'),
        get_string('setting_max_token_validity_desc', 'tool_teachermcp'),
        90,
        PARAM_INT
    ));

    // K3-R7 & M4: Enable/disable dynamic OAuth client registration (default 0, B7).
    $settings->add(new admin_setting_configcheckbox(
        'tool_teachermcp/oauth_registration_enabled',
        get_string('setting_oauth_registration_enabled', 'tool_teachermcp'),
        get_string('setting_oauth_registration_enabled_desc', 'tool_teachermcp'),
        0
    ));

    // B11: Maximum active OAuth tokens per client per user (default 5).
    $settings->add(new admin_setting_configtext(
        'tool_teachermcp/max_oauth_tokens_per_client',
        get_string('setting_max_oauth_tokens_per_client', 'tool_teachermcp'),
        get_string('setting_max_oauth_tokens_per_client_desc', 'tool_teachermcp'),
        5,
        PARAM_INT
    ));

    // M3: Message rate limit per hour per user/token.
    $settings->add(new admin_setting_configtext(
        'tool_teachermcp/message_ratelimit_per_hour',
        get_string('setting_message_ratelimit_per_hour', 'tool_teachermcp'),
        get_string('setting_message_ratelimit_per_hour_desc', 'tool_teachermcp'),
        50,
        PARAM_INT
    ));

    // L2: CORS allowed origins.
    $settings->add(new admin_setting_configtextarea(
        'tool_teachermcp/allowed_cors_origins',
        get_string('setting_allowed_cors_origins', 'tool_teachermcp'),
        get_string('setting_allowed_cors_origins_desc', 'tool_teachermcp'),
        '',
        PARAM_RAW
    ));

    // M6: Maximum accepted JSON-RPC request size, in bytes.
    $settings->add(new admin_setting_configtext(
        'tool_teachermcp/max_mcp_request_bytes',
        get_string('setting_max_mcp_request_bytes', 'tool_teachermcp'),
        get_string('setting_max_mcp_request_bytes_desc', 'tool_teachermcp'),
        1048576,
        PARAM_INT
    ));

    // M6: Maximum JSON-RPC batch size.
    $settings->add(new admin_setting_configtext(
        'tool_teachermcp/max_mcp_batch_size',
        get_string('setting_max_mcp_batch_size', 'tool_teachermcp'),
        get_string('setting_max_mcp_batch_size_desc', 'tool_teachermcp'),
        10,
        PARAM_INT
    ));

    // M6: Maximum returned MCP tool response size, in bytes.
    $settings->add(new admin_setting_configtext(
        'tool_teachermcp/max_mcp_response_bytes',
        get_string('setting_max_mcp_response_bytes', 'tool_teachermcp'),
        get_string('setting_max_mcp_response_bytes_desc', 'tool_teachermcp'),
        262144,
        PARAM_INT
    ));

    // M6: Maximum authenticated MCP requests per token and IP address per minute.
    $settings->add(new admin_setting_configtext(
        'tool_teachermcp/mcp_requests_per_minute',
        get_string('setting_mcp_requests_per_minute', 'tool_teachermcp'),
        get_string('setting_mcp_requests_per_minute_desc', 'tool_teachermcp'),
        60,
        PARAM_INT
    ));

    // External providers are disabled until a site administrator explicitly opts in.
    $settings->add(new admin_setting_configcheckbox(
        'tool_teachermcp/enable_external_tools',
        get_string('setting_enable_external_tools', 'tool_teachermcp'),
        get_string('setting_enable_external_tools_desc', 'tool_teachermcp'),
        0
    ));

    // N3: Allow deleting questions via MCP tools (default 0).
    $settings->add(new admin_setting_configcheckbox(
        'tool_teachermcp/allow_delete_questions',
        get_string('setting_allow_delete_questions', 'tool_teachermcp'),
        get_string('setting_allow_delete_questions_desc', 'tool_teachermcp'),
        0
    ));

    // N6: Enable write tools (default 1).
    $settings->add(new admin_setting_configcheckbox(
        'tool_teachermcp/enable_write_tools',
        get_string('setting_enable_write_tools', 'tool_teachermcp'),
        get_string('setting_enable_write_tools_desc', 'tool_teachermcp'),
        1
    ));

    // N6: Enable grade tools (default 1).
    $settings->add(new admin_setting_configcheckbox(
        'tool_teachermcp/enable_grade_tools',
        get_string('setting_enable_grade_tools', 'tool_teachermcp'),
        get_string('setting_enable_grade_tools_desc', 'tool_teachermcp'),
        1
    ));

    // N6: Enable messaging tool (default 1).
    $settings->add(new admin_setting_configcheckbox(
        'tool_teachermcp/enable_messaging_tool',
        get_string('setting_enable_messaging_tool', 'tool_teachermcp'),
        get_string('setting_enable_messaging_tool_desc', 'tool_teachermcp'),
        1
    ));

    // N6: Enable delete tool (default 1).
    $settings->add(new admin_setting_configcheckbox(
        'tool_teachermcp/enable_delete_tool',
        get_string('setting_enable_delete_tool', 'tool_teachermcp'),
        get_string('setting_enable_delete_tool_desc', 'tool_teachermcp'),
        1
    ));

    // N3: Delete activity requires UI confirmation (default 1).
    $settings->add(new admin_setting_configcheckbox(
        'tool_teachermcp/delete_requires_ui_confirmation',
        get_string('setting_delete_requires_ui_confirmation', 'tool_teachermcp'),
        get_string('setting_delete_requires_ui_confirmation_desc', 'tool_teachermcp'),
        1
    ));

    $ADMIN->add('tools', $settings);

    $ADMIN->add('tools', new admin_externalpage(
        'tool_teachermcp_manage',
        get_string('manage_tokens', 'tool_teachermcp'),
        new moodle_url('/admin/tool/teachermcp/index.php')
    ));
}
