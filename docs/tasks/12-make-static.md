# 12 — Static elements: `make_static` and auto-static

Branch: `task/12-make-static`. Needs task 10 (`modx_invoke`). Spec: sections 3.2, 13.
1.x reference (behaviour and the careful file handling; not the direct SQL):
`makeElementStatic()` / `shouldAutoStatic()` in
`core/components/modxmcp/model/traits/elements.trait.php`.

## Goal

1. `modx_invoke {kind, id|name, op: "make_static"}` for `chunk`, `snippet`, `template`,
   `plugin`: move the element's code from the database into a file.
2. When `modxmcp.auto_static` is on (it is by default), elements of those kinds created
   through the API become static right away.
3. Files this feature creates are removed again when the element is deleted or its
   creation is undone, so the site is not littered.

## Design (decided — do not change)

### Where files go

`<core_path>elements/<dir>/<slug>-<id>.<ext>` with dir/ext: chunk `chunks`/`tpl`, snippet
`snippets`/`php`, template `templates`/`tpl`, plugin `plugins`/`php`. `slug` = the name
with every run of characters outside `A-Za-z0-9._-` replaced by `-`, cut to 120 characters,
trimmed of `-._`; empty → the kind name. If the file exists, try `<slug>-<id>-1`, `-2`, …
up to 20, then fail. The element stores the path as `[[++core_path]]elements/<dir>/<file>`
with `source` 0. This directory is the **managed directory**.

### `src/Kind/StaticFiles.php` (new, static methods)

- `makeStatic(Context $context, Transaction $tx, Kind $kind, $id): array`
  1. The object must be touched already. If it is static: return
     `['status' => 'already_static', 'static_file' => <its path as stored>]`, change nothing.
  2. Choose the file name as above; create the directory (0755) if needed; create the file
     **exclusively** (`fopen(…, 'x')`) and write the element's current code into it. Never
     overwrite or reuse an existing file.
  3. `Objects::save($context, $tx, $kind, $id, ['static' => 1, 'static_file' => <stored
     path>, 'source' => 0], [])`. Writing the file first is deliberate: MODX compares the
     file with the column when an element becomes static, and equal content means it
     leaves both alone.
  4. Verify: reload, `Kind::effectiveContent()` must report static, an existing file and
     exactly the code from before. Otherwise throw (the transaction restores the element).
  5. If anything fails after the file was created, delete that file (only the one this call
     created) before rethrowing.
  6. Return `['status' => 'converted', 'static_file' => <stored path>]`.
- `isManaged(\modX $modx, $absolutePath): bool` — the real path lies inside
  `<core_path>elements/`.
- `removeManaged(\modX $modx, $absolutePath): void` — if `isManaged`, delete the file and
  then remove directories left empty, up to but not including `<core_path>elements`.
  Errors are logged, never thrown.

### Wiring

- The four code kinds declare op `make_static` (`needs_object` true, write, no arguments)
  in one place: add it in the `Kind` base through a helper the four kinds call (e.g.
  `staticOperations()`), handler calling `StaticFiles::makeStatic`.
- Auto-static: in `Objects::save()`, after a successful **create** of a kind that has the
  op, when `modxmcp.auto_static` is on and `$columns` does not contain `static`: call
  `StaticFiles::makeStatic`. A failure there fails the whole create (the transaction then
  removes the new element). `modx_duplicate` passes `static` explicitly, so copies stay in
  the database — keep it that way.
- Cleanup of managed files:
  - `DeleteAction`: before the processor runs, note the element's static file path
    (`Kind::effectiveContent($object)[2]`); after a successful delete, `removeManaged` it.
  - `Journal\Snapshot::restore()` with a `null` state (undo of a create): same — note the
    path before `remove()`, `removeManaged` after.
  - Restoring a deleted static element already rewrites its file (existing behaviour).
  Files outside the managed directory are never deleted (existing behaviour).

### Results and docs

- `modx_create` for these kinds: the result's `changed` then includes `static`,
  `static_file`; nothing else to add.
- Add to `core/components/modxmcp/docs/getting_started.md`, section "Objects and kinds",
  two sentences: new elements are static by default (file under `core/elements/`), their
  code is still edited with the object and code tools; `modx_invoke … op: "make_static"`
  converts an existing element.

## Out of scope

Converting back to database storage, moving static files, media-source-based static files,
batch conversion.

## Tests (required)

The fake's `core_path` is a temp directory; make the fake resolve `[[++core_path]]` in
`getSourceFile()` the way MODX does (only that tag), and extend its element save so a
static element whose file already exists with equal content is left alone.

- `make_static` on each of the four kinds: file at the expected path with the code, element
  static with the stored tag path, `revision` changed, one changeset; code read back equal.
- name with spaces / Cyrillic / only symbols → slug rules; existing file → `-1` suffix,
  the existing file untouched.
- already static → `already_static`, no changeset.
- undo of `make_static`: element back in the database with its code; the file is left in
  place (it is not a create).
- auto-static on: `modx_create` of a chunk makes it static; `static: false` given
  explicitly keeps it in the database; setting off → not static; `tv` and `category`
  unaffected; `modx_duplicate` copy is not static.
- undo of that create removes the element **and** the managed file and its empty directory.
- `modx_delete` of a managed static element removes the file; `modx_undo` brings the
  element back static with the file restored.
- a static element whose file is outside the managed directory: delete and undo-of-create
  leave the file alone.
- failure injection: make the fake's update processor fail → no file left behind, element
  unchanged.
- existing tests keep passing with auto-static **on** by default in the fake; where an old
  test depends on database storage, set the option off for that block rather than changing
  its assertions, and list those places in the report.

## Acceptance

`npm run check`, `npm test`. Files: `src/Kind/StaticFiles.php`, `src/Kind/Kind.php`, the four
code kinds, `src/Kind/Objects.php`, `src/Action/DeleteAction.php`, `src/Journal/Snapshot.php`,
the guide, tests, `tests/php/FakeModx.php`.

## Report

Based on: task/11-tv-operations at 0d6f282

- `StaticFiles.php`: exclusive managed-file allocation, slug/suffix rules, processor-based
  conversion, content verification, failure cleanup and realpath-bounded managed deletion.
- `Kind.php` and four code kinds: shared make_static declaration/handler. `Objects.php`:
  auto-static after successful creates only when static was not supplied; duplicate remains
  non-static. DeleteAction/Snapshot remove managed files after deletion/undo-of-create.
- Guide updated. Fake resolves the core_path tag and avoids rewriting equal existing
  static content; real model defaults for property_preprocess/disabled/icon were added so
  rollback snapshots do not acquire previously absent fake-only columns.
- Tests cover all four kinds, Unicode/symbol slugs, collisions, no-op conversion, undo,
  defaults/overrides, managed and external files, and conversion/create failure cleanup.
- `npm run check` and `npm test` passed: 879 PHP checks and 24 Node/HTTP tests.

The old main-site object/journal block (from fakeSite setup through the resource-block
boundary) explicitly disables auto-static because its assertions and external-change
fixtures assume database storage; it is restored before resource tests. Other fake sites,
including endpoint tests, keep auto-static on by default. Assertions were not rewritten.

Additional conservative path check: refuse a managed subdirectory symlink resolving outside
core/elements. No direct SQL or other design deviations. The real MODX source confirms the
file-first conversion approach; its cache writeFile returns a boolean even for empty code.
Only fake MODX and real temporary files were exercised. Verify native static-source changes,
processor events, file permissions, empty content, deletion and undo on the stand (especially
sites enabling MODX's own static-file automation). No blocking questions, deployment or push.

## Review

Accepted and merged with the rest of the stack (09–14). Verified on the stand with
`scripts/stand/check-operations.cjs`; every earlier stand check was rerun.

One real defect, found only on the stand, fixed by the architect:

MODX stores snippet and plugin files with an opening PHP tag (`modScript::setFileContent()`
prepends `<?php\n`, `getFileContent()` strips it). The conversion wrote the bare code, and
`Kind::effectiveContent()` returned the raw file, so the same snippet had two different
contents depending on who wrote the file last: after a delete and undo MODX rewrote the
file with the tag and the restored revision no longer matched. The brief and the 2.0 core
were both wrong here, not the executor: the core had the same flaw since stage 2 and it only
surfaced once new elements became static by default.

Now `Kind::fileContent()` / `Kind::codeFromFile()` translate between code and file bytes for
kinds linted as PHP; every place that reads or writes a static file uses them. The API shows
snippet and plugin code without the tag whether or not the element is static.
