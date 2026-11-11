# Getting started

You are working on a live MODX Revolution site. Every change you make is journaled and can
be undone, and the site is edited by people in the manager at the same time.

## Orient

1. `modx_site_info` — versions, object counts, contexts, installed add-ons.
2. `modx_capabilities` — the tools and kinds this token may use. Anything unavailable is
   listed with the reason: scope too low, group switched off, add-on not installed.
3. `modx_describe` — without arguments lists the object kinds; with `kind` returns its
   fields, relations and which of them can be written.

## Objects and kinds

Everything is addressed as `kind` + `id` (or `name`). The element kinds are `chunk`,
`snippet`, `template`, `plugin`, `tv` and `category`. The same tools work for every kind:

- `modx_list {kind, query?, where?, fields?, limit?, start?}` — paged list; `total` and
  `next_start` tell you whether there is more. Code is never listed.
- `modx_get {kind, id|name, fields?, full?}` — fields, relations and `revision`.
- `modx_view_code {kind, id|name, start_line?, end_line?}` — code as numbered lines.

Field names are the same across element kinds: the code of a chunk, snippet, template or
plugin is always `content`, and the name is always `name`. For a static element `content`
is the file. Snippet and plugin code is PHP **without** an opening `<?php` tag; MODX removes
the tag if you send one, which shifts line numbers, so read the code back before editing it
by lines.

## Changing things

- `modx_create {kind, fields, relations?}`
- `modx_update {kind, id|name, fields?, relations?, expected_revision?}` — only the given
  fields change.
- `modx_edit_code {kind, id|name, edits, expected_revision?}` — line edits; prefer this
  for a change inside long code. Give `expect` (the current text of the lines) so stale
  line numbers cannot corrupt the code. Examples, for code with 10 lines:
  replace lines 3–4: `{start_line: 3, end_line: 4, replacement: "…"}`;
  delete line 5: `{start_line: 5, replacement: ""}`;
  insert before line 2: `{start_line: 2, end_line: 1, replacement: "…"}`;
  append at the end: `{start_line: 11, end_line: 10, replacement: "…"}`.
- `modx_delete {kind, id|name, expected_revision?}`

Rules that keep a live site safe:

- **Pass `expected_revision`** from your last read. If someone changed the object since,
  the write is refused with `revision_conflict` and tells you when and by whom. Read the
  object again and apply your change to the current state; never overwrite blindly.
- **Use `dry_run: true`** before a delete or a risky change: it validates everything and
  reports what would change without changing anything.
- **PHP is checked before it is saved.** A snippet or plugin with a syntax error is
  refused with `lint_failed` and the line number; nothing is written.
- A write returns `changeset_id`, the new `revision`, the list of `changed` fields and
  `reversible`. Keep the revision for your next write to the same object.
- If a write times out, do not repeat it with new arguments. Check
  `modx_request_status` with the reported `request_id`, or retry the identical call with
  that ID as `_request_id`.

## Undo

- `modx_checkpoint {label}` before a larger task.
- `modx_history` — what was changed, newest first; filter by `this_session`, `kind`, `id`.
- `modx_history_diff {changeset_id}` — old and new values, and a line diff for code.
- `modx_undo` with one of `changeset_id`, `last`, `this_session`, `to_checkpoint`.
  Without `confirm: true` it returns the plan. A deleted object comes back with the same
  id and its related rows.

The plan names an action per object: `restore` (back to its earlier state), `recreate` (a
deleted object returns) or `remove` (an object that was created is removed). Selecting by
`last`, `this_session` or `to_checkpoint` skips changesets that are already undone and undos
themselves; to re-apply an undo, undo it by its `changeset_id`. "This session" means since
the MCP client was started. To clean up objects you created, undoing the session is simpler
than deleting them one by one.

If PHP code is refused with `lint_failed`, fix the code. `skip_lint: true` exists for
deliberately incomplete code and should not be used to get past an error on a live site.

If an object was changed after the changeset you are undoing, the plan marks it as a
conflict and the undo stops. Look at the later change before deciding; `force: true`
undoes over it and journals the overwritten state, so that is reversible too.
