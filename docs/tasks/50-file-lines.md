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
