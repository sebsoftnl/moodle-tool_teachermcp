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

use tool_teachermcp\tools\base_tool;

/**
 * Normalizes supplied built-in booleans after top-level schema validation.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class argument_normalizer {
    /**
     * Prepare built-in inputs without injecting defaults or traversing opaque payloads.
     *
     * @param base_tool $tool Built-in tool; external provider arguments must not pass through this helper.
     * @param array $arguments Arguments whose original top-level values have already passed validation.
     * @return array Arguments with supplied boolean positions normalized.
     * @throws \InvalidArgumentException For an unsupported boolean representation.
     */
    public static function normalize(base_tool $tool, array $arguments): array {
        $schema = $tool->get_input_schema();
        $properties = (array)($schema['properties'] ?? []);
        $arguments = self::normalize_properties($arguments, $properties);

        if ($tool->get_name() === 'scaffold_assessment_unit' && isset($arguments['questions'])) {
            foreach ($arguments['questions'] as $index => $question) {
                if (is_array($question)) {
                    $arguments['questions'][$index] = self::normalize_properties(
                        $question,
                        $properties['questions']['items']['properties'],
                        'questions[].'
                    );
                }
            }
        }
        return $arguments;
    }

    private static function normalize_properties(array $arguments, array $properties, string $prefix = ''): array {
        foreach ($properties as $name => $property) {
            if (($property['type'] ?? '') !== 'boolean' || !array_key_exists($name, $arguments)) {
                continue;
            }
            $arguments[$name] = match ($arguments[$name]) {
                true, 1, '1', 'true' => true,
                false, 0, '0', 'false' => false,
                default => throw new \InvalidArgumentException("Parameter '{$prefix}{$name}' must be a boolean."),
            };
        }
        return $arguments;
    }
}
