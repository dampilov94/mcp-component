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

Field names are the same across kinds: the code of a chunk, snippet, template or plugin is
always `content`, and the name is always `name`. For a static element `content` is the file.

## Changing things

- `modx_create {kind, fields, relations?}`
- `modx_update {kind, id|name, fields?, relations?, expected_revision?}` — only the given
  fields change.
- `modx_edit_code {kind, id|name, edits, expected_revision?}` — line edits; prefer this
  for a change inside long code. Give `expect` (the current text of the lines) so stale
  line numbers cannot corrupt the code.
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

If an object was changed after the changeset you are undoing, the plan marks it as a
conflict and the undo stops. Look at the later change before deciding; `force: true`
undoes over it and journals the overwritten state, so that is reversible too.
