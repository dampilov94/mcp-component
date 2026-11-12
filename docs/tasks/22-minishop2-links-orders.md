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

## Report

Based on: main at 9c53633

Counts: before, `npm run check` OK (150 PHP files, 20 JS files), PHP 3903 checks, Node 28 tests. After: check OK (155 PHP files), PHP 4234 checks, Node 28 tests (all pass). Existing assertions changed, because the brief adds four kinds: `ms2: kind list with miniShop2 installed` in `run.php` (the list now ends `ms2_option, ms2_link, ms2_product_link, ms2_order, ms2_order_status`); in `endpoint.test.js` the miniShop2 test (admin kinds 23 to 27, read kinds 18 to 21, the tail of both lists, plus three new read/list assertions on `ms2_order`). The setup lists of processor files in `run.php` and `endpoint.php` got the five new processor paths (setup only). Nothing weakened or deleted.

### Implemented, file by file

- `src/Kind/AbstractMs2Kind.php` (new): what the miniShop2 kinds share (group, `prepare()` registers the package and tolerates a missing add-on, `assertUndoable()` needs the models, `processors_path`). `Ms2OptionKind` was left as it is.
- `src/Kind/Ms2LinkKind.php` (new): `ms2_link` (develop/read), processors `mgr/settings/link/*`; `type` required and immutable; `assertColumns` checks the four types; `deleteCascade()` returns every `msProductLink` row of the type as a deleted `ms2_product_link` (more than 1000: refused).
- `src/Kind/Ms2ProductLinkKind.php` (new): `ms2_product_link`, string key `<link>:<master>:<slave>` (three positive ids, normalised, otherwise `validation_failed`), computed `label`, create and delete only (`assertObjectWritable` refuses update and duplicate), create/delete through the miniShop2 productlink processors. The rows a processor adds or removes besides the requested one are computed beforehand and touched (`alsoAffected()` for create, `deleteCascade()` for delete), so each is locked and journaled; warnings list them.
- `src/Kind/Ms2OrderKind.php` (new): `ms2_order`, read and write scope admin, `genericWrites()` false, fields as in the brief (decimals as the new field type `number`), relations `products`, `address`, `log` (`in_state` false, own `presentRelation`), operation `change_status` (plan, then confirm through the miniShop2 service).
- `src/Kind/Ms2OrderStatusKind.php` (new): `ms2_order_status`, read `read`, generic writes refused.
- `src/Kind/KindRegistry.php`: the four kinds after `Ms2OptionKind`.
- `src/Integration/MiniShop2.php`: `LINK_TYPES`, `ROW_LIMIT` (1000), `service()` (registers the package, then `getService('miniShop2', 'miniShop2', <model path>)`).
- `src/Action/InvokeAction.php`: two new keys in an operation's declaration. `plan`: the name of a method that answers without changing anything; the action returns it (`dry_run: true`, `planned: true` for a plan-only token) unless the call is confirmed (`args.confirm`) and is not a dry run or plan-only. `informational`: the object is only locked, not touched; the handler's `summary` goes into a change-less changeset (task 19), the result carries `changeset_id` and `reversible: false`.
- `src/Kind/Kind.php`: field type `number` (`(float)`, shown by describe).
- Tests: `FakeModx.php` (primary key and cascade of `msProductLink`/`msLink`; processors `mgr/settings/link/*` and `mgr/product/productlink/*` written from the source, including the closure of many_to_many, the reverse rows, the removal of a slave's every row; `getService()` and a `FakeMs2Service` with `changeOrderStatus()` as in 3.0.7: rules, order log, both events, e-mails recorded in `$modx->sentMails`; options `fake_ms2_before_refuse`, `fake_ms2_after_failure`, `fake_ms2_throw_before_save`, `fake_ms2_throw_after_save`), `run.php` (about 330 checks), `endpoint.php`, `endpoint.test.js`.

### Source versus brief

- `msOrderAddress` has no `order_id`. The address is the one row whose `id` is the order's `address` column (composite `Address`, local `address`). The `address` relation reads it that way; `fk` is not used.
- The order's comment column is `order_comment` (field `comment` has `column` set). `session_id`, `properties` (may hold payment tokens) and the log's `ip` are never shown; `type` is not exposed.
- `msLink.type` has no list in the map; the four types come from the cases of the productlink processors and the lexicon. The link update processor unsets `type`, so it is `immutable` (not in the brief).
- The product link create processor does not store the requested row for `many_to_one`: it stores (slave, master). The kind returns the stored key and warns; the requested key is `not_found`.
- The remove processor, for `many_to_many`, ignores the master and deletes every row where the slave is master or slave, i.e. takes the slave out of the whole group (a delete of one link can remove many rows). For `one_to_one` it removes both directions. All of them are journaled; the dry run warns.
- A create that already exists (all rows there) is not an error in the processor (`ON DUPLICATE KEY UPDATE`): it changes nothing and records no changeset. Kept.
- `changeOrderStatus()` calls `switchContext()` to the order's context. The kind switches back to the context the request was in. The service's rules are replicated in the plan (active status, old status final, fixed with rank not higher, same status; the source keeps only the last refusal so "same status" is reported first). A site with a custom order handler that overrides `changeOrderStatus` may have other rules; the plan cannot know.
- `emails_sent` is derived, not observed: miniShop2 sends inside the service and only logs failures. It follows the source's conditions (flag, non-empty subject, a manager address with `@` in `ms2_email_manager` or `emailsender`, a customer profile with an `@` address). If the service returns an error after the status was saved or throws, `emails_sent` is false/false and a warning says mails may be missing; the change is still recorded. If it throws before the status changed, the exception propagates and nothing is recorded.

### Other readings of the brief

- `change_status` with a plan-only token, `dry_run`, or no `confirm` returns the plan; every refusal (unknown/inactive status, final, fixed, same status) is thrown by the plan too.
- A create dry run of a product link only validates fields (the generic create action does not run the kind's `beforeCreate`); the site checks (link type exists, master and slave are products, not equal) happen in the real call, before anything is touched. The delete dry run does show the extra rows (warning).
- Undo of a product link refuses (`not_reversible`) when a product is no longer an `msProduct`; it does not require the link type to exist (it may be restored in the same undo).
- `modx_history` without a kind filter shows the summary `order <num>: <old> → <new>` of status changes to any token with read scope (an order number and status names, no personal data). The `ms2_order` kind itself has no journal rows.
- Not done: `docs/getting_started.md` and the other built-in help do not mention the new kinds (not in the brief's file list).

### Verified only against the fake MODX (check on the stand)

- Everything about the real classes and processors: the five new processors found through `processors_path` and their property names (`link`, `master`, `slave`; `name`, `type`, `description`); the productlink processors' raw SQL (`ON DUPLICATE KEY UPDATE`) running under the API user with `msproduct_save`/`mssetting_save`, and the exact rows they write (many_to_many closure, `many_to_one` reversal, the slave-wide removal), compared with the touched/journaled set; xPDO `getObject`/`remove`/`fromArray`+`save` on `msProductLink` with the composite key (`link`, `master`, `slave`) and on `msLink` with its composite removal of product links.
- `getService('miniShop2', 'miniShop2', <model>/)` as an API request: that its constructor (`pdoFetch`) works there, that `changeOrderStatus()` with `switchContext` and `initialize($ctx)` runs headless, writes the order log with the right user (`$modx->user` of the API), sends real e-mails (use a test order with an address you own) and that the context switch back is harmless; that `msOrder.createdon`/`updatedon` come back as date strings through `get()`, that `msOrderProduct.options` is decoded JSON, and the list of `msOrderAddress` columns.
- A product-link `modx_list` on MySQL (sort by `link`, `master`, `slave`; `query` is a LIKE on the two id columns) and an order list sorted by `id` DESC.

### Open questions

- Should `query` on `ms2_product_link` exist? It matches `master`/`slave` ids as text; harmless but odd.
- Should the create dry run of a product link run the site checks (needs a generic change to `CreateAction`)?
- Should `modx_history` hide the summaries of order status changes from tokens below admin?
- `ROW_LIMIT` 1000 for cascades and many_to_many closures is a guess.

## Review

Accepted and merged. Verified on the stand with `scripts/stand/check-minishop2-links.cjs`:
order statuses read (5, Cyrillic names), no orders on the stand; product links of all four
link types created between test products, and for each type the journal holds exactly the
rows MODX stored (`many_to_many` and `one_to_one` write both directions, `many_to_one`
stores the reverse key), delete and undo restore them; deleting a link type takes its
product links along and the undo brings them back. All earlier stand checks pass.

Not verified on the stand: the order status change with real e-mails (no orders there, and
it would send e-mails); `getService('miniShop2')` headless is therefore still only checked
against the fake.

Change by the architect: `modx_list` items of a string-key kind did not include the key
(only the kind's list fields), so a listed product link could not be addressed — found by
the stand check. Items of string-key kinds now always start with `key`; two assertions that
fixed the exact item keys were updated.

Open questions answered: `query` on product links stays; the create dry run stays
field-only; order-status summaries in history are fine (number and status names only);
the row limit of 1000 is kept.
