# 03 — `modx_search`

Branch: `task/03-search`. Spec: section 3 (tool table, "Код"), section 11 (context economy).
1.x reference (behaviour to keep, code not to copy): `core/components/modxmcp/model/traits/search.trait.php`.

## Goal

Find where a piece of text occurs in the site's code: chunks, snippets, templates, plugins
and, on request, resource content. Read-only, cheap in tokens.

`modx_search {query, kinds?, case_sensitive?, limit?, max_matches?}`

## Design (decided — do not change)

1. New class `src/Action/SearchAction.php` extending `Action` (read-only, scope `read`),
   name `search`, group `code`, registered in `Action/Registry.php` right after
   `EditCodeAction`.
2. Input:
   - `query` — required string, 2..500 characters, matched **literally** (no regex, no
     wildcards);
   - `kinds` — optional list of kind names; default `["chunk","snippet","template","plugin"]`.
     Only kinds that have a code field (`Kind::codeField() !== null`) are accepted;
     anything else is `validation_failed` naming the valid kinds. `resource` is valid but
     never default;
   - `case_sensitive` — boolean, default false;
   - `limit` — objects to return, default 50, 1..200;
   - `max_matches` — matching lines to show per object, default 5, 1..50.
3. Each kind is resolved with `Objects::kind($context, $name)` so scope and group rules
   apply; a kind this token may not read is `forbidden_scope` / `unavailable` as usual.
4. What is searched is the **effective** code: `Kind::effectiveContent($object)` — the file
   for a static element — never `getContent()` (it can write the file back into the
   database). The object name is also searched: an object whose name contains the query is
   a hit even with no code match (`name_match: true`).
5. Iteration: page through each kind with `newQuery` + `limit(200, offset)` ordered by id.
   For kinds without static files (`resource`) add a SQL pre-filter
   `content LIKE %query%` OR name-column `LIKE %query%` to avoid loading every page; for
   kinds that can be static, load all objects of the kind (their code may be in files).
   Escape `%`, `_` and `\` in the query for `LIKE`.
6. Output (every count is exact unless `truncated`):
   ```json
   { "query": "…", "case_sensitive": false,
     "objects": 3, "truncated": false,
     "results": [
       { "kind": "chunk", "id": 12, "name": "header", "static": false,
         "match_count": 4, "name_match": false,
         "matches": [ { "line": 7, "text": "<nav>[[$menu]]</nav>" } ] } ] }
   ```
   - `match_count` = number of matching **lines** in the object; `matches` holds the first
     `max_matches` of them; a line's `text` is trimmed and cut to 300 characters with `…`
     (cut on a character boundary; keep the match visible: if the match starts after
     character 250, show a window around it prefixed with `…`).
   - results are ordered by kind in the order requested, then by name.
   - stop collecting after `limit` objects; if more objects match, `truncated: true`
     (keep scanning only as far as needed to know that one more exists).
   - an object whose static file is missing is skipped for code but can still be a
     `name_match`.
7. Description for the model, short: literal text search in element code (and resource
   content when `kinds` includes `resource`); returns matching lines with line numbers
   usable with `modx_view_code` / `modx_edit_code`.
8. Output schema: declare the top-level keys; `results` items may be an open object.

## Out of scope

Regex, replace (`modx_replace` is a later brief), `find_usages`, searching files of media
sources or components, TV values, settings.

## Tests (required)

In `tests/php/run.php`, a new block after the "List" block, on the objects that exist at
that point plus a few stored directly in the fake:

- a query present in two chunks and one snippet: exact `objects`, per-object `match_count`,
  line numbers and texts; order by requested kinds then name.
- case-insensitive by default; `case_sensitive: true` narrows it.
- `max_matches` caps `matches` but not `match_count`; `limit` caps `results` and sets
  `truncated`.
- a static chunk whose file contains the query but whose database column does not is found,
  and the file and the database row are unchanged afterwards (no synchronisation).
- a name-only hit has `name_match: true`, `match_count: 0`, `matches: []`.
- `kinds: ["resource"]` finds resource content; resources are not searched by default.
- `kinds: ["tv"]` → `validation_failed`; unknown kind → `not_found`.
- a query containing `%` and `_` is literal (does not match everything).
- long line: `text` is at most 300 characters plus the ellipsis and contains the query.
- a `read` token can call it.

Extend `FakeQuery`/`match()` in `tests/php/FakeModx.php` only if needed (e.g. `OR` groups
already exist; `LIKE` is case-insensitive there as in MySQL's default collation).

Update the expected tool lists/counts in `tests/php/run.php` and `tests/endpoint.test.js`
(one more read tool: it appears in the read-token list too).

## Acceptance

`npm run check` and `npm test` pass. Changes limited to `src/Action/SearchAction.php`,
`src/Action/Registry.php`, the two test files and, if needed, `tests/php/FakeModx.php`.

## Report

Based on: main at 490ec2c

- `SearchAction.php`: literal effective-code/name search, paged reads, resource LIKE
  prefilter with escaping, matching-line counts, bounded UTF-8 excerpts and truncation.
- `Registry.php`: registered search after edit_code. Both test files include the new read
  tool (25 tools total). `run.php` covers the requested search cases; `FakeModx.php` now
  interprets escaped LIKE patterns, including literal percent, underscore and backslash.
- Validation: `npm run check` and `npm test` passed; 393 PHP checks, 24 Node/HTTP tests.

Interpretation: id-paged scanning stops at the first extra hit, then sorts the selected
results by requested kind and name. With truncation this is a sorted scanned subset, not
necessarily the alphabetically earliest subset: globally selecting by name would contradict
stopping as soon as one extra hit is known. Long queries exceeding 300 characters cannot
fit entirely in the specified excerpt; the window includes their start. No other deviation.

Only fake MODX/local files were exercised. Check real xPDO paging, MySQL LIKE escaping and
static-file reads on the stand. No site operations or pushes. Open question: confirm the
truncated-subset ordering interpretation above.
