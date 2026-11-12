# 11 — TV operations: `list_input_types`, `suggest_type`

Branch: `task/11-tv-operations`. Needs task 10 (`modx_invoke`). Spec: section 3.2.
1.x source to port: `core/components/modxmcp/model/traits/tvinputs.trait.php`
(`listTvInputTypes`, `suggestTvType`) and the 1.x help topic
`docs/legacy-help/tv_input_types.md`.

## Goal

Two read-only operations of the kind `tv`, called without an object:

- `modx_invoke {kind: "tv", op: "list_input_types"}`
- `modx_invoke {kind: "tv", op: "suggest_type", args: {…}}`

## Design (decided — do not change)

1. Put the logic in `src/Kind/TvInputTypes.php` (plain class, static methods taking
   `\modX`), and declare both ops in `TvKind::operations()` with `needs_object => false`,
   `read_only => true`, handlers delegating to that class.
2. Port the behaviour of the two 1.x methods exactly: same inputs (derive the `input`
   schemas from what the 1.x code reads from `$data`; every argument optional unless the
   1.x code requires it), same output keys and values. Do not redesign, rename or trim the
   output; if something in the 1.x code is clearly broken, keep it and report it.
3. Replace 1.x idioms with 2.0 ones: `ModxMCPClientException` → `ApiException('validation_failed', …)`;
   no access to the old facade or its traits; results must be JSON objects (wrap a list
   result as `{"types": [...]}` if the 1.x method returned a bare list, and say so).
4. Rewrite `docs/legacy-help/tv_input_types.md` for 2.0 and move it to
   `core/components/modxmcp/docs/tv_input_types.md` (it is then served by `modx_help`):
   replace every 1.x tool name with the 2.0 call (`modx_invoke …`, `modx_create {kind:
   "tv", …}`, `modx_update … relations: {templates: […]}`, `modx_get {kind: "resource"}`
   for TV values, `modx_list {kind: "media_source"}`, `modx_read_file {component: …}`);
   drop sentences about tools that do not exist in 2.0 yet rather than inventing
   replacements, and list what you dropped in the report. Add the topic to
   `core/components/modxmcp/docs/index.md`.

## Tests (required)

`tests/php/run.php`: both ops through `modx_invoke` with a read token; outputs for a few
representative inputs asserted against values you derive by reading the 1.x code (state in
the report how you derived them); argument validation; `id` given with an object-less op →
`validation_failed`; `modx_help {topic: "tv_input_types"}` returns the rewritten topic and
it contains no 1.x-only tool name (assert that none of `modx_suggest_tv_type`,
`modx_list_tv_input_types`, `modx_get_element`, `modx_update_element`,
`modx_create_element`, `modx_get_resource_tvs` occurs).

## Acceptance

`npm run check`, `npm test`. Files: `src/Kind/TvInputTypes.php`, `src/Kind/TvKind.php`, the
moved help topic and `docs/index.md` of the component, tests, `tests/php/FakeModx.php` if
the port needs objects the fake lacks. Do not change the 1.x trait.
