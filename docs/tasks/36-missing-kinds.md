# 36 — Kinds property_set and lexicon_entry; media source properties

Branch: `task/36-missing-kinds` (create it from `task/35-security-cleanup`, stacked).
Spec: section 3.1 (kinds `property_set`, `lexicon_entry`), 3.2 (operations of `property_set`:
`assign`, `unassign`). The groups `property_sets` and `lexicon` exist in the panel and in token
restrictions but no kind belongs to them yet, so their switches control nothing. Read first: an
existing kind with a string key (`src/Kind/SystemSettingKind.php`, `NamespaceKind.php`,
`ContextSettingKind.php`), how relations are declared and written (`TvKind`, `PluginKind`),
`src/Kind/MediaSourceKind.php`, `src/Kind/TvKind.php` (`input_properties` as an object field),
the element kinds' relation `property_sets`, and in `modx-example-2-8-8/`: the maps of
`modPropertySet`, `modElementPropertySet`, `modLexiconEntry`, the processors
`element/propertyset/*`, `workspace/lexicon/*`, `source/*`, and `modLexicon` (how a DB entry
overrides a file entry).

## Design (decided — do not change)

### `property_set` (group `property_sets`, write scope develop)

Fields `name` (required, unique), `description`, `category`, `properties` (object: property name →
`{type, value, desc?, options?, lexicon?, area?}`, stored the way MODX stores it). Relation
`elements` (writable list of `{kind, id}` — chunk, snippet, template, plugin, tv), mirroring
`modElementPropertySet`; the element kinds' existing `property_sets` relation becomes writable
(list of property set ids) and stays consistent with it. Operations `assign` / `unassign`
(`{kind, id}`) as shortcuts. Create/update/delete through the core processors where they exist;
journaled and undoable like every kind. Elements also get a writable field `properties` (their
default properties, same object shape), because default `&tpl`-style parameters of pdoTools /
miniShop2 snippets live there.

### `lexicon_entry` (group `lexicon`, write scope develop)

Key `namespace:topic:language:name` (e.g. `core:default:ru:error`). Reading returns the effective
value and where it comes from (`source: file | database`, with the file value when overridden).
`list` filters by `namespace`, `topic`, `language`, `query` (in name or value); it lists the file
entries too (that is what people search for), with `overridden` true/false. `update` (or
`create`) writes a database override; `delete` removes the override (the file value applies
again). Journaled and undoable; `modx_maintenance` is not needed — clear the lexicon cache the way
the core processors do.

### Media source properties

`media_source` gets the field `properties` (object of property name → value: `basePath`,
`baseUrl`, `basePathRelative`, `baseUrlRelative`, `allowedFileTypes`, `skipFiles`, … — whatever
the source's class declares; merge into the stored definition as MODX does, keeping each
property's type/desc). Writes are checked so a token cannot turn a source into a window on
protected paths: a `basePath` that resolves into `{core_path}config/`, `{core_path}modxmcp-data/`
or outside `base_path`/`core_path` needs the `admin` scope and `modxmcp.allow_root_filesystem_read`
(state the exact rule you implement and why in the report; keep it consistent with `FileRoot`).

## Tests (required)

`tests/php/run.php`, block "Property sets, lexicon, media source properties": CRUD + undo for each
kind; assign/unassign both ways and consistency of the two relations; element `properties`
written and read back, undo; lexicon file vs database value, override, delete, undo, list filters
with Cyrillic values; media source properties merge, undo, the protected-path rule per scope;
groups: a disabled `lexicon` / `property_sets` group hides exactly these kinds, a token with the
group denied cannot use them. Update exact kind/tool counts where asserted.

## Acceptance

`npm run check`, `npm test`. Commit after each kind. Report as usual, with what needs the stand
(real processors and lexicon cache).

## Report

Based on: task/35-security-cleanup at 42d9bef (stacked; 35 is not merged)

Counts: `npm test` 37 pass before and after; `tests/php/run.php` 5221 checks before (end of 35), 5410 after; `npm run check` passes. Kinds: 23 → 25 for an admin token (`property_set`, `lexicon_entry`).

### Implemented

- `src/Kind/Properties.php` (new): the `properties` column of elements, property sets and sources (serialized map name => {name, desc, type, options, value, lexicon, area}; JSON in the fake) read as `{name: {type, value, desc?, options?, lexicon?, area?}}`, validated and turned into the stored map on write.
- `src/Kind/PropertySets.php`, `PropertySetKind.php` (new): kind `property_set` (group `property_sets`, develop scope): fields id, name, description, category, `properties`; relation `elements` (writable list of `{kind, id}`); operations `assign` / `unassign`. Created, updated and deleted through `element/propertyset/{create,update,remove}`; links through `element/propertyset/addelement` and `removeelement`.
- `src/Kind/Kind.php`: new field flag `stored_properties`; `elementFields()` and `TvKind` get the writable field `properties`; the `property_sets` relation of elements is writable (shared helper `propertySetRelation()`), written through `PropertySets`; new hooks `relationAffects()` (objects of other kinds touched and journaled with a relation write; `Objects::save()` calls it) and `customList()` (`ListAction` calls it); `relationBlockReason()` hides the element's `property_sets` relation from a token that cannot use the `property_set` kind.
- `src/Kind/LexiconEntry.php`, `LexiconEntryKind.php` (new): kind `lexicon_entry` (group `lexicon`, develop scope), key `namespace:topic:language:name`. `LexiconEntry` is a non-xPDO stand-in for "the entry as the site shows it" (override row or file value); state without `id` = no override, so undo of a first write removes the row. Writes: `workspace/lexicon/create` and `updatefromgrid`; delete: `revert`. Fields namespace, topic, language, name, value, computed `source`, `overridden`, `file_value`, and `id`.
- `src/Kind/MediaSourceKind.php`: field `properties` as `{name: value}` (effective: class defaults under stored values); a write merges into the stored definitions (type, desc, lexicon kept) and sends the full list to `source/update` / `source/create`; booleans cast for `combo-boolean` types; names the class does not declare are refused when the class declares any.
- `Kind/KindRegistry.php`: `PropertySetKind`, `LexiconEntryKind` after `ProviderKind` (before the add-on kinds). `Action/ListAction.php`: the `customList()` hook. `docs/getting_started.md`: a short section.
- Tests: three blocks at the end of `tests/php/run.php` ("Property sets, lexicon, media source properties"); fake: `FakeLexicon`, processors for property sets, lexicon and sources, `getDefaultProperties()`, composite key of `modElementPropertySet`.

### The media source rule (what I implemented)

On a write of `properties` to a local file source (`class_key` containing `modFileMediaSource`) the resulting `basePath` (relative to `base_path` unless `basePathRelative` is false; `[[++setting]]` tags filled in; normalised like `StaticPath::normalize`) is "protected" when it (a) is or lies in `{core_path}config/`, (b) is or lies in `{core_path}modxmcp-data/`, (c) lies outside both `base_path` and `core_path`, or cannot be resolved (`..`, other tags). A protected path is refused with `validation_failed` unless `modxmcp.allow_root_filesystem_read` is on and the token has `admin` scope; with both it is written and a warning says so. The kind already needs `admin` to write, so the scope part is checked first and also again in the rule. These are the same directories `FileRoot` forbids; a path that spans the whole site or the core is not judged here (the brief names only the three cases) because `FileRoot` gates every use of it. Judged on create and on update, only when `properties` is in the write.

### Deviations and judgement calls

- Element `properties` are sent to the processors as the stored-map array in the generic `properties` argument, not as `propdata`: in 2.8.8 only the create processors read `propdata`, the update processors ignore it, and `fromArray()` of the processors stores an array as a serialized `properties` column. An unchanged value is never sent (so a save of another field cannot rewrite it).
- Existing assertions changed (3 kinds of edit): (1) exact kind lists in two PHP checks (`describe: lists element kinds`, `ms2: kind list with miniShop2 installed`) and the kind counts in four places of `tests/endpoint.test.js` (17/22 → 19/24 for read/admin, 27 → 29, 21 → 23, 23 → 25, 18 → 20, 25 → 27, 20 → 22, with their comments); (2) `duplicate: non-writable relations are omitted with one warning` is now `duplicate: property sets are now a writable relation and are copied without a warning`, because `property_sets` became writable (the fixture now stores property set 7 that the link points to); (3) nothing else.
- The element's `property_sets` relation is hidden from a token that cannot use `property_set`; `modxmcp.disabled_groups` contains `property_sets` by default, so on a fresh install a chunk read no longer shows that relation until the group is switched on.
- `modx_history` cannot filter by a string key (`id` is an integer in its schema; same for system settings); not changed.
- `StaticPath::settingTags()` and `inside()` (brief 35) are now public for the media source rule.
- Deleting an element or a property set removes its link rows through MODX's own composites; the object on the other side is not touched in the journal, so its next write records an "outside change, author unknown". Not fixed (no hook for it).
- The dry-run `would_change` of `modx_update` lists a `properties` field that was sent even when equal (the comparison reads the raw serialized column); `modx_history_diff` shows the raw serialized text for the properties of elements and sources. The written result and the journal are exact.
- A lexicon list needs at least one of namespace, topic, language, name or query (all entries of a site are too many); at most 500 lexicon files are read per call.

### Verified only against the fake (check on the stand)

- Real processors: `element/propertyset/*` (permissions `save_propertyset` / `delete_propertyset`), `source/create` and `update` with the full property list (`modMediaSource::setProperties` drops values equal to the class default), `workspace/lexicon/create`, `updatefromgrid` and `revert`; that `fromArray()` of the element processors serializes the `properties` array; that the update processors leave `properties` alone when it is not sent.
- `workspace/lexicon/updatefromgrid` dies (`$this->entry` is null) when no override exists and the value equals the file's; the kind guards it (no write then). The real lexicon cache (`modLexicon::clearCache` via `modLexiconEntry::save()` / `clearCache()`), `getLanguageList` / `getTopicList` (which also query the database), `getFileTopic` includes the lexicon PHP files, and the real `getDefaultProperties()` of file and S3 sources.
- `Snapshot::restore()` of a lexicon state goes through `LexiconEntry::save()` / `remove()` (direct xPDO writes, as the rule allows) and clears the topic cache itself.

### Open questions

- Should an element's `property_sets` relation stay visible when the `property_sets` group is off (as before), now that it is writable?
- Should deleting an element or property set journal the other side (needs a delete hook beside `deleteCascade`)?
