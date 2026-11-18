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
