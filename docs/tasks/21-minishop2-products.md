# 21 — miniShop2: product data, options, extra categories

Branch: `task/21-minishop2-products`. Spec: section 3.1 (miniShop2 kinds), 13 (integrations
are optional: everything here exists only when miniShop2 is installed and the `minishop2`
group is not disabled).

Source to read first (read-only, never execute or modify):
- miniShop2 3.0.7 as installed on the stand: `.local/reference/minishop2-3.0.7/core/`
  (`model/minishop2/*.class.php`, `model/minishop2/mysql/*.map.inc.php`, `model/schema/`,
  `processors/mgr/product/`, `processors/mgr/settings/option/`, and how `minishop2.class.php`
  loads its package).
- 1.x behaviour known to work on real sites: `core/components/modxmcp/model/traits/minishop2.trait.php`
  and `docs/legacy-help/minishop2.md`.

## Goal

A product (`msProduct`, a resource) is today editable only as a resource: its price, article,
options and extra categories are not visible, and changing them is not journaled
(`ResourceKind::writeWarnings()` says so). After this task they are part of the resource and of
its revision, and options are a kind of their own.

## Design (decided — do not change)

### Loading the model

One helper `src/Integration/MiniShop2.php`: `available(Context)` (via `Capabilities`) and
`load(\modX)` that registers the miniShop2 xPDO package once per request (`addPackage` with
the package's model path, the way `minishop2.class.php` does it, without instantiating the
miniShop2 service unless that is required for the processors you use — say which).
Every place below calls `load()` before touching an `ms*` class.

### Product data on the `resource` kind

When miniShop2 is available, `ResourceKind` gains three relations for resources whose
`class_key` is `msProduct` (for other resources they are absent from `modx_get` and empty in
the snapshot):

| Relation | Class | What |
|---|---|---|
| `ms2_data` | `msProductData` (one row, key = resource id) | map `{column: value}` of the product data: every column of the map except the key (`price`, `old_price`, `article`, `weight`, `vendor`, `made_in`, `new`, `popular`, `favorite`, `color`, `size`, `tags`, `source`, `image`, `thumb` — take the exact list and types from the map). `image` and `thumb` are read-only (the gallery maintains them). Writable map: `relations: {ms2_data: {price: 1500}}` changes only the given columns. JSON columns (`color`, `size`, `tags`) are lists of strings in the API. |
| `ms2_options` | `msProductOption` (`product_id`, `key`, `value`, several rows per key) | map `{option key: [values]}`. Writable map: each given key's values are replaced; `[]` removes the key. A key must be an existing `msOption` (`validation_failed` listing nothing — just the unknown key). |
| `ms2_categories` | `msCategoryMember` (`product_id`, `category_id`) | list of additional category resource ids (not the parent). Writable list, replaces the set; every id must be an `msCategory` resource. |

These relations are part of the resource's snapshot, so `modx_undo` restores price, options
and categories. `ResourceKind::writeWarnings()` no longer warns for `msProduct` (it still warns
for other extended classes).

**How to write.** Read the miniShop2 product processors (`processors/mgr/product/update`) and
the classes. If writing `msProductData` / `msProductOption` / `msCategoryMember` rows directly
through xPDO inside the transaction is what those processors do too (plus events), do that and
fire the same miniShop2 events the processor fires around a product save (name them in the
report); otherwise use the processor with `processors_path` of miniShop2. Either way the
resource update processor must not run twice for one call, and the product data must not be
reset by a core resource update (check what `resource/update` does with an `msProduct`: if it
loads `msProductUpdateProcessor` through `getInstance()`, or saves `msProductData` from
request properties, make sure a plain `modx_update` of a product's `pagetitle` leaves price
and options as they are — add that test).

Creating a product: `modx_create {kind: "resource", fields: {class_key: "msProduct", parent:
<category id>, …}, relations: {ms2_data: {…}}}` must create the `msProductData` row as
miniShop2 does (read how; a product without its data row breaks the shop).

### Kind `ms2_option`

Class `msOption`, integer key, name field `key`, group `minishop2`, read `read`, write `develop`.
Fields: `id`, `key` (required, list, unique), `caption` (list), `description`, `measure_unit`,
`category` (int: element category), `type` (list; one of the option types miniShop2 knows —
read them from the source; `modx_describe` lists them in the field description),
`properties` (object, JSON column; type-specific values such as combo-box choices). Relation
`categories` (`msCategoryOption`, fk `option_id`, present `['category_id', 'active',
'required', 'rank', 'value']`, read-only). Operations through `modx_invoke`:
`assign_to_category {category, active?, required?, value?}` and
`remove_from_category {category}` (write; touch the option). Use miniShop2's processors
(`mgr/settings/option/*`) where they run headless; the 1.x trait shows which did.
Deleting an option also removes its product values and category links in miniShop2 (check):
journal them through `deleteCascade` / delete-only relations so an undo restores them.

## Out of scope

Links, product links, orders, statuses, deliveries, payments, vendors (brief 22 and later),
the gallery (files), price recalculation of carts.

## Tests (required)

Extend the fake with the `ms*` classes involved (from the maps), `addPackage`, the cascades
and whatever the product processors do that the API relies on. Make the fake's
`resource/update` behave like the real one does for an `msProduct` (as you found it).

- product: get shows the three relations; update of `ms2_data` (one column), `ms2_options`
  (replace, remove a key, unknown key refused), `ms2_categories` (non-category id refused);
  each journaled and undone exactly; a plain `pagetitle` update keeps price and options;
  create of a product creates its data row; delete + undo restores data, options, categories.
- a non-product resource: no ms2 relations in get; writing them refused.
- miniShop2 unavailable (group disabled, or not installed): no ms2 relations, kind
  `ms2_option` unavailable, existing resource tests unchanged.
- `ms2_option`: CRUD, `properties` object, assign/remove category (journaled, undone),
  delete cascade + undo, scopes.
- Cyrillic option captions and values.

Update kind lists/counts in both test files.

## Acceptance

`npm run check`, `npm test`. Files: `src/Integration/MiniShop2.php`, `src/Kind/ResourceKind.php`,
`src/Kind/Ms2OptionKind.php`, `src/Kind/KindRegistry.php`, generic code only for a hook the
conditional relations need (say which), tests, `tests/php/FakeModx.php`.

## Report

Based on: main at 1e52531

Counts: before, `npm run check` OK (148 PHP files, 19 JS files), PHP 3698 checks, Node 27 tests. After: check OK (150 PHP files), PHP 3900 checks, Node 28 tests (all pass). No existing assertion was weakened, changed or deleted (the old "extended classes get a journaling warning" check still passes: that site has no miniShop2, see deviations).

### How product data is written, and why

- Resource fields still go through the core `resource/update` / `resource/create` processors (`Objects::save`), exactly once per call. With miniShop2's package registered, `modResourceUpdateProcessor::getInstance()` picks `msProductUpdateProcessor` / `msProductCreateProcessor` by `class_key`. The request holds only resource columns, so nothing resets product data: `msProduct::set()` routes only non-resource properties to the data row, and `msProductUpdateProcessor::handleCheckBoxes()` touches `new` / `popular` / `favorite` / `show_in_tree` only when they are sent (`modProcessor::setCheckbox` is conditional in 2.8.8). A plain `pagetitle` update is tested to leave every ms2 table identical.
- The three relations are written directly with xPDO inside the transaction (`ResourceKind::writeRelation`), the way `msProductData::save()` and the product processors write them: `msProductData` through its own `save()` (so the `prepareObject()` normalisation and the JSON-to-option mirror run), `msProductOption` and `msCategoryMember` rows with `removeCollection` + `save()` of new rows. The product update processor is deliberately not used for them: with `options` in its request, `msProductData::saveProductOptions()` deletes every option value whose key is not assigned to one of the product's categories (data loss), and its `categories` handling is a plain-list-or-diff dual format.
- miniShop2 events: none. The product processors and `msProductData` fire no miniShop2 event on save (`msOnGetProductPrice/Weight/Fields` are read events). The only events are the core `OnBeforeDocFormSave` / `OnDocFormSave` of the resource processor, which run when resource fields are written. A relation-only change fires no event (as the `tvs` relation does) and refreshes the cache. An undo fires `OnDocFormSave` through the existing restore path.
- A product is never left without its data row: the create processor makes it (`msProduct::save()` → `loadData()`), `ResourceKind::beforeCreate` checks and creates it if missing, and `writeData` creates it when absent.
- miniShop2's service (`getService('miniShop2')`) is never instantiated: `MiniShop2::load()` only calls `addPackage('minishop2', <core>/model/)` (idempotent in xPDO; miniShop2's own plugin normally did it at OnMODXInit), because the models and the processors used here do not need the service.

### Implemented, file by file

- `src/Integration/MiniShop2.php` (new): `siteAvailable(Context)`, `available(Context)` (token-aware), `corePath`, `processorsPath`, `load(\modX)`, the msProductData column table from the 3.0.7 map, the three JSON-mirrored option keys, the nine option types.
- `src/Kind/ResourceKind.php`: relations `ms2_data`, `ms2_options`, `ms2_categories` (writable, only while miniShop2 is usable on the site; get/duplicate/write only for `class_key` msProduct); `attach`, `prepare`, `hasRelation`, `relationBlockReason`, `objectColumns`, `beforeCreate`, `assertUndoable`, `restoreRelation`; `writeWarnings()` is silent for msProduct while miniShop2 is usable.
- `src/Kind/Ms2OptionKind.php` (new, registered last in `KindRegistry`): kind `ms2_option`, read `read`, write `develop`; relation `categories`; operations `assign_to_category` (processor `mgr/settings/option/assign`) and `remove_from_category` (processor `mgr/category/option/delete`); create/update/remove through `mgr/settings/option/*` with `processors_path` set to miniShop2's processors; `deleteCascade`, `restoreRelation`.
- Generic code (hooks with no-op defaults, in `Kind.php`): `attach(Context)`, `prepare(\modX)`, `objectColumns($object)`, `hasRelation()`, `relationBlockReason()`, `restoreRelation()`, `processorOptions()`; field flag `json` (type `object` holding any JSON object, for `properties`) with `Kind::jsonValue()` / `canonicalJson()`, `Objects::normalizeJson()`. Wiring: `KindRegistry` takes the `Context` and attaches kinds; `Objects::kind()` and `ObjectSubject::__construct` call `prepare()`; `Objects::run/process` take processor options; `Objects::assertWritableRelations` takes the context; `Snapshot::capture` uses `objectColumns`, `Snapshot::restore` asks `restoreRelation`; `GetAction`, `DuplicateAction`, `DeleteAction`, `CreateAction`, `UpdateAction`, `ObjectSubject` (diff of json fields), `Capabilities::integrationPath()`, `Context`.
- Tests: `FakeModx.php` gets the miniShop2 classes (msProductData with `prepareObject`/JSON mirror/default `source`, PK-less `msProductOption`, composite keys of `msCategoryMember` / `msCategoryOption`, `msCategoryOption::save/remove` side effects, `msProduct` object whose `toArray()` mixes in the data, product create/update routing), the processors of options (found only through `processors_path`), namespace queries for `Capabilities`, option `fake_ms2_no_data`. `run.php`: section "miniShop2 (task 21)", 202 checks. `endpoint.php` / `endpoint.test.js`: a site with miniShop2 installed (env `MODXMCP_TEST_MS2`); kind counts 22 -> 23 admin, 17 -> 18 read there; without miniShop2 the existing counts are unchanged.

### Deviations and conservative readings

- Product rows in the snapshot of every resource: when miniShop2 is usable, a plain resource's snapshot holds `ms2_data: []`, `ms2_options: []`, `ms2_categories: []` (brief: "empty in the snapshot"), so the revision of every resource on such a site changes once, at the first MCP write after this lands (it shows as one "changed outside the journal" entry per resource). With miniShop2 off or absent the snapshot is as before.
- `writeWarnings()` for msProduct is silent only while miniShop2 is usable; otherwise the old warning stays (the data is not journaled then). That keeps the existing test.
- `ms2_option.key` is immutable (`validation_failed` on change): the update processor renames the key in every product's option rows (unjournaled changes to all products).
- On create, `category` defaults to 0 and `type` to `textfield` (no database default for `category`: an insert without it fails in strict mode; an empty type breaks the product form). `type` is validated against the nine shipped types; a site-specific option type cannot be set through the API.
- `ms2_options` does not show or accept the keys `tags`, `color`, `size`: `msProductData::save()` deletes and rebuilds those option rows from the JSON columns on every product save. Writing them is refused with a pointer to `ms2_data`. They are part of the snapshot rows.
- Option values are cleaned as miniShop2 does (`prepareOptionValues`: trim, drop duplicates and empty strings); numbers are accepted and stored as text. A key listed as `[]` is removed.
- `ms2_categories` refuses the parent category (miniShop2 would silently drop it).
- `remove_from_category` uses `mgr/category/option/delete` (no `mgr/settings/option/*` processor removes a link). It renumbers the later links with a plain UPDATE, so those options are touched and journaled, as are the products whose values go (cap 1000 products per operation, else `validation_failed`).
- Undo of a product state recorded with ms2 rows is refused (`not_reversible`) while miniShop2 is switched off; undo of an option needs miniShop2 installed.
- Extra edits outside the brief's file list are the generic hooks above (the brief allowed "only for a hook the conditional relations need"; the restore hook and the json type were needed beyond that).

### Where the source differed from the brief

- `msProduct::toArray()` merges the data row, option values and vendor into the result, so the existing snapshot of a product (`Snapshot::row`) already contained price etc. in `fields`, and `fromArray` on restore would write them back through `msProduct::set`. Fixed with `objectColumns()` (resource columns only).
- `msProductData::save()` re-saves on every product save: the JSON columns are copied into `ms2_product_options` rows (existing rows with those keys are deleted first, so an option row `color` without matching JSON is lost on any product save, as in the manager), and a new row gets the default `source` whatever it was given. `restoreRelation('ms2_data')` therefore puts the recorded `source` back after saving.
- The product update processor deletes option values not assigned to the product's categories when `options` is sent (reason for writing rows directly).
- `msCategoryOption::save()` gives default values to the category's own products that have none; `::remove()` deletes the option's values of those products. Deleting an option removes its links (so those product values), but xPDO cannot remove `msProductOption` rows (no primary key), so values of products outside the linked categories remain. Restoring a link would re-add default values; `Ms2OptionKind::restoreRelation` takes away the ones that save added, so an undo is exact.
- `msOption` validation: a key must match `^(?!\W+)(?!\d)[a-zA-Z0-9\x2d-\x2f\x7f-\xff-_]+(?!\s)$` without `/u`, so a key may contain Cyrillic but not start with a non-ASCII byte; it must not be one of the reserved resource/product field names.
- Deleting a product through `resource/delete` only trashes it; its data stays. The restore path for data (e.g. after the trash was emptied) is tested through a real undo with `force`.

### Verified only against the fake MODX (check on the stand)

- Everything about the real classes: `msProduct` loading after `addPackage` without the service; `msProduct::save()` creating/keeping the data row; `msProductData::save()` mirror/`prepareObject` effects and a restored row's `source`; `msProductOption` (no PK) via `getCollection`, `newObject()->save()` and `removeCollection`; `msCategoryMember` / `msCategoryOption` composite keys and `save()` / `remove()` side effects; the five miniShop2 processors found through `processors_path` and their property names; `getInstance()` choosing `msProductUpdateProcessor`; `objectColumns()` on a real `msProduct` (`_fields`, `getResourceFieldsNames()`); `json` option `properties` round trip through `xPDO::set`; product `class_key` change creating/removing the data row; the cache after relation writes.
- Test the cases that cost data if wrong: a product with a non-default `source`, with options not assigned to any category, with `tags` set, with a category membership: plain `pagetitle` update, then `ms2_data`/`ms2_options` changes and their undo; assign/remove of an option on a category with products that have no value.

### Open questions

- Should an option-key rename be possible (with every product's rows journaled, over a product cap)? Left refused.
- Option types added by site plugins are refused; allow any type that exists in miniShop2's `types/` directory of the site?
- `modx_help` / `getting_started.md` do not mention the new relations or the kind yet (not in the brief's file list).
- Values of orphan option rows (keys that are not an `msOption`) are shown but cannot be written back (unknown key).
