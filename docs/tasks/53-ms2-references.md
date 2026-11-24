# 53 — miniShop2 vendors, deliveries and payments

Branch: `task/53-ms2-references` (create it from `task/52-check-draft`, stacked).
Read first: `src/Kind/AbstractMs2Kind.php`, `Ms2OptionKind.php`, `Ms2OrderKind.php`,
`Ms2OrderStatusKind.php`, how product data shows `vendor` and orders show `delivery`/`payment`,
and the miniShop2 3.0.7 source in `.local/reference/minishop2-3.0.7/` (maps of `msVendor`,
`msDelivery`, `msPayment`, `msDeliveryMember`; processors `mgr/settings/vendor/*`, `delivery/*`,
`payment/*`).

## Why

Products store the vendor and orders store the delivery and payment as ids; without `query_db` the
AI cannot tell what they are.

## Design (decided — do not change)

- Kind `ms2_vendor` (group `minishop2`, read scope read, write scope content): fields as the map
  (`name` required, `resource`, `country`, `logo`, `address`, `phone`, `fax`, `email`,
  `description`, `properties`), list/get/create/update/delete through the miniShop2 processors,
  journaled and undoable; a delete is refused while products use the vendor (name how many), the
  same way other ms2 guards report it.
- Kinds `ms2_delivery` and `ms2_payment`: read-only (list/get), all map fields; `ms2_delivery` has a
  relation `payments` (ids, from `msDeliveryMember`). Writes are refused with a clear message
  ("change deliveries and payments in the miniShop2 settings").
- Product data and order results add `vendor_name`, `delivery_name`, `payment_name` next to the
  ids (read side only; null when the referenced row is gone).
- `modx_describe`, help topic `minishop2` updated.

## Tests (required)

On the fake with miniShop2 installed: each kind's list/get; vendor CRUD + undo + the in-use guard;
read-only refusal for the two other kinds; the names in product and order results (and null for a
dangling id); group off / not installed hides the kinds. Update exact kind lists/counts.

## Acceptance

`npm run check`, `npm test`. Report as usual, with what needs the stand (real processors).

## Report

Based on: task/52-check-draft at 3926692

Checks: `npm run check` passes; `npm test` 6035 PHP checks (5956 before this brief; 5908 on `main`) and 43 Node tests (43 before).

### Implemented

- `src/Kind/Ms2VendorKind.php` (new, `ms2_vendor`): group `minishop2`, read scope `read`, write scope `content`; fields exactly as the `msVendor` map (`name` required; `resource`, `country`, `logo`, `address`, `phone`, `fax`, `email`, `description`, `properties` as a JSON object); generic list/get/create/update/delete/duplicate through `mgr/settings/vendor/*` (the processors read from `.local/reference/minishop2-3.0.7`: `name` required and unique, remove has no checks of its own). `assertObjectWritable()` refuses a delete with `validation_failed` and "used by N product(s)" while `msProductData.vendor` points at it (the same error code and style as the context guard), also in a dry run.
- `src/Kind/Ms2MethodKind.php` (new abstract base), `Ms2DeliveryKind.php`, `Ms2PaymentKind.php` (new): read-only (`genericWrites() === false`, message "Change deliveries and payments in the miniShop2 settings of the manager; here they are read only."), all map fields (delivery: `price`, `weight_price`, `distance_price`, `requires`, `free_delivery_amount`, ...), sorted by rank. `ms2_delivery` has the read-only relation `payments` (ids from `msDeliveryMember`, `in_state` false).
- `src/Kind/KindRegistry.php`: the three kinds are registered after `ms2_order_status`.
- `src/Kind/Ms2OrderKind.php`: derived `delivery_name` and `payment_name` (listed), `null` when the row is gone.
- `src/Kind/ResourceKind.php`: `ms2_data` read result gets `vendor_name` right after `vendor` (`null` for vendor 0 or a gone vendor). It is added in `presentRelation()` only; `storedRelation()` (snapshots, revisions, duplicate) is untouched, so a product's revision does not change when a vendor is renamed (tested). Writing `vendor_name` gives a clear read-only error pointing to `vendor`. The `ms2_data` description mentions it.
- `docs/getting_started.md`: new subsection "miniShop2 vendors, deliveries and payments".
- Tests: `tests/php/run.php` (block before the MIGX section), `tests/php/FakeModx.php` (fake processors `mgr/settings/vendor/create|update|remove`; a created vendor row has every map column, as a real row), `tests/endpoint.test.js`.

### Deviations

- `properties` of deliveries and payments: the brief says "all map fields", but those hold courier and payment-system keys (payment passwords, API tokens). A token below the admin scope sees `"[hidden]"` plus `secret: true` instead of a non-empty `properties`; admin sees them; the field is an object, so `where` cannot probe it (tested). Everything else is shown to a read token as the brief says.
- There is no help topic `minishop2` (no such file in `docs/`); I did not create one and added a subsection to `getting_started.md` instead.
- Registry order: the new kinds follow `ms2_order_status`.

### Existing assertions changed (all because this brief adds kinds or result fields)

- `tests/php/run.php`: "ms2: kind list with miniShop2 installed" (three more kinds); "ms2: get shows the three relations of a product" (`vendor_name` in the `ms2_data` key list); "ms2_order: describe" (two more fields, read-only count 15 to 17); "ms2_order: list, newest first, with the listed fields" (two more listed keys). Fixture addition: the three vendor processor files in the fake miniShop2 core.
- `tests/endpoint.test.js`: miniShop2 kind counts 29 to 32 (admin) and 23 to 26 (read) and the tail lists.

### Verified only against the fake

The real vendor processors (create/update/remove with the real permission `mssetting_save`, unique-name error text), undo of a vendor through `Snapshot::restore` on a real `msVendor` row (JSON `properties` column), `msDeliveryMember` rows and JSON handling of `properties` of real deliveries/payments, and the names in real product and order results. The stand should check: create/update/delete/undo of a vendor, a delete refused for a vendor in use, `ms2_delivery` payments relation, and that a read token never sees a payment property.

### Open questions

- Should `delivery`/`payment` `properties` stay admin-only, or is a finer redaction (by key names, as `Properties::redactCredentials` does) wanted so a read token sees non-secret settings?
- A client that reads a product, edits the `ms2_data` map and sends it back must drop `vendor_name`; the error says so.
