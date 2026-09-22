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
 * CLI script to manually execute Teacher MCP maintenance cleanup.
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
], [
    'h' => 'help',
]);

if ($options['help']) {
    $help = "Execute Teacher MCP cleanup task.

Purges expired OAuth authorization codes and updates expired agent tokens.

Usage:
  php admin/tool/teachermcp/cli/cleanup.php

Options:
  -h, --help    Print this help screen.
";
    cli_write($help);
    exit(0);
}

cli_heading('Teacher MCP Cleanup');
$task = new \tool_teachermcp\task\cleanup_task();
$task->execute();
cli_write("Cleanup complete.\n");
