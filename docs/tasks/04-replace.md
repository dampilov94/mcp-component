# 04 — `modx_replace`

Branch: `task/04-replace` (stack on `task/03-search` if that is not merged yet).
Spec: section 3 ("Код"), section 7. 1.x reference (behaviour, not code):
`replaceAcross()` in `core/components/modxmcp/model/traits/elements.trait.php`.

## Goal

Replace a literal text in the code of many elements in one reviewed, undoable step:
`modx_replace {find, replacement, kinds?, case_sensitive?, limit?, dry_run?, expected_revisions?}`.

## Design (decided — do not change)

1. `src/Action/ReplaceAction.php` extends `WriteAction`, name `replace`, group `code`,
   registered right after `SearchAction`. `destructive()` true.
2. Move the matching/iteration logic of `SearchAction` into a helper both actions use
   (`src/Code/CodeSearch.php`: resolve kinds, iterate objects with their effective content).
   `SearchAction` must keep its exact behaviour and tests.
3. Input: `find` (required, 2..500 chars, literal), `replacement` (required string, may be
   empty), `kinds` (same rules and default as search; each resolved with
   `Objects::kind($context, $name, true)` — write scope applies), `case_sensitive` (default
   **true** here), `limit` (objects, default 50, max 100), `dry_run`,
   `expected_revisions` (object: `"kind:id"` → revision).
4. Two-step protocol, enforced:
   - **Dry run** (`dry_run: true`, or a plan-only token): find every object whose effective
     code contains `find`; return per object `kind`, `id`, `name`, `static`, `occurrences`,
     `revision` (`Snapshot::revision(Snapshot::capture(...))`) and up to 3 matching lines
     (`line`, `text` — same trimming as search), plus `total_occurrences`, `objects`,
     `truncated`, and `revisions`: the map `"kind:id" → revision` to pass back. Nothing is
     changed. If more than `limit` objects match, `truncated: true` and only `limit` are
     listed.
   - **Real run**: `expected_revisions` is **required** (else `validation_failed` telling
     the caller to run a dry run first). Exactly the listed objects are processed — no new
     search. Keys must match `^[a-z_]+:[1-9][0-9]*$` and name an allowed kind; at most 100.
5. Real run, inside one `Writer::run` (one changeset, all or nothing):
   - `touch` every listed object first; an object that no longer exists → `not_found`.
   - Compare each current revision with the expected one. If any differ, throw
     `revision_conflict` with `details.changed` = list of `"kind:id"` (nothing written).
   - Compute the new content (`str_replace` / `str_ireplace`) from the touched state's code
     column. An object with zero occurrences is skipped (reported with `occurrences: 0`).
   - Lint every new content (`Objects::lint`) **before** writing anything; on `lint_failed`
     include `details.object` = `"kind:id"`.
   - A static element whose file is missing (`static_file_missing` in the state) →
     `validation_failed` naming it.
   - Write each with `Objects::save($context, $tx, $kind, $id, [$column => $new], [])`.
   - Result: `objects` (kind, id, name, occurrences, revision after), `total_occurrences`,
     `changeset_id`, `reversible`.
6. Description, short: literal replace across element code; first call with `dry_run` to
   see the affected objects and get `revisions`, then call again with them as
   `expected_revisions`; one changeset, undoable.

## Out of scope

Regex, replacing in files or TV values, resource content unless `kinds` includes `resource`.

## Tests (required)

`tests/php/run.php`, new block after the search block:

- dry run lists the right objects, occurrences, revisions; changes nothing; no changeset.
- real run without `expected_revisions` → `validation_failed`.
- real run with the dry-run map replaces in all of them, one changeset, and
  `modx_undo` of it restores every object.
- an object changed between dry run and real run → `revision_conflict` naming it; nothing
  changed anywhere.
- a replacement that breaks a snippet's PHP → `lint_failed` with the object; nothing
  changed anywhere (including the chunks that would have been fine).
- static chunk: the file is rewritten; undo restores it.
- case sensitivity: default sensitive; `case_sensitive: false` replaces all casings.
- `expected_revisions` naming an object of a kind not in `kinds`, a malformed key, or a
  missing object → proper errors.
- a `content` token cannot replace in chunks (`forbidden_scope`); plan-only token gets the
  dry-run result.
- search tests still pass unchanged.

Update tool lists/counts in both test files.

## Acceptance

`npm run check`, `npm test`. Files: `src/Action/ReplaceAction.php`, `src/Action/SearchAction.php`,
new `src/Code/CodeSearch.php`, `src/Action/Registry.php`, tests, `tests/php/FakeModx.php` only if needed.
