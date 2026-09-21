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
use tool_teachermcp\local\provider_registry;

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

/**
 * Valid implementation used to prove the public provider contract.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_discovery_fake_tool implements external_tool {
    /** @inheritDoc */
    public function get_description(): string {
        return 'A fake provider tool.';
    }

    /** @inheritDoc */
    public function get_input_schema(): array {
        return ['type' => 'object'];
    }

    /** @inheritDoc */
    public function execute(array $arguments, execution_context $context): array {
        return ['userid' => $context->get_userid(), 'arguments' => $arguments];
    }
}

/**
 * Tests for the external Teacher MCP provider discovery contract.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      tool_teachermcp
 * @covers     \tool_teachermcp\local\provider_registry
 */
final class provider_discovery_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        provider_discovery_test_callbacks::$declarations = [];
        provider_discovery_test_callbacks::$throwing = [];
    }

    public function test_execution_context_exposes_only_safe_immutable_identifiers(): void {
        $context = new execution_context(42, 7, 9);

        $this->assertSame(42, $context->get_userid());
        $this->assertSame(7, $context->get_courseid());
        $this->assertSame(9, $context->get_cmid());
        $this->assertFalse(method_exists($context, 'get_bearer_token'));
        $this->assertFalse(method_exists($context, 'get_headers'));
        $this->assertFalse(method_exists($context, 'get_payload'));
        $this->assertFalse(method_exists($context, 'set_userid'));
    }

    public function test_discovers_valid_declaration_from_injected_callback_map(): void {
        $declaration = $this->valid_declaration();
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$declaration];

        $tools = provider_registry::discover([
            'tool_teachermcp' => [provider_discovery_test_callbacks::class, 'get'],
        ]);

        $this->assertCount(1, $tools);
        $tool = $tools['tool_teachermcp.example'];
        $this->assertSame('tool_teachermcp.example', $tool->get_name());
        $this->assertSame('tool_teachermcp', $tool->get_component());
        $this->assertSame('example', $tool->get_id());
        $this->assertSame('read', $tool->get_scope());
        $this->assertSame('moodle/course:view', $tool->get_capability());
        $this->assertSame('tool_teachermcp/enable_example', $tool->get_enabled_setting());
        $this->assertSame(provider_discovery_fake_tool::class, $tool->get_classname());
    }

    public function test_discovers_valid_declaration_from_moodle_callback_map_shape(): void {
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$this->valid_declaration()];

        $tools = provider_registry::discover([
            'tool' => [
                'teachermcp' => [provider_discovery_test_callbacks::class, 'get'],
            ],
        ]);

        $this->assertArrayHasKey('tool_teachermcp.example', $tools);
    }

    public function test_accepts_a_single_provider_declaration_without_a_list_wrapper(): void {
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = $this->valid_declaration();

        $tools = provider_registry::discover([
            'tool_teachermcp' => [provider_discovery_test_callbacks::class, 'get'],
        ]);

        $this->assertArrayHasKey('tool_teachermcp.example', $tools);
    }

    public function test_rejects_provider_with_mismatched_component_identity(): void {
        $declaration = $this->valid_declaration(['provider_id' => 'local_other']);
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$declaration];

        $this->assertSame([], provider_registry::discover($this->callback_map('tool_teachermcp')));
    }

    public function test_rejects_provider_with_an_unsupported_api_version(): void {
        $declaration = $this->valid_declaration(['api_version' => 2]);
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$declaration];

        $this->assertSame([], provider_registry::discover($this->callback_map('tool_teachermcp')));
    }

    public function test_rejects_tool_with_invalid_local_id(): void {
        $declaration = $this->valid_declaration(['tools' => [$this->valid_tool(['id' => 'not-valid'])]]);
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$declaration];

        $this->assertSame([], provider_registry::discover($this->callback_map('tool_teachermcp')));
    }

    public function test_rejects_tool_with_an_unavailable_class(): void {
        $declaration = $this->valid_declaration(['tools' => [$this->valid_tool(['class' => '\\local_demo\\missing_tool'])]]);
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$declaration];

        $this->assertSame([], provider_registry::discover($this->callback_map('tool_teachermcp')));
    }

    public function test_rejects_tool_class_that_does_not_implement_the_public_contract(): void {
        $declaration = $this->valid_declaration(['tools' => [$this->valid_tool(['class' => \stdClass::class])]]);
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$declaration];

        $this->assertSame([], provider_registry::discover($this->callback_map('tool_teachermcp')));
    }

    public function test_rejects_tool_with_an_invalid_scope(): void {
        $declaration = $this->valid_declaration(['tools' => [$this->valid_tool(['scope' => 'admin'])]]);
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$declaration];

        $this->assertSame([], provider_registry::discover($this->callback_map('tool_teachermcp')));
    }

    public function test_rejects_tool_with_an_invalid_capability(): void {
        $declaration = $this->valid_declaration(['tools' => [$this->valid_tool(['capability' => 'not a capability'])]]);
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$declaration];

        $this->assertSame([], provider_registry::discover($this->callback_map('tool_teachermcp')));
    }

    public function test_rejects_tool_with_a_capability_not_owned_by_the_provider(): void {
        $declaration = $this->valid_declaration(['tools' => [$this->valid_tool(['capability' => 'moodle/course:view'])]]);
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$declaration];

        $this->assertSame([], provider_registry::discover($this->callback_map('tool_teachermcp')));
    }

    public function test_rejects_tool_with_an_invalid_provider_setting_name(): void {
        $declaration = $this->valid_declaration(['tools' => [$this->valid_tool(['enabled_setting' => 'local_other/enable_example'])]]);
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$declaration];

        $this->assertSame([], provider_registry::discover($this->callback_map('tool_teachermcp')));
    }

    public function test_rejects_tool_without_a_provider_owned_enable_setting(): void {
        $declaration = $this->valid_declaration(['tools' => [$this->valid_tool(['enabled_setting' => null])]]);
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$declaration];

        $this->assertSame([], provider_registry::discover($this->callback_map('tool_teachermcp')));
    }

    public function test_rejects_duplicate_qualified_tool_names(): void {
        $declaration = $this->valid_declaration(['tools' => [$this->valid_tool(), $this->valid_tool()]]);
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$declaration];

        $this->assertSame([], provider_registry::discover($this->callback_map('tool_teachermcp')));
    }

    public function test_throwing_callback_does_not_prevent_other_provider_discovery(): void {
        provider_discovery_test_callbacks::$throwing['local_broken'] = true;
        provider_discovery_test_callbacks::$declarations['tool_teachermcp'] = [$this->valid_declaration()];

        $tools = provider_registry::discover([
            'local_broken' => [provider_discovery_test_callbacks::class, 'get'],
            'tool_teachermcp' => [provider_discovery_test_callbacks::class, 'get'],
        ]);

        $this->assertCount(1, $tools);
        $this->assertArrayHasKey('tool_teachermcp.example', $tools);
    }

    /**
     * Build a valid provider declaration with optional replacements.
     *
     * @param array $replace Values to replace.
     * @return array
     */
    private function valid_declaration(array $replace = []): array {
        return array_replace([
            'api_version' => 1,
            'provider_id' => 'tool_teachermcp',
            'tools' => [$this->valid_tool()],
        ], $replace);
    }

    /**
     * Build a valid external tool declaration with optional replacements.
     *
     * @param array $replace Values to replace.
     * @return array
     */
    private function valid_tool(array $replace = []): array {
        return array_replace([
            'id' => 'example',
            'class' => provider_discovery_fake_tool::class,
            'scope' => 'read',
            'capability' => 'tool/teachermcp:manageall',
            'enabled_setting' => 'tool_teachermcp/enable_example',
        ], $replace);
    }

    /**
     * Build an injected callback map for a component.
     *
     * @param string $component Component name.
     * @return array
     */
    private function callback_map(string $component): array {
        return [$component => [provider_discovery_test_callbacks::class, 'get']];
    }
}
