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

## Report

Based on: main at 4636954

Counts: before, `npm run check` OK (155 PHP files, 21 JS files), PHP 4235 checks, Node 28 tests. After: check OK (157 PHP files), PHP 4433 checks, Node 29 tests (all pass). No existing assertion was changed, weakened or deleted: MIGX is not installed on the sites the existing tests use, so every existing kind count and list is unchanged; the new kind is covered by a new section "MIGX" in `run.php` and a new test in `endpoint.test.js` (a site with MIGX: 23 admin kinds, 18 read kinds, the kind last).

### Implemented, file by file

- `src/Integration/Migx.php` (new): `siteAvailable`, `available` (token-aware), `corePath`, `load(\modX)` (`addPackage('migx', <core>/model/)`, idempotent), `TV_TYPE`. `Capabilities` already declared the integration, so no change there.
- `src/Kind/MigxConfigKind.php` (new, last in `KindRegistry`): kind `migx_config` (group `migx`, read `read`, write `develop`, integer key, name `name`, names unique among configurations that are not deleted). Written through the xPDO object (see below), not processors. Fields `id`, `name`, `category`, `published`, `deleted` (read-only), the JSON fields `formtabs`, `columns`, `filters`, `extended`, `permissions`, `fieldpermissions`, and the strings `contextmenus`, `actionbuttons`, `columnbuttons`. Shape checks with the first bad path (`formtabs[0].fields[1].field must be a non-empty string`), warnings for an `inputTV` that is not a TV and a `columns[].dataIndex` that names no form-tab field (`MIGX_id` exempt), delete = `deleted` flag, `find()` by name ignores deleted ones, `listCriteria()` hides them, `assertUndoable()` refuses to bring back a deleted config while a live one has its name, `stateColumns()` drops `editedon`/`editedby`.
- `src/Kind/ResourceKind.php`: the `tvs` relation. `templateTvs()` also returns the TV's input type; a TV of type `migx` is read as a decoded list/object when the stored text is valid JSON (otherwise the text), and written from a list of objects (each item without `MIGX_id` gets one, continuing from the highest `MIGX_id` of the list sent) or from a string as before. Every other TV: same code path and same messages as before.
- Generic code (no-op defaults in `Kind.php`): field type `json` (`Kind::jsonDecoded()`, `Objects::normalizeJsonValue()`, `Kind::canonicalValue()`, `sameValue`, `ListAction` refuses it in `where`, `ObjectSubject` diff shows it decoded); `usesProcessors()` / `writeObject()` / `removeObject()` (used by `Objects::save` and `DeleteAction` for kinds without processors); `listCriteria()` (`ListAction`); `prepareRestore()` (`Snapshot::restore`); relation flag `named_by` (`Snapshot::changed`).
- `core/components/modxmcp/docs/migx.md` (new, rewritten) and `docs/index.md`; `docs/legacy-help/migx.md` removed (moved).
- Tests: `FakeModx.php` (`migxConfig` as MIGX 3.0.2 saves it, `migxFormtab`, `migxFormtabField`, composites, no id reuse), `run.php` (about 200 checks), `endpoint.php` (`MODXMCP_TEST_MIGX`), `endpoint.test.js`.

### What the source says (and where it differs from the brief)

- **No headless processors, confirmed.** MIGX's config grid uses the generic `processors/mgr/default/update.php` / `remove.php`, which need `$modx->migx` and request state (`$scriptProperties['object_id']`, `customconfigs`). So the xPDO object is written directly, inside the transaction, with `fromArray($data, '', false, true)` (raw: the JSON text is stored exactly as given; `set()` of a json-phptype column ignores a value that does not decode to an array).
- **`migxConfig::save()` is not a plain save** (brief did not know). It mirrors the decoded `formtabs` into the tables `migx_formtabs` and `migx_formtab_fields` (`migxFormtab`, `migxFormtabField`, matched by `MIGX_id` = row id, the rest removed or created) and then rewrites `formtabs` with `MIGX_id` and `pos` on every tab and field. These rows are therefore part of the state: two hidden relations `formtab_rows`, `formtab_fields` are journaled, so an undo puts the same rows back. On restore the kind sets MIGX's own flag `preventformtabhandling` (new hook `prepareRestore`), otherwise the save would mirror the text again and change it. An update that does not change `formtabs` sets the flag too (nothing to mirror, no row churn). The relations are not shown; `named_by` makes `changed` say `formtabs` instead of the two relation names.
- **`contextmenus`, `actionbuttons`, `columnbuttons` are not JSON** (brief listed them as JSON): MIGX stores names joined by `||` (`explode('||', …)` in `migx.class.php`; the MIGX form saves checkbox values that way). They are strings. `filters`, `formtabs`, `columns` are JSON text; `extended`, `permissions`, `fieldpermissions` are xPDO phptype `json` columns (get() decodes them), objects in practice.
- **Deleting**: MIGX's `update.php` task `delete` sets `deleted = 1`, `deletedon`, `deletedby`; task `recall` clears only `deleted`. `modx_delete` does the first, an undo restores the recorded columns (so `deletedon`/`deletedby` go back too).
- **Events / cache**: MIGX fires no events and does not cache configurations (grep of the source), so no event is invoked and the cache is not refreshed.
- **`MIGX_id` of a new row**: `processors/mgr/default/migxupdate.php`: highest `MIGX_id` of the existing items + 1 (starting at 1), appended to the new row. The kind does that over the list sent, for every item without an id; ids already present are kept as they are (int or string).
- **A TV names its config in its input properties**: `configs` = config name (several joined by commas, `name:package` for an add-on package); without it `formtabs` / `columns` are read from the input properties. **The `tv` kind has no field for input properties** (nor does `spec-2.0.md` list one), so `modx_create {kind: "tv", …}` cannot bind a config. The help says so and points to the manager; this needs a decision (below).
- `object` + `json` flag from task 21 was not reused: it holds JSON objects only and stores `{}` for nothing; MIGX needs lists, scalars and `null` ("" stored). New type `json`.

### Deviations and conservative readings

- A new config is created **published** unless `published` is given (the MIGX form sets `published = 1` on create; the column default is 0); `createdon`/`createdby`, `editedon`/`editedby`, `publishedon`/`publishedby` are set as the MIGX update processor does, with the API user's id. `editedon`/`editedby` are not part of the state; the other timestamps are (an undo of a delete keeps them).
- An update whose JSON value equals the stored one (another key order) does not write that column, so the stored text and the revision stay (the update processors of other kinds would rewrite it).
- `update` of a deleted config and a second `delete` are refused (`validation_failed`); undo is the way back. `duplicate` of a deleted config is allowed.
- `name` must not be blank and has at most 100 characters (the column), `category` 191, counted in characters. The create dry run does not check the name (like every kind: `beforeCreate` is not run in a dry run).
- MIGX TV value: a top-level **object** is refused (a MIGX value is a list of rows); `null` is refused for a MIGX TV ("a string or a list of objects"); an empty list is stored as `[]` (MIGX itself stores an empty string; both read back as an empty value: `[]` vs the string). The `modx_update` dry run does not validate MIGX rows (relations are not validated in a dry run for any kind). `modx_duplicate` of a resource copies the stored text (`storedRelation`), unchanged, no renumbering.
- An empty JSON object and an empty list look alike once the request is decoded; both are stored as `[]`.
- Extra edits outside the brief's file list are the generic hooks above and `endpoint.php` / `endpoint.test.js` (test harness).

### Dropped from the 1.x help (no 2.0 equivalent)

The `modx_migx_*` / `create_element` / `modx_update_resource_tvs` examples (replaced by 2.0 calls); the example of creating a TV with `input_properties` (the kind has no such field); "clear_cache after creating" (the API refreshes the cache itself); the caveat "an xPDO-created config may show the Title column until opened and saved in the manager" (the kind now mirrors the form tabs as MIGX's save does; not verified on a real MIGX); the 1.x advice to prefer inline configs over named ones is kept only as "inline is enough for one TV".

### Verified only against the fake MODX (check on the stand)

- Everything about the real classes: `migxConfig::save()` mirroring (the fake implements it from the source) and that the second, internal `save()` and `preventformtabhandling` as an ad-hoc field behave on MODX 2.8.8 / MIGX 3.0.2; that a rename-only update (flag set) leaves the `formtabs` text and both tables untouched; that a formtabs update with the `MIGX_id`s sent back updates rows in place; that an undo (`removeCollection` of tabs, which removes their fields as composites, then re-creating rows with their recorded ids, with `preventformtabhandling`) restores tables and text exactly.
- Raw `fromArray(…, false, true)` on json-phptype columns and datetime columns; `get()` of `extended` etc. returning arrays; default values of a new `migxConfig` (the kind sets every column explicitly); the user id from `$modx->user`.
- That a configuration created through the API is accepted by MIGX in the manager (grid of configurations, a MIGX TV bound to it with `configs`), with Cyrillic captions.
- `migx.core_path` and the probe file `model/migx/migxconfig.class.php` on the stand's layout; `Migx::load()` on a request without MIGX's own plugin having run.
- A MIGX TV value written as a list and read back in the manager's MIGX grid (ids as ints, not strings, in `MIGX_id`).

### Open questions

- Add `input_properties` (an object with the `json` flag, or the `inopt_*` mapping of the TV processors) to the `tv` kind, so that a TV can be bound to a config from the API? Needed to make the help's example complete; the TV processors take `inopt_*` properties and replace the whole set only when one is sent.
- Should a new config really be created published (as the MIGX form does) rather than with the column default 0?
- `named_by` hides the two mirrored-row relations in `changed`; is it acceptable to add the flag to the generic relation specs?
- The hard removal of a config (the manager has an "empty trash") is not offered; undo of a create removes the row and its mirrored rows.
