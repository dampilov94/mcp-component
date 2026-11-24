# 50 — Search in files; read and edit files by lines

Branch: `task/50-file-lines` (already created by the architect from `main`; work on it).
Spec: section 3 (file tools), 11 (saving context). Read first: `src/Action/ReadFileAction.php`,
`WriteFileAction.php`, `FileWriteAction.php`, `ListFilesAction.php`, `src/Files/FileRoot.php`,
`src/Files/FileSubject.php`, `src/Files/WritePathPolicy.php`, `src/Code/LineEdit.php`,
`src/Code/CodeSearch.php`, `src/Action/SearchAction.php`, `src/Action/ViewCodeAction.php`,
`src/Action/EditCodeAction.php`, and how `client/index.js` tracks file revisions.

## Why

CSS, JS, file-based templates (`{include 'file:…'}`, `@FILE`) and add-on code live in files. Today
the AI must read a file whole and write it back whole: expensive for a 3000-line stylesheet and
risky (a cut-off write truncates the file). And `modx_search` only searches elements.

## Design (decided — do not change)

### `modx_read_file`: line windows

Add `start_line` / `end_line` (1-based, inclusive) and `numbered` (bool). With a line window or
`numbered: true`, the result has `numbered` text in the `modx_view_code` format (`N\tline`),
`total_lines`, `start_line`, `end_line`, plus the file's `revision` (of the whole file, as today).
Without them the behaviour is unchanged. Binary files refuse line windows (`validation_failed`).
The result also carries `root` (the root id the file was read from) so a follow-up edit names
the same file.

### `modx_edit_file` (new write tool)

Line edits of a text file in a writable root (media sources; component code stays read-only as
today): `{source | root, path, edits, expected_revision?, dry_run?, skip_lint?, _request_id?}`, the
`edits` exactly as `modx_edit_code` (`start_line`, `end_line`, `replacement`, `expect`, inserts
with `end_line = start_line - 1`). It reuses `Code\LineEdit`, keeps the file's line endings and
final newline, journals the change through the existing `FileSubject` (undoable, revision
checked, same `WritePathPolicy` / scope rules as `modx_write_file`, PHP lint for PHP files as
`modx_write_file` does), and returns `{root, path, revision, changeset_id, reversible,
edits_applied, total_lines_before, total_lines_after}`. `dry_run` returns the line diff
(`Code\Diff`) that would be applied. A binary file, a file over `modxmcp.max_read_bytes`, or a
missing file → `validation_failed` (use `modx_write_file` to create).

The bridge's revision tracking (`client/index.js`) must cover `modx_edit_file` like
`modx_write_file`.

### `modx_search_files` (new read tool)

Literal search in the text files of one root: `{source | root (as list_files / read_file), path?
(sub-directory, default the root), query (2–500 chars), case_sensitive? (default false),
extensions? (list, default: tpl, html, htm, css, scss, less, js, json, php, txt, md, xml, svg),
limit? (files with matches, default 50, max 200), max_matches? (lines per file, default 5, max
50)}`. Walk the tree breadth-first, skip binary files, symbolic links, files over
`modxmcp.max_read_bytes` (counted in `skipped`) and the directories `.git`, `node_modules`,
`vendor`. Stop after 2000 files scanned (`truncated: true`, note). Matching lines come back with
their numbers, cut to 300 characters around the match (Unicode-safe, no per-character arrays).
Result `{root, query, files_scanned, files_matched, truncated, skipped, results: [{path, matches:
[{line, text}]}]}`. Read scope; it never reads what `modx_read_file` may not read (same
`FileRoot` rules, including `allow_root_filesystem_read`).

Help (`getting_started.md`, `index.md`): the three changes in a few lines; `SearchAction`'s
description mentions `modx_search_files` for files.

## Tests (required)

PHP block "File lines": windows and numbering, `root` in the result, binary refusal; edit_file:
replace/insert/delete, `expect` relocation and refusal, CRLF and missing final newline kept,
revision conflict, dry-run diff, journal + undo restores bytes, PHP lint refusal, protected paths
(component dirs, `.htaccess`, core/config) refused exactly as `write_file` refuses them, scope
rules; search_files: matches with line numbers, case, extensions filter, sub-path, skipped
binary/oversize/symlink/`node_modules`, the 2000-file cap, Cyrillic text, cut long lines, the
root-filesystem gate. Endpoint test: the two new tools in the exact tool lists/counts (update
them), one edit through the bridge with revision tracking.

## Acceptance

`npm run check`, `npm test`. Commit after each tool. Report as usual.

## Report

Based on: main at 52566ab (branch `task/50-file-lines`, created by the architect).

### Implemented

- `src/Code/FileLines.php` (new): line view and line edits of a text file. A final line break ends
  the last line instead of opening an empty one, so `"a\nb\n"` has 2 lines and "append" is
  `start_line = total_lines + 1`. Edits delegate to `Code\LineEdit::apply()`, then restore the file's
  line endings (CRLF if the file contains any CRLF) and its final newline (or its absence).
- `src/Action/ReadFileAction.php`: `start_line`, `end_line`, `numbered`; with any of them the result
  has `numbered`, `total_lines`, `start_line`, `end_line`, `truncated` (and `next_line`), no `content`;
  `revision` is of the whole file. The numbered text is cut at a line boundary at `max_bytes`. Binary
  files and `offset` together with a window are `validation_failed`. `root` (the root id, e.g. `s1`,
  `c:demo:core`) is now in every read_file result.
- `src/Action/EditFileAction.php` (new, `modx_edit_file`): as briefed; goes through `Writer::run()`,
  `touchSubject()`, the existing `FileSubject`, `FileRoot::assertWritable()` (so protected paths, component
  directories and PHP scope rules are exactly those of `write_file`), PHP lint (`skip_lint`), dry run
  returning `Code\Diff::lines`. Result: `{root, path, revision, changeset_id, reversible, edits_applied,
  total_lines_before, total_lines_after}`. `source` or `root` (a root id `sN` as returned by read_file).
- `src/Action/SearchFilesAction.php` (new, `modx_search_files`): as briefed, using `CodeSearch::position`
  / `excerpt` (Unicode-safe).
- `src/Files/FileSubject.php`: `root()` accessor. `src/Action/Registry.php`: the two actions
  (search_files right after read_file, edit_file right after write_file). `src/Action/SearchAction.php`:
  description mentions `modx_search_files`.
- `client/index.js`: revision tracking covers `modx_edit_file` (by `source` or by `root` id `sN`; results
  carry `root`, so a read by source and an edit by root share one revision key).
- Help: `docs/index.md`, `docs/getting_started.md`.

### Tests

PHP checks 5784 -> 5858 (+74); Node tests 41 -> 43 (+2: bridge+kernel "file lines with revision tracking"
in `tests/endpoint.test.js`, mock-bridge "line edits of a file are tracked by root id or source" in
`tests/client.test.js`). `npm run check` and `npm test` pass.

Existing assertions changed (exact tool lists/counts only): `tests/php/run.php` read-token tool list gains
`modx_search_files`; full tool count 35 -> 37; "escape" counts 35 -> 37 and 37 -> 39 (label text too);
`tests/endpoint.test.js` read-token manifest list gains `modx_search_files`, 35 -> 37 (two places), admin
37 -> 39 and 36 -> 38, read token 19 -> 20; `tests/client.test.js` mock tool list gains `modx_edit_file`.
Harness changes (not assertions): `tests/php/endpoint.php` creates a media source and a stylesheet when
`MODXMCP_TEST_FILES` is set; `startSite()` now passes its env to the setup step as well.

### Deviations and interpretations

- "`source | root`" for `modx_edit_file`: `root` is a root id string `^s[1-9][0-9]*$` (what `read_file`
  returns). `read_file` / `search_files` keep the existing `source | component + area` selection;
  component roots are read-only, so a component id is refused by the schema.
- `total_lines` of files excludes the phantom empty line after a final newline (differs from `modx_view_code`
  on elements, where the same LineEdit counts it).
- A mixed-EOL file is normalised to CRLF by an edit (any CRLF means CRLF), as `LineEdit` already did.
- An empty file is refused by `edit_file` ("use modx_write_file"); an edit that changes nothing returns
  `changeset_id: null`.
- `search_files`: files with a non-listed extension are not counted in `files_scanned`; symbolic links are
  counted in `skipped` but not scanned; oversize and binary/non-UTF-8 files are scanned (count towards the
  2000 cap) and counted in `skipped`; secret file names (`FileRoot::SECRET_NAMES`) and directories forbidden
  by `FileRoot::resolve()` (core/config, modxMCP data, under an opened root source) are silently skipped.
  A query containing a line break never matches (search is line by line).
- `edit_file` size limit uses `modxmcp.max_read_bytes` (default 262144), not read_file's 8 MB.

### Verified only against the fake MODX

Everything (real media source classes, `getBasePath`, file permissions/ownership, journal on MySQL, behaviour of
`modFileMediaSource` with other base paths). Worth checking on the stand: a real CSS edit through the bridge,
undo, CRLF file edited, a PHP file edited with develop and refused without, search over a large media source.

### Open questions

None blocking.
