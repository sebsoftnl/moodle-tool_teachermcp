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

/**
 * Tests for the MCP server registry, argument validation, and tool permissions.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      tool_teachermcp
 * @covers     \tool_teachermcp\server
 */
final class server_test extends \advanced_testcase {
    public function test_tools_catalog_and_registry(): void {
        $this->resetAfterTest(true);

        $teacher = $this->getDataGenerator()->create_user();
        $this->setUser($teacher);

        $tools = server::get_tools();
        $this->assertIsArray($tools);
        $this->assertGreaterThanOrEqual(20, count($tools));

        $names = array_keys($tools);
        $this->assertContains('get_my_profile', $names);
        $this->assertContains('export_course_learning_material', $names);
        $this->assertContains('scaffold_assessment_unit', $names);
        $this->assertContains('detect_at_risk_students', $names);
        $this->assertContains('analyze_class_understanding', $names);
        $this->assertContains('manage_course_completion', $names);
    }

    public function test_argument_validation(): void {
        $this->resetAfterTest(true);

        $schema = [
            'type' => 'object',
            'properties' => [
                'course_id' => ['type' => 'integer'],
                'format' => ['type' => 'string', 'enum' => ['markdown', 'json']],
            ],
            'required' => ['course_id'],
        ];

        // Valid arguments.
        $valid = server::validate_tool_arguments(['course_id' => 10, 'format' => 'json'], $schema);
        $this->assertTrue($valid['valid']);

        // Missing required.
        $invalid1 = server::validate_tool_arguments(['format' => 'json'], $schema);
        $this->assertFalse($invalid1['valid']);

        // Invalid enum.
        $invalid2 = server::validate_tool_arguments(['course_id' => 10, 'format' => 'xml'], $schema);
        $this->assertFalse($invalid2['valid']);

        // An external provider can opt into strict argument schemas.
        $schema['additionalProperties'] = false;
        $invalid3 = server::validate_tool_arguments(
            ['course_id' => 10, 'unexpected' => 'must not reach the provider'],
            $schema
        );
        $this->assertFalse($invalid3['valid']);
        $this->assertStringContainsString('unexpected', $invalid3['error']);
    }

    public function test_blok5_admin_scope_toggles_and_filtering(): void {
        $this->resetAfterTest(true);

        $teacher = $this->getDataGenerator()->create_user();
        $this->setUser($teacher);

        $server = new server();

        // Default state: delete_activity is enabled and present.
        set_config('enable_delete_tool', 1, 'tool_teachermcp');
        $tools = server::get_tools();
        $names = array_keys($tools);
        $this->assertContains('delete_activity', $names);

        // 1. Disable delete tool -> removed from get_tools() catalog.
        set_config('enable_delete_tool', 0, 'tool_teachermcp');
        $toolsDisabled = server::get_tools();
        $namesDisabled = array_keys($toolsDisabled);
        $this->assertNotContains('delete_activity', $namesDisabled);

        // Attempting to execute disabled tool directly via server throws error_tool_disabled.
        try {
            $server->execute_tool('delete_activity', ['cmid' => 999]);
            $this->fail('Expected error_tool_disabled when delete tool is toggled off');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_tool_disabled', $e->errorcode);
        }

        // Re-enable delete tool.
        set_config('enable_delete_tool', 1, 'tool_teachermcp');

        // 2. Disable write tools -> authoring tools removed.
        set_config('enable_write_tools', 0, 'tool_teachermcp');
        $toolsNoWrite = server::get_tools();
        $namesNoWrite = array_keys($toolsNoWrite);
        $this->assertNotContains('manage_content_activity', $namesNoWrite);
        $this->assertNotContains('create_h5p_activity', $namesNoWrite);
        $this->assertNotContains('scaffold_assessment_unit', $namesNoWrite);
        // Read tools should still be present.
        $this->assertContains('get_my_profile', $namesNoWrite);
        $this->assertContains('get_course_contents', $namesNoWrite);

        try {
            $server->execute_tool('create_h5p_activity', ['course_id' => 1, 'title' => 'T', 'library_name' => 'L', 'params' => []]);
            $this->fail('Expected error_tool_disabled for write tool');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_tool_disabled', $e->errorcode);
        }

        // 3. Disable grade tools.
        set_config('enable_write_tools', 1, 'tool_teachermcp');
        set_config('enable_grade_tools', 0, 'tool_teachermcp');
        $toolsNoGrade = server::get_tools();
        $namesNoGrade = array_keys($toolsNoGrade);
        $this->assertNotContains('prepare_grading_session', $namesNoGrade);

        // 4. Disable messaging tool.
        set_config('enable_grade_tools', 1, 'tool_teachermcp');
        set_config('enable_messaging_tool', 0, 'tool_teachermcp');
        $toolsNoMsg = server::get_tools();
        $namesNoMsg = array_keys($toolsNoMsg);
        $this->assertNotContains('send_student_message', $namesNoMsg);
    }

    public function test_question_deletion_requires_delete_policy_and_token_grant(): void {
        $this->resetAfterTest(true);
        $server = new server();
        $args = ['course_id' => 1, 'action' => 'delete_question', 'question_id' => 1];
        $writeonly = (object)['permissions' => json_encode(['write'])];
        set_config('enable_delete_tool', 0, 'tool_teachermcp');
        try {
            $server->execute_tool('manage_question_bank', $args, $writeonly);
            $this->fail('Deletion must obey the site-wide delete switch.');
        } catch (\moodle_exception $error) {
            $this->assertSame('error_tool_disabled', $error->errorcode);
        }

        set_config('enable_delete_tool', 1, 'tool_teachermcp');
        try {
            $server->execute_tool('manage_question_bank', $args, $writeonly);
            $this->fail('A write-only token must not delete questions.');
        } catch (\moodle_exception $error) {
            $this->assertSame('error_tool_not_permitted', $error->errorcode);
        }
        $this->assertTrue(server::is_tool_allowed_by_token('delete_activity',
            (object)['permissions' => json_encode(['write', 'delete'])]));
    }

    public function test_mcp_limits_are_bounded_and_configurable(): void {
        $this->resetAfterTest(true);

        set_config('max_mcp_request_bytes', 4096, 'tool_teachermcp');
        $this->assertSame(4096, server::get_mcp_limit('max_mcp_request_bytes', 1048576, 1024, 10485760));

        set_config('max_mcp_request_bytes', 0, 'tool_teachermcp');
        $this->assertSame(1048576, server::get_mcp_limit('max_mcp_request_bytes', 1048576, 1024, 10485760));

        set_config('max_mcp_request_bytes', PHP_INT_MAX, 'tool_teachermcp');
        $this->assertSame(10485760, server::get_mcp_limit('max_mcp_request_bytes', 1048576, 1024, 10485760));

        set_config('max_mcp_batch_size', 2, 'tool_teachermcp');
        $response = (new server())->handle_payload(json_encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'],
        ]));
        $this->assertSame(-32600, $response['error']['code']);

        set_config('max_mcp_response_bytes', 1024, 'tool_teachermcp');
        $output = server::format_tool_result_for_response(str_repeat('x', 2048));
        $this->assertLessThanOrEqual(1024, strlen($output));
        $this->assertStringContainsString('[Output truncated', $output);

        $unicodeoutput = server::format_tool_result_for_response(str_repeat('€', 1024));
        $this->assertLessThanOrEqual(1024, strlen($unicodeoutput));
        $this->assertTrue(mb_check_encoding($unicodeoutput, 'UTF-8'));
        $this->assertNotFalse(json_encode($unicodeoutput, JSON_UNESCAPED_UNICODE));
    }

    public function test_mcp_rate_limit_blocks_a_second_request_in_same_window(): void {
        $this->resetAfterTest(true);
        set_config('mcp_requests_per_minute', 1, 'tool_teachermcp');
        $token = (object)['id' => random_int(1000000, 9999999)];

        auth::enforce_mcp_request_rate_limit($token);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_rate_limited', 'tool_teachermcp'));
        auth::enforce_mcp_request_rate_limit($token);
    }

    public function test_mcp_rate_limit_carries_retry_after_from_its_sampled_window(): void {
        $this->resetAfterTest(true);
        set_config('mcp_requests_per_minute', 1, 'tool_teachermcp');
        foreach ([120 => 60, 179 => 1, 180 => 60] as $now => $expected) {
            $token = (object)['id' => random_int(1000000, 9999999)];
            auth::enforce_mcp_request_rate_limit($token, $now);
            try {
                auth::enforce_mcp_request_rate_limit($token, $now);
                $this->fail('A second request in the same sampled window must be rate limited');
            } catch (\moodle_exception $error) {
                $this->assertSame('error_rate_limited', $error->errorcode);
                $this->assertSame($expected, $error->a, 'Retry-After must describe the window used for the counter');
            }
        }
    }

    public function test_mcp_rate_limit_starts_a_new_fixed_minute_window(): void {
        $this->resetAfterTest(true);
        set_config('mcp_requests_per_minute', 1, 'tool_teachermcp');
        $token = (object)['id' => random_int(1000000, 9999999)];
        auth::enforce_mcp_request_rate_limit($token, 179);
        auth::enforce_mcp_request_rate_limit($token, 180);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_rate_limited', 'tool_teachermcp'));
        auth::enforce_mcp_request_rate_limit($token, 180);
    }

    public function test_tool_execution_audit_event_records_safe_failure_metadata(): void {
        $this->resetAfterTest(true);
        $this->setUser($this->getDataGenerator()->create_user());

        $sink = $this->redirectEvents();
        server::log_tool_execution(
            'get_my_profile',
            [],
            (object)['id' => 123],
            false,
            new \moodle_exception('error_invalid_params', 'tool_teachermcp')
        );
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(\tool_teachermcp\event\tool_executed::class, $event);
        $this->assertFalse($event->other['success']);
        $this->assertSame(123, $event->other['tokenid']);
        $this->assertSame('error_invalid_params', $event->other['errorcode']);
        $this->assertSame('moodle_exception', $event->other['errorclass']);
        $this->assertArrayNotHasKey('arguments', $event->other);
        $this->assertArrayNotHasKey('result', $event->other);
    }

    public function test_blok5_token_scope_permission_filtering(): void {
        $this->resetAfterTest(true);

        // 1. Token restricted to read and grade scopes.
        $tokenReadGrade = (object)[
            'permissions' => json_encode(['scopes' => ['read', 'grade']]),
        ];
        $this->assertTrue(server::is_tool_allowed_by_token('get_my_profile', $tokenReadGrade));
        $this->assertTrue(server::is_tool_allowed_by_token('prepare_grading_session', $tokenReadGrade));
        $this->assertFalse(server::is_tool_allowed_by_token('manage_activity', $tokenReadGrade));
        $this->assertFalse(server::is_tool_allowed_by_token('delete_activity', $tokenReadGrade));
        $this->assertFalse(server::is_tool_allowed_by_token('send_student_message', $tokenReadGrade));

        // 2. Token restricted to flat array of scopes.
        $tokenRead = (object)[
            'permissions' => json_encode(['read']),
        ];
        $this->assertTrue(server::is_tool_allowed_by_token('export_course_learning_material', $tokenRead));
        $this->assertTrue(server::is_tool_allowed_by_token('read_activity_file', $tokenRead));
        $this->assertFalse(server::is_tool_allowed_by_token('delete_activity', $tokenRead));
        $this->assertFalse(server::is_tool_allowed_by_token('create_h5p_activity', $tokenRead));

        // 3. Token restricted to specific tool names.
        $tokenSpecific = (object)[
            'permissions' => json_encode(['delete_activity']),
        ];
        $this->assertTrue(server::is_tool_allowed_by_token('delete_activity', $tokenSpecific));
        $this->assertFalse(server::is_tool_allowed_by_token('get_my_profile', $tokenSpecific));

        // 4. Token restricted to curriculum preset (read, write).
        $tokenCurriculum = (object)[
            'permissions' => json_encode(['scopes' => ['read', 'write']]),
        ];
        $this->assertTrue(server::is_tool_allowed_by_token('create_assignment', $tokenCurriculum));
        $this->assertTrue(server::is_tool_allowed_by_token('manage_content_activity', $tokenCurriculum));
        $this->assertFalse(server::is_tool_allowed_by_token('grade_submission', $tokenCurriculum));
        $this->assertFalse(server::is_tool_allowed_by_token('delete_activity', $tokenCurriculum));
    }

    public function test_resources_require_a_read_grant(): void {
        $this->resetAfterTest(true);

        $this->assertTrue(server::is_resource_allowed_by_token((object)[
            'permissions' => json_encode(['scopes' => ['read']]),
        ]));
        $this->assertTrue(server::is_resource_allowed_by_token((object)[
            'permissions' => json_encode(['all_teacher_tools' => true]),
        ]));
        $this->assertFalse(server::is_resource_allowed_by_token((object)[
            'permissions' => json_encode(['scopes' => ['grade']]),
        ]));
        $this->assertFalse(server::is_resource_allowed_by_token((object)[
            'permissions' => json_encode(['tool_teachermcp.example' => true]),
        ]));
    }

    public function test_prompts_list_and_get(): void {
        $this->resetAfterTest(true);

        $server = new server();

        // 1. Check initialize returns prompts capability.
        $initresponse = $server->handle_payload(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ]));
        $this->assertArrayHasKey('result', $initresponse);
        $this->assertArrayHasKey('prompts', $initresponse['result']['capabilities']);

        // 2. Test prompts/list.
        $listresponse = $server->handle_payload(json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'prompts/list',
        ]));
        $this->assertArrayHasKey('result', $listresponse);
        $prompts = $listresponse['result']['prompts'];
        $this->assertIsArray($prompts);
        $this->assertCount(15, $prompts);

        $names = array_column($prompts, 'name');
        $this->assertContains('ingest_course', $names);
        $this->assertContains('create_page', $names);
        $this->assertContains('h5p_flashcards', $names);
        $this->assertContains('scaffold_quiz', $names);

        // 3. Test prompts/get for existing prompt with arguments.
        $getresponse = $server->handle_payload(json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'prompts/get',
            'params' => [
                'name' => 'ingest_course',
                'arguments' => ['course_id' => 42],
            ],
        ]));
        $this->assertArrayHasKey('result', $getresponse);
        $messages = $getresponse['result']['messages'];
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('42', $messages[0]['content']['text']);

        // 4. Test prompts/get for nonexistent prompt.
        $badresponse = $server->handle_payload(json_encode([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'prompts/get',
            'params' => [
                'name' => 'nonexistent_prompt',
            ],
        ]));
        $this->assertArrayHasKey('error', $badresponse);
        $this->assertSame(-32602, $badresponse['error']['code']);
    }

    public function test_resources_list_templates_and_read(): void {
        $this->resetAfterTest(true);

        $server = new server();

        $tokenproperty = new \ReflectionProperty(auth::class, 'currenttoken');
        $tokenproperty->setAccessible(true);
        $tokenproperty->setValue(null, (object)[
            'permissions' => json_encode(['scopes' => ['read']]),
        ]);

        // 1. Check initialize returns resources capability.
        $initresponse = $server->handle_payload(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ]));
        $this->assertArrayHasKey('result', $initresponse);
        $this->assertArrayHasKey('resources', $initresponse['result']['capabilities']);

        // 2. Test resources/list.
        $listresponse = $server->handle_payload(json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'resources/list',
        ]));
        $this->assertArrayHasKey('result', $listresponse);
        $resources = $listresponse['result']['resources'];
        $this->assertIsArray($resources);
        $this->assertNotEmpty($resources);
        $this->assertSame('moodle://courses', $resources[0]['uri']);

        // 3. Test resources/templates/list.
        $temresponse = $server->handle_payload(json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'resources/templates/list',
        ]));
        $this->assertArrayHasKey('result', $temresponse);
        $templates = $temresponse['result']['resourceTemplates'];
        $this->assertIsArray($templates);
        $this->assertCount(3, $templates);

        $uris = array_column($templates, 'uriTemplate');
        $this->assertContains('moodle://course/{course_id}/syllabus', $uris);
        $this->assertContains('moodle://course/{course_id}/announcements', $uris);
        $this->assertContains('moodle://activity/{cmid}/rubric', $uris);

        // 4. Test resources/read for invalid URI.
        $badread = $server->handle_payload(json_encode([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'resources/read',
            'params' => ['uri' => 'moodle://invalid/uri'],
        ]));
        $this->assertArrayHasKey('error', $badread);
        $this->assertSame(-32602, $badread['error']['code']);

        $tokenproperty->setValue(null, (object)[
            'permissions' => json_encode(['scopes' => ['grade']]),
        ]);
        foreach (['resources/list', 'resources/templates/list', 'resources/read'] as $method) {
            $request = [
                'jsonrpc' => '2.0',
                'id' => 5,
                'method' => $method,
            ];
            if ($method === 'resources/read') {
                $request['params'] = ['uri' => 'moodle://courses'];
            }
            $response = $server->handle_payload(json_encode($request));
            $this->assertSame(-32602, $response['error']['code']);
            $this->assertSame('Resource not found or inaccessible.', $response['error']['message']);
        }
        $tokenproperty->setValue(null, null);
    }
}
