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

namespace tool_teachermcp\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Public contract for an externally provided Teacher MCP tool.
 *
 * Tool identity, scope, capability, and mandatory enable setting are declared by
 * the provider callback. Implementations must not derive their own MCP name.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface external_tool {
    /**
     * Return a human- and model-readable description of the tool.
     *
     * @return string
     */
    public function get_description(): string;

    /**
     * Return the JSON schema for tool arguments.
     *
     * @return array
     */
    public function get_input_schema(): array;

    /**
     * Execute the tool with validated arguments and safe execution context.
     *
     * @param array $arguments Validated MCP tool arguments.
     * @param execution_context $context Immutable, data-minimised execution context.
     * @return mixed A result serialisable by the Teacher MCP transport.
     */
    public function execute(array $arguments, execution_context $context): mixed;
}
