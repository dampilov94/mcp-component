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

## Two-agent workflow

Work on 2.0 is split between an **architect** session and an **executor** session.

- The architect writes task briefs in `docs/tasks/`, reviews the executor's branch, deploys
  to the development stand, runs the stand checks and merges into `main`.
- The executor implements exactly one brief at a time. If you were started with a brief
  (e.g. "do docs/tasks/01-duplicate.md"), you are the executor; read `docs/tasks/README.md`
  first and follow it.
- Several briefs may be done in a row on stacked branches; `docs/tasks/README.md` has the
  rules.
- The executor works only in its own branch `task/NN-name`, never commits to `main`, never
  pushes, never deploys and never runs anything in `scripts/stand/` (those scripts act on a
  real site). It does not touch `.env`, `.mcp.json` or `.local/`.

## How the 2.0 core is built (read before changing it)

- **An action is one class** in `src/Action/`, listed in `Action/Registry.php`. It declares
  name, description, input/output schema, scope, group and effect; nothing is defined in the
  client. Schemas must stay inside the subset `src/Schema.php` validates, and every action
  returns a JSON object (an empty map must encode as `{}`: use `new \stdClass()`).
- **A kind is one class** in `src/Kind/`, listed in `Kind/KindRegistry.php`. Generic tools
  (`list`, `get`, `create`, `update`, `delete`, code tools) work from its declarations. API
  field names differ from columns via `column`; code is always the field `content`.
- **Every change goes through `Write\Writer::run()`.** Inside, `touch()` each object before
  changing it (lock + snapshot); the transaction journals whatever really differs at commit
  and restores touched objects if the body throws. Never write to the site outside a
  transaction, and do not use database transactions for rollback.
- **Change objects through the core processors** (`Kind\Objects::save()` /
  `Objects::process()`), so MODX validation and events run. `Journal\Snapshot::restore()` is
  the only place that writes xPDO objects directly.
- **A revision** is the SHA-256 of an object's canonical state (`Journal\Snapshot`). The
  same value appears in read results, write results and the journal.
- The component's own tables are plain SQL through `src/Db.php`. Queries must run on both
  MySQL (sites) and SQLite (tests); table creation lives in `Journal::install()` /
  `TokenStore::install()` (MySQL) and `fakeSchema()` in `tests/php/FakeModx.php` (SQLite).
- PHP 7.4 syntax only: no `match`, enums, constructor promotion, named arguments, union
  types, `readonly`, nullsafe `?->`, or `str_contains`/`str_starts_with`.

MODX traps already handled; keep them handled:

- Update processors expect the complete record. Omitted checkbox fields of a resource
  (`published`, `hidemenu`, `deleted`, …) are reset to off, and `syncsite` must be sent or
  the cache is not refreshed. `Objects::save()` sends the full record; do not bypass it.
- Resource `pub_date` / `unpub_date` are unix times in the database but must be sent to the
  processor as date strings; a raw number publishes the page immediately.
- MODX strips an opening `<?php` from snippet and plugin code and writes a static file only
  when the database column changed.
- A resource delete also trashes its descendants, and saves change the parent's `isfolder`.
  Kinds report such cascades through `alsoAffected()` so they are journaled.
- MODX refuses to delete a TV that is attached to a template, a template in use, the site
  start page, etc. Pass these errors through; do not work around them.

A full MODX 2.8.8 source tree is available locally (ignored by git) in `modx-example-2-8-8/`.
Read the real processors and maps there instead of guessing how MODX behaves; never modify
or execute anything in it.
Source of add-ons installed on the stand is copied to `.local/reference/<addon>-<version>/`
(ignored) when a brief needs it; the same rules apply.

## Tests

- `npm run check` (syntax + release metadata) and `npm test` must both pass before a task
  is reported done. `npm test` needs PHP with `pdo_sqlite`.
- `tests/php/run.php` drives actions on an in-memory MODX stand-in
  (`tests/php/FakeModx.php`), validating arguments and results against the action schemas.
  New behaviour needs checks there; extend the fake only as far as the real MODX behaves.
- `tests/endpoint.test.js` runs the real kernel over HTTP and through the bridge. It asserts
  the exact tool list and counts; update them when you add a tool or a kind.
- The fake is not MODX. State in your report which behaviour you could only verify against
  the fake, so the architect checks it on the stand.

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
