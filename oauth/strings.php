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
 * Returns localized bridge strings in JSON format for mcp-bridge.mjs.
 * Managed and translated directly via standard Moodle AMOS language packs.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../../../config.php');

$lang = optional_param('lang', '', PARAM_SAFEDIR);
if (!empty($lang)) {
    force_current_language($lang);
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

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

echo json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
