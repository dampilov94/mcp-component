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
