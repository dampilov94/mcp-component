# 34 — MCP over HTTP directly from the site (no Node bridge)

Branch: `task/34-mcp-http` (already created by the architect from `main`; work on it).
Spec: section 2 (architecture), 4 (manifest and client), 5 (protocol). Read first:
`src/Kernel.php` (request flow, auth, limits, envelope), `client/index.js` (how the manifest
becomes MCP tools, prompts and resources — this task moves the same mapping into PHP),
`src/Manifest.php`, `src/Action/ManifestAction.php`, `src/Action/PromptAction.php`,
`src/Action/HelpAction.php`, `src/Request/RequestStore.php`, `src/Manager/TokenService.php`
(`config()`), the token page JS, and the MCP specification:
- 2025-06-18 transports and lifecycle: https://modelcontextprotocol.io/specification/2025-06-18/basic/transports ,
  https://modelcontextprotocol.io/specification/2025-06-18/basic/lifecycle
- 2026-07-28 (current): https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http ,
  https://modelcontextprotocol.io/specification/2026-07-28/basic/lifecycle and the `server/discover` page it links.
Read those pages (you can fetch them); where this brief and the spec differ, follow the spec and
say so in the report.

## Why

Today a client needs Node and the `modx-mcp` bridge. Most AI tools (Claude Code, Codex CLI,
Gemini CLI, opencode, Cursor, VS Code, Cline, Zed) can talk to a remote MCP server over
Streamable HTTP with a static `Authorization: Bearer <token>` header. The site should answer
them directly at the same `api.php`, with the same tokens, tools, prompts, journal and limits.
OAuth (needed by claude.ai and ChatGPT web connectors) is out of scope.

## Design (decided — do not change)

### Routing

`api.php` keeps one URL. The kernel decides per request:
- `POST` whose body is JSON-RPC (an object with `"jsonrpc": "2.0"`, or an array of such objects)
  → the new MCP handler `src/Mcp/HttpServer.php`. Anything else on `POST` → protocol 2 as today
  (the Node bridge keeps working unchanged).
- `GET` with `Accept` containing `text/event-stream`, or with an `MCP-Protocol-Version` header →
  `405` with `Allow: POST` (no SSE stream is offered). Any other `GET` → the health probe as today.
- `DELETE` → `405`.
Shared before both: enabled check, HTTPS and IP rules, token authentication (`Authorization:
Bearer` is already accepted), payload and response size limits, output buffering, the service
user. For MCP requests these failures are answered as JSON-RPC errors with the HTTP status the
spec or common practice gives (401 with `WWW-Authenticate: Bearer realm="modxMCP"`, 403, 413,
415 …), never with the protocol-2 envelope. The token value never appears in any response.

`Origin`: if the header is present it must equal the site's own origin (from `site_url`) or be
listed in a new setting `modxmcp.allowed_origins` (comma-separated, default empty); otherwise
`403`. Command-line clients send no `Origin`.

### Protocol versions

Support both eras on the same URL, statelessly, answering every request with
`Content-Type: application/json` (never SSE):
- **initialize era**: `2024-11-05`, `2025-03-26`, `2025-06-18`, `2025-11-25`. `initialize` →
  `{protocolVersion, capabilities, serverInfo: {name: "modxMCP", version: Kernel::VERSION},
  instructions}`; answer with the client's version when supported, else the newest of this
  list. Issue an `Mcp-Session-Id` (random, 32 hex) on the `initialize` response; accept it on
  later requests only as the journal's session label (`Context::sessionId`) — no server-side
  session state, never 404 for an unknown id. `notifications/*` → `202` no body. A JSON array
  body (batch, allowed in 2025-03-26) is processed item by item and answered with an array
  (notifications produce no item; all-notifications → `202`). Honour `MCP-Protocol-Version`
  (unsupported value → `400`).
- **2026-07-28**: implement what the spec requires for a stateless server: the per-request
  `MCP-Protocol-Version` / `Mcp-Method` / `Mcp-Name` headers and their agreement with the body
  (`400`, `-32020`), the `_meta` fields, `server/discover`, the specified `404`/`400` error codes.
  No session id. Read the spec pages for the exact shapes; keep this part in its own class so it
  can follow the spec as it moves.

Capabilities: `tools` (`listChanged: false` — a stateless PHP endpoint cannot push; the tool list
refreshes when the client reconnects), `prompts` (`listChanged: false`), `resources` (no
subscribe). Methods: `ping`, `tools/list`, `tools/call`, `prompts/list`, `prompts/get`,
`resources/list`, `resources/read`, `resources/templates/list` (empty). Unknown method →
`-32601`. Pagination cursors are not needed (lists are small); ignore a `cursor`.

### Mapping (one PHP place, the same result as the bridge)

`src/Mcp/Catalog.php` turns the manifest into MCP objects exactly as `client/index.js` does
today: tool name, description, `inputSchema` (plus `_request_id` for write tools), `annotations`
(readOnly/destructive/idempotent/openWorld), `outputSchema` only for protocol `2025-06-18` and
newer; prompts (name, title, description, arguments) and help topics as resources
(`modx-help://<topic>`, `text/markdown`). Add a PHP test that compares `Catalog` with the bridge's
mapping on the same manifest (export the bridge's `toMcpTool` for the test, without changing its
behaviour) so the two cannot drift.

`tools/call`: runs the action through the same path as protocol 2 (scope, group, schema
validation, service user, replay protection when `_request_id` is given for a write, journal with
the session label). Result: `content: [{type: "text", text: <compact JSON>}]`, plus
`structuredContent` for protocol `2025-06-18`+, `isError: false`. A failure of the action
(`ApiException`, refusals, lint errors, conflicts) is a tool result with `isError: true` and the
text `MODX <code>: <message> details=<json>` (the same text the bridge produces, token redacted) —
not a JSON-RPC error. Unknown tool / invalid params of the MCP request itself → JSON-RPC `-32602`.
Internal errors → `isError: true` with the error id, details only with `modxmcp.debug`.

### Token page and docs

`TokenService::config()` becomes `configs($token)`: a map of ready snippets, shown on the token
page as a select "Клиент" with the snippet and a copy button (the existing box after creation;
default "Claude Code"). Exact formats (from the clients' official docs; keep them exactly):

- Claude Code — `.mcp.json`:
  `{"mcpServers":{"modx":{"type":"http","url":"<URL>","headers":{"Authorization":"Bearer <TOKEN>"}}}}`
- Codex CLI — `~/.codex/config.toml`: `[mcp_servers.modx]` `url = "<URL>"`
  `bearer_token_env_var = "MODX_MCP_TOKEN"` and a comment line `# export MODX_MCP_TOKEN=<TOKEN>`
- Gemini CLI — `settings.json`:
  `{"mcpServers":{"modx":{"httpUrl":"<URL>","headers":{"Authorization":"Bearer <TOKEN>"}}}}`
- opencode — `opencode.json`:
  `{"$schema":"https://opencode.ai/config.json","mcp":{"servers":{"modx":{"type":"remote","url":"<URL>","oauth":false,"headers":{"Authorization":"Bearer <TOKEN>"}}}}}`
- Cursor — `.cursor/mcp.json`:
  `{"mcpServers":{"modx":{"url":"<URL>","headers":{"Authorization":"Bearer <TOKEN>"}}}}`
- VS Code — `.vscode/mcp.json`:
  `{"servers":{"modx":{"type":"http","url":"<URL>","headers":{"Authorization":"Bearer <TOKEN>"}}}}`
- Cline — `cline_mcp_settings.json`:
  `{"mcpServers":{"modx":{"type":"streamableHttp","url":"<URL>","headers":{"Authorization":"Bearer <TOKEN>"}}}}`
- Zed — `settings.json`: `{"context_servers":{"modx":{"url":"<URL>","headers":{"Authorization":"Bearer <TOKEN>"}}}}`
- Через Node (Claude Desktop и клиенты без HTTP) — the existing `npx github:dampilov94/mcp-component`
  snippet.

JSON is pretty-printed with 2 spaces. `README.md`: the connection section lists HTTP first with
the Claude Code example and the table of clients (file → key names), the Node bridge as the
alternative and `mcp-remote` for clients without HTTP support:
`npx mcp-remote <URL> --transport http-only --header "Authorization:${AUTH_HEADER}"` with
`AUTH_HEADER="Bearer <TOKEN>"` in `env` (add `--allow-http` for a plain-HTTP site). Mention that
over HTTP the tool list refreshes on reconnect, while the bridge refreshes it at once. Built-in
help (`getting_started.md`): one paragraph.

### Settings

`modxmcp.allowed_origins` (textfield, area `modxmcp:security`, both lexicons).

## Out of scope

OAuth, SSE responses, server-initiated notifications, `subscriptions/listen`, resource
subscriptions, sampling/elicitation.

## Tests (required)

PHP (`tests/php/run.php`, block "MCP over HTTP"): `Catalog` equals the bridge mapping;
`initialize` negotiation for each supported and an unsupported version; each method; tool
errors as `isError`; `-32601`/`-32602`; batch; notifications 202; version header rules;
2026-07-28 header/body agreement and `server/discover`; Origin rules; outputSchema /
structuredContent only for 2025-06-18+; session label reaches the journal; `configs()` (every
snippet parses — JSON with `json_decode`, the TOML by a simple line check — and contains the URL
and token in the right key).
Node (`tests/endpoint.test.js`, through the real kernel behind PHP's built-in server): connect
with the official SDK client `StreamableHTTPClientTransport` (installed in `node_modules`) using
`requestInit.headers.Authorization`; list tools, call a read tool and a write tool and an undo,
list and get a prompt, list and read a resource; a wrong token is an error; GET with
`Accept: text/event-stream` gets 405; the protocol-2 bridge still works on the same URL. Update
exact counts only where they change.

## Acceptance

`npm run check`, `npm test`. Report as usual: which spec pages you read (with their revision),
every place the brief and the spec disagreed, and what needs the stand (real clients).
