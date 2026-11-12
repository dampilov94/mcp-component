# 22 — miniShop2: link types, product links, orders

Branch: `task/22-minishop2-links-orders`. Builds on task 21 (`src/Integration/MiniShop2.php`,
group `minishop2`). Source: `.local/reference/minishop2-3.0.7/core/` (`model/minishop2/mslink`,
`msproductlink`, `msorder*`, `msorderstatus`, `msdelivery`, `mspayment` classes and maps;
`processors/mgr/settings/link/`, `processors/mgr/product/productlink/`,
`processors/mgr/orders/`; `minishop2.class.php` `changeOrderStatus()`); 1.x reference
`core/components/modxmcp/model/traits/minishop2.trait.php` (`ms2LinkAction`).

## Design (decided — do not change)

### Kind `ms2_link` (link types)

Class `msLink`, integer key, name field `name`, group `minishop2`, read `read`, write
`develop`. Fields: `id`, `name` (required, list), `type` (required, list; one of
`many_to_many`, `one_to_many`, `many_to_one`, `one_to_one` — take the list from the source),
`description`. Deleting a link type removes its product links in miniShop2 (check): journal
them through `deleteCascade` as deleted `ms2_product_link` objects.

### Kind `ms2_product_link`

Class `msProductLink` (columns `link`, `master`, `slave`; no id). String key
`"<link>:<master>:<slave>"` (task 15's key layer; follow `ContextSettingKind` for a composite
key). Fields: `link`, `master`, `slave` (all required, list, immutable) plus a computed
`label` (`"<link name>: <master pagetitle> → <slave pagetitle>"`). Create and delete only
(update has nothing to change: `validation_failed`). Use the productlink processors if they
run headless and handle the link type semantics (e.g. `many_to_many` creating the reverse
row — read `create.class.php`); otherwise reproduce exactly what they do and say so. Rows a
processor creates or removes besides the requested one must be journaled too.
`modx_list` accepts `where` on `link`, `master`, `slave`. `master`/`slave` must be `msProduct`
resources.

### Kind `ms2_order` (read, plus a status operation)

Class `msOrder`, integer key, name field `num`, group `minishop2`, **read scope `admin`**
(orders hold customers' names, addresses, phones, e-mails), write scope `admin`.
Fields (read-only all): `id`, `num` (list), `user_id` (list), `createdon` (datetime, list),
`updatedon` (datetime), `cost` (list), `cart_cost`, `delivery_cost`, `weight`, `status`
(int, list), `delivery` (int), `payment` (int), `context`, `comment`. Computed
`status_name` (list). Relations, read-only: `products` (`msOrderProduct`, fk `order_id`,
present `['product_id', 'name', 'count', 'price', 'cost', 'options']`), `address`
(`msOrderAddress`, one row, present its contact columns), `log` (`msOrderLog`, present
`['timestamp', 'action', 'entry', 'user_id']`).

`create`, `update`, `delete`, `duplicate` are refused for this kind (`genericWrites()` false,
task 19). The only write is `modx_invoke {kind: "ms2_order", id, op: "change_status",
args: {status, confirm?}}`:

- `status` must be an existing `msOrderStatus` id; the plan (without `confirm`, or for a
  plan-only token) shows the current and new status names and whether the new status sends
  e-mails to the customer / manager (`email_user`, `email_manager` of the status — read the
  map).
- With `confirm`: change it the way miniShop2 does (`changeOrderStatus()` of the service, or
  the orders update processor — whichever the source shows is the supported path; it may
  need the miniShop2 service instantiated: then do it here and say so). It sends the e-mails
  and fires miniShop2's events. Record a change-less changeset (task 19) with summary
  `order <num>: <old> → <new>`, `reversible` false — an undo would not unsend e-mails, so
  this operation is never undone. The result includes `emails_sent` as far as known.

Also add kind `ms2_order_status` (class `msOrderStatus`), read-only for everyone with read
scope `read`: fields `id`, `name` (list), `color`, `email_user` (bool), `email_manager`
(bool), `active` (bool), `final` (bool), `fixed` (bool), `rank`.

## Out of scope

Creating orders, editing order products/addresses, deliveries and payments as kinds,
vendors, the gallery.

## Tests (required)

Fake rows and processors per the source (including the reverse-row behaviour of link
types, cascades, the status change path and its e-mail flags — record "sent" e-mails in a
list on the fake). Cover: link type CRUD + delete cascade + undo; product link create/delete
for each link type semantics, journaled with every row the processor touched, undo; key
parsing; non-product refused; orders list/get with relations (Cyrillic customer data);
order reads refused below admin; generic writes refused; change_status plan, plan-only,
confirm (e-mails recorded as the status flags say), changeset recorded and never undone;
unknown status refused; order statuses readable by a read token; miniShop2 unavailable →
all these kinds unavailable.

Update kind lists/counts in both test files.

## Acceptance

`npm run check`, `npm test`. Files: new kinds `Ms2LinkKind`, `Ms2ProductLinkKind`,
`Ms2OrderKind`, `Ms2OrderStatusKind`, `src/Kind/KindRegistry.php`,
`src/Integration/MiniShop2.php` if the service is needed, tests, `tests/php/FakeModx.php`.
