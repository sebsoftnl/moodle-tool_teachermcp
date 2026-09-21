# Teacher MCP Provider Security Requirements

This document applies to every plugin that publishes a Teacher MCP provider through `tool_teachermcp`.

## Trust boundary

Installing a Moodle plugin installs trusted PHP code. Teacher MCP cannot sandbox a provider. The platform reduces accidental exposure by requiring:

- Moodle discovery of installed plugin callbacks only;
- a valid versioned declaration;
- global Teacher MCP external-tool approval;
- a provider-owned default-off enable setting;
- an exact qualified token grant; and
- the normal Teacher MCP transport, schema, output, rate-limit, and audit controls.

These controls do not authorise a provider's domain action. The provider must do that itself.

## Required provider behaviour

1. Derive the Moodle context from the authorised target resource, never from a convenient system context or an untrusted ID alone.
2. Call `require_capability()` for the provider-declared capability in that real context, for the acting user from `execution_context`.
3. For course and activity data, apply the relevant active-enrolment and group-access rules before exposing personal data or changing content.
4. Validate domain invariants after Teacher MCP validates JSON schema. Schema validation does not prove an identifier refers to an authorised object.
5. Use Moodle APIs and `$DB` with bound parameters. Do not construct database SQL from model-controlled text.
6. Return a bounded, paginated result. Teacher MCP bounds final response size but providers should avoid expensive unbounded queries and loading unnecessary personal data.
7. Treat all agent-supplied text, URLs, HTML, filenames, and identifiers as untrusted. Use Moodle parameter/output APIs and avoid provider-controlled SSRF or remote file retrieval.
8. Never log a bearer token, raw headers, permissions JSON, raw MCP payload, or an unredacted tool result. `execution_context` deliberately cannot expose these values.
9. Implement Moodle privacy APIs if the provider stores personal data. A stateless provider should implement Moodle's `null_provider` metadata contract.
10. Use a new local tool ID for any material change in write or delete semantics. Do not repurpose a published MCP tool name.

## Provider callback rules

The callback is discovery metadata only. It must be fast, deterministic, and side-effect free. It must not write data, contact remote services, invoke MCP tools, or make decisions based on the current bearer token or request payload.

Provider failures are skipped from the MCP registry. Do not rely on a discovery failure being visible to an agent. A later unexpected provider exception is returned as a generic MCP error, not the provider exception text. Expose actionable diagnostics only to Moodle administrators and never include stack traces, secrets, or provider return payloads.

## Verification before release

Test at least these paths in a configured Moodle test environment:

- an unprivileged user is denied by the provider capability check;
- a valid user is denied until both global and provider switches are enabled;
- a valid user is denied without the exact qualified token grant;
- malformed callback output and an exception do not break valid built-in tools;
- schema-invalid input does not reach provider execution;
- a successful and failed call produce data-minimised Teacher MCP audit events;
- provider output is bounded and does not include unexpected personal data.
