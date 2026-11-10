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

namespace tool_teachermcp;

use tool_teachermcp\local\execution_context;
use tool_teachermcp\local\external_tool;

/**
 * Provider with an unsupported argument schema, used to prove publication validation.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_registry_invalid_schema_tool implements external_tool {
    /**
     * Return the fixture description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'This provider has an invalid schema.';
    }

    /**
     * Return the fixture schema.
     *
     * @return array
     */
    public function get_input_schema(): array {
        return ['type' => 'string'];
    }

    /**
     * Execute the fixture tool.
     *
     * @param array $arguments Validated tool arguments.
     * @param execution_context $context Current tool execution context.
     * @return array Empty fixture result.
     */
    public function execute(array $arguments, execution_context $context): array {
        return [];
    }
}
