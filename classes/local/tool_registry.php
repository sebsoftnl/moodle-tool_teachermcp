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

use Throwable;
use tool_teachermcp\tools\base_tool;

/**
 * Unified built-in and external Teacher MCP tool registry.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_registry {
    /** @var base_tool[]|null Per-request tool instances. */
    private static ?array $tools = null;

    /** @var array<string, array>|null Per-request immutable tool metadata. */
    private static ?array $metadata = null;

    /** @var array|null Injectable discovery source, test-only. */
    private static ?array $providercallbackmap = null;

    /**
     * Get all registered tools.
     *
     * @return base_tool[]
     */
    public static function get_all_tools(): array {
        self::load();
        return self::$tools;
    }

    /**
     * Get metadata for a registered tool.
     *
     * @param string $name Tool name.
     * @return array|null Tool metadata.
     */
    public static function get_tool_metadata(string $name): ?array {
        self::load();
        return self::$metadata[$name] ?? null;
    }

    /**
     * Get metadata for all registered tools.
     *
     * @return array<string, array>
     */
    public static function get_tool_metadata_map(): array {
        self::load();
        return self::$metadata;
    }

    /**
     * Set the provider callback source for an isolated test.
     *
     * @param array|null $callbackmap Callback map, or null for production discovery.
     * @return void
     */
    public static function set_provider_callback_map_for_testing(?array $callbackmap): void {
        self::$providercallbackmap = $callbackmap;
        self::purge_for_testing();
    }

    /**
     * Purge per-request registry state for isolated tests.
     *
     * @return void
     */
    public static function purge_for_testing(): void {
        self::$tools = null;
        self::$metadata = null;
    }

    /**
     * Populate the registry once for this request.
     *
     * @return void
     */
    private static function load(): void {
        if (self::$tools !== null && self::$metadata !== null) {
            return;
        }

        self::$tools = [];
        self::$metadata = [];
        foreach (self::get_builtin_classes() as $class) {
            if (!class_exists($class)) {
                continue;
            }
            try {
                $tool = new $class();
            } catch (Throwable $e) {
                continue;
            }
            if (!$tool instanceof base_tool || isset(self::$tools[$tool->get_name()])) {
                continue;
            }
            $name = $tool->get_name();
            self::$tools[$name] = $tool;
            self::$metadata[$name] = [
                'external' => false,
                'scope' => null,
                'component' => null,
                'enabledsetting' => null,
            ];
        }

        foreach (provider_registry::discover(self::$providercallbackmap) as $name => $declaration) {
            if (isset(self::$tools[$name])) {
                continue;
            }
            try {
                $classname = $declaration->get_classname();
                $external = new $classname();
                if (!$external instanceof external_tool) {
                    continue;
                }
                $description = $external->get_description();
                $schema = $external->get_input_schema();
                if (!is_string($description) || trim($description) === '' || !self::is_valid_external_schema($schema)) {
                    continue;
                }
                $adapter = new external_tool_adapter($declaration, $external, $description, $schema);
            } catch (Throwable $e) {
                continue;
            }

            if (isset(self::$tools[$adapter->get_name()])) {
                continue;
            }
            self::$tools[$adapter->get_name()] = $adapter;
            self::$metadata[$adapter->get_name()] = [
                'external' => true,
                'scope' => $declaration->get_scope(),
                'component' => $declaration->get_component(),
                'enabledsetting' => $declaration->get_enabled_setting(),
            ];
        }
    }

    /**
     * Validate the deliberately narrow v1 JSON-schema subset for provider tools.
     *
     * @param mixed $schema Candidate input schema.
     * @return bool
     */
    private static function is_valid_external_schema(mixed $schema): bool {
        if (!is_array($schema) || ($schema['type'] ?? null) !== 'object') {
            return false;
        }
        $allowedtop = ['type', 'properties', 'required', 'additionalProperties'];
        if (array_diff(array_keys($schema), $allowedtop)) {
            return false;
        }
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties)) {
            return false;
        }
        foreach ($properties as $name => $property) {
            if (!is_string($name) || !is_array($property)
                || array_diff(array_keys($property), ['type', 'enum'])
                || !isset($property['type'])
                || !in_array($property['type'], ['integer', 'number', 'boolean', 'string', 'array'], true)
                || (isset($property['enum']) && !is_array($property['enum']))) {
                return false;
            }
        }
        $required = $schema['required'] ?? [];
        if (!is_array($required)) {
            return false;
        }
        foreach ($required as $name) {
            if (!is_string($name) || !array_key_exists($name, $properties)) {
                return false;
            }
        }
        return !isset($schema['additionalProperties']) || is_bool($schema['additionalProperties']);
    }

    /**
     * Return the existing fixed built-in tool list.
     *
     * @return string[]
     */
    private static function get_builtin_classes(): array {
        return [
            \tool_teachermcp\tools\get_my_profile::class,
            \tool_teachermcp\tools\get_teacher_courses::class,
            \tool_teachermcp\tools\export_course_learning_material::class,
            \tool_teachermcp\tools\get_course_contents::class,
            \tool_teachermcp\tools\scaffold_assessment_unit::class,
            \tool_teachermcp\tools\detect_at_risk_students::class,
            \tool_teachermcp\tools\analyze_class_understanding::class,
            \tool_teachermcp\tools\prepare_grading_session::class,
            \tool_teachermcp\tools\manage_activity::class,
            \tool_teachermcp\tools\grade_submission::class,
            \tool_teachermcp\tools\manage_grades::class,
            \tool_teachermcp\tools\get_group_students::class,
            \tool_teachermcp\tools\manage_course_completion::class,
            \tool_teachermcp\tools\send_student_message::class,
            \tool_teachermcp\tools\manage_question_bank::class,
            \tool_teachermcp\tools\duplicate_activity::class,
            \tool_teachermcp\tools\read_activity_file::class,
            \tool_teachermcp\tools\delete_activity::class,
            \tool_teachermcp\tools\upload_course_asset::class,
            \tool_teachermcp\tools\manage_content_activity::class,
            \tool_teachermcp\tools\get_h5p_libraries::class,
            \tool_teachermcp\tools\create_h5p_activity::class,
        ];
    }
}
