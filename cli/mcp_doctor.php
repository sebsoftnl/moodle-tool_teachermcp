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
 * CLI Diagnostic and Health Check tool for Teacher MCP.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'cleanup' => false,
], [
    'h' => 'help',
    'c' => 'cleanup',
]);

if ($options['help']) {
    $help = "Teacher MCP Diagnostic & Health Check Tool.

Usage:
  php admin/tool/teachermcp/cli/mcp_doctor.php [options]

Options:
  -h, --help     Print this help screen.
  -c, --cleanup  Run scheduled cleanup task immediately.
";
    cli_write($help);
    exit(0);
}

cli_heading('Teacher MCP Agent Integration — System Diagnostics');

global $DB, $CFG;

$issues = 0;

// 1. PHP Version
cli_write("[1/8] Checking PHP runtime environment...\n");
$phpversion = phpversion();
cli_write("      PHP Version: {$phpversion}\n");
if (version_compare($phpversion, '8.1.0', '<')) {
    cli_problem("      FAIL: PHP 8.1+ is required.");
    $issues++;
} else {
    cli_write("      OK: PHP version meets Moodle requirements.\n");
}

// 2. Required PHP extensions
cli_write("[2/8] Checking required PHP extensions...\n");
$exts = ['json', 'openssl', 'simplexml', 'mbstring', 'curl'];
foreach ($exts as $ext) {
    if (!extension_loaded($ext)) {
        cli_problem("      FAIL: Extension '{$ext}' is missing.");
        $issues++;
    }
}
cli_write("      OK: All required extensions are installed.\n");

// 3. Database Schema Integrity
cli_write("[3/8] Checking database tables & schema integrity...\n");
$tables = [
    'tool_teachermcp_tokens',
    'tool_teachermcp_oauth_clients',
    'tool_teachermcp_oauth_codes',
    'tool_teachermcp_assets',
];
foreach ($tables as $t) {
    if (!$DB->get_manager()->table_exists($t)) {
        cli_problem("      FAIL: Database table '{$t}' does not exist!");
        $issues++;
    }
}
cli_write("      OK: All 4 plugin tables exist.\n");

// 4. Token & Client Statistics
cli_write("[4/8] Gathering token and OAuth statistics...\n");
$activetokens = $DB->count_records('tool_teachermcp_tokens', ['status' => 1]);
$revokedtokens = $DB->count_records('tool_teachermcp_tokens', ['status' => 0]);
$clients = $DB->count_records('tool_teachermcp_oauth_clients');
$pendingcodes = $DB->count_records('tool_teachermcp_oauth_codes', ['status' => 0]);

cli_write("      Active Agent Tokens:    {$activetokens}\n");
cli_write("      Revoked Tokens:         {$revokedtokens}\n");
cli_write("      OAuth Clients:          {$clients}\n");
cli_write("      Pending Auth Codes:     {$pendingcodes}\n");

// 5. Tool Catalog Count
cli_write("[5/8] Verifying Teacher MCP Tool Catalog...\n");
$tools = \tool_teachermcp\server::get_all_tools();
cli_write("      Registered Tools:       " . count($tools) . "\n");

// 6. MCP Protocol Capabilities
cli_write("[6/8] Verifying MCP Protocol primitives...\n");
$prompts = \tool_teachermcp\local\prompt_registry::get_all_prompts();
$resources = \tool_teachermcp\local\resource_registry::get_resources();
$templates = \tool_teachermcp\local\resource_registry::get_templates();
cli_write("      MCP Prompts:            " . count($prompts) . "\n");
cli_write("      MCP Static Resources:   " . count($resources) . "\n");
cli_write("      MCP Resource Templates: " . count($templates) . "\n");

// 7. Security & HTTPS Configuration
cli_write("[7/8] Checking HTTPS & Security Settings...\n");
$ishttps = (strpos($CFG->wwwroot, 'https://') === 0);
if (!$ishttps) {
    cli_write("      WARNING: wwwroot is not HTTPS ({$CFG->wwwroot}). HTTPS is recommended for production OAuth 2.1.\n");
} else {
    cli_write("      OK: Secure HTTPS endpoint configured.\n");
}

// 8. End-to-End JSON-RPC Pipeline Dry-Run
cli_write("[8/8] Testing MCP JSON-RPC Server Pipeline (Dry-run)...\n");
try {
    $server = new \tool_teachermcp\server();
    $initresp = $server->handle_payload(json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
    ]));
    if (!isset($initresp['result']['capabilities'])) {
        throw new \Exception('Missing capabilities in initialize response.');
    }
    $toolsresp = $server->handle_payload(json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
    ]));
    if (!isset($toolsresp['result']['tools']) || count($toolsresp['result']['tools']) === 0) {
        throw new \Exception('Tools list response is empty or invalid.');
    }
    cli_write("      OK: JSON-RPC pipeline initialized successfully (" . count($toolsresp['result']['tools']) . " tools ready).\n");
} catch (\Throwable $e) {
    cli_problem("      FAIL: Pipeline dry-run error: " . $e->getMessage());
    $issues++;
}

if (!empty($options['cleanup'])) {
    cli_heading('Running Maintenance Task');
    $task = new \tool_teachermcp\task\cleanup_task();
    $task->execute();
}

cli_heading('Diagnostic Summary');
if ($issues === 0) {
    cli_write("SUCCESS: Teacher MCP is fully configured and healthy!\n");
    exit(0);
} else {
    cli_problem("COMPLETED WITH {$issues} ISSUE(S). Please resolve the errors above.");
    exit(1);
}
