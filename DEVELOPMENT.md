# Developing modxMCP

modxMCP connects an MCP client to a MODX Revolution site. The Node.js server exposes
MCP tools and sends authenticated HTTP requests to a PHP endpoint. The PHP model runs
MODX operations with the configured service user. The endpoint is a privileged
administrator/developer API; manager screens and processors require `settings`.

Target MODX 2.8.x and PHP 7.4. Keep compatibility with PHP 8.x where possible. Syntax
checks on PHP 8 do not prove runtime compatibility. The owner has not required a separate
PHP 8 integration environment. Node.js requires version 18 or later.

## Where to work

Version 2.0 is being built in stages against `docs/spec-2.0.md`; `CHANGELOG.md` says which
stages have landed. It is not compatible with 1.x clients or tool names.

- `core/components/modxmcp/src/`: the 2.0 core, namespace `ModxMCP\`, loaded by
  `src/autoload.php`. `Kernel.php` handles HTTP, authentication, validation and the response
  envelope. `Action/` holds one class per action; `Action/Registry.php` lists them.
  `Kind/` holds one class per object kind; `Kind/KindRegistry.php` lists them. `Auth/` has
  the token store, scopes and IP/HTTPS checks; `Request/` the replay-protection store;
  `Manifest.php` builds the per-token tool list; `Schema.php` is the schema subset and its
  validator.
- `client/index.js`: thin MCP bridge. It fetches the manifest from the site and exposes it as
  MCP tools; it contains no tool definitions. `client/api.js`: HTTP transport and envelope.
- `assets/components/modxmcp/api.php`: bootstraps MODX and calls the kernel.
- `core/components/modxmcp/model/`: the 1.x facade and traits. The API no longer calls them.
  They stay as porting source and because the manager dependency graph still uses the facade;
  remove a part only when its replacement in `src/` has landed.
- `tests/`: offline tests. `tests/php/run.php` covers pure logic; `tests/php/endpoint.php` runs
  the real kernel with stubbed MODX classes and a SQLite token table; the `*.test.js` files
  drive the bridge and that kernel.
- `core/components/modxmcp/docs/`: documentation shipped with the component and served
  through `modx_help`. Manager controllers, processors, templates and lexicons are under
  the same component directory; public assets are in `assets/components/modxmcp/`.
- `_build/`: MODX transport builder, settings and resolvers. `scripts/check.cjs` performs
  local syntax and release metadata checks.

Use the code as the source of truth. `README.md` is the installation and client guide;
`CHANGELOG.md` is the release history. `AGENTS.md` is the concise agent entry point.

## Changing a tool

1. An action is one class in `src/Action/` extending `Action`. It declares its name,
   description, input and output schema, scope, group and effect; the manifest, argument
   validation, access checks and client annotations all derive from those methods. To add a
   tool, add the class and list it in `Action/Registry.php`. Nothing changes in the client.
2. Keep schemas inside the subset `Schema.php` validates, and make every action return a JSON
   object. Empty maps must encode as `{}`, not `[]`.
3. A new object kind is one class in `src/Kind/` listed in `Kind/KindRegistry.php`.
4. Update built-in help or settings/lexicons when behavior changes. Preserve the existing
   file encoding and line endings, particularly for server PHP files.

`readOnly()` decides whether a call runs under request-ID replay protection; classify side
effects conservatively. Scope and group checks are enforced by the kernel, not by the
client. Successful calls return compact JSON text and the same object as `structuredContent`.

## Checks and development site

Install dependencies with `npm ci --ignore-scripts` when necessary. Run `npm run check` for
JavaScript and PHP syntax and release-version consistency. The checker uses `PHP_BIN` if
set, otherwise portable PHP runtimes in `.local/tools/php-*/`, then `php` on PATH. Run
`npm test` for the offline tests; the kernel tests need PHP with `pdo_sqlite` and are skipped
without it. Neither command exercises MODX, proves PHP runtime compatibility or tests site
behavior: MODX classes are stubbed and the token table is SQLite, while a site uses MySQL. Choose
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

- HTTP requests use a token from the `modxmcp_tokens` table (hash only; created in the
  manager). A token's scope and denied groups decide which actions it may call. The table is
  created by the install resolver and, for file-only deployments, on the first request that
  finds it missing; at that moment a 1.x `modxmcp.api_token` is imported as an admin token.
  Request IDs protect writes from accidental re-execution;
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
