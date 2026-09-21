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
 * Post-installation actions for tool_teachermcp.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute tool_teachermcp post-installation actions.
 */
function xmldb_tool_teachermcp_install() {
    global $DB;

    // Seed default public client for Claude Desktop (RFC 8252 loopback client).
    if (!$DB->record_exists('tool_teachermcp_oauth_clients', ['clientid' => 'claude-desktop'])) {
        $record = (object)[
            'clientid' => 'claude-desktop',
            'clientsecret' => null,
            'clientname' => 'Claude Desktop (Local AI Assistant)',
            'redirecturis' => json_encode([
                ['uri' => 'http://127.0.0.1/callback', 'loopback' => true],
            ]),
            'isloopback' => 1,
            'isverified' => 1,
            'timecreated' => time(),
        ];
        $DB->insert_record('tool_teachermcp_oauth_clients', $record);
    }
}
