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
 * Download handler for Teacher MCP bridge script and agent skill.
 * Note: File downloads via fileid are deprecated and disallowed in favor of core moodle_url::make_pluginfile_url.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

$type = optional_param('type', '', PARAM_ALPHA);

// K2: Reject any token query parameter explicitly. Tokens in URL query parameters are forbidden.
if (isset($_GET['token'])) {
    throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
}

// Serve the Node.js bridge script if requested.
if ($type === 'bridge') {
    require_login();
    if (!\tool_teachermcp\auth::user_can_connect($USER->id)) {
        throw new moodle_exception('error_no_capability', 'tool_teachermcp');
    }

    $bridgepath = __DIR__ . '/bridge/mcp-bridge.mjs';
    if (file_exists($bridgepath)) {
        $content = file_get_contents($bridgepath);

        // Inject active user language strings directly from Moodle AMOS translations.
        $keys = [
            'missing_endpoint' => 'bridge_missing_endpoint',
            'usage' => 'bridge_usage',
            'auth_failed' => 'bridge_auth_failed',
            'invalid_code' => 'bridge_invalid_code',
            'auth_success' => 'bridge_auth_success',
            'connected_desc' => 'bridge_connected_desc',
            'close_tab' => 'bridge_close_tab',
            'token_fetch_error' => 'bridge_token_fetch_error',
            'browser_opening' => 'bridge_browser_opening',
            'auth_error' => 'bridge_auth_error',
            'comm_error' => 'bridge_comm_error',
        ];

        $strings = [];
        foreach ($keys as $shortkey => $stringkey) {
            $strings[$shortkey] = get_string($stringkey, 'tool_teachermcp');
        }

        $replacement = json_encode($strings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $content = preg_replace(
            '/\/\* __MOODLE_I18N_INJECT_START__ \*\/.*?\/\* __MOODLE_I18N_INJECT_END__ \*\//s',
            $replacement,
            $content
        );

        header('Content-Type: application/javascript; charset=utf-8');
        header('Content-Disposition: attachment; filename="mcp-bridge.mjs"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit(0);
    }
    throw new moodle_exception('filenotfound', 'error');
}

// Serve the Agent Skill markdown documentation.
if ($type === 'skill' || $type === 'agents') {
    require_login();
    if (!\tool_teachermcp\auth::user_can_connect($USER->id)) {
        throw new moodle_exception('error_no_capability', 'tool_teachermcp');
    }

    $docpath = __DIR__ . '/AGENTS.md';
    if (file_exists($docpath)) {
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="moodle_teacher_mcp_skill.md"');
        header('Content-Length: ' . filesize($docpath));
        readfile($docpath);
        exit(0);
    }
    throw new moodle_exception('filenotfound', 'error');
}

// K1: Direct stored_file serving by fileid is retired in favor of core pluginfile.php URLs.
throw new moodle_exception('filenotfound', 'error');
