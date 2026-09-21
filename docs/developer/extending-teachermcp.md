# Extending Teacher MCP

Teacher MCP can publish MCP tools from independently installed Moodle plugins. This is the supported v1 integration path for `local_*`, `tool_*`, and other standard Moodle components.

Teacher MCP remains the only MCP endpoint. It owns bearer-token authentication, token permissions, JSON schema validation, request and response limits, rate limiting, MCP response formatting, and privacy-minimised audit events. A provider must not implement a parallel MCP endpoint or receive a bearer token.

## Compatibility

This API is available from Teacher MCP v1.4.4-beta:

- public API version: `\tool_teachermcp\local\provider_registry::API_VERSION` (`1`);
- minimum Moodle: 4.5 LTS;
- minimum PHP: 8.1;
- public classes: `external_tool` and `execution_context` only;
- internal classes, including `server`, `base_tool`, registries, adapters, and declaration value objects, are not extension API.

A provider must return no declarations, rather than a declaration for a version it cannot support. Teacher MCP skips unknown API versions and invalid declarations without breaking its MCP endpoint.

## Install and enablement

Install the provider as a normal, separate Moodle plugin. Do not copy its classes into `admin/tool/teachermcp` and do not make it a Teacher MCP subplugin.

Discovery occurs through Moodle's normal callback mechanism, `get_plugins_with_function('teachermcp_get_tool_providers', 'lib.php')`. Moodle returns callbacks by plugin type and plugin name; Teacher MCP derives the component identity from that map. Discovery is not available during a plugin install or upgrade, so providers must not depend on discovery from upgrade logic.

For a discovered tool to appear in `tools/list` and execute, all of the following must be true:

1. The provider declaration is valid.
2. The Teacher MCP site setting “Enable externally contributed MCP tools” is enabled. Its default is disabled.
3. The provider's own declared enable setting is enabled. Its default must be disabled.
4. The token permissions contain the exact qualified MCP name.
5. The scope category is enabled by the Teacher MCP site policy.
6. The provider's own runtime capability and contextual access checks pass.

A legacy `all_teacher_tools`, `read`, or `all` token grant does not grant access to an external tool. Users can select approved external tools on the Teacher MCP agent-token page. OAuth clients request each approved qualified tool name in the OAuth `scope` value. In both flows, grant the exact name, for example:

```php
['local_example.lookup_student' => true]
```

## Callback contract

Put one callback in the contributing plugin's `lib.php`. Moodle discovers callback names ending in `teachermcp_get_tool_providers` through `get_plugins_with_function()`.

```php
function local_example_teachermcp_get_tool_providers(): array {
    return [[
        'api_version' => \tool_teachermcp\local\provider_registry::API_VERSION,
        'provider_id' => 'local_example',
        'tools' => [[
            'id' => 'lookup_student',
            'class' => \local_example\teachermcp\lookup_student_tool::class,
            'scope' => 'read',
            'capability' => 'local/example:usemcp',
            'enabled_setting' => 'local_example/enable_teachermcp_tools',
        ]],
    ]];
}
```

The callback must be deterministic, fast, side-effect free, and return only arrays. It must not execute a tool, write to the database, perform network calls, read bearer tokens, or mutate Teacher MCP server state.

## Declaration rules

Every provider declaration must contain exactly `api_version`, `provider_id`, and `tools`.

Every tool declaration must contain exactly `id`, `class`, `scope`, `capability`, and `enabled_setting`.

- `provider_id` must exactly match the declaring component, such as `local_example`.
- The local `id` must match `^[a-z][a-z0-9_]*$` and be at most 64 characters.
- The published MCP name is always `<component>.<id>`, for example `local_example.lookup_student`.
- The class must be inside the declaring component namespace and implement `\tool_teachermcp\local\external_tool`.
- `scope` must be one of `read`, `write`, `grade`, `message`, or `delete`.
- `capability` must be a Moodle capability name owned and enforced by the provider.
- `enabled_setting` is mandatory and must be a component-owned setting in the form `<component>/enable_<name>`.
- The v1 input schema is an object schema using only `type`, `properties`, `required`, and `additionalProperties`. Property schemas may use `type` and `enum`; supported types are `integer`, `number`, `boolean`, `string`, and `array`. Unknown schema keys are rejected at publication time.

Names collide fail closed. An external provider cannot replace a built-in tool or another provider's tool. A provider throwing an exception, returning malformed data, referencing a missing class, or publishing duplicate tool IDs contributes no affected tool.

## Tool interface

```php
namespace local_example\teachermcp;

final class lookup_student_tool implements \tool_teachermcp\local\external_tool {
    public function get_description(): string {
        return 'Returns a permitted course-specific student summary.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'course_id' => ['type' => 'integer'],
                'user_id' => ['type' => 'integer'],
            ],
            'required' => ['course_id', 'user_id'],
            'additionalProperties' => false,
        ];
    }

    public function execute(
        array $arguments,
        \tool_teachermcp\local\execution_context $context
    ): array {
        $coursecontext = \context_course::instance((int)$arguments['course_id']);
        require_capability('local/example:usemcp', $coursecontext, $context->get_userid());

        // Derive and authorise the target resource here before returning it.
        return ['status' => 'authorised'];
    }
}
```

`execution_context` is immutable. It exposes only the acting Moodle user ID and optional established course or course-module IDs. It intentionally does not expose bearer values, raw token records, request headers, raw request payloads, or permissions JSON.

The host validates the JSON-schema structure before calling the provider. Providers must still validate domain-level invariants, derive the actual Moodle context from authorised resources, enforce their own declared capability using `require_capability()`, and apply active-enrolment checks where appropriate.

Read [compatibility-policy.md](compatibility-policy.md) before depending on this beta API and [../security/extension-provider-security.md](../security/extension-provider-security.md) before publishing a provider.
