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
 * Model Context Protocol (MCP) JSON-RPC HTTP Endpoint for Teachers.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// L2: CORS configuration. Only send CORS headers if allowed origins are configured in settings.
$allowedorigins = trim(get_config('tool_teachermcp', 'allowed_cors_origins') ?: '');
if (!empty($allowedorigins)) {
    $originheader = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!empty($originheader)) {
        $origins = array_map('trim', explode("\n", str_replace("\r", "", $allowedorigins)));
        if (in_array('*', $origins, true) || in_array($originheader, $origins, true)) {
            header('Access-Control-Allow-Origin: ' . $originheader);
            header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Max-Age: 86400');
        }
    }
}

// Handle pre-flight CORS OPTIONS request.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Basic healthcheck / discovery on GET (L1: moodle_release removed for security).
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $oauthmeta = \tool_teachermcp\oauth_server::get_metadata();
    echo json_encode([
        'status' => 'online',
        'server' => 'Moodle Teacher MCP Agent Endpoint',
        'protocol' => 'MCP (JSON-RPC 2.0)',
        'auth' => [
            'type' => 'Bearer / OAuth 2.0 (RFC 7636 PKCE)',
            'authorization_endpoint' => $oauthmeta['authorization_endpoint'],
            'token_endpoint' => $oauthmeta['token_endpoint'],
            'metadata_endpoint' => $CFG->wwwroot . '/admin/tool/teachermcp/oauth/metadata.php',
            'resource_metadata' => $CFG->wwwroot . '/admin/tool/teachermcp/oauth/protected-resource.php',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit(0);
}

// For POST requests, process JSON-RPC.
try {
    // Reject oversized requests before reading the full JSON payload.
    $maxrequestbytes = \tool_teachermcp\server::get_mcp_limit('max_mcp_request_bytes', 1048576, 1024, 10485760);
    $contentlength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentlength > $maxrequestbytes) {
        http_response_code(413);
        echo json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32600, 'message' => 'Request body exceeds the configured size limit.'],
            'id' => null,
        ]);
        exit(0);
    }

    // Authenticate via Bearer token and initialize teacher context.
    $user = \tool_teachermcp\auth::authenticate_request();
    $token = \tool_teachermcp\auth::get_current_token();
    \tool_teachermcp\auth::enforce_mcp_request_rate_limit($token);

    $rawinput = file_get_contents('php://input');
    if (strlen($rawinput) > $maxrequestbytes) {
        http_response_code(413);
        echo json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32600, 'message' => 'Request body exceeds the configured size limit.'],
            'id' => null,
        ]);
        exit(0);
    }
    if (empty($rawinput)) {
        http_response_code(400);
        echo json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32600, 'message' => 'Empty request body.'],
            'id' => null,
        ]);
        exit(0);
    }

    $server = new \tool_teachermcp\server();
    $response = $server->handle_payload($rawinput);

    // M6: Notifications (requests without id) get no response. If single notification or empty batch response, return 202.
    if ($response === null || (is_array($response) && empty($response) && !isset($response['jsonrpc']))) {
        http_response_code(202);
        exit(0);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\moodle_exception $e) {
    http_response_code(401);
    $resourcemeta = $CFG->wwwroot . '/admin/tool/teachermcp/oauth/protected-resource.php';
    header('WWW-Authenticate: Bearer error="invalid_token", resource_metadata="' . $resourcemeta . '"');
    echo json_encode([
        'jsonrpc' => '2.0',
        'error' => [
            'code' => -32000,
            'message' => 'Authentication failed: ' . $e->getMessage(),
        ],
        'id' => null,
    ], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    // Generic error to prevent sensitive data leakage unless debugdeveloper is on.
    $msg = 'Internal server error occurred.';
    if (debugging('', DEBUG_DEVELOPER)) {
        $msg .= ' ' . $e->getMessage();
    }
    echo json_encode([
        'jsonrpc' => '2.0',
        'error' => [
            'code' => -32603,
            'message' => $msg,
        ],
        'id' => null,
    ]);
}
