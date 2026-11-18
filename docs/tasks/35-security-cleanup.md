# 35 — Static file paths, dead 1.x code, small fixes

Branch: `task/35-security-cleanup` (already created by the architect from `main`; work on it).
Found by an outside review of 2.0.0-alpha1 and confirmed by the architect on the code.
Read first: `src/Kind/Kind.php` (`effectiveContent`, `fileContent`, element fields),
`src/Kind/Objects.php` (`save`, static handling), `src/Kind/StaticFiles.php`,
`src/Journal/Snapshot.php` (`capture`, `restore`, `writeFile`), `src/Files/FileRoot.php`
(protected paths, `SECRET_NAMES`, symlink rules), `src/Manager/Recorder.php`, `src/Kernel.php`,
`src/Action/MaintenanceAction.php`, `src/Kind/TvKind.php` (`writeRelation`),
`src/Graph/GraphBuilder.php`, `src/Manager/Overview.php`, `templates/home.tpl`,
`_build/data/transport.settings.php`, `_build/resolvers/resolve.token.php`, and in
`modx-example-2-8-8/core/model/modx/modelement.class.php`: `getSourceFile`, `getSourcePath`,
`isStaticSourceValidPath`, `isStaticSourceMutable`.

## 1. Static element paths (security — highest priority)

Today `static`, `static_file` and `source` of chunks, snippets, templates and plugins are
writable without any check of where the file is. A `develop` token can point an element at
`core/config/config.inc.php` (or `../../…`, an absolute path, a media source whose base is the
site root) and read it through `modx_get` / `modx_view_code`, or write files outside the places
static elements belong. MODX's own `isStaticSourceValidPath()` only runs when the content
changes, not when the path does.

Design (decided):
- New `src/Kind/StaticPath.php`: `resolve(\modX $modx, $object)` returns the absolute path the
  element's static file resolves to exactly as MODX resolves it (`getSourceFile()` semantics:
  `[[++…]]` / `{…}` placeholders, media source base path, `element_static_path` /
  `components_path` fallback), normalised with `realpath` of the file or of its nearest existing
  parent. `allowed(\modX $modx, $absolute)` is true only when the path is inside one of the roots
  of a new setting `modxmcp.static_roots` (comma-separated, relative to `base_path` or as
  `{core_path}` / `{assets_path}` placeholders; default
  `{core_path}elements/,{core_path}components/,{assets_path}`), contains no symlink between the
  root and the file, is not inside `{core_path}config/`, `{core_path}modxmcp-data/`,
  `{core_path}cache/`, `{core_path}packages/`, and its name does not match
  `FileRoot::SECRET_NAMES`. Stream wrappers (`://`) are never allowed.
- **Writes:** `create`, `update`, `duplicate`, `make_static` / auto-static and `invoke`
  operations that set `static`, `static_file` or `source` are refused with `validation_failed`
  ("The static file must be inside one of modxmcp.static_roots: …") when the resulting path is
  not allowed. Writing code of an element whose existing static path is not allowed is refused
  the same way.
- **Reads:** `Kind::effectiveContent()` never reads a file whose path is not allowed: it returns
  the database column and flags it (`static_file_outside_roots`), and tools that show the code
  add a warning. `Snapshot::capture()` uses the same rule (no file bytes from outside the roots
  in the journal), the search, graph and audit too (they all go through `effectiveContent`).
- **Undo:** `Snapshot::restore()` never writes a file outside the roots; it restores the database
  row and reports the skipped file in the change note.
- The audit gets a check `static_outside_roots` (error) listing such elements, so an existing
  site with an odd layout sees them instead of silently losing the file content in the tools.
- Setting `modxmcp.static_roots` in `_build/data/transport.settings.php` (area `modxmcp:security`,
  both lexicons, explained: where static element files may live).

## 2. Dead 1.x code and settings that do nothing

- Delete `core/components/modxmcp/model/` (nothing references it; check with grep and say so).
- Remove the settings `modxmcp.audit_log` and `modxmcp.component_code_roots` (nothing reads
  them in 2.0) from the build data, the panel (`Overview`, `home.tpl`) and the lexicons.
- Upgrades keep old files and settings (MODX's file vehicle copies but never deletes, and
  settings are not overwritten). Add to the install resolver, for upgrades only, the removal of a
  fixed, explicit list of 1.x leftovers — nothing else: the directory
  `core/components/modxmcp/model/`, the processors `processors/mgr/regeneratetoken.class.php`,
  `createtoken.class.php`, `revoketoken.class.php`, the 1.x help files that 2.0 no longer ships
  (list them from `git log` / `docs/legacy-help/`), and the settings `modxmcp.audit_log`,
  `modxmcp.component_code_roots`. Also remove `versionx` from the value of
  `modxmcp.disabled_groups` if present (the group no longer exists). Log one line per removed item.
  Never touch `core/components/modxmcp/logs/` (it may hold a 1.x audit log the owner wants) or
  anything outside `core/components/modxmcp/`.

## 3. Changes made by the API are never "manager" changes

The recorder must stay silent for everything the HTTP API does, including operations that run
MODX processors outside a write transaction (`modx_maintenance` empty_trash, `modx_run`,
`modx_run_processor`, package install/uninstall): today an API `empty_trash` is journaled as
`source: manager` by the service user. Add a static flag set by the kernel for the whole API
request (`Kernel::servingApi()`), and make `Recorder::handle()` return at once while it is set.
Manager requests (no kernel) are unaffected.

## 4. Small fixes

- `TvKind::writeRelation('templates')` keeps the existing `rank` of templates that stay attached
  (today every row is recreated with rank 0, which reorders the TV tabs in the manager); new
  attachments get rank 0 as MODX does.
- `GraphBuilder`: skip MODX comment tags `[[- … ]]` including what is nested in them (a chunk that
  is only mentioned in a comment is not "used" and not "missing"); in the orphan check against
  resource content, escape `%` / `_` in the `LIKE` pattern and look only at non-deleted resources.
- `Verify\Runner` (`modx_run`, text mode): always run the parser passes, even when the text has no
  `[[` (a pdoTools/Fenom parser may handle other syntax).

## Tests (required)

`tests/php/run.php`, block "Static paths and cleanup": for each write path above, allowed paths
(core/elements, core/components/x, assets/…, Cyrillic file names) work and refused ones fail
(`[[++core_path]]config/config.inc.php`, `../`-traversal, absolute paths outside, a media source
whose base is the site root pointing at `core/config`, a symlinked directory, `.env`, a stream
wrapper); a pre-existing element pointing outside: `get` / `view_code` / `search` do not reveal the
file, snapshot holds no file bytes, undo does not write it, the audit reports it; the setting
widens the roots; the recorder is silent during an API maintenance call and still records a
manager save; TV ranks preserved; comment tags and LIKE escaping in the graph; the resolver's
cleanup list (run it against a temporary directory tree, prove it removes exactly the listed items
and nothing else, and that a fresh install removes nothing). Existing static-element tests must
keep passing unchanged.

## Acceptance

`npm run check`, `npm test`. Commit after each numbered part (the host machine may sleep and
interrupt you). Report as usual; say which MODX path rules you mirrored and from where.

## Report

Based on: main at 1ec3fd6

Counts: `npm test` 37 pass before and after; `tests/php/run.php` 5141 checks before, 5221 after; `npm run check` passes.

### Implemented

1. **Static paths** (`src/Kind/StaticPath.php`, new). `resolveFor($modx, $static_file, $source)` mirrors `modElement::getSourceFile()` as read in
   `modx-example-2-8-8/core/model/modx/modelement.class.php`: `[[++setting]]` tags are filled in (any other tag, a `://` scheme, `..` or an empty
   path makes the path unresolvable and refused); a streaming media source (`is_stream`) puts `getBasePath()` in front; a path that does not exist as
   given and has no source is taken below `element_static_path` / `components_path` (core/components). `allowed()` normalises with the realpath of the
   nearest existing parent and requires: inside a root of `modxmcp.static_roots` (placeholders `{core_path}` etc. or relative to `base_path`; empty means the
   default `{core_path}elements/,{core_path}components/,{assets_path}`), the lexical path starts with the root, the real path is the root's real path plus the
   same tail (so no symlink anywhere), not in `{core_path}config|modxmcp-data|cache|packages`, name not matching `FileRoot::SECRET_NAMES`.
   - Writes: `Objects::assertStaticWrite()` is called in `Objects::save()` (the chokepoint for create, update, duplicate, edit_code, replace, drafts, make_static) and
     in `CreateAction`, `UpdateAction`, `DuplicateAction` before the dry-run branch. It looks at the result of the change (current values overridden by the columns
     sent) when static, static_file, source or the code is touched and the element is static. `StaticFiles::makeStatic` checks the target before reserving a file.
   - Reads: `Kind::effectiveContent()` returns the database column when the file is outside (a fifth value `true`; four-value callers are unaffected).
     `Snapshot::capture()` flags the state `static_file_outside_roots` (only then, so no revision changes). Warnings in `modx_get`, `modx_view_code`, `modx_search`;
     `modx_edit_code` refuses such an element.
   - Undo: `Snapshot::restore()` does not write the file and calls `$object->setOption(xPDO::OPT_SETUP, true)` before `save()`, because `modElement::save()` itself
     reads the file into the row or writes the content to it when static source fields change; the skipped file is reported through `Snapshot::takeNotes()` into the
     undo warnings.
   - Audit: check `static_outside_roots` (error), placed before `fenom_syntax` so `fenom_syntax` stays last; `scanned` counts static elements.
   - Setting `modxmcp.static_roots` (area `modxmcp:security`, both lexicons), `modx_help` getting_started mentions the check.
2. **Dead 1.x code.** `core/components/modxmcp/model/` deleted (grep found no reference: only text comments about other add-ons' `model/` dirs). Settings `audit_log` and
   `component_code_roots` removed from build data, `Overview`, `controllers/index.class.php`, `home.tpl`, lexicons (also the label `modxmcp_cmp_audit_log`).
   `src/Setup/LegacyCleanup.php` (new) is run by `resolve.token.php` on `ACTION_UPGRADE` only: removes the three token processors, `docs/acl.md`, `docs/minishop2.md`,
   `docs/study_component.md` (the 1.x help files absent from `docs/` now, from `git log --diff-filter=D`), the `model/` directory, the two settings, and `versionx` from
   `modxmcp.disabled_groups`; one log line per item; `logs/` and everything outside the component directory is untouched; a symlink is never followed.
3. **Recorder.** `Kernel::servingApi()` (getter/setter) is set by `Kernel::handle()`; `Recorder::handle()` returns first thing when it is set.
4. **Small fixes.** `TvKind::writeRelation('templates')` keeps ranks. `GraphBuilder`: `withoutComments()` removes `[[- ... ]]` with nested tags before scanning; the orphan LIKE
   escapes `\ % _` and adds `deleted = 0`. `Verify\Runner`: text mode always runs both parser passes.

### Deviations and judgement calls

- `tests/php/FakeModx.php` changed: `fakeSite()` now sets `base_path`, `assets_path` and `modxmcp.static_roots = './'` so the older tests (static files anywhere under the site
  directory) keep passing unchanged; my new block uses a site with the setting empty (the defaults). `FakeObject::getSourceFile()` now fills any `[[++x]]` tag and a streaming media
  source's base path (it only knew `core_path`); `setOption()` and `xPDO::OPT_SETUP` added, and save() skips the file sync in setup mode like `modElement::save()`.
- Existing assertions changed (2): the audit summary counts `array(3, 1, 3, 1, 1, 2, 1, 0)` became `... 0, 0)` for the new check; the manager overview test no longer asserts
  `$ovData['audit_log']` (and the `modxmcp.audit_log` option passed in its fixture is gone), as the setting was removed.
- The brief says the cleanup removes the 1.x help files on every upgrade; I gate those three (not the processors or settings) on the 1.x marker file `model/modxmcp.class.php`
  existing, because a later stage may ship a help topic of the same name and a 2.x upgrade must not delete it; a test fails while a listed topic is shipped.
- Real MODX: an absolute static path that does not exist yet resolves below `components_path` (`$_sourcePath . $filename`), so for a new file `[[++core_path]]config/x.tpl` really
  resolves to `core/components//…/core/config/x.tpl` (inside the roots). I mirror that (it is what MODX writes). Existing files are checked at their real location, so the tests for
  config, cache, symlinks and `.env` use existing files. The fake's `getSourceFile()` does not do this fallback.
- `resolve($modx, $object)` uses the object's own `getSourceFile()` (MODX's answer) for reads; `resolveFor()` (my mirror) is used for writes where no object exists yet.
- Undo notes go to the response warnings (there is no per-call note column for this).
- AGENTS.md still calls `model/` "the backend of the manager dependency graph"; the graph processor uses `src/Graph`, so that sentence is outdated (not edited).

### Verified only against the fake (check on the stand)

- Real `modElement::save()` with `OPT_SETUP` on restore (read from source: it skips the file sync and file write); `getOldStaticFilePath()` still runs, so with
  `static_elements_automate_*` on, changing the path of an element that currently points outside deletes that old (outside) file in MODX itself, in the update processors and
  in a restore. Not guarded.
- Media-source resolution (`initialize()`/`getBasePath()` with `basePathRelative`), `{core_path}`-style placeholders and symlink behaviour on the real file system/hosting.
- Real processors' behaviour for refused paths (they never run: refusal happens before).

### Open questions

- Should `FileRoot` share the `SECRET_NAMES`/protected-directory list with `StaticPath` (now two lists of the same dirs)?
- Existing sites with static files outside the default roots lose file reads until `modxmcp.static_roots` is widened (the audit check lists them); is a post-install notice wanted?
