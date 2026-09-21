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

defined('MOODLE_INTERNAL') || die();

use tool_teachermcp\local\execution_context;
use tool_teachermcp\local\external_tool;
use tool_teachermcp\local\tool_registry;

/**
 * Injectable external provider declaration source for registry tests.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_registry_test_callbacks {
    /** @var array */
    public static array $declarations = [];

    /**
     * Return test declarations.
     *
     * @return array
     */
    public static function get(): array {
        return self::$declarations;
    }
}

/**
 * External tool implementation used by the registry test.
 *
 * Providers must derive a resource context and call require_capability() there;
 * declaration capability metadata is not an authorisation decision.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_registry_fake_external_tool implements external_tool {
    /** @inheritDoc */
    public function get_description(): string {
        return 'A test external tool.';
    }

    /** @inheritDoc */
    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => ['value' => ['type' => 'string']],
            'required' => ['value'],
        ];
    }

    /** @inheritDoc */
    public function execute(array $arguments, execution_context $context): array {
        return [
            'userid' => $context->get_userid(),
            'value' => $arguments['value'],
            'courseid' => $context->get_courseid(),
            'cmid' => $context->get_cmid(),
        ];
    }
}


/**
 * Provider with an unsupported argument schema, used to prove publication validation.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_registry_invalid_schema_tool implements external_tool {
    /** @inheritDoc */
    public function get_description(): string {
        return 'This provider has an invalid schema.';
    }

    /** @inheritDoc */
    public function get_input_schema(): array {
        return ['type' => 'string'];
    }

    /** @inheritDoc */
    public function execute(array $arguments, execution_context $context): array {
        return [];
    }
}

/**
 * Provider whose metadata accessor fails, used to prove discovery isolation.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_registry_throwing_description_tool implements external_tool {
    /** @inheritDoc */
    public function get_description(): string {
        throw new \RuntimeException('Provider description must not reach the MCP client.');
    }

    /** @inheritDoc */
    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => []];
    }

    /** @inheritDoc */
    public function execute(array $arguments, execution_context $context): array {
        return [];
    }
}

/**
 * Tests for the unified Teacher MCP tool registry.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      tool_teachermcp
 * @covers     \tool_teachermcp\local\tool_registry
 */
final class tool_registry_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        tool_registry_test_callbacks::$declarations = [];
        tool_registry::set_provider_callback_map_for_testing([
            'tool_teachermcp' => [tool_registry_test_callbacks::class, 'get'],
        ]);
        tool_registry::purge_for_testing();
    }

    protected function tearDown(): void {
        tool_registry::set_provider_callback_map_for_testing(null);
        tool_registry::purge_for_testing();
        parent::tearDown();
    }

    public function test_external_tool_is_listed_only_when_global_gate_is_enabled(): void {
        $this->resetAfterTest(true);
        tool_registry_test_callbacks::$declarations = [$this->valid_provider()];
        tool_registry::purge_for_testing();

        set_config('enable_external_tools', 0, 'tool_teachermcp');
        $this->assertArrayNotHasKey('tool_teachermcp.example', server::get_tools());

        set_config('enable_external_tools', 1, 'tool_teachermcp');
        set_config('enable_example', 1, 'tool_teachermcp');
        $tools = server::get_tools();
        $this->assertArrayHasKey('tool_teachermcp.example', $tools);
        $this->assertSame('A test external tool.', $tools['tool_teachermcp.example']->get_description());
        $this->assertSame('read', server::get_tool_scope('tool_teachermcp.example'));
        $this->assertTrue(server::is_external_tool('tool_teachermcp.example'));
    }

    public function test_grantable_external_tools_are_limited_to_currently_approved_tools(): void {
        $this->resetAfterTest(true);
        tool_registry_test_callbacks::$declarations = [$this->valid_provider()];
        tool_registry::purge_for_testing();

        set_config('enable_external_tools', 0, 'tool_teachermcp');
        $this->assertSame([], server::get_grantable_external_tool_names());

        set_config('enable_external_tools', 1, 'tool_teachermcp');
        set_config('enable_example', 1, 'tool_teachermcp');
        $this->assertSame(['tool_teachermcp.example'], server::get_grantable_external_tool_names());

        $scopeconfig = \tool_teachermcp\oauth_server::normalise_scope(
            'teachermcp tool_teachermcp.example'
        );
        $this->assertSame('teachermcp tool_teachermcp.example', $scopeconfig['scope']);
        $this->assertTrue($scopeconfig['permissions']['all_teacher_tools']);
        $this->assertTrue($scopeconfig['permissions']['tool_teachermcp.example']);
    }

    public function test_external_tool_requires_an_exact_qualified_token_grant(): void {
        $this->resetAfterTest(true);
        tool_registry_test_callbacks::$declarations = [$this->valid_provider()];
        tool_registry::purge_for_testing();
        $name = 'tool_teachermcp.example';

        $this->assertFalse(server::is_tool_allowed_by_token($name, (object)[
            'permissions' => json_encode(['all_teacher_tools' => true]),
        ]));
        $this->assertFalse(server::is_tool_allowed_by_token($name, (object)[
            'permissions' => json_encode(['scopes' => ['read', 'all']]),
        ]));
        $this->assertFalse(server::is_tool_allowed_by_token($name, (object)[
            'permissions' => json_encode(['read']),
            'scope' => 'all',
        ]));
        $this->assertFalse(server::is_tool_allowed_by_token($name, (object)[]));
        $this->assertTrue(server::is_tool_allowed_by_token($name, (object)[
            'permissions' => json_encode([$name => true]),
        ]));
        $this->assertTrue(server::is_tool_allowed_by_token($name, (object)[
            'permissions' => json_encode([$name]),
        ]));
    }

    public function test_external_tool_execution_receives_only_safe_user_context(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_config('enable_external_tools', 1, 'tool_teachermcp');
        set_config('enable_example', 1, 'tool_teachermcp');
        tool_registry_test_callbacks::$declarations = [$this->valid_provider()];
        tool_registry::purge_for_testing();

        $result = (new server())->execute_tool('tool_teachermcp.example', ['value' => 'safe'], (object)[
            'permissions' => json_encode(['tool_teachermcp.example' => true]),
        ]);

        $this->assertSame((int)$user->id, $result['userid']);
        $this->assertSame('safe', $result['value']);
        $this->assertNull($result['courseid']);
        $this->assertNull($result['cmid']);
    }

    public function test_provider_with_an_invalid_input_schema_is_not_registered(): void {
        $this->resetAfterTest(true);
        tool_registry_test_callbacks::$declarations = [$this->valid_provider([
            'class' => tool_registry_invalid_schema_tool::class,
        ])];
        tool_registry::purge_for_testing();

        $this->assertArrayNotHasKey('tool_teachermcp.example', server::get_all_tools());
    }

    public function test_provider_with_a_throwing_description_is_not_registered(): void {
        $this->resetAfterTest(true);
        tool_registry_test_callbacks::$declarations = [$this->valid_provider([
            'class' => tool_registry_throwing_description_tool::class,
        ])];
        tool_registry::purge_for_testing();

        $this->assertArrayNotHasKey('tool_teachermcp.example', server::get_all_tools());
    }

    public function test_duplicate_provider_tool_declaration_is_not_exposed(): void {
        $this->resetAfterTest(true);
        set_config('enable_external_tools', 1, 'tool_teachermcp');
        $provider = $this->valid_provider();
        $provider['tools'][] = $provider['tools'][0];
        tool_registry_test_callbacks::$declarations = [$provider];
        tool_registry::purge_for_testing();

        $this->assertArrayNotHasKey('tool_teachermcp.example', server::get_tools());
    }

    /**
     * Build a valid test provider declaration.
     *
     * @return array
     */
    private function valid_provider(array $toolreplace = []): array {
        return [
            'api_version' => 1,
            'provider_id' => 'tool_teachermcp',
            'tools' => [array_replace([
                'id' => 'example',
                'class' => tool_registry_fake_external_tool::class,
                'scope' => 'read',
                'capability' => 'tool/teachermcp:manageall',
                'enabled_setting' => 'tool_teachermcp/enable_example',
            ], $toolreplace)],
        ];
    }
}
