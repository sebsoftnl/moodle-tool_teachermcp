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
 * Real provider-v1 adapter fixture with observable execution and strict enum validation.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class argument_normalizer_provider_fixture implements external_tool {
    /**
     * @var int Number of calls reaching provider execution.
     *
     * @return mixed
     */
    public static int $calls = 0;

    /**
     * Return the external provider declaration.
     *
     * @return mixed
     */
    public static function provider(): array {
        return [['api_version' => 1, 'provider_id' => 'tool_teachermcp', 'tools' => [[
            'id' => 'boolean_probe', 'class' => self::class, 'scope' => 'read',
            'capability' => 'tool/teachermcp:manageall',
            'enabled_setting' => 'tool_teachermcp/enable_boolean_probe',
        ]]]];
    }

    /**
     * Return the provider fixture description.
     *
     * @return mixed
     */
    public function get_description(): string {
        return 'Provider-v1 argument preservation fixture.';
    }

    /**
     * Return the provider fixture input schema.
     *
     * @return mixed
     */
    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => [
            'flag' => ['type' => 'boolean'],
            'restricted' => ['type' => 'boolean', 'enum' => [false]],
            'payload' => ['type' => 'array'],
        ], 'required' => ['flag'], 'additionalProperties' => false];
    }

    /**
     * Execute the provider fixture.
     *
     * @return mixed
     */
    public function execute(array $arguments, execution_context $context): array {
        require_capability('tool/teachermcp:manageall', \context_system::instance(), $context->get_userid());
        self::$calls++;
        return $arguments;
    }
}
