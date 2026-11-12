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
