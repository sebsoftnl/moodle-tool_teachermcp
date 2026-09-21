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

/**
 * Discovers and validates external Teacher MCP tool providers.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_registry {
    /** Supported external provider declaration API version. */
    public const API_VERSION = 1;

    /** @var string[] Valid external tool scopes. */
    private const SCOPES = ['read', 'write', 'grade', 'message', 'delete'];

    /**
     * Discover declarations supplied by installed Moodle components.
     *
     * The optional callback map is a test seam only. Production discovery uses
     * Moodle's callback registry and never scans the filesystem.
     *
     * @param array|null $callbackmap Map of component names to declared callbacks.
     * @return provider_tool_declaration[] Map keyed by qualified MCP tool name.
     */
    public static function discover(?array $callbackmap = null): array {
        if ($callbackmap === null) {
            if (!function_exists('get_plugins_with_function')) {
                return [];
            }
            try {
                $callbackmap = get_plugins_with_function('teachermcp_get_tool_providers', 'lib.php');
            } catch (Throwable $e) {
                return [];
            }
        }

        if (!is_array($callbackmap)) {
            return [];
        }

        $tools = [];
        $collisions = [];
        foreach (self::flatten_callback_map($callbackmap) as $component => $callback) {
            try {
                $providers = call_user_func($callback);
            } catch (Throwable $e) {
                continue;
            }

            if (self::is_provider_declaration($providers)) {
                $providers = [$providers];
            }
            if (!is_array($providers)) {
                continue;
            }

            foreach ($providers as $provider) {
                $validated = self::validate_provider($component, $provider);
                if ($validated === null) {
                    continue;
                }

                foreach ($validated as $tool) {
                    $name = $tool->get_name();
                    if (isset($tools[$name]) || isset($collisions[$name])) {
                        unset($tools[$name]);
                        $collisions[$name] = true;
                        continue;
                    }
                    $tools[$name] = $tool;
                }
            }
        }

        return $tools;
    }

    /**
     * Normalize Moodle's plugin-type callback map for safe iteration.
     *
     * Moodle returns callbacks as [plugintype][plugin] = callable. The flat
     * component map is retained only as an isolated test seam.
     *
     * @param array $callbackmap Moodle callback map or a flat test seam.
     * @return array<string, callable>
     */
    private static function flatten_callback_map(array $callbackmap): array {
        $callbacks = [];
        foreach ($callbackmap as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (self::is_component_name($key) && is_callable($value)) {
                $callbacks[$key] = $value;
                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $key) || !is_array($value)) {
                continue;
            }
            foreach ($value as $plugin => $callback) {
                $component = $key . '_' . $plugin;
                if (is_string($plugin) && self::is_component_name($component) && is_callable($callback)) {
                    $callbacks[$component] = $callback;
                }
            }
        }
        return $callbacks;
    }

    /**
     * Validate one provider declaration from its discovered source component.
     *
     * @param string $component Discovered Frankenstyle component name.
     * @param mixed $provider Provider declaration.
     * @return provider_tool_declaration[]|null Null if invalid.
     */
    private static function validate_provider(string $component, mixed $provider): ?array {
        if (!is_array($provider)
            || !self::has_exact_keys($provider, ['api_version', 'provider_id', 'tools'])
            || ($provider['api_version'] ?? null) !== self::API_VERSION
            || ($provider['provider_id'] ?? null) !== $component
            || !is_array($provider['tools'])
            || $provider['tools'] === []) {
            return null;
        }

        $tools = [];
        $names = [];
        foreach ($provider['tools'] as $tool) {
            $declaration = self::validate_tool($component, $tool);
            if ($declaration === null || isset($names[$declaration->get_name()])) {
                return null;
            }
            $names[$declaration->get_name()] = true;
            $tools[] = $declaration;
        }

        return $tools;
    }

    /**
     * Validate one external tool declaration.
     *
     * @param string $component Declaring component.
     * @param mixed $tool Tool declaration.
     * @return provider_tool_declaration|null Null if invalid.
     */
    private static function validate_tool(string $component, mixed $tool): ?provider_tool_declaration {
        if (!is_array($tool)
            || !self::has_exact_keys($tool, ['id', 'class', 'scope', 'capability', 'enabled_setting'])) {
            return null;
        }

        $id = $tool['id'];
        $classname = $tool['class'];
        $scope = $tool['scope'];
        $capability = $tool['capability'];
        $enabledsetting = $tool['enabled_setting'];
        $classpathprefix = $component . '\\';

        if (!is_string($id) || strlen($id) > 64 || !preg_match('/^[a-z][a-z0-9_]*$/', $id)
            || !is_string($classname) || !str_starts_with(ltrim($classname, '\\'), $classpathprefix)
            || !class_exists($classname) || !is_a($classname, external_tool::class, true)
            || !is_string($scope) || !in_array($scope, self::SCOPES, true)
            || !self::is_owned_capability($component, $capability)) {
            return null;
        }

        if (!is_string($enabledsetting)
            || !preg_match('/^' . preg_quote($component, '/') . '\/enable_[a-z][a-z0-9_]*$/', $enabledsetting)) {
            return null;
        }

        return new provider_tool_declaration($component, $id, $classname, $scope, $capability, $enabledsetting);
    }

    /**
     * Validate that an existing Moodle capability belongs to the provider.
     *
     * @param string $component Declaring Moodle component.
     * @param mixed $capability Candidate capability name.
     * @return bool
     */
    private static function is_owned_capability(string $component, mixed $capability): bool {
        if (!is_string($capability)
            || !preg_match('/^[a-z][a-z0-9_]*\/[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/', $capability)
            || !str_starts_with($capability, str_replace('_', '/', $component) . ':')) {
            return false;
        }
        if (!function_exists('get_capability_info')) {
            // The grammar and ownership checks remain safe in isolated unit seams.
            return true;
        }
        $info = get_capability_info($capability);
        return $info !== null && ($info->component ?? null) === $component;
    }

    /**
     * Determine whether a value is a provider declaration rather than a list.
     *
     * @param mixed $value Candidate provider declaration.
     * @return bool
     */
    private static function is_provider_declaration(mixed $value): bool {
        return is_array($value) && array_key_exists('api_version', $value) && array_key_exists('provider_id', $value);
    }

    /**
     * Validate a restricted Frankenstyle component name.
     *
     * @param string $component Component name.
     * @return bool
     */
    private static function is_component_name(string $component): bool {
        return (bool)preg_match('/^[a-z][a-z0-9_]*_[a-z][a-z0-9_]*$/', $component);
    }

    /**
     * Check the declaration has no unknown keys and all required keys.
     *
     * @param array $value Declaration value.
     * @param string[] $keys Required keys.
     * @return bool
     */
    private static function has_exact_keys(array $value, array $keys): bool {
        sort($keys);
        $actual = array_keys($value);
        sort($actual);
        return $actual === $keys;
    }
}
