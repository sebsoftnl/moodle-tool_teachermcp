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
 * OAuth 2.0 Dynamic Client Registration Endpoint (RFC 7591) for Teacher MCP.
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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'error' => 'invalid_request',
        'error_description' => 'Only POST requests are allowed for client registration.',
    ]);
    exit(0);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: [];

$clientname = $payload['client_name'] ?? ($_POST['client_name'] ?? '');
$redirecturis = $payload['redirect_uris'] ?? ($_POST['redirect_uris'] ?? []);
if (is_string($redirecturis)) {
    $redirecturis = [$redirecturis];
}

try {
    $clientinfo = \tool_teachermcp\oauth_server::register_client(
        $clientname,
        $redirecturis,
        getremoteaddr()
    );

    http_response_code(201);
    echo json_encode($clientinfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (\moodle_exception $e) {
    $errcode = $e->errorcode;
    $httpcode = 400;
    if ($errcode === 'error_registration_disabled') {
        $httpcode = 403;
    } else if ($errcode === 'error_rate_limited') {
        $httpcode = 429;
    }

    http_response_code($httpcode);
    echo json_encode([
        'error' => 'invalid_client_metadata',
        'error_description' => $e->getMessage(),
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'server_error',
        'error_description' => 'Internal server error during client registration.',
    ]);
}
