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

/**
 * Built-in argument preparation and provider compatibility tests.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/argument_normalizer_provider_fixture.php');

use tool_teachermcp\local\tool_registry;
use tool_teachermcp\tools\base_tool;

/**
 * Built-in argument preparation and provider-v1 compatibility tests.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class argument_normalizer_test extends \advanced_testcase {
    /** @var array Explicit boolean positions in the approved built-in contract. */
    private const POSITIONS = [
        'get_teacher_courses' => ['only_active'],
        'get_course_contents' => ['include_descriptions'],
        'export_course_learning_material' => ['include_glossary'],
        'get_h5p_libraries' => ['include_schema_examples'],
        'prepare_grading_session' => ['only_ungraded'],
        'grade_submission' => ['applytoall'],
        'manage_activity' => ['visible'],
        'create_assignment' => ['allow_online_text', 'allow_file_submissions', 'visible'],
        'manage_content_activity' => ['is_subchapter', 'visible'],
        'manage_question_bank' => ['single', 'correct_truefalse'],
        'scaffold_assessment_unit' => ['visible'],
        'create_h5p_activity' => ['deploy_as_activity', 'add_to_content_bank', 'visible'],
    ];

    /** @var array Accepted wire values paired with their intended native values. */
    private const VALUES = [[true, true], [false, false], [0, false], [1, true],
        ['0', false], ['1', true], ['true', true], ['false', false]];

    /** @var \stdClass|null Token state saved for isolation. */
    private ?\stdClass $originaltoken = null;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->originaltoken = auth::get_current_token();
        $this->set_token(null);
        tool_registry::set_provider_callback_map_for_testing([]);
        argument_normalizer_provider_fixture::$calls = 0;
    }

    protected function tearDown(): void {
        $this->set_token($this->originaltoken);
        tool_registry::set_provider_callback_map_for_testing(null);
        parent::tearDown();
    }

    public function test_direct_dispatch_normalizes_every_builtin_boolean_position(): void {
        $this->assert_boolean_positions('direct');
    }

    public function test_rpc_dispatch_normalizes_every_builtin_boolean_position(): void {
        $this->assert_boolean_positions('rpc');
    }

    public function test_omitted_flags_unknown_fields_and_opaque_payloads_remain_unchanged(): void {
        $tools = server::get_all_tools();
        foreach (['direct', 'rpc'] as $transport) {
            foreach (self::POSITIONS as $name => $fields) {
                $schema = $tools[$name]->get_input_schema();
                [$server] = $this->server_with_probe($name, $schema);
                $arguments = $this->required_arguments($schema);
                $arguments['unknown'] = ['visible' => 'false', 'correct_truefalse' => null];
                foreach ($fields as $field) {
                    $this->assertArrayNotHasKey($field, $arguments);
                }
                $this->assertSame($arguments, $this->dispatch($server, $transport, $name, $arguments));
            }
            $name = 'create_h5p_activity';
            $schema = $tools[$name]->get_input_schema();
            [$server] = $this->server_with_probe($name, $schema);
            foreach (
                [['visible' => 'false', 'correct' => 'true', 'items' => [['flag' => '0']]],
                    '{"visible":"false","correct":false}'] as $payload
            ) {
                $arguments = $this->required_arguments($schema);
                $arguments['params'] = $payload;
                $arguments['intro'] = '<p>false</p>';
                $arguments['max_grade'] = '1';
                $this->assertSame($arguments, $this->dispatch($server, $transport, $name, $arguments));
            }
        }
    }

    public function test_nested_question_boolean_is_normalized_without_touching_other_question_data(): void {
        $name = 'scaffold_assessment_unit';
        $schema = server::get_all_tools()[$name]->get_input_schema();
        foreach (['direct', 'rpc'] as $transport) {
            [$server] = $this->server_with_probe($name, $schema);
            foreach (self::VALUES as [$input, $expected]) {
                $arguments = $this->required_arguments($schema);
                $arguments['questions'] = [
                    ['name' => 'true', 'text' => '<p>false</p>', 'qtype' => 'truefalse',
                        'correct_truefalse' => $input, 'unknown' => 'false'],
                    ['name' => 'Essay', 'text' => 'false', 'qtype' => 'essay',
                        'options' => [['answer' => 'false', 'unknown' => 'true']]],
                ];
                $wanted = $arguments;
                $wanted['questions'][0]['correct_truefalse'] = $expected;
                $this->assertSame(
                    $wanted,
                    $this->dispatch($server, $transport, $name, $arguments),
                    $transport . ' must normalize only questions[].correct_truefalse'
                );
            }
        }
    }

    public function test_invalid_declared_booleans_are_rejected_before_execution(): void {
        $tools = server::get_all_tools();
        foreach (['direct', 'rpc'] as $transport) {
            foreach (self::POSITIONS as $name => $fields) {
                $schema = $tools[$name]->get_input_schema();
                [$server, $probe] = $this->server_with_probe($name, $schema);
                foreach ($fields as $field) {
                    foreach ([null, 2, -1, 0.0, 1.0, '', 'TRUE', 'False', 'yes', 'no', [], ['true']] as $invalid) {
                        $arguments = $this->required_arguments($schema);
                        $arguments[$field] = $invalid;
                        $this->assert_rejected($server, $transport, $name, $arguments);
                        $this->assertSame(0, $probe->calls, 'Invalid boolean must not reach a built-in');
                    }
                }
            }
        }
    }

    public function test_invalid_nested_boolean_is_rejected_before_execution(): void {
        $name = 'scaffold_assessment_unit';
        $schema = server::get_all_tools()[$name]->get_input_schema();
        foreach (['direct', 'rpc'] as $transport) {
            [$server, $probe] = $this->server_with_probe($name, $schema);
            foreach ([null, 2, -1, 0.0, 1.0, '', 'TRUE', 'False', 'yes', 'no', [], ['true']] as $invalid) {
                $arguments = $this->required_arguments($schema);
                $arguments['questions'] = [['name' => 'Question', 'text' => 'False?',
                    'qtype' => 'truefalse', 'correct_truefalse' => $invalid]];
                $this->assert_rejected($server, $transport, $name, $arguments);
                $this->assertSame(0, $probe->calls, 'Invalid nested boolean must not reach a built-in');
            }
        }
    }

    public function test_original_top_level_enum_and_unknown_key_rules_run_before_normalization(): void {
        $schema = ['type' => 'object', 'properties' => ['flag' => ['type' => 'boolean', 'enum' => [false]]],
            'required' => ['flag'], 'additionalProperties' => false];
        foreach (['direct', 'rpc'] as $transport) {
            [$server, $probe] = $this->server_with_probe('boolean_probe', $schema);
            $this->assertSame(['flag' => false], $this->dispatch($server, $transport, 'boolean_probe', ['flag' => false]));
            foreach ([['flag' => 'false'], ['flag' => 0], ['flag' => false, 'extra' => 'false'], []] as $arguments) {
                $this->assert_rejected($server, $transport, 'boolean_probe', $arguments);
            }
            $this->assertSame(1, $probe->calls, 'Strict enum and unknown-field rejection must precede execution');
        }
    }

    public function test_provider_v1_values_enums_and_exact_grants_remain_unchanged(): void {
        tool_registry::set_provider_callback_map_for_testing([
            'tool_teachermcp' => [argument_normalizer_provider_fixture::class, 'provider'],
        ]);
        set_config('enable_external_tools', 1, 'tool_teachermcp');
        set_config('enable_boolean_probe', 1, 'tool_teachermcp');
        $name = 'tool_teachermcp.boolean_probe';
        $server = new server();
        $this->assertTrue(server::is_external_tool($name));
        foreach (['direct', 'rpc'] as $transport) {
            $this->set_token((object)['id' => 1, 'permissions' => json_encode([$name => true])]);
            foreach (self::VALUES as [$input]) {
                $arguments = ['flag' => $input, 'restricted' => false,
                    'payload' => ['visible' => 'false', 'questions' => [['correct_truefalse' => 'false']]]];
                $this->assertSame(
                    $arguments,
                    $this->dispatch($server, $transport, $name, $arguments),
                    'Provider v1 must receive original argument values and types'
                );
            }
            $before = argument_normalizer_provider_fixture::$calls;
            $this->assert_rejected($server, $transport, $name, ['flag' => true, 'restricted' => 'false']);
            $this->assert_rejected($server, $transport, $name, ['flag' => 'yes']);
            $this->assert_rejected($server, $transport, $name, ['flag' => false, 'extra' => 'false']);
            $this->set_token((object)['id' => 1, 'permissions' => json_encode(['all_teacher_tools' => true])]);
            if ($transport === 'direct') {
                try {
                    $server->execute_tool($name, ['flag' => 'false']);
                    $this->fail('A wildcard must not grant access to a provider');
                } catch (\moodle_exception $error) {
                    $this->assertSame('error_tool_not_permitted', $error->errorcode);
                }
            } else {
                $response = $this->rpc($server, $name, ['flag' => 'false']);
                $this->assertTrue($response['result']['isError']);
            }
            $this->assertSame($before, argument_normalizer_provider_fixture::$calls);
        }
    }

    public function test_h5p_visibility_is_a_declared_hidden_by_default_boolean(): void {
        $schema = server::get_all_tools()['create_h5p_activity']->get_input_schema();
        $this->assertArrayHasKey('visible', $schema['properties'], 'Consumed H5P visibility must be declared');
        $this->assertSame('boolean', $schema['properties']['visible']['type']);
        $this->assertFalse($schema['properties']['visible']['default']);
    }

    private function assert_boolean_positions(string $transport): void {
        $tools = server::get_all_tools();
        foreach (self::POSITIONS as $name => $fields) {
            $schema = $tools[$name]->get_input_schema();
            [$server] = $this->server_with_probe($name, $schema);
            foreach ($fields as $field) {
                $this->assertArrayHasKey($field, $schema['properties'], $name . '.' . $field);
                $this->assertSame('boolean', $schema['properties'][$field]['type']);
                foreach (self::VALUES as [$input, $expected]) {
                    $arguments = $this->required_arguments($schema);
                    $arguments[$field] = $input;
                    $wanted = $arguments;
                    $wanted[$field] = $expected;
                    $this->assertSame(
                        $wanted,
                        $this->dispatch($server, $transport, $name, $arguments),
                        $transport . ' ' . $name . '.' . $field . ' must receive native boolean for ' . json_encode($input)
                    );
                }
            }
        }
    }

    private function required_arguments(array $schema): array {
        $arguments = [];
        foreach ($schema['required'] ?? [] as $name) {
            $property = $schema['properties'][$name];
            $arguments[$name] = $property['enum'][0] ?? match ($property['type']) {
                'integer', 'number' => 1,
                'array', 'object' => [],
                'boolean' => false,
                'string' => 'Fixture',
            };
        }
        return $arguments;
    }

    private function server_with_probe(string $name, array $schema): array {
        $probe = new class ($name, $schema) extends base_tool {
            /** @var int Number of calls reaching the probe. */
            public int $calls = 0;

            /** @var string Tool name. */
            private string $toolname;

            /** @var array Tool schema. */
            private array $schema;

            public function __construct(string $toolname, array $schema) {
                $this->toolname = $toolname;
                $this->schema = $schema;
                parent::__construct();
            }

            public function get_name(): string {
                return $this->toolname;
            }

            public function get_description(): string {
                return 'Captures arguments delivered by the server.';
            }

            public function get_input_schema(): array {
                return $this->schema;
            }

            public function execute(array $params): array {
                $this->calls++;
                return $params;
            }
        };
        $server = new server();
        $property = new \ReflectionProperty(server::class, 'tools');
        $property->setAccessible(true);
        $property->setValue($server, [$name => $probe]);
        return [$server, $probe];
    }

    private function dispatch(server $server, string $transport, string $name, array $arguments): array {
        if ($transport === 'direct') {
            return $server->execute_tool($name, $arguments);
        }
        $response = $this->rpc($server, $name, $arguments);
        $this->assertArrayHasKey('result', $response);
        $this->assertFalse($response['result']['isError']);
        return json_decode($response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Keep floating point invalid inputs distinct from accepted integers on the JSON path.
     *
     * @param server $server Server being exercised.
     * @param string $name Tool name.
     * @param array $arguments Request arguments.
     * @return array RPC response.
     */
    private function rpc(server $server, string $name, array $arguments): array {
        return $server->handle_payload(json_encode(['jsonrpc' => '2.0', 'id' => 42, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function assert_rejected(server $server, string $transport, string $name, array $arguments): void {
        if ($transport === 'direct') {
            try {
                $server->execute_tool($name, $arguments);
                $this->fail('Invalid boolean/enum/schema input must be rejected before execution: ' . $name);
            } catch (\moodle_exception $error) {
                $this->assertSame('error_invalid_params', $error->errorcode);
            }
        } else {
            $response = $this->rpc($server, $name, $arguments);
            $this->assertArrayHasKey('error', $response, 'Invalid parameters must produce a JSON-RPC error');
            $this->assertSame(-32602, $response['error']['code']);
        }
    }

    private function set_token(?\stdClass $token): void {
        $property = new \ReflectionProperty(auth::class, 'currenttoken');
        $property->setAccessible(true);
        $property->setValue(null, $token);
    }
}
