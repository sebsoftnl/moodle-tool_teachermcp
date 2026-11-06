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

use Exception;
use moodle_exception;
use Throwable;
use stdClass;
use context_system;
use tool_teachermcp\tools\base_tool;
use tool_teachermcp\local\argument_normalizer;
use tool_teachermcp\local\tool_registry;

/**
 * Model Context Protocol (MCP) JSON-RPC 2.0 server for Moodle.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class server {
    /** @var base_tool[] Map of registered tool instances by name */
    protected array $tools = [];

    /**
     * Tool scope categories mapping (N6).
     */
    public const TOOL_SCOPES = [
        // Read tools.
        'get_my_profile' => 'read',
        'get_teacher_courses' => 'read',
        'get_course_contents' => 'read',
        'get_group_students' => 'read',
        'read_activity_file' => 'read',
        'export_course_learning_material' => 'read',
        'detect_at_risk_students' => 'read',
        'analyze_class_understanding' => 'read',
        'get_h5p_libraries' => 'read',
        'manage_course_completion' => 'read',

        // Write tools.
        'manage_content_activity' => 'write',
        'create_h5p_activity' => 'write',
        'scaffold_assessment_unit' => 'write',
        'manage_question_bank' => 'write',
        'upload_course_asset' => 'write',
        'manage_activity' => 'write',
        'duplicate_activity' => 'write',
        'create_assignment' => 'write',
        'manage_course_calendar' => 'write',

        // Grade tools.
        'prepare_grading_session' => 'grade',
        'submit_assignment_grades' => 'grade',
        'manage_quiz_grades' => 'grade',
        'grade_submission' => 'grade',
        'manage_grades' => 'grade',

        // Message tools.
        'send_student_message' => 'message',

        // Delete tools.
        'delete_activity' => 'delete',
    ];

    /**
     * Constructor initializing all available teacher MCP tools.
     */
    public function __construct() {
        $this->tools = self::get_all_tools();
    }

    /**
     * Instantiate and register all available tool classes.
     *
     * @return base_tool[]
     */
    public static function get_all_tools(): array {
        return tool_registry::get_all_tools();
    }

    /**
     * Get the scope category for a tool.
     *
     * @param string $toolname
     * @return string
     */
    public static function get_tool_scope(string $toolname): string {
        $metadata = tool_registry::get_tool_metadata($toolname);
        if (!empty($metadata['external']) && isset($metadata['scope'])) {
            return $metadata['scope'];
        }
        return self::TOOL_SCOPES[$toolname] ?? 'read';
    }

    /**
     * Check if a tool scope is enabled by site administrator settings.
     *
     * @param string $scope Scope name ('read', 'write', 'grade', 'message', 'delete').
     * @return bool
     */
    public static function is_scope_enabled_by_admin(string $scope): bool {
        switch ($scope) {
            case 'write':
                $val = get_config('tool_teachermcp', 'enable_write_tools');
                return $val === false || $val === null || (int)$val === 1;
            case 'grade':
                $val = get_config('tool_teachermcp', 'enable_grade_tools');
                return $val === false || $val === null || (int)$val === 1;
            case 'message':
                $val = get_config('tool_teachermcp', 'enable_messaging_tool');
                return $val === false || $val === null || (int)$val === 1;
            case 'delete':
                $val = get_config('tool_teachermcp', 'enable_delete_tool');
                return $val === false || $val === null || (int)$val === 1;
            default:
                return true;
        }
    }

    /**
     * Determine whether a registered tool was contributed by another component.
     *
     * @param string $toolname MCP tool name.
     * @return bool True for a validated external tool.
     */
    public static function is_external_tool(string $toolname): bool {
        $metadata = tool_registry::get_tool_metadata($toolname);
        return !empty($metadata['external']);
    }

    /**
     * Check whether externally contributed tools are globally enabled.
     *
     * The default is deny, so a fresh or unset installation never publishes an
     * independently installed component's MCP tools without administrator action.
     *
     * @return bool
     */
    public static function is_external_tools_enabled_by_admin(?string $toolname = null): bool {
        if ((int)get_config('tool_teachermcp', 'enable_external_tools') !== 1) {
            return false;
        }
        if ($toolname === null) {
            return true;
        }
        $metadata = tool_registry::get_tool_metadata($toolname);
        $setting = $metadata['enabledsetting'] ?? null;
        if (!is_string($setting) || $setting === '') {
            // Registry validation makes this unreachable for a published v1
            // provider, but fail closed if a future registry violates it.
            return false;
        }
        [$component, $settingname] = explode('/', $setting, 2);
        return (int)get_config($component, $settingname) === 1;
    }

    /**
     * Return external MCP tool names currently approved for token selection.
     *
     * The result intentionally does not evaluate a user's provider capability:
     * providers must evaluate that against the real resource context at call time.
     *
     * @return string[]
     */
    public static function get_grantable_external_tool_names(): array {
        $names = [];
        foreach (self::get_tools() as $name => $unusedtool) {
            if (self::is_external_tool($name)) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /**
     * Check whether a tool is allowed for a given token record.
     *
     * @param string $toolname Name of the tool.
     * @param stdClass $token Token record.
     * @return bool True if allowed.
     */
    public static function is_tool_allowed_by_token(string $toolname, stdClass $token): bool {
        $toolscope = self::get_tool_scope($toolname);

        // External tools are opt-in per qualified name. Legacy wildcard and scope
        // grants apply only to the built-in catalogue and must never expand to a
        // newly installed provider.
        if (self::is_external_tool($toolname)) {
            if (empty($token->permissions)) {
                return false;
            }
            $allowed = is_array($token->permissions) ? $token->permissions : json_decode($token->permissions, true);
            if (!is_array($allowed)) {
                return false;
            }
            return !empty($allowed[$toolname]) || in_array($toolname, $allowed, true);
        }

        // 1. Check token permissions field (JSON encoded or array).
        if (!empty($token->permissions)) {
            $allowed = is_array($token->permissions) ? $token->permissions : json_decode($token->permissions, true);
            if (is_array($allowed)) {
                // If wildcard all_teacher_tools is true, tool is permitted.
                if (!empty($allowed['all_teacher_tools'])) {
                    return true;
                }

                // Check scopes sub-array: e.g. ['scopes' => ['read', 'write']]
                if (isset($allowed['scopes']) && is_array($allowed['scopes'])) {
                    if (in_array($toolscope, $allowed['scopes'], true) || in_array('all', $allowed['scopes'], true)) {
                        return true;
                    }
                }

                // Check associative keys: e.g. ['read' => true, 'get_my_profile' => true]
                if (!empty($allowed[$toolscope]) || !empty($allowed[$toolname])) {
                    return true;
                }

                // Check flat values list: e.g. ['read', 'write'] or ['get_my_profile', 'export_course_learning_material']
                if (in_array($toolscope, $allowed, true) || in_array($toolname, $allowed, true) || in_array('all', $allowed, true)) {
                    return true;
                }

                return false;
            }
        }

        // 2. Check token scope field if present (e.g. OAuth scope string).
        if (!empty($token->scope) && $token->scope !== 'teachermcp') {
            $scopes = preg_split('/[\s,]+/', trim($token->scope));
            if (!in_array($toolscope, $scopes, true) && !in_array('all', $scopes, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether a token may read MCP resources.
     *
     * Resources expose course data and therefore follow the same read-scope
     * policy as built-in read tools. External-tool grants never imply read access.
     *
     * @param stdClass|null $token Authenticated token record.
     * @return bool True when the token has a Teacher MCP read grant.
     */
    public static function is_resource_allowed_by_token(?stdClass $token): bool {
        if ($token === null) {
            return false;
        }

        return self::is_tool_allowed_by_token('get_course_contents', $token);
    }

    /**
     * Get a bounded numeric MCP limit from plugin configuration.
     *
     * Invalid or unset values use the supplied default; values are clamped to
     * the declared safe range.
     *
     * @param string $setting Plugin configuration setting name.
     * @param int $default Default value.
     * @param int $minimum Inclusive lower bound.
     * @param int $maximum Inclusive upper bound.
     * @return int Safe configured limit.
     */
    public static function get_mcp_limit(string $setting, int $default, int $minimum, int $maximum): int {
        $value = (int)get_config('tool_teachermcp', $setting);
        if ($value <= 0) {
            $value = $default;
        }
        return max($minimum, min($maximum, $value));
    }

    /**
     * Return a text response bounded to the configured MCP output budget.
     *
     * @param mixed $result Tool result.
     * @return string JSON-safe text result.
     */
    public static function format_tool_result_for_response(mixed $result): string {
        $text = is_string($result)
            ? $result
            : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($text === false) {
            $text = 'Unable to encode the tool response.';
        }

        $limit = self::get_mcp_limit('max_mcp_response_bytes', 262144, 1024, 5242880);
        if (strlen($text) <= $limit) {
            return $text;
        }

        $suffix = "\n[Output truncated at {$limit} bytes. Narrow the request or request a smaller result set.]";
        $available = max(0, $limit - strlen($suffix));
        return \core_text::str_max_bytes($text, $available) . $suffix;
    }

    /**
     * Bound each text value in an MCP resource response.
     *
     * @param array $response MCP resource response.
     * @return array Resource response with bounded text content.
     */
    public static function format_resource_response_for_response(array $response): array {
        if (!isset($response['contents']) || !is_array($response['contents'])) {
            return ['contents' => []];
        }

        foreach ($response['contents'] as $index => $content) {
            if (!is_array($content) || !isset($content['text']) || !is_string($content['text'])) {
                continue;
            }
            $response['contents'][$index]['text'] = self::format_tool_result_for_response($content['text']);
        }
        return $response;
    }

    /**
     * Record a tool execution event without storing tool arguments or results.
     *
     * @param string $toolname MCP tool name.
     * @param array $arguments Tool arguments, used only to select audit context.
     * @param stdClass|null $token Authenticated token record.
     * @param bool $success Whether execution succeeded.
     * @param Throwable|null $error Failure details, when available.
     * @return void
     */
    public static function log_tool_execution(
        string $toolname,
        array $arguments,
        ?stdClass $token,
        bool $success,
        ?Throwable $error = null
    ): void {
        $courseid = (int)($arguments['course_id'] ?? 0);
        $eventcontext = $courseid ? \context_course::instance($courseid, IGNORE_MISSING) : null;
        if (!$eventcontext) {
            $eventcontext = context_system::instance();
        }

        $other = [
            'tool' => $toolname,
            'tokenid' => $token ? $token->id : null,
            'success' => $success,
        ];
        $metadata = tool_registry::get_tool_metadata($toolname);
        if (!empty($metadata['external']) && !empty($metadata['component'])) {
            $other['providercomponent'] = $metadata['component'];
        }
        if (!$success && $error !== null) {
            $other['errorcode'] = $error instanceof \moodle_exception ? $error->errorcode : 'internal_error';
            $other['errorclass'] = (new \ReflectionClass($error))->getShortName();
        }

        $eventdata = [
            'context' => $eventcontext,
            'other' => $other,
        ];
        if ($courseid && $eventcontext instanceof \context_course) {
            $eventdata['courseid'] = $courseid;
        }
        \tool_teachermcp\event\tool_executed::create($eventdata)->trigger();
    }

    /**
     * Get all registered tool instances, filtered by admin policies and token permissions.
     * Supports both instance and static calls: server::get_tools() and $server->get_tools().
     *
     * @param stdClass|null $token Optional token to filter tools against.
     * @return base_tool[]
     */
    public static function get_tools(?stdClass $token = null): array {
        if ($token === null) {
            $token = auth::get_current_token();
        }

        $all = self::get_all_tools();
        $filtered = [];

        foreach ($all as $name => $tool) {
            $scope = self::get_tool_scope($name);
            if (self::is_external_tool($name) && !self::is_external_tools_enabled_by_admin($name)) {
                continue;
            }
            // Filter disabled scopes.
            if (!self::is_scope_enabled_by_admin($scope)) {
                continue;
            }
            // Filter by token permissions/scopes if token is present.
            if ($token && !self::is_tool_allowed_by_token($name, $token)) {
                continue;
            }
            $filtered[$name] = $tool;
        }

        return $filtered;
    }

    /**
     * Execute a tool by name with parameter validation and permission checks.
     *
     * @param string $toolname Name of the tool.
     * @param array $arguments Tool arguments.
     * @param stdClass|null $token Optional token to check permissions against.
     * @return mixed Tool execution result.
     * @throws moodle_exception If tool is unknown, disabled, or unpermitted.
     */
    public function execute_tool(string $toolname, array $arguments, ?stdClass $token = null): mixed {
        if (!isset($this->tools[$toolname])) {
            throw new moodle_exception('error_tool_not_found', 'tool_teachermcp', '', $toolname);
        }

        // N6: Verify scope is enabled by admin.
        $scope = self::get_tool_scope($toolname);
        if (self::is_external_tool($toolname) && !self::is_external_tools_enabled_by_admin($toolname)) {
            throw new moodle_exception('error_tool_disabled', 'tool_teachermcp', '', $toolname);
        }
        if (!self::is_scope_enabled_by_admin($scope)) {
            throw new moodle_exception('error_tool_disabled', 'tool_teachermcp', '', $toolname);
        }

        // N6: Verify tool is permitted by token.
        if ($token === null) {
            $token = auth::get_current_token();
        }
        if ($token && !self::is_tool_allowed_by_token($toolname, $token)) {
            throw new moodle_exception('error_tool_not_permitted', 'tool_teachermcp', '', $toolname);
        }

        // A destructive action inside a write-scoped tool needs an additional delete grant.
        if ($toolname === 'manage_question_bank' && ($arguments['action'] ?? null) === 'delete_question') {
            if (!self::is_scope_enabled_by_admin('delete')) {
                throw new moodle_exception('error_tool_disabled', 'tool_teachermcp', '', $toolname);
            }
            if ($token && !self::is_tool_allowed_by_token('delete_activity', $token)) {
                throw new moodle_exception('error_tool_not_permitted', 'tool_teachermcp', '', $toolname);
            }
        }

        $tool = $this->tools[$toolname];

        $schemavalidation = self::prepare_tool_arguments($tool, $arguments);
        if ($schemavalidation !== true) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null, (string)$schemavalidation);
        }

        return $tool->execute($arguments);
    }

    /**
     * Magic static method call handler for backwards compatibility.
     *
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments) {
        $instance = new self();
        if ($name === 'execute_tool') {
            return $instance->execute_tool(...$arguments);
        }
        if (method_exists($instance, $name)) {
            return $instance->$name(...$arguments);
        }
        throw new \BadMethodCallException("Method {$name} does not exist on server.");
    }

    /**
     * Handle incoming JSON-RPC payload string and produce JSON response.
     *
     * @param string $rawpayload Raw JSON string from request body.
     * @return array|null Response structure, or null if all requests were notifications.
     */
    public function handle_payload(string $rawpayload): ?array {
        $data = json_decode($rawpayload, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return $this->build_jsonrpc_error(null, -32700, 'Parse error: Invalid JSON received.');
        }

        // Empty batch or object is invalid JSON-RPC 2.0.
        if (empty($data)) {
            return $this->build_jsonrpc_error(null, -32600, 'Invalid Request: Empty batch or object.');
        }

        if (array_is_list($data) && count($data) > self::get_mcp_limit('max_mcp_batch_size', 10, 1, 50)) {
            return $this->build_jsonrpc_error(null, -32600, 'Invalid Request: Batch size exceeds the configured limit.');
        }

        // Support batch requests.
        if (array_is_list($data)) {
            $responses = [];
            foreach ($data as $request) {
                if (!is_array($request)) {
                    $responses[] = $this->build_jsonrpc_error(null, -32600, 'Invalid Request: Batch element must be an object.');
                    continue;
                }
                $res = $this->process_single_request($request);
                if ($res !== null) {
                    $responses[] = $res;
                }
            }
            return empty($responses) ? null : $responses;
        }

        return $this->process_single_request($data);
    }

    /**
     * Process an individual JSON-RPC 2.0 request.
     *
     * @param array $req Request array.
     * @return array|null Response or null for notifications.
     */
    protected function process_single_request(array $req): ?array {
        $isnotification = !array_key_exists('id', $req);
        $id = $req['id'] ?? null;
        $method = $req['method'] ?? '';

        if (empty($method) || !is_string($method)) {
            return $isnotification ? null : $this->build_jsonrpc_error($id, -32600, 'Invalid Request: Missing or invalid method.');
        }

        if (isset($req['params']) && !is_array($req['params'])) {
            return $isnotification ? null : $this->build_jsonrpc_error($id, -32602, 'Invalid params: Parameters must be an object or array.');
        }

        $params = $req['params'] ?? [];

        try {
            switch ($method) {
                case 'initialize':
                    if ($isnotification) {
                        return null;
                    }
                    return $this->build_jsonrpc_success($id, [
                        'protocolVersion' => '2024-11-05',
                        'serverInfo' => [
                            'name' => 'moodle-teachermcp',
                            'version' => \core_plugin_manager::instance()->get_plugin_info('tool_teachermcp')->release,
                        ],
                        'capabilities' => [
                            'tools' => [
                                'listChanged' => false,
                            ],
                            'prompts' => [
                                'listChanged' => false,
                            ],
                            'resources' => [
                                'subscribe' => false,
                                'listChanged' => false,
                            ],
                            'logging' => new \stdClass(),
                        ],
                    ]);

                case 'notifications/initialized':
                    // Client acknowledgment notification; no return value.
                    return null;

                case 'ping':
                    if ($isnotification) {
                        return null;
                    }
                    return $this->build_jsonrpc_success($id, new \stdClass());

                case 'tools/list':
                    if ($isnotification) {
                        return null;
                    }
                    $toollist = [];
                    foreach ($this->get_tools() as $tool) {
                        $toollist[] = [
                            'name' => $tool->get_name(),
                            'description' => $tool->get_description(),
                            'inputSchema' => $tool->get_input_schema(),
                        ];
                    }
                    return $this->build_jsonrpc_success($id, ['tools' => $toollist]);

                case 'prompts/list':
                    if ($isnotification) {
                        return null;
                    }
                    return $this->build_jsonrpc_success($id, [
                        'prompts' => \tool_teachermcp\local\prompt_registry::get_all_prompts(),
                    ]);

                case 'prompts/get':
                    if ($isnotification) {
                        return null;
                    }
                    $promptname = $params['name'] ?? '';
                    $promptargs = $params['arguments'] ?? [];

                    if (!is_string($promptname) || empty($promptname)) {
                        return $this->build_jsonrpc_error($id, -32602, 'Invalid params: Missing prompt name.');
                    }

                    if (!is_array($promptargs)) {
                        return $this->build_jsonrpc_error($id, -32602, 'Invalid params: Arguments must be an object.');
                    }

                    $prompt = \tool_teachermcp\local\prompt_registry::get_prompt($promptname, $promptargs);
                    if ($prompt === null) {
                        return $this->build_jsonrpc_error($id, -32602, "Prompt '{$promptname}' not found.");
                    }
                    return $this->build_jsonrpc_success($id, $prompt);

                case 'resources/list':
                    if ($isnotification) {
                        return null;
                    }
                    $token = auth::get_current_token();
                    if (!self::is_resource_allowed_by_token($token)) {
                        return $this->build_jsonrpc_error($id, -32602, 'Resource not found or inaccessible.');
                    }
                    return $this->build_jsonrpc_success($id, [
                        'resources' => \tool_teachermcp\local\resource_registry::get_resources(),
                    ]);

                case 'resources/templates/list':
                    if ($isnotification) {
                        return null;
                    }
                    $token = auth::get_current_token();
                    if (!self::is_resource_allowed_by_token($token)) {
                        return $this->build_jsonrpc_error($id, -32602, 'Resource not found or inaccessible.');
                    }
                    return $this->build_jsonrpc_success($id, [
                        'resourceTemplates' => \tool_teachermcp\local\resource_registry::get_templates(),
                    ]);

                case 'resources/read':
                    if ($isnotification) {
                        return null;
                    }
                    $uri = $params['uri'] ?? '';
                    if (!is_string($uri) || empty($uri)) {
                        return $this->build_jsonrpc_error($id, -32602, 'Invalid params: Missing or invalid uri.');
                    }
                    $token = auth::get_current_token();
                    if (!self::is_resource_allowed_by_token($token)) {
                        return $this->build_jsonrpc_error($id, -32602, 'Resource not found or inaccessible.');
                    }
                    $res = \tool_teachermcp\local\resource_registry::read_resource($uri);
                    if ($res === null) {
                        return $this->build_jsonrpc_error($id, -32602, 'Resource not found or inaccessible.');
                    }
                    return $this->build_jsonrpc_success(
                        $id,
                        self::format_resource_response_for_response($res)
                    );

                case 'tools/call':
                    if ($isnotification) {
                        return null;
                    }
                    $toolname = $params['name'] ?? '';
                    $arguments = $params['arguments'] ?? [];

                    if (!is_string($toolname) || empty($toolname)) {
                        return $this->build_jsonrpc_error($id, -32602, 'Invalid params: Missing or invalid tool name.');
                    }

                    if (!is_array($arguments)) {
                        return $this->build_jsonrpc_error($id, -32602, 'Invalid params: Arguments must be an object.');
                    }

                    if (!isset($this->tools[$toolname])) {
                        return $this->build_jsonrpc_success($id, [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => "Tool '{$toolname}' is not found or not permitted.",
                                ],
                            ],
                            'isError' => true,
                        ]);
                    }

                    // N6: Check admin scope toggle.
                    $scope = self::get_tool_scope($toolname);
                    if (self::is_external_tool($toolname) && !self::is_external_tools_enabled_by_admin($toolname)) {
                        return $this->build_jsonrpc_success($id, [
                            'content' => [['type' => 'text', 'text' => get_string('error_tool_disabled', 'tool_teachermcp', $toolname)]],
                            'isError' => true,
                        ]);
                    }
                    if (!self::is_scope_enabled_by_admin($scope)) {
                        return $this->build_jsonrpc_success($id, [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => get_string('error_tool_disabled', 'tool_teachermcp', $toolname),
                                ],
                            ],
                            'isError' => true,
                        ]);
                    }

                    // N6: Check token scopes / permissions.
                    $token = auth::get_current_token();
                    if ($token && !self::is_tool_allowed_by_token($toolname, $token)) {
                        return $this->build_jsonrpc_success($id, [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => get_string('error_tool_not_permitted', 'tool_teachermcp', $toolname),
                                ],
                            ],
                            'isError' => true,
                        ]);
                    }

                    $tool = $this->tools[$toolname];

                    $schemavalidation = self::prepare_tool_arguments($tool, $arguments);
                    if ($schemavalidation !== true) {
                        return $this->build_jsonrpc_error($id, -32602, 'Invalid params: ' . $schemavalidation);
                    }

                    // Execute tool.
                    $result = $tool->execute($arguments);

                    // Record the successful execution without retaining arguments or results.
                    self::log_tool_execution($toolname, $arguments, $token, true);

                    $textcontent = self::format_tool_result_for_response($result);

                    return $this->build_jsonrpc_success($id, [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $textcontent,
                            ],
                        ],
                        'isError' => false,
                    ]);

                default:
                    return $isnotification ? null : $this->build_jsonrpc_error($id, -32601, "Method '{$method}' not found.");
            }
        } catch (Throwable $e) {
            // For tool execution errors, MCP guidelines prefer returning isError: true in the result block.
            if ($method === 'tools/call') {
                self::log_tool_execution(
                    $toolname ?? 'unknown',
                    isset($arguments) && is_array($arguments) ? $arguments : [],
                    isset($token) && $token instanceof stdClass ? $token : auth::get_current_token(),
                    false,
                    $e
                );

                return $isnotification ? null : $this->build_jsonrpc_success($id, [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $e instanceof \moodle_exception
                                ? 'Moodle error: ' . $e->getMessage()
                                : 'Internal tool execution error.',
                        ],
                    ],
                    'isError' => true,
                ]);
            }

            return $isnotification ? null : $this->build_jsonrpc_error($id, -32603, 'Internal server error.');
        }
    }

    /**
     * Normalize and validate arguments before tool execution.
     *
     * @param base_tool $tool tool value.
     * @param array $arguments arguments value.
     * @return bool|string Result of the operation.
     */
    private static function prepare_tool_arguments(base_tool $tool, array &$arguments): bool|string {
        $validation = self::validate_tool_arguments($tool, $arguments);
        if ($validation !== true || self::is_external_tool($tool->get_name())) {
            return $validation;
        }
        try {
            $arguments = argument_normalizer::normalize($tool, $arguments);
        } catch (\InvalidArgumentException $error) {
            return $error->getMessage();
        }
        return true;
    }

    /**
     * Validate tool arguments against its inputSchema or against a raw schema array (M6).
     *
     * @param base_tool|array $toolorargs Tool instance or arguments array
     * @param array $argsorschema Arguments array or schema array
     * @return true|string|array True/string when validating tool, or ['valid' => bool, 'error' => string] for schema
     */
    public static function validate_tool_arguments($toolorargs, $argsorschema) {
        if ($toolorargs instanceof base_tool) {
            $schema = $toolorargs->get_input_schema();
            $args = is_array($argsorschema) ? $argsorschema : [];
            $res = self::validate_arguments_against_schema($args, $schema);
            return $res['valid'] ? true : $res['error'];
        }

        return self::validate_arguments_against_schema(
            is_array($toolorargs) ? $toolorargs : [],
            is_array($argsorschema) ? $argsorschema : []
        );
    }

    /**
     * Validate arguments against a JSON schema array.
     *
     * @param array $args Arguments to validate.
     * @param array $schema Schema definition.
     * @return array ['valid' => bool, 'error' => string]
     */
    public static function validate_arguments_against_schema(array $args, array $schema): array {
        $required = $schema['required'] ?? [];
        $properties = $schema['properties'] ?? [];

        foreach ($required as $field) {
            if (!array_key_exists($field, $args)) {
                return ['valid' => false, 'error' => "Missing required parameter '{$field}'."];
            }
        }

        foreach ($args as $name => $val) {
            if (!isset($properties[$name])) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    return ['valid' => false, 'error' => "Unexpected parameter '{$name}'."];
                }
                continue;
            }
            $prop = $properties[$name];
            $expectedtype = $prop['type'] ?? '';

            if (isset($prop['enum']) && is_array($prop['enum'])) {
                if (!in_array($val, $prop['enum'], true)) {
                    return ['valid' => false, 'error' => "Parameter '{$name}' must be one of: " . implode(', ', $prop['enum'])];
                }
            }

            switch ($expectedtype) {
                case 'integer':
                    if (!is_int($val) && !ctype_digit((string)$val)) {
                        return ['valid' => false, 'error' => "Parameter '{$name}' must be an integer."];
                    }
                    break;
                case 'number':
                    if (!is_numeric($val)) {
                        return ['valid' => false, 'error' => "Parameter '{$name}' must be a number."];
                    }
                    break;
                case 'boolean':
                    if (!is_bool($val) && $val !== 0 && $val !== 1 && $val !== '0' && $val !== '1' && $val !== 'true' && $val !== 'false') {
                        return ['valid' => false, 'error' => "Parameter '{$name}' must be a boolean."];
                    }
                    break;
                case 'string':
                    if (!is_string($val)) {
                        return ['valid' => false, 'error' => "Parameter '{$name}' must be a string."];
                    }
                    break;
                case 'array':
                    if (!is_array($val)) {
                        return ['valid' => false, 'error' => "Parameter '{$name}' must be an array."];
                    }
                    break;
            }
        }

        return ['valid' => true, 'error' => ''];
    }

    /**
     * Build standard JSON-RPC success response.
     */
    protected function build_jsonrpc_success($id, $result): array {
        return [
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id,
        ];
    }

    /**
     * Build standard JSON-RPC error response.
     */
    protected function build_jsonrpc_error($id, int $code, string $message): array {
        return [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'id' => $id,
        ];
    }
}
