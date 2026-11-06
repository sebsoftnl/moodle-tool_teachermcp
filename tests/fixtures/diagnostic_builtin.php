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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Test-only diagnostic_builtin fixture class.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_teachermcp\tests\fixtures;

defined('MOODLE_INTERNAL') || die();

/**
 * Built-in tool fixture.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnostic_builtin extends \tool_teachermcp\tools\base_tool {
    /**
     * Return the tool name, or simulate a name lookup failure.
     *
     * @return string Tool name.
     */
    public function get_name(): string {
        return 'get_my_profile';
    }

    /**
     * Return the tool description, or simulate a lookup failure.
     *
     * @return string Tool description.
     */
    public function get_description(): string {
        return 'Owned diagnostic fixture.';
    }

    /**
     * Return the input schema, or simulate a schema failure.
     *
     * @return array Tool input schema.
     */
    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    /**
     * Execute the fixture tool with no external side effects.
     *
     * @param array $params Tool input parameters.
     * @return array Fixture result.
     */
    public function execute(array $params): array {
        return ['fixture_only' => true];
    }
}
