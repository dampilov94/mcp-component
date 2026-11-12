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
