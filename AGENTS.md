# modxMCP: instructions for development agents

## Project

modxMCP connects an AI client to MODX Revolution through a Node.js MCP server and a
PHP API. Target MODX 2.8.x and PHP 7.4; keep PHP 8.x compatibility where possible.
The API is intended for administrators and developers and has high privileges.

- `client/index.js` defines MCP tools; `client/api.js` handles HTTP and
  `client/contracts.js` defines tool effects and result schemas.
- `assets/components/modxmcp/api.php` is the PHP endpoint.
- `core/components/modxmcp/model/modxmcp.class.php` is the model facade;
  `model/traits/registry.trait.php` registers actions and the other traits implement them.
- `core/components/modxmcp/docs/` is built-in help served by `modx_help` and shipped
  with the component. `_build/` creates the MODX transport package.
- `README.md` is the user guide; `CHANGELOG.md` records releases. `DEVELOPMENT.md`
  has further technical detail. Source code is authoritative for current behavior.

## Working practices

- Follow the user's current scope and preferences. Keep changes focused and preserve
  existing local work. The user prefers to review tasks one at a time and perform
  functional checks personally; avoid unnecessary tests and test accounts.
- Before changing an action, inspect its registry entry, implementation, client tool and
  contract. Preserve compatibility and existing access controls unless explicitly changed.
- `npm run check` checks JavaScript and PHP syntax and release metadata; it is not an
  integration test. It uses `PHP_BIN`, available `.local/tools/php-*` runtimes, or `php`
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
- For releases, align `_build/build.config.php`, the model version, `package.json`, both
  version fields in `package-lock.json`, and `CHANGELOG.md`. Build and publish only when
  requested. A Git push and a GitHub Release are separate operations.
