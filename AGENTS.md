# modxMCP: instructions for development agents

## Project

modxMCP connects an AI client to MODX Revolution through a Node.js MCP server and a
PHP API. Target MODX 2.8.x and PHP 7.4; keep PHP 8.x compatibility where possible.
The API is intended for administrators and developers and has high privileges.

Version 2.0 is in progress: `docs/spec-2.0.md` is the approved specification and lists the
stages; `CHANGELOG.md` records which stages have landed. 2.0 is not compatible with 1.x.

- `core/components/modxmcp/src/` is the 2.0 core (namespace `ModxMCP\`): `Kernel.php` is the
  HTTP entry point, `Action/` has one class per tool (registered in `Action/Registry.php`),
  `Kind/` one class per object kind, `Auth/` tokens and scopes, `Request/` replay protection.
- `client/index.js` is a thin MCP bridge that serves the site's tool manifest; `client/api.js`
  handles HTTP. Tools are never defined in the client.
- `assets/components/modxmcp/api.php` bootstraps MODX and hands the request to the kernel.
- `core/components/modxmcp/model/` is the 1.x model: porting source for later stages and the
  backend of the manager dependency graph. The API no longer calls it; delete a part only
  when its replacement in `src/` has landed.
- `core/components/modxmcp/docs/` is built-in help served by `modx_help` and shipped
  with the component. `_build/` creates the MODX transport package.
- `README.md` is the user guide; `CHANGELOG.md` records releases. `DEVELOPMENT.md`
  has further technical detail. Source code is authoritative for current behavior.

## Working practices

- Follow the user's current scope and preferences. Keep changes focused and preserve
  existing local work. The user prefers to review tasks one at a time and perform
  functional checks personally; avoid unnecessary tests and test accounts.
- Before changing an action, inspect its class, its registry entry and the spec section it
  implements. Preserve existing access controls unless explicitly changed.
- `npm run check` checks JavaScript and PHP syntax and release metadata. `npm test` runs
  offline tests (pure PHP logic, the kernel with stubbed MODX, the bridge end to end);
  neither exercises a real MODX site. It uses `PHP_BIN`, available `.local/tools/php-*` runtimes, or `php`
  on PATH. Run checks appropriate to the change and report what was not verified.
- Preserve the existing file encoding and line endings, especially in PHP server files.
- The dedicated development stand has previously been accessed through `.mcp.json` and
  `.env`. Confirm the target and current state before site operations; do not use unrelated
  sites. Back up and compare existing server files before replacing them.
- Keep credentials out of output, commits, and release artifacts. `.env`, `.mcp.json`,
  `.local/`, `_reference/` and `_packages/` are ignored; they may contain credentials,
  backups or local tools. Do not remove them indiscriminately.
- `core/modxmcp-data/requests` on an installed site contains replay-protection state;
  do not treat it as disposable component cache or include it in a release.
- For releases, align `_build/build.config.php`, `Kernel::VERSION`, `package.json`, both
  version fields in `package-lock.json`, and `CHANGELOG.md`. Build and publish only when
  requested. A Git push and a GitHub Release are separate operations.
