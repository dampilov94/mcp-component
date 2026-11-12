# 23 — MIGX: kind `migx_config`, MIGX TV values, help topic

Branch: `task/23-migx`. Spec: section 3.1 (MIGX). Source (read-only; never execute or
modify): MIGX 3.0.2 as installed on the stand, copied to `.local/reference/migx-3.0.2/core/`
(`model/migx/*.class.php`, `model/migx/mysql/*.map.inc.php`, `processors/mgr/`). 1.x reference
known to work: `core/components/modxmcp/model/traits/migx.trait.php` (it writes `migxConfig`
directly because MIGX's processors need the manager controller) and
`docs/legacy-help/migx.md`.

## Design (decided — do not change)

### Integration

`src/Integration/Migx.php` like `MiniShop2.php`: availability through `Capabilities` (group
`migx`), `load(\modX)` registering the MIGX package once (`addPackage('migx', <core>/model/)`).

### Kind `migx_config`

Class `migxConfig`, integer key, name field `name` (unique among configs that are not
deleted), group `migx`, read `read`, write `develop`. Writes go through the xPDO object
inside the transaction (as 1.x does — the processors are not headless; confirm in the
source and say so), with `Kind` hooks as needed; there are no MIGX events to fire (check).

Fields (exact columns from the map): `id`, `name` (required, list), `category` (string,
list), `published` (bool, list), `deleted` (bool, read-only — see delete), and the JSON
columns `formtabs`, `columns`, `contextmenus`, `actionbuttons`, `columnbuttons`, `filters`,
`extended`, `permissions`, `fieldpermissions` as decoded JSON values in the API (arrays or
objects as stored; `null`/`""` stored → `null`). Add a field type `json` to the field layer
(any JSON value, encoded with `JSON_UNESCAPED_UNICODE` on write; a value that is not valid
JSON-encodable → `validation_failed`); `object` with the `json` flag from task 21 may be
reused if it fits — say which. The JSON columns are not listed by default and cannot be
used in `where`. Other columns of the map (createdon, editedon, …) are derived or ignored
(take care that a timestamp MIGX sets on every save does not change the revision — use
`stateColumns`).

**Delete:** MIGX marks configs deleted (`deleted = 1`) rather than removing them in its UI
(check the source). The API's `modx_delete` sets `deleted = 1` too (an undo clears it);
`modx_list` hides deleted configs unless `where: {deleted: true}`.

**Validation of formtabs/columns:** before a write, check the shape MIGX expects (read how
the MIGX TV input renders `formtabs` and `columns`): `formtabs` is a list of
`{caption, fields: [{field, caption, inputTV?, inputTVtype?, …}]}`, `columns` a list of
`{header, dataIndex, …}`. Refuse a write whose `formtabs` / `columns` do not have that shape
(`validation_failed` naming the first bad path); unknown extra keys are allowed. Warn (not
refuse) when a `columns[].dataIndex` does not name a field of any form tab, and when an
`inputTV` names a TV that does not exist.

### MIGX TV values

A TV with input type `migx` stores a JSON list in the resource's TV value (`tvs` relation of
the resource). Today a model writing `relations: {tvs: {gallery: "[…]"}}` must hand-encode
JSON. Make the resource `tvs` map accept, for a TV of type `migx`, either a string (as
today) or a JSON list/object (encoded by the kind before saving), and make `modx_get`
return a MIGX TV value decoded (a list) when it is valid JSON, otherwise the raw string.
Each item of a written list must be an object; give every item without `MIGX_id` one
(`MIGX_id` values continue from the highest present, as MIGX does — check the source).
Only TVs of type `migx` change behaviour; every other TV stays a string.

### Help topic

Rewrite `docs/legacy-help/migx.md` for 2.0 and move it to
`core/components/modxmcp/docs/migx.md`; add it to the component's `docs/index.md`. Use 2.0
calls only (`modx_create {kind: "migx_config", …}`, `modx_create {kind: "tv", fields: {type:
"migx", …}}` with the config referenced as MIGX expects — read how a TV names its config,
`modx_update {kind: "resource", relations: {tvs: {…}}}` with a JSON list). Drop what has no
2.0 equivalent and list it in the report.

## Out of scope

MIGXdb (custom tables), MIGX's own import/export, package building, the manager UI.

## Tests (required)

Fake `migxConfig` per the map, `addPackage`, MIGX unavailable paths.
- migx_config: create with formtabs/columns as JSON (Cyrillic captions), get decodes them,
  update one JSON column, list hides deleted, delete sets deleted and undo clears it,
  duplicate (`new_name`), shape refusals with the bad path, warnings for an unknown
  `dataIndex` and `inputTV`, a timestamp change alone does not change the revision, scopes,
  group disabled → unavailable.
- MIGX TV values: write a list (MIGX_ids assigned, continuing from the highest), read it
  back decoded, write a raw string still works, a non-object item refused, an invalid stored
  JSON value read as the raw string; a non-MIGX TV is unaffected (an array for it refused).
- `modx_help {topic: "migx"}` returns the rewritten topic without 1.x tool names
  (`modx_migx_`, `modx_update_resource_tvs`, `modx_get_resource_tvs`, `modx_create_element`).

Update kind lists/counts in both test files.

## Acceptance

`npm run check`, `npm test`. Files: `src/Integration/Migx.php`, `src/Kind/MigxConfigKind.php`,
`src/Kind/KindRegistry.php`, `src/Kind/ResourceKind.php` (MIGX TV values), the field layer
for `json`, the help topic and the component's `docs/index.md`, tests, `tests/php/FakeModx.php`.
