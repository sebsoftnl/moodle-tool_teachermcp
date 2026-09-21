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
 * OAuth 2.0 / 2.1 Token Endpoint for Teacher MCP clients (RFC 6749, RFC 7636).
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
header('Cache-Control: no-store');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'error' => 'invalid_request',
        'error_description' => 'Only POST requests are allowed on the token endpoint.',
    ]);
    exit(0);
}

// Read POST payload (support application/x-www-form-urlencoded and application/json).
$postbody = file_get_contents('php://input');
$jsondata = json_decode($postbody, true) ?: [];

$granttype = $_POST['grant_type'] ?? ($jsondata['grant_type'] ?? '');
$code = $_POST['code'] ?? ($jsondata['code'] ?? '');
$redirecturi = $_POST['redirect_uri'] ?? ($jsondata['redirect_uri'] ?? '');
$clientid = $_POST['client_id'] ?? ($jsondata['client_id'] ?? '');
$codeverifier = $_POST['code_verifier'] ?? ($jsondata['code_verifier'] ?? null);

// K3-R9: RFC 6749 error validation.
if ($granttype !== 'authorization_code') {
    http_response_code(400);
    echo json_encode([
        'error' => 'unsupported_grant_type',
        'error_description' => 'Grant type must be authorization_code.',
    ]);
    exit(0);
}

if (empty($code) || empty($clientid) || empty($redirecturi)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'invalid_request',
        'error_description' => 'Missing code, client_id, or redirect_uri parameter.',
    ]);
    exit(0);
}

try {
    $tokenresponse = \tool_teachermcp\oauth_server::exchange_code_for_token(
        $code,
        $clientid,
        $redirecturi,
        $codeverifier
    );

    http_response_code(200);
    echo json_encode($tokenresponse, JSON_UNESCAPED_SLASHES);
} catch (\moodle_exception $e) {
    $errorkey = $e->errorcode;
    $httpcode = 400;
    $rfcerror = 'invalid_grant';

    if ($errorkey === 'error_invalid_client') {
        $httpcode = 401;
        $rfcerror = 'invalid_client';
    } else if ($errorkey === 'error_invalid_request') {
        $httpcode = 400;
        $rfcerror = 'invalid_request';
    } else if ($errorkey === 'error_unsupported_grant_type') {
        $httpcode = 400;
        $rfcerror = 'unsupported_grant_type';
    }

    http_response_code($httpcode);
    echo json_encode([
        'error' => $rfcerror,
        'error_description' => $e->getMessage(),
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'server_error',
        'error_description' => 'Internal server error during token generation.',
    ]);
}
