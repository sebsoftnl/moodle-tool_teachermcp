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

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/diagnostic_tools.php');

use tool_teachermcp\local\tool_registry;
use tool_teachermcp\tests\fixtures\diagnostic_fixtures;

/**
 * Catalog integrity and installed release diagnostics.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnostics_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        tool_registry::set_provider_callback_map_for_testing([]);
    }

    protected function tearDown(): void {
        tool_registry::set_provider_callback_map_for_testing(null);
        parent::tearDown();
    }

    public function test_builtin_catalog_retains_all_expected_names_and_metadata(): void {
        $tools = tool_registry::get_all_tools();
        $names = array_keys($tools);
        $expected = diagnostic_fixtures::BUILTINS;
        sort($names);
        sort($expected);
        $this->assertCount(24, $tools);
        $this->assertSame($expected, $names);
        foreach ($tools as $name => $tool) {
            $this->assertSame($name, $tool->get_name());
            $this->assertNotEmpty($tool->get_description());
            $this->assertSame('object', $tool->get_input_schema()['type']);
            $this->assertFalse(tool_registry::get_tool_metadata($name)['external']);
        }
    }

    public function test_initialize_release_matches_installed_metadata_without_protocol_change(): void {
        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');
        $installed = \core_plugin_manager::instance()->get_plugin_info('tool_teachermcp');
        $this->assertSame($plugin->release, $installed->release);
        $response = (new server())->handle_payload(json_encode(['jsonrpc' => '2.0', 'id' => 19, 'method' => 'initialize']));
        $this->assertSame(19, $response['id']);
        $this->assertSame('2024-11-05', $response['result']['protocolVersion']);
        $this->assertSame('moodle-teachermcp', $response['result']['serverInfo']['name']);
        $this->assertSame(
            $installed->release,
            $response['result']['serverInfo']['version'],
            'Initialize must report the installed plugin release, not a second hard-coded version.'
        );
    }

    public function test_healthy_builtin_diagnostics_are_independent_of_scope_filters(): void {
        foreach (['enable_write_tools', 'enable_grade_tools', 'enable_messaging_tool', 'enable_delete_tool'] as $key) {
            set_config($key, 0, 'tool_teachermcp');
        }
        $this->assertLessThan(24, count(server::get_tools()));
        $this->assertTrue(
            method_exists(tool_registry::class, 'get_builtin_diagnostics'),
            'The registry must expose bounded built-in registration diagnostics.'
        );
        $this->assertSame(
            ['expected' => 24, 'registered' => 24, 'complete' => true, 'failures' => []],
            tool_registry::get_builtin_diagnostics()
        );
        tool_registry::purge_for_testing();
        $this->assertSame([], tool_registry::get_builtin_diagnostics()['failures']);
    }

    public function test_external_provider_failure_does_not_change_builtin_diagnostics(): void {
        tool_registry::set_provider_callback_map_for_testing([
            'tool_teachermcp' => [diagnostic_fixtures::class, 'providers'],
        ]);
        $tools = tool_registry::get_all_tools();
        $this->assertCount(25, $tools);
        $this->assertArrayHasKey('tool_teachermcp.diagnostic_good', $tools);
        $this->assertArrayNotHasKey('tool_teachermcp.diagnostic_bad', $tools);
        $this->assertTrue(tool_registry::get_tool_metadata('tool_teachermcp.diagnostic_good')['external']);
        $this->assertTrue(
            method_exists(tool_registry::class, 'get_builtin_diagnostics'),
            'External tool counts must not mask the built-in registration status.'
        );
        $this->assertSame(
            ['expected' => 24, 'registered' => 24, 'complete' => true, 'failures' => []],
            tool_registry::get_builtin_diagnostics()
        );
    }
}
