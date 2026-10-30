# ChatGPT desktop: local Teacher MCP package

This local-desktop route uses the existing Node.js bridge, not a public ChatGPT remote-MCP connection. The generated package contains the bridge unchanged and connects to Moodle over HTTP/HTTPS using its existing browser OAuth flow.

**Qualification:** tested on ChatGPT desktop 26.1021.31345, macOS Apple Silicon, in a local Codex conversation against an owned Moodle 5.3/PHP 8.4 instance. The check covered installation, browser OAuth, teacher/course reads, one hidden Page creation/read-back and a cached profile read after restart. Independent teacher/student browser checks confirmed the Page stayed hidden. This does not qualify every tool, host version, operating system or remote ChatGPT connection.

The generator uses the explicit `.codex-plugin/plugin.json` / `.mcp.json` format. ChatGPT 26.1021.31345 recognized the initial portable-root package as installed but discovered no MCP servers; its host API did discover `moodle` with this explicit format. Installation alone is therefore not the success criterion.

## Prerequisites

- A current ChatGPT desktop app exposing Plugins and locally executed conversations (Work/Codex), not ChatGPT Classic. Account/rollout restrictions still apply. Check the actual UI; having Pro is not itself proof that a feature is enabled.
- Node.js 22.11 or later on the computer running the desktop app.
- An approved Moodle endpoint reachable from that computer and an ordinary teacher account with `tool/teachermcp:connectagent` and the course capabilities needed for the selected workflow. Use synthetic data and a disposable test course first.
- The pre-registered `claude-desktop` OAuth client created by the plugin installation/upgrade. The bridge uses this identifier even when ChatGPT launches it. It does not require enabling dynamic client registration.

No public URL or tunnel is needed when the local process can reach Moodle. The AI client still sends conversation/tool content to its service; use only data approved for that purpose. The package does not bypass Moodle permissions or destructive-action confirmation.

## Generate the package

From the `tool_teachermcp` checkout, using the exact approved endpoint:

```sh
node bridge/build-chatgpt-plugin.mjs \
  --endpoint 'https://your-moodle.example.com/admin/tool/teachermcp/endpoint.php' \
  --output "$HOME/teacher-mcp-desktop"
```

The endpoint above is illustrative, not a service to connect to. For an owned local fixture, use the endpoint from its operator handoff; loopback HTTP is accepted. Non-loopback endpoints must use HTTPS. Credentials, query parameters and fragments are rejected. Do not pass a bearer token; this package uses `--oauth`.

The output directory must not already exist, and its parent must exist. The generator refuses to overwrite existing files and does not edit your personal marketplace or ChatGPT/Codex settings. It prints the generated paths as JSON.

```text
teacher-mcp-desktop/
├── .agents/plugins/marketplace.json
└── plugins/moodle-teacher-mcp/
    ├── .codex-plugin/plugin.json
    ├── .mcp.json
    └── mcp-bridge.mjs
```

The manifest explicitly points `mcpServers` at `./.mcp.json`. The server uses `cwd: "./"`, which the tested host resolves relative to the installed plugin, and `./mcp-bridge.mjs` as the script argument. It does not depend on placeholder expansion: this host treated `${CLAUDE_PLUGIN_ROOT}` literally in the earlier draft. Its `command` is the absolute Node executable used to run the generator, avoiding GUI shell-PATH assumptions. A 240-second host startup allowance accommodates the bridge's bounded 180-second OAuth flow. Generate the package on the target computer; rebuild if that Node installation moves. The package contains your endpoint and local Node path, but no bearer/password. Keep generated output outside the plugin repository.

## Register and install locally

Choose one registration method, not both.

**With a supporting Codex CLI:** first check `codex plugin marketplace add --help`, then register the generated marketplace root:

```sh
codex plugin marketplace add "$HOME/teacher-mcp-desktop"
```

This is a user-level client configuration change, performed explicitly by you. If that command is unavailable, use the personal-marketplace method below rather than changing unrelated configuration or installing a random CLI package.

**Without that CLI:** OpenAI documents a personal marketplace at `~/.agents/plugins/marketplace.json`. If it exists, preserve its name, metadata and every other entry; merge only the new plugin entry into `plugins`. If an entry named `moodle-teacher-mcp` already exists, review it instead of duplicating or overwriting it. For a new personal marketplace and the output location shown above:

```json
{
  "name": "moodle-teacher-mcp-local",
  "interface": {"displayName": "Moodle Teacher MCP (Local)"},
  "plugins": [
    {
      "name": "moodle-teacher-mcp",
      "source": {
        "source": "local",
        "path": "./teacher-mcp-desktop/plugins/moodle-teacher-mcp"
      },
      "policy": {"installation": "AVAILABLE", "authentication": "ON_INSTALL"},
      "category": "Productivity"
    }
  ]
}
```

The personal `source.path` above is relative to your home marketplace root, not to `~/.agents/plugins/`. Change it if you chose a different output location inside that root. The generator's own marketplace already uses paths relative to its generated root; do not copy that file unchanged into the personal location.

1. Restart the ChatGPT desktop app, open Plugins, and select the local marketplace source.
2. Install `moodle-teacher-mcp`. Keep it local; public distribution/partner approval is a different process.
3. Allow the configured Node bridge to start when prompted. OAuth may start during installation or the first use. Confirm the browser is on the intended Moodle site, sign in as the test teacher and inspect consent before approving. Never paste credentials into the conversation.
4. Start a locally executed conversation and enable/select the plugin. If the local source or execution mode is absent, record the app version and visible controls and stop. Do not switch to a remote-MCP setup and treat it as the same test.

On first connection, the host may start several bridge instances and open multiple consent tabs before any credential is cached. Complete one current flow as the intended teacher and wait for **Authentication successful!**, then close the other tabs. If other instances remain pending, restart the app once to reuse the saved credential. Consent expires after three minutes; an old callback tab is not a new login attempt. Moodle's consent page may still name the shared registered client **Claude Desktop** even when ChatGPT launches it. The bridge's success page uses neutral wording and tells you to return to your AI app.

The bridge caches credentials per endpoint in its normal private credential store. On macOS that is `~/Library/Application Support/MoodleTeacherMCP/tokens.json`. A cached connection may skip the browser flow. Do not clear the entire store or the user's browser data. For disposable QA that reuses a normal site's localhost URL, the operator should give only the generated MCP child a fresh owned `HOME` using its local `.mcp.json` server `env` object before installation; do not change the desktop app's HOME. Remove that owned cache only during the run's teardown.

## Verify in the real app

First request only read operations:

> Use only the Moodle Teacher MCP plugin. Call get_my_profile and get_teacher_courses. Show the actual teacher identity, site and permitted course IDs. Do not create, update, grade, message or delete anything. Report unavailable tools or authentication errors instead of substituting browsing or terminal commands.

Compare the result with Moodle, then request `get_course_contents` for the discovered synthetic course. Expand and retain sanitized actual tool-call results; a prose answer is not proof of an MCP call.

After explicitly approving a write check:

> In the discovered synthetic course, use manage_content_activity with action create_page to create exactly one Page named ChatGPT desktop check, content `<p>ChatGPT desktop connection check.</p>`, and visible false. Show the returned course-module ID. Do not publish or create anything else. If the write times out, stop without retrying.

Review the proposed call/approval, then open the returned Page as the teacher in Moodle. Require the exact content and hidden state. Restart the app and repeat only the profile read to check reconnection; do not recreate the Page. Follow the existing [agent guide](../AGENTS.md) for teaching workflows and confirmation boundaries.

## Updates, cleanup and limits

The host installs a cached copy of the local bridge/package files. When those files change, regenerate into a new directory, update only this marketplace entry/source, and use the client's refresh/reinstall controls and restart. Retain the old source until the replacement is verified.

Tool descriptions and schemas come from Moodle through MCP discovery; the generated local package contains no PHP tool definitions. For server-only description changes, restart/reconnect the MCP server and start a new conversation, then compare the exposed definitions with a fresh authenticated `tools/list` response. Rebuilding an unchanged bridge is not a tool-catalog refresh. Workspace-managed remote ChatGPT connections have a separate frozen approval snapshot and require the authorized owner's tool refresh/review; see the [catalog-refresh guide](../README.md#refresh-tool-definitions-after-updates). Do not claim the client received updated definitions until that client actually exposes them.

Disconnect/uninstall only this plugin, remove only its marketplace registration, and have the authorized owner revoke the identified test Moodle connection. Delete only the output and isolated cache owned by the test. Shared marketplace files, other plugins and normal browser sessions must remain intact. Removing local files alone does not revoke Moodle access.

This is desktop-local packaging only. It does not repair remote ChatGPT OAuth discovery/transport compatibility, install Node, change Moodle settings or publish a plugin. Fresh OAuth, per-call approvals and the read/write/reconnect path above were observed on the stated configuration; other host versions and workflows require their own checks. Native Windows operation is not inferred from the macOS result. An ordinary Moodle login page in ChatGPT's web preview uses a separate browser session and does not imply that the MCP credential expired.

## References

- [OpenAI: Package your plugin and local marketplaces](https://developers.openai.com/plugins/build/plugins)
- [OpenAI: Desktop-only stdio plugins](https://developers.openai.com/plugins/deploy/desktop-only)
- [OpenAI: ChatGPT macOS app versus Classic](https://help.openai.com/en/articles/9275200-downloading-the-chatgpt-macos-app)

Packaging format checked 2026-10-26. Recheck host documentation if its UI or manifest support changes.
