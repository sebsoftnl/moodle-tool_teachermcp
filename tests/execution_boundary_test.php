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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared tool execution and MCP response contracts.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tool_teachermcp\server
 */
final class execution_boundary_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setUser($this->getDataGenerator()->create_user());
        $this->set_token(['read', 'write']);
        set_config('enable_write_tools', 1, 'tool_teachermcp');
        set_config('enable_delete_tool', 1, 'tool_teachermcp');
        set_config('allow_delete_questions', 1, 'tool_teachermcp');
    }

    protected function tearDown(): void {
        (new \ReflectionProperty(auth::class, 'currenttoken'))->setValue(null, null);
        parent::tearDown();
    }

    public function test_mcp_question_deletion_requires_delete_grant(): void {
        [$server, $probe] = $this->server_with_probe();
        foreach ([[], ['confirm_token' => 'previously-issued-confirmation']] as $confirmation) {
            $response = $this->rpc($server, 'manage_question_bank', [
                'course_id' => SITEID, 'action' => 'delete_question',
            ] + $confirmation);
            $this->assertTrue($response['result']['isError'], 'MCP deletion must reject a read/write-only connection.');
            $this->assertSame(
                get_string('error_tool_not_permitted', 'tool_teachermcp', 'manage_question_bank'),
                $response['result']['content'][0]['text']
            );
            $this->assertSame(0, $probe->calls, 'Neither preview nor confirmation may reach the tool.');
        }
    }

    public function test_authoring_and_authorized_deletion_execute_once(): void {
        [$server, $probe] = $this->server_with_probe();
        $response = $this->rpc($server, 'manage_question_bank', ['course_id' => SITEID, 'action' => 'update_question']);
        $this->assertFalse($response['result']['isError']);
        $this->assertSame(1, $probe->calls);
        $this->set_token(['read', 'write', 'delete']);
        $response = $this->rpc($server, 'manage_question_bank', ['course_id' => SITEID, 'action' => 'delete_question']);
        $this->assertFalse($response['result']['isError']);
        $this->assertSame(2, $probe->calls);
    }

    public function test_existing_dispatch_error_responses_are_preserved(): void {
        [$server, $probe] = $this->server_with_probe();
        $unknown = $this->rpc($server, 'unknown_tool', []);
        $this->assertSame([
            'jsonrpc' => '2.0', 'result' => [
                'content' => [['type' => 'text', 'text' => "Tool 'unknown_tool' is not found or not permitted."]],
                'isError' => true,
            ], 'id' => 'request-42',
        ], $unknown);
        $args = ['course_id' => SITEID, 'action' => 'update_question'];
        set_config('enable_write_tools', 0, 'tool_teachermcp');
        $disabled = $this->rpc($server, 'manage_question_bank', $args);
        $this->assertTrue($disabled['result']['isError']);
        $this->assertSame(get_string('error_tool_disabled', 'tool_teachermcp', 'manage_question_bank'),
            $disabled['result']['content'][0]['text']);
        set_config('enable_write_tools', 1, 'tool_teachermcp');
        $this->set_token(['read']);
        $denied = $this->rpc($server, 'manage_question_bank', $args);
        $this->assertTrue($denied['result']['isError']);
        $this->assertSame(get_string('error_tool_not_permitted', 'tool_teachermcp', 'manage_question_bank'),
            $denied['result']['content'][0]['text']);
        $this->set_token(['read', 'write']);
        $schema = $this->rpc($server, 'manage_question_bank', ['action' => 'update_question']);
        $this->assertSame(-32602, $schema['error']['code']);
        $this->assertSame('request-42', $schema['id']);
        $this->assertStringStartsWith('Invalid params: ', $schema['error']['message']);
        $this->assertSame(0, $probe->calls);
    }

    public function test_tool_errors_are_not_misclassified_as_dispatch_errors(): void {
        [$server, $probe] = $this->server_with_probe();
        $probe->failure = new \moodle_exception('error_invalid_params', 'tool_teachermcp');
        $args = ['course_id' => SITEID, 'action' => 'update_question'];
        $moodle = $this->rpc($server, 'manage_question_bank', $args);
        $this->assertTrue($moodle['result']['isError']);
        $this->assertSame('Moodle error: ' . $probe->failure->getMessage(), $moodle['result']['content'][0]['text']);
        $probe->failure = new \RuntimeException('PRIVATE INTERNAL DETAIL');
        $internal = $this->rpc($server, 'manage_question_bank', $args);
        $this->assertTrue($internal['result']['isError']);
        $this->assertSame('Internal tool execution error.', $internal['result']['content'][0]['text']);
        $this->assertSame(2, $probe->calls);
    }

    public function test_mcp_logs_each_actual_execution_once_and_ignores_notifications(): void {
        [$server, $probe] = $this->server_with_probe();
        $args = ['course_id' => SITEID, 'action' => 'update_question'];
        $sink = $this->redirectEvents();
        $this->rpc($server, 'manage_question_bank', $args);
        $probe->failure = new \RuntimeException('PRIVATE INTERNAL DETAIL');
        $this->rpc($server, 'manage_question_bank', $args);
        $notification = $server->handle_payload(json_encode([
            'jsonrpc' => '2.0', 'method' => 'tools/call',
            'params' => ['name' => 'manage_question_bank', 'arguments' => $args],
        ]));
        $this->assertNull($notification);
        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(2, $events);
        $this->assertSame(2, $probe->calls);
        foreach ($events as $index => $event) {
            $this->assertInstanceOf(\tool_teachermcp\event\tool_executed::class, $event);
            $this->assertSame($index === 0, $event->other['success']);
            $this->assertSame(123, $event->other['tokenid']);
            $this->assertArrayNotHasKey('arguments', $event->other);
            $this->assertArrayNotHasKey('result', $event->other);
        }
    }

    /**
     * Install a token without changing the authenticated user.
     *
     * @param array $scopes Connection scopes.
     */
    private function set_token(array $scopes): void {
        (new \ReflectionProperty(auth::class, 'currenttoken'))->setValue(null, (object)[
            'id' => 123, 'permissions' => json_encode(['scopes' => $scopes]),
        ]);
    }

    /**
     * Send a request through the actual JSON-RPC dispatcher.
     *
     * @param server $server Server under test.
     * @param string $name Tool name.
     * @param array $arguments Tool arguments.
     * @return array Response envelope.
     */
    private function rpc(server $server, string $name, array $arguments): array {
        return $server->handle_payload(json_encode([
            'jsonrpc' => '2.0', 'id' => 'request-42', 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Replace execution only, keeping the real registry scope and dispatcher.
     *
     * @return array Server and invocation-counting tool.
     */
    private function server_with_probe(): array {
        $probe = new class extends \tool_teachermcp\tools\base_tool {
            /** @var int Number of actual executions. */
            public int $calls = 0;
            /** @var \Throwable|null Tool failure to expose to the dispatcher. */
            public ?\Throwable $failure = null;

            public function get_name(): string {
                return 'manage_question_bank';
            }

            public function get_description(): string {
                return 'Execution boundary fixture.';
            }

            public function get_input_schema(): array {
                return ['type' => 'object', 'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'action' => ['type' => 'string', 'enum' => ['update_question', 'delete_question']],
                    'confirm_token' => ['type' => 'string'],
                ], 'required' => ['course_id', 'action']];
            }

            public function execute(array $params): array {
                $this->calls++;
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                return $params;
            }
        };
        $server = new server();
        (new \ReflectionProperty(server::class, 'tools'))->setValue($server, ['manage_question_bank' => $probe]);
        return [$server, $probe];
    }
}
