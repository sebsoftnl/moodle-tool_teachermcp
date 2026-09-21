# Teacher MCP Provider API Compatibility Policy

## Public API

Teacher MCP v1.4.4-beta exposes provider API version `1` for Moodle 4.5 LTS and PHP 8.1 or later.

The supported public PHP types are:

- `\tool_teachermcp\local\external_tool`
- `\tool_teachermcp\local\execution_context`
- `\tool_teachermcp\local\provider_registry::API_VERSION`

The callback name suffix and declaration shape documented in `docs/developer/extending-teachermcp.md` are also public API.

## Not public API

Providers must not subclass, instantiate, mutate, or persist the following implementation details:

- `\tool_teachermcp\server`
- `\tool_teachermcp\tools\base_tool`
- `\tool_teachermcp\local\tool_registry`
- `\tool_teachermcp\local\external_tool_adapter`
- `\tool_teachermcp\local\provider_registry`
- `\tool_teachermcp\local\provider_tool_declaration`

They are host internals and may change in a beta release without a provider migration path.

## Change policy

- A compatible implementation fix may be released in a patch version.
- Adding an optional declaration field or an independent future contribution type requires a documented compatible API version.
- Removing or changing an existing required public method, declaration field, validation rule, permission rule, or name-generation rule requires a new provider API version.
- A provider declares the API version it implements. Teacher MCP skips unsupported versions rather than attempting compatibility guessing.
- A provider should test the oldest supported Moodle and Teacher MCP release before publishing a new version.

## Support boundary

This provider API is beta. The core transport enforces generic MCP policy, but provider authors remain responsible for domain authorisation, Moodle privacy obligations, resource limits, input/output safety, and semantic correctness of their own tools.
