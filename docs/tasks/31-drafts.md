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
