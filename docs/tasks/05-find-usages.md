# 05 — `modx_find_usages`

Branch: `task/05-find-usages` (stack on the previous task branch if it is not merged yet).
Spec: section 3 ("Код"). 1.x reference: `findUsages()` in
`core/components/modxmcp/model/traits/search.trait.php`.

## Goal

Answer "where is this used?" for one object before it is renamed or deleted:
`modx_find_usages {kind, id|name, limit?}` — read-only, scope `read`, group `code`.

## Design (decided — do not change)

1. `src/Action/FindUsagesAction.php` extends `Action`; registered after `ReplaceAction`
   (or after `SearchAction` if replace is not present on your base).
2. The target is resolved with `Objects::kind` + `Objects::resolve`. Supported target
   kinds and what counts as a usage (N = the target's name, ID = its id). A name match
   must be followed by a character that cannot continue a name (anything except
   `A-Z a-z 0-9 _ . -`), or the end of the text.

   | Target | Patterns searched in code (each hit carries `via`) |
   |---|---|
   | `chunk` | `[[$N` and `[[!$N` (`via: tag`); `getChunk('N'` / `getChunk("N"` (`api`); a backtick property value that is exactly N: `` `N` `` (`property`); Fenom `{include 'N'` / `{include "N"` and `'N' | chunk` (`fenom`) |
   | `snippet` | `[[N` and `[[!N` (`tag`); `runSnippet('N'` / `runSnippet("N"` (`api`); `` `N` `` (`property`); `'N' | snippet` (`fenom`) |
   | `tv` | `[[*N` and `[[!*N` (`tag`); `getTVValue('N'` / `getTVValue("N"` (`api`); `resource.N` (`fenom`); N as a whole word inside a backtick value of a property named `includeTVs`, `processTVs` or `tvFilters` (`property`) |
   | `resource` | `[[~ID` and `[[!~ID` (`tag`); `makeUrl(ID` (`api`); `ID | url` (`fenom`) — ID must not be followed by a digit |
   | `template` | no code patterns; see "extra" below |
   | `category` | no code patterns; see "extra" below |

   Any other target kind → `validation_failed` listing the supported kinds.
3. Where it searches: the effective code of every chunk, snippet, template and plugin, and
   the `content` of resources (pre-filter resources in SQL with `LIKE` on N / ID; escape
   wildcards). The target itself is excluded. Use the shared `Code\CodeSearch` helper from
   task 04 if present; otherwise put the iteration in a helper of your own that search can
   adopt later.
4. Output:
   ```json
   { "kind": "chunk", "id": 12, "name": "header",
     "objects": 2, "truncated": false,
     "usages": [ { "kind": "template", "id": 3, "name": "Base", "static": true,
                   "match_count": 1,
                   "matches": [ { "line": 14, "text": "[[$header]]", "via": "tag" } ] } ],
     "extra": {} }
   ```
   `limit` = objects (default 50, max 200); up to 5 matches per object; `text` trimmed and
   cut as in search. Order: kind (chunk, snippet, template, plugin, resource), then name.
5. `extra`:
   - `template`: `{"resources": <count of non-deleted resources using it>, "resource_ids":
     [first 20 ids], "tvs": [ids of TVs attached]}`.
   - `tv`: `{"templates": [ids it is attached to], "resources_with_value": <count of
     modTemplateVarResource rows>}`.
   - `category`: `{"chunks": n, "snippets": n, "templates": n, "plugins": n, "tvs": n,
     "subcategories": n}`.
   - `chunk`, `snippet`, `resource`: `{}` (for `resource` add `"children": <count>`).
6. Description, short: where a chunk, snippet, TV, template, category or resource is used —
   tags, API calls, snippet properties and Fenom — to check before renaming or deleting it.
   Say that matching is textual and can miss names built at run time.

## Out of scope

Changing `modx_delete`'s dry run (next brief), dependency graph, plugins as targets.

## Tests (required)

`tests/php/run.php`, new block after the replace (or search) block. For each target kind
store a few elements in the fake whose code contains: a real usage of each `via` type, the
name as a prefix of a longer name (`header` vs `header_top` — must **not** match), the id
as a prefix of a longer number (`[[~12]]` vs `[[~123]]`), cached and uncached tag forms, a
static chunk whose file holds the usage, and the target's own code mentioning itself
(excluded). Check exact `objects`, `via`, lines; `extra` for template, tv, category,
resource; `limit` / `truncated`; unsupported kind; read token allowed.

Update tool lists/counts in both test files.

## Acceptance

`npm run check`, `npm test`. Files: `src/Action/FindUsagesAction.php`, `src/Action/Registry.php`,
`src/Code/*` helper if needed, tests, `tests/php/FakeModx.php` only if needed.
