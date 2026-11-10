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
 * Consent-only provider fixture using the unchanged external API v1.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class oauth_consent_provider_fixture implements external_tool {
    /**
     * Return the external provider declaration.
     *
     * @return mixed
     */
    public static function provider(): array {
        return [['api_version' => 1, 'provider_id' => 'tool_teachermcp', 'tools' => [[
            'id' => 'consent_probe', 'class' => self::class, 'scope' => 'read',
            'capability' => 'tool/teachermcp:manageall',
            'enabled_setting' => 'tool_teachermcp/enable_consent_probe',
        ]]]];
    }

    /**
     * Return the provider fixture description.
     *
     * @return mixed
     */
    public function get_description(): string {
        return 'Consent-only external tool fixture.';
    }

    /**
     * Return the provider fixture input schema.
     *
     * @return mixed
     */
    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => []];
    }

    /**
     * Execute the provider fixture.
     *
     * @param array $arguments Validated tool arguments.
     * @param execution_context $context Current tool execution context.
     * @return array Fixture result (this method always throws).
     */
    public function execute(array $arguments, execution_context $context): array {
        throw new \LogicException('The consent-only fixture must not execute.');
    }
}
