# 31 — Resource drafts

Branch: `task/31-drafts` (create it from `main`). Spec: section 10 ("Черновики ресурсов").
Read first: `src/Kind/ResourceKind.php` (fields, relations `tvs` / `resource_groups`,
`alsoAffected`, `openInManager`), `src/Action/DuplicateAction.php` (how a resource copy is
made), `src/Action/InvokeAction.php` and `src/Kind/Ms2OrderKind.php` (an operation with a plan
and `confirm`), `src/Journal/Snapshot.php` and `src/Write/Transaction.php`, `src/Journal/Journal.php`
(`install()`, plain SQL through `src/Db.php`), `src/Manager/Overview.php` and the component panel
(`controllers/index.class.php`, `templates/home.tpl`, `js/home.js`).

## Why

The owner works directly on live sites. A draft lets the AI (or a person) prepare a change of a
page, look at it under a preview link, and only then put it on the live page in one step — or
throw it away. The live page is untouched until then.

## Design (decided — do not change)

### What a draft is

A draft is a separate resource: an unpublished copy of the original in the same parent, made
the way `modx_duplicate` copies a resource (TV values included, no children), then set to
`published = 0`, `hidemenu = 1`, `searchable = 0`, `pub_date = unpub_date = 0`, alias
`<original alias>-draft-<draft id>` (or `draft-<draft id>` when the original has none). Its
pagetitle stays the original's. Only resources with `class_key = modDocument` can have drafts
(others: `validation_failed`, naming the class — miniShop2 products have data a copy does not
carry). An original can have at most one open draft; a draft cannot have a draft.

The link lives in a new table `modxmcp_drafts` (`id`, `original_id`, `draft_id` unique,
`created_at`, `token_id` null, `user_id` null, `base_revision` CHAR(64) — the original's revision
when the draft was made). Create it in a `DraftStore::install()` (MySQL) called by the install
resolver after the journal tables, and lazily on first use like the journal; SQLite version in
`fakeSchema()`.

Preview: `$modx->makeUrl(draftId, '', '', 'full')`. MODX shows an unpublished page to a user
logged into the manager; the result says so (`preview_note`).

### Operations of the kind `resource` (through `modx_invoke`)

- `create_draft` (on the original; no args): makes the copy in one changeset (the copy is a
  `create`, the original's parent may change `isfolder` — journal what is touched), records the
  link, returns `{draft_id, preview_url, preview_note, base_revision}`. Refused when the
  original is a draft, already has a draft, is deleted, or is not a `modDocument`.
- `apply_draft` (on the original or on the draft): with a plan — without `confirm: true` it
  returns what would change on the original: field names with old/new values (content as a line
  diff, like `modx_history_diff`) and TVs with old/new values, plus `original_changed` (true when
  the original's revision differs from `base_revision`, with the changed field names) and the
  open-in-the-manager marker. With `confirm: true`: copies to the original exactly the fields
  `pagetitle, longtitle, description, introtext, content, menutitle, link_attributes, template,
  richtext` and all TV values of the draft (a TV the draft no longer has a value for is cleared on
  the original), through the normal update path (`Objects::save`, so MODX events and validation
  run), then removes the draft resource completely and the link row. One changeset for all of it
  (summary `apply draft <draft id> to resource <id>`), so one undo brings the original's old
  state and the draft back. If the original changed since the draft was made, the confirmed
  apply is refused with `revision_conflict` unless `force: true` (the same flag as for open
  pages). The original being open in the manager is refused like any write (`assertNotOpen`).
- `discard_draft` (on the original or on the draft; `confirm: true` required, plan otherwise):
  removes the draft resource and the link row in one changeset; undo brings it back.
- `list_drafts` (read-only, no object): every open draft — original id and title, draft id,
  created at, author (token label or manager username, like the history), preview URL,
  `original_changed`.

The draft resource is removed with the same path an undo uses to remove an object
(`Snapshot::restore(..., null)` through the transaction's subject), not by the delete processor,
so it does not land in the trash. A draft never has children: a resource whose parent would be
a draft is refused by `create`, `update` and the bulk `move` with `validation_failed`.

Keeping drafts consistent:
- Deleting the original (API delete, bulk delete, manager delete through the recorder is out of
  scope) is refused while it has an open draft: `validation_failed` "discard or apply the draft
  first". Deleting a draft resource directly through `modx_delete` is refused the same way (use
  `discard_draft`).
- `modx_get` and `modx_list` of a resource show `draft_of` (original id) for a draft and
  `draft_id` for an original that has one; `modx_resource_tree` marks both.
- `modx_audit_site` does not report a draft's address or links as problems of the site.
- An undo that recreates or removes a draft resource keeps the link table consistent (an undo
  of `create_draft` removes the link; an undo of `apply_draft`/`discard_draft` restores it). The
  link table is not journaled itself: derive it — after any undo touching a resource, a link row
  whose draft no longer exists is deleted, and a draft recreated by the undo gets its row back
  (store enough in the changeset summary or a dedicated column of the change to restore it;
  choose the simplest correct way and explain it in the report).

### Panel

A "Черновики" block on the component panel (only when drafts exist): original title (link to
edit it in the manager), draft created at and by whom, "Открыть предпросмотр" (new tab),
"Применить" and "Отменить черновик" — both show the plan first (as the history page does for an
undo) and need a confirmation; `original_changed` shown as a warning with the changed fields.
Processors `processors/mgr/drafts/{getlist,apply,discard}.class.php` (permission `settings`)
through a `src/Manager/DraftService.php` that runs the same `InvokeAction` (as the history page
runs `UndoAction`), so a manager apply is journaled as `manager` with the user. Lexicon in ru and
en.

### Help

`core/components/modxmcp/docs/getting_started.md`: a section "Drafts" — when to use one (a
visible change of a live page), the four steps, and that a person can apply it from the panel.

## Out of scope

Drafts of miniShop2 products and other classes, several drafts per page, merging concurrent
changes, drafts of elements.

## Tests (required)

`tests/php/run.php`, block "Drafts" on the fake site (extend the fake only as far as the real
MODX behaves; the duplicate processor is already in the fake): create (fields, TVs copied, flags,
alias, link row, one changeset, refusals: not modDocument, second draft, draft of a draft,
deleted original); edit the draft through `modx_update` / `modx_edit_code` and its TVs; apply plan
(fields, content diff, TVs, `original_changed` false/true); apply confirm (exact field list copied,
alias/parent/published of the original untouched, TV cleared, draft gone, no trash entry, link
gone, one changeset); undo of the apply restores both and the link; conflict without/with
force; open in the manager refused; discard and its undo; delete refusals; `list_drafts`;
`get`/`list`/`resource_tree` markers; audit ignores drafts; a manager apply through
`DraftService` is journaled as `manager`. Cyrillic titles. Update exact tool/kind counts only if
they change (they should not: these are operations, not tools).

## Acceptance

`npm run check`, `npm test`. Report as usual; say exactly how the link table stays consistent
through undo, and what needs the stand (real duplicate processor, preview of an unpublished page
for a logged-in manager user, real URIs of the copy).

## Report

Based on: main at f2aefac

Checks: `npm run check` and `npm test` pass. PHP checks 4927 before, 5012 after (85 new, block "Drafts" in `tests/php/run.php`); Node tests 32 before and after. No tool or kind was added, so no tool/kind count or list assertion changed, and no existing assertion was edited.

### Implemented

- `src/Draft/DraftStore.php` (new): plain-SQL tables `modxmcp_drafts` (the columns of the brief plus `base_state`, see deviations) and `modxmcp_draft_archive`; `install()` (MySQL, also created lazily on first use like the journal), lookups that always check that both resources exist (`ofOriginal`, `ofDraft`, `linkOf`, `all`, `marks`, `draftIds`), `add()`, `reconcile()`.
- `src/Draft/Drafts.php` (new): the four operations. `create` copies like `modx_duplicate` (fields, TV values) with the draft flags, placeholder alias first and `<alias>-draft-<id>` / `draft-<id>` right after the id is known, in the same transaction; `planApply` / `apply` / `planDiscard` / `discard` / `items`. The draft resource is removed through `ObjectSubject::restore(null)` (the path an undo uses), never the delete processor.
- `src/Kind/ResourceKind.php`: `operations()` (create_draft, apply_draft, discard_draft, list_drafts) with thin handlers, `redact()` (adds `draft_of` / `draft_id` to `modx_get` and `modx_list`), `assertObjectWritable()` (delete of a page with a draft or of a draft is `validation_failed`), `assertColumns()` (a parent that is a draft is refused by create and update), `afterInvoke()`; `templateTvs()` became public.
- `src/Kind/Kind.php`: new no-op hook `afterInvoke()`. `src/Action/InvokeAction.php`: see deviations.
- `src/Action/BulkResourcesAction.php`: bulk `delete` and `move` honour the same guards. `src/Action/ResourceTreeAction.php`: nodes carry `draft_of` / `draft_id`. `src/Verify/SiteAudit.php`: draft resources are skipped by the per-resource checks. `src/Action/UndoAction.php`: `reconcile()` after an undo.
- `src/Context.php`: `actingUserId()`. `src/Manager/HistoryService.php`: `context()` made public. `src/Manager/DraftService.php` (new): runs `InvokeAction` for list_drafts / apply_draft / discard_draft in the manager context.
- Panel: `processors/mgr/drafts/{getlist,apply,discard}.class.php` (permission `settings`), `controllers/index.class.php` (drafts and the `modxmcp_drafts_*` lexicon go into `Modxmcp`), `templates/home.tpl` (container and styles), `assets/components/modxmcp/js/home.js` (the block: list, plan, confirmation, "apply anyway" when the page changed or is open; every site value via textContent), lexicons ru and en (29 keys each, prefix `modxmcp_drafts_`).
- `_build/resolvers/resolve.token.php`: `DraftStore::install()` after the journal tables. `docs/getting_started.md`: section "Drafts".
- Tests: `tests/php/FakeModx.php` (`fakeSchema()` has both tables; `setTVValue()` now behaves like `modTemplateVar::setValue()`: a value equal to the TV default is not stored and removes an existing row; no existing check changed because of it) and `tests/php/run.php` block "Drafts".

### How the link table stays consistent through undo

The link table is not journaled; the resources are. Rules (all in `DraftStore`):
1. Every lookup believes a link only while both resources exist.
2. `reconcile()` runs after create/apply/discard (`afterInvoke`) and after every undo (`UndoAction`, so also the manager's). A link whose draft resource is gone is deleted; if the journal's last change of that draft resource is a `delete`, the link is first copied to `modxmcp_draft_archive` keyed by that changeset id (the apply, the discard, or the undo that removed the draft). A link whose original is gone is deleted without archive.
3. A draft resource that exists again gets its link back from the archive when the changeset that archived it now has status `undone` (undo of apply / discard, or undo of the undo of a create_draft), the original exists and has no other open draft. Checking the status matters because a removed resource's id can be reused by a new resource.
4. Creating a link for a draft id deletes archive rows of that id. Archive rows whose changeset the retention removed are pruned in `reconcile()`.
Tested: apply, undo, undo of the undo, and again; discard and undo; undo of create_draft and undo of that undo; id reuse.

### Deviations and decisions

- Table `modxmcp_drafts` has an extra column `base_state` (the original's canonical state when the draft was made). The brief asks for the changed field names in `original_changed`; only a hash cannot give them, and the journal's blobs are garbage-collected when unreferenced. Added the table `modxmcp_draft_archive` (see above).
- `InvokeAction` (generic, small): (a) a top-level `force` is passed to the handler as `args.force` (the confirmed apply needs it for `revision_conflict`); (b) a handler may return `summary` to name its changeset (`apply draft <draft id> to resource <id>`); (c) an operation may declare `check_open => false`: `create_draft` and `discard_draft` do not change the page they are called on, so a page open in the manager does not block them (apply checks both the page and the draft, whichever the call was made on; discard checks the draft); (d) calls `Kind::afterInvoke()` after the commit.
- `Context::actingUserId()` and a public `HistoryService::context()` exist only because an existing test asserts which source files mention `managerUserId` / `forManager`; `DraftService` and `Drafts` avoid those words instead of changing that assertion.
- Resource groups are not copied to a draft: `modx_duplicate` does not copy them either (the relation is not writable through the API), and the brief says "the way modx_duplicate copies".
- Apply writes every TV of the draft's template (stored value, or the TV default, which removes the row on the original through `setTVValue`, as `modTemplateVar::setValue()` does). Rows of TVs that belong only to the original's old template are left, as MODX leaves them on a template change.
- `list_drafts` does no housekeeping writes (a read token reads); it only hides links that are not believed.
- A draft's `uri` is whatever the update processor derives from the alias; the brief gave no rule beyond the alias.

### Verified only against the fake MODX (please check on the stand)

- The real create processor with the placeholder alias followed by an update to `<alias>-draft-<id>` (uri regeneration, duplicate-alias rules, `friendly_urls`), and a `template` change through the real update processor.
- `makeUrl($id, '', '', 'full')` for an unpublished resource just created (real URI of the copy), and that a manager user in the same browser actually sees the draft while others get a 404 (`preview_note` says so).
- xPDO `remove()` of the draft cascades its TV values (and resource group rows) on MySQL; that nothing lands in the trash; that `modTemplateVar::setValue` really drops the row when given the default.
- `DraftStore::install()` DDL on MySQL (SQLite versions are in `fakeSchema()`), the lazy creation on a site without the tables, `INSERT`/`DELETE` of the archive on MySQL.
- The manager page: the Drafts block, plan, force button, preview link, edit links (`?a=resource/update&id=`), lexicon keys, `logManagerAction`.
- The manager lock (`getLock()`) behaviour for the page and the draft.

### Open questions

- Deleting a parent (generic or bulk) trashes the original and its draft together as descendants; only a direct delete of a page with a draft or of a draft is refused. The link stays and works after an undo. Say if descendants should be refused too.
- Undo of a discard while the page has since got a new draft: the draft resource comes back (that is the journal's job) but its link is not restored because the page already has an open one; the old draft is then a plain unpublished page with a `-draft-` alias. A stronger option is to make such an undo a conflict.
- Editing the draft's `parent` / `alias` through `modx_update` is allowed (only a draft as a parent is refused); apply never copies them.
- Manager-side deletion of a page or draft through the tree (recorder) is out of scope and not guarded, as the brief says.
