# 19 — Packages: kinds `package` and `provider`, tools `install_package` / `uninstall_package`

Branch: `task/19-packages`. Spec: sections 3 ("Пакеты"), 3.1, 8.7 (irreversible actions).
1.x reference (behaviour that is known to work on real sites, not code to copy):
`core/components/modxmcp/model/traits/packages.trait.php`. MODX source to read first
(`modx-example-2-8-8/core/model/modx/`): `processors/workspace/packages/` (`getlist`,
`install`, `uninstall`, `remove`, `rest/download`, `rest/getlist` or whatever the search
uses — find it), `processors/workspace/providers/` (`create`, `update`, `remove`),
`transport/modtransportpackage.class.php`, `transport/modtransportprovider.class.php`, and the maps.

## Goal

See what is installed, find and install add-ons from a provider (modstore / modx.com), and
remove them — with every irreversible step clearly marked and confirmed.

## Design (decided — do not change)

### Kind `package` (read-only)

Class `transport.modTransportPackage`, string key = `signature` (e.g. `pdotools-2.13.2-pl`),
group `package_management`, read scope `read`, write scope `admin` — but `create`, `update`,
`delete`, `duplicate` are all refused for this kind (`validation_failed`: "Packages are
installed and removed with modx_install_package / modx_uninstall_package."). Add a kind hook
for "generic writes are not allowed" (e.g. `Kind::genericWrites()` returning false) and
use it in the four write actions before anything else.

Fields: `signature` (list), `package_name` (list), `version` (computed `major.minor.patch-release`,
list), `installed` (datetime, list; `""` when only downloaded), `provider` (int), `source`,
`updated` (datetime), `workspace` (int). The kind is not journaled at all: package install
state is never restored by `modx_undo`.

### Kind `provider`

Class `transport.modTransportProvider`, integer key, name field `name`, group
`package_management`, read `read`, write `admin`, processors `workspace/providers/`.
Fields: `id`, `name` (required, list), `service_url` (required, list), `description`,
`username` (list), `api_key` (secret: hidden from non-admin, `[hidden]` in every diff — use
the existing `secretColumns` / `redact` hooks), `priority` (int). Journaled like any kind.

### `modx_install_package {signature? | package_name?, provider?, version?, confirm?}`

Write action (`WriteAction`), scope `admin`, group `package_management`, `destructive`,
`reversible()` **false**.

1. Resolve what to install:
   - `signature` of a package already downloaded into `core/packages` (listed by the kind
     with `installed: ""`), or
   - `package_name` (+ optional `version`) to find on `provider` (default: the provider
     named `modx.com`, else the first one; resolve as the 1.x code does). Search the
     provider for the newest version compatible with this MODX (or the given version), and
     download it — through the same processors the manager uses.
2. Without `confirm: true` (and always for a plan-only token): do nothing beyond the search;
   return `performed: false` with what would be installed (`signature`, `version`,
   `provider`, whether a download is needed, whether an older version is installed and
   would be upgraded) and the warning that installing runs the package's own resolvers,
   cannot be undone by `modx_undo`, and that the package can only be removed with
   `modx_uninstall_package`.
3. With `confirm: true`: download if needed, install (`workspace/packages/install`, the
   processor that runs the package's vehicles and resolvers), then refresh the cache.
   Return `performed: true`, `signature`, `installed` (datetime), and — through
   `modx_read_log`'s reader — the error-log entries written during the install
   (`log: {new_entries, entries}`), because package resolvers report problems only there.
4. The install changes many objects the journal knows nothing about. Record **one**
   changeset with no change rows: summary `install package <signature>`, `reversible`
   false, so that `modx_history` shows that it happened. It is informational only:
   `modx_undo` ignores it (a selection by `last` / `this_session` / `to_checkpoint` skips
   it; undoing it by `changeset_id` → `not_reversible`) and is never blocked by it. Add
   `Journal::commit` support for a changeset without changes if it does not exist; it must
   not break `changes()` / `history_diff` for it (`objects: []`).
5. Refuse to install a package whose name is `modxMCP` (the API must not replace itself
   while it is running).

### `modx_uninstall_package {signature, confirm?, remove?}`

Same shape: without `confirm` → plan (what is installed, that its resolvers will remove its
files/objects, irreversible); with `confirm` → `workspace/packages/uninstall` (and with
`remove: true` also delete the package file and record, `workspace/packages/remove`).
Refuse for `modxMCP` itself. Journal one changeset without changes, as above.

### Search

`modx_list {kind: "package"}` lists what is in the workspace (installed or downloaded).
Searching a provider's catalogue is the operation `search` of kind `provider` through
`modx_invoke {kind: "provider", id, op: "search", args: {query, limit?}}` (read-only),
returning `{name, signature, version, description (≤300 chars), downloads, installed:
bool}` items. Use what the 1.x `searchPackages` does.

## Out of scope

Package updates as a separate tool (installing a newer version upgrades), uploading a
transport zip, building packages, dependencies resolution beyond what MODX does itself.

## Tests (required)

The fake cannot run real transport packages. Extend it with
`transport.modTransportPackage` / `modTransportProvider` rows and with stub processors
for search, download, install, uninstall and remove that record what they were asked and
change the rows as the real ones do in outline (install sets `installed`; uninstall
clears it; remove deletes the row). Assert:

- kind `package`: list/get by signature; every generic write refused; not in the journal.
- kind `provider`: CRUD, `api_key` hidden for non-admin and in diffs, undo.
- `search` op through invoke, read token allowed.
- install: plan without confirm (nothing called beyond search), plan-only token, confirm
  path calls download then install, returns `log` entries written during the install (make
  the stub append to the fake's error log), one changeset without changes appears in
  `modx_history` with `reversible: false` and `history_diff` works for it; `modx_undo`
  `last: N` across it works for the other changesets.
- uninstall with and without `remove`; refusals for `modxMCP`; scopes (`develop` →
  `forbidden_scope`); `package_management` group disabled → tools absent from the manifest
  and kinds unavailable.

Update tool and kind lists/counts in both test files.

## Acceptance

`npm run check`, `npm test`. Files: `src/Kind/PackageKind.php`, `src/Kind/ProviderKind.php`,
`src/Action/InstallPackageAction.php`, `src/Action/UninstallPackageAction.php`,
`src/Action/Registry.php`, `src/Kind/KindRegistry.php`, `src/Kind/Kind.php` and the four
generic write actions for the `genericWrites()` hook, `src/Journal/Journal.php` only if a
change-less changeset needs it, tests, `tests/php/FakeModx.php`.

## Report

Based on: main at 98e2f7d

Counts: before, `npm run check` OK (142 PHP files, 18 JS files), PHP 1952 checks, Node 25 tests. After: check OK (146 PHP files), PHP 2235 checks, Node 25 tests (all pass). Three existing assertions changed because the brief adds two tools and two kinds: `manifest: full tool count` 32 to 34; `describe: lists element kinds` (a read token) now ends with `package, provider`; in `endpoint.test.js` the admin tool count 32 to 34 (twice: manifest and bridge), the read kind count 15 to 17 and the admin kind count 20 to 22. Nothing weakened or deleted.

Implemented, file by file:

- `src/Kind/PackageKind.php` (new): read-only kind `package`, string key `signature`, group `package_management`, read `read`, write `admin`. Computed `version` (`major.minor.patch[-release[index]]`), `installed` datetime (`""` for downloaded or the all-zero date). `genericWrites()` false with the brief's message. Helpers shared by the tools: `isOwn()`, `isInstalled()`, `versionOf()`, `logOffset()` / `logSince()` (uses `LogReader`). `stateColumns()` drops `manifest`, `attributes`, `metadata` from the (never journaled) state.
- `src/Kind/ProviderKind.php` (new): kind `provider` (integer key, `name`, processors `workspace/providers/`), `api_key` through `secretColumns()` (hidden in every diff) and `redact()` (`[hidden]` plus `secret: true` for non-admin tokens; `no_filter` so it cannot be probed with `where`). Read-only op `search` (`modx_invoke`), and static helpers `defaultProviderId()`, `search()`, `rowVersion()` used by the install tool.
- `src/Action/InstallPackageAction.php`, `UninstallPackageAction.php` (new, registered after `maintenance`): as the brief; admin scope, group `package_management`, destructive, irreversible. `install_package` is `open_world: true` (it reaches the provider).
- `Kind.php`: hooks `genericWrites()` / `genericWritesMessage()`, `describe()` adds `generic_writes: false` and `note` for such a kind, `datetime()` treats `0000-00-00…` as `""`. `Objects.php`: `assertGenericWrites()`, and `run()` (processor call that returns the response) split out of `process()`. `CreateAction`, `UpdateAction`, `DeleteAction`, `DuplicateAction`: call `assertGenericWrites()` right after the kind is resolved (so a token without the write scope still gets `forbidden_scope`).
- Journal: `Journal::commit()` accepts no changes (reversible 0, always); filter `with_changes`. `Transaction::commit(..., $informational)` records such a changeset when nothing journaled changed, `Transaction::lock()` takes a non-object lock (both install tools lock `package:workspace`), `Writer` passes `informational` from the body. `Undo::plan()` throws `not_reversible` for a changeset without changes that is not reversible; `UndoAction` adds `with_changes` to the `last` / `this_session` / `to_checkpoint` selections.
- Tests: `FakeModx.php` (trait `FakePackageProcessors`: stubs for `workspace/packages/rest/getlist`, `rest/download`, `install`, `uninstall`, `remove`, `workspace/providers/*`; they record calls in `packageCalls`, change rows in outline, append to the error log; `FakeResponse::getResponse()`; primary key `signature`), `run.php` (section "Packages", 283 checks: both kinds, search, plan / plan-only / confirm on every path, every refusal for modxMCP on each path, history and `history_diff`, undo across the install, failures, scopes, disabled group), `endpoint.test.js` counts.

Deviations and conservative readings:

- A failed install or uninstall (processor error) still records the change-less changeset, summary `... (failed)`, and the error carries `details.log` and `details.changeset_id`; the processors may have partly run (a download, resolvers). The brief only describes the success case.
- Without `version`, the newest **stable** release (`pl` or none) is chosen even if a newer pre-release exists; a pre-release is installed only when its version is given. Exact name match only (case-insensitive, `mb_*`): 1.x fell back to the first result, which could install a different package.
- `signature` cannot be combined with `provider` or `version` (`validation_failed`). An already installed signature or version is refused. `package_name` lookup asks the provider for 50 rows once; a version that is not in them is `not_found`.
- modxMCP detection: package name `modxMCP`, or the name part of the signature (before the first `-<digit>`) equals `modxmcp`, case-insensitive, checked before any search, on the signature, the stored package name and every provider row. `modxMCP-fork` is therefore not refused.
- Default provider: `modx.com`, else `modxcms.com` (as `modPackageDownloadProcessor` does), else the first.
- `remove: true` on an installed package runs `uninstall` and then `remove` as two processor calls (the real `remove` processor would also uninstall, but without `uninstall`'s own checks).
- A plan-only token returns `planned: true` instead of `dry_run`; the install plan result is not a `WriteAction::plan()`.
- Extra files touched that the brief did not list: `Transaction.php` (`lock()`, `$informational`), `Writer.php`, `Undo.php`, `UndoAction.php`, `Objects.php`, `Kind.php` (hooks above).

MODX source vs brief:

- `workspace/providers/create` and `update` call `$object->verify()`, a request to the provider's `service_url`; creating or updating a provider therefore needs network access and fails when the service does not answer. The fake refuses the address `unverified`.
- `workspace/packages/rest/getlist` answers with `outputArray` (no `object`), so the rows are read from `getResponse()` (JSON), as 1.x does. Its `limit`/`start` give `page = round(start/limit)`. The provider request already includes `supports` (this MODX version), so the list is compatible versions.
- `packages/remove` uninstalls an installed package first (`removePackage`), and `uninstall` / `remove` both `sleep(2)`; a request may take several seconds longer.
- `modTransportPackage.installed` may be the string `0000-00-00 00:00:00` in old data (the manager's own getlist handles it); treated as not installed.
- `uninstall` takes an optional `preexisting_mode`; not exposed.

Verified only against the fake MODX (please check on the stand): all processor names, property names and refusals; `getResponse()` of `rest/getlist` being the JSON `{total, results}` with the row keys used (`name`, `signature`, `version`, `release`, `version-compiled`, `location`, `description`, `downloads`); `rest/download`'s object carrying `signature`; that `install`/`uninstall`/`remove` run correctly as the service user (`packages` permission) from an API request, within PHP's time limits, and that package resolvers write to `core/cache/logs/error.log` so `log` is filled; that xPDO accepts the class names `transport.modTransportPackage` / `modTransportProvider` in `getObject` / `getCollection` / `newQuery` with string key `signature`; the ordering of `modx_list kind package` (MySQL collation); `api_key` handling by the provider update processor (sent back in full).

Open questions:

- Should a failed install really leave a changeset (see above), or only the success case?
- Upgrade/downgrade policy: installing an older version over a newer one only adds a warning to the plan. Refuse instead?
- `docs/getting_started.md` (built-in help) does not mention the two tools and kinds yet; left for the documentation pass.
