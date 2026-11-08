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
 * Test callback source for provider discovery.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_discovery_test_callbacks {
    /** @var array<string, array> */
    public static array $declarations = [];

    /** @var array<string, bool> */
    public static array $throwing = [];

    /**
     * Return declarations for the supplied test component.
     *
     * @param string $component Component name.
     * @return array
     */
    public static function get(string $component = 'tool_teachermcp'): array {
        if (!empty(self::$throwing[$component])) {
            throw new \RuntimeException('Provider callback failed.');
        }
        return self::$declarations[$component] ?? [];
    }
}
