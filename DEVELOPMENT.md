# Developing modxMCP

modxMCP connects an MCP client to a MODX Revolution site. The Node.js server exposes
MCP tools and sends authenticated HTTP requests to a PHP endpoint. The PHP model runs
MODX operations with the configured service user. The endpoint is a privileged
administrator/developer API; manager screens and processors require `settings`.

Target MODX 2.8.x and PHP 7.4. Keep compatibility with PHP 8.x where possible. Syntax
checks on PHP 8 do not prove runtime compatibility. The owner has not required a separate
PHP 8 integration environment. Node.js requires version 18 or later.

## Where to work

- `client/index.js`: MCP tool definitions, discovery and dispatch.
- `client/api.js`: HTTP transport, request limits and error handling.
- `client/contracts.js`: tool effect annotations and output schemas.
- `assets/components/modxmcp/api.php`: authentication and HTTP endpoint.
- `core/components/modxmcp/model/modxmcp.class.php`: facade and element dispatch.
- `core/components/modxmcp/model/traits/registry.trait.php`: action registry; other
  traits contain operations grouped by area. Traits share the facade's private scope.
- `model/requeststore.class.php`, `model/security.class.php`, `model/logreader.class.php`:
  replay protection, IP/HTTPS checks and bounded reverse log reading.
- `core/components/modxmcp/docs/`: documentation shipped with the component and served
  through `modx_help`. Manager controllers, processors, templates and lexicons are under
  the same component directory; public assets are in `assets/components/modxmcp/`.
- `_build/`: MODX transport builder, settings and resolvers. `scripts/check.cjs` performs
  local syntax and release metadata checks.

Use the code as the source of truth. `README.md` is the installation and client guide;
`CHANGELOG.md` is the release history. `AGENTS.md` is the concise agent entry point.

## Changing a tool

1. Find its definition in `client/index.js` and action in `actionRegistry()`. Inspect the
   implementing method and relevant MODX or add-on processors before changing behavior.
2. Update the server action, client input schema and `client/contracts.js` effects/output
   schema as needed. Availability and group gates are handled by the capabilities trait.
3. Update user-facing built-in help or settings/lexicons when behavior changes. Preserve
   the existing file encoding and line endings, particularly for server PHP files.

Effect annotations are hints, not permissions. Read/write classification also affects
request IDs; classify side effects conservatively. Successful calls return the legacy JSON
text and `structuredContent.result`, representing the same data in two forms.

## Checks and development site

Install dependencies with `npm ci --ignore-scripts` when necessary. Run `npm run check` for
JavaScript and PHP syntax and release-version consistency. The checker uses `PHP_BIN` if
set, otherwise portable PHP runtimes in `.local/tools/php-*/`, then `php` on PATH. These
checks do not exercise MODX, prove PHP runtime compatibility or test site behavior. The
1.8.20-to-1.10.0 upgrade was not integration-tested during the 1.10.0 release. Choose
additional verification appropriate to the change and report its limits. The owner prefers
functional checks personally and does not want unnecessary tests or test accounts.

A dedicated test stand has previously been configured in ignored `.mcp.json` and `.env`.
The owner has authorized using those connections as needed for component development.
Verify the actual target and current state before acting; do not use unrelated sites.
Before replacing site files, save their current contents and compare them to the expected
baseline. Upload dependencies before the facade and verify uploaded bytes. Preserve a
rollback path. Use FTPS where available; do not print credentials or include them in URLs,
commits, logs or release artifacts. A code change in the local client may require MCP
reconnection. For the general MODX cache, use `clear_cache` when appropriate.

## Important behavior

- HTTP requests use a token. Request IDs protect writes from accidental re-execution;
  after an uncertain write, use `get_request_status` and reuse the same ID only for the
  same request. Request history is stored in `core/modxmcp-data/requests` on the site,
  outside the component and MODX cache. Protect and back it up; deleting it loses replay
  protection history. Runtime state is not part of the transport package.
- The component has high privileges and supports optional integrations. Capability discovery
  considers settings, namespaces and installed code; keep this aligned with action dispatch.
  Restrictions such as disabled groups and `modxmcp.allow_run_processor` remain authoritative.
- HTTPS proxy handling trusts only configured peers; IP/CIDR rules are shared with the
  allowlist. Avoid weakening authorization, filesystem boundaries or request validation.
- Batch resource and static operations defer component-owned cache refreshes. MODX and
  third-party processors may perform their own cache work and events.
- `.env`, `.mcp.json`, `.local/`, `_reference/` and `_packages/` are ignored local files.
  They may contain credentials, backups, runtimes, build evidence or release artifacts.

## Release

Only prepare a release when requested. Keep the version aligned in `_build/build.config.php`,
`modxMCP::VERSION`, `package.json`, both root version fields in `package-lock.json`, and
`CHANGELOG.md`. Run local checks. The builder needs a real MODX installation and reads
`_build/`, `core/`, `assets/`, `README.md`, `CHANGELOG.md` and `LICENSE`. Run
`_build/build.transport.php` in that environment; the transport ZIP is written to the build
site's `core/packages/`. Prefer CLI: the web entry requires the API token in a `key` query
parameter, which can expose it through browser history or server logs.

Verify archive contents and ensure they contain no credentials, backups or development
artifacts. The Node package can be prepared with `npm pack --ignore-scripts`. The optional
`_build/install.transport.php` helper is CLI-only; use `--help` for options. Building,
installing, pushing Git commits and publishing a GitHub Release are separate actions.
