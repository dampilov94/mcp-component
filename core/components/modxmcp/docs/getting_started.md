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
`snippet`, `template`, `plugin`, `tv` and `category`; pages are the kind `resource`. The
same tools work for every kind:

- `modx_list {kind, query?, where?, fields?, limit?, start?}` — paged list; `total` and
  `next_start` tell you whether there is more. Code is never listed.
- `modx_get {kind, id|name, fields?, full?}` — fields, relations and `revision`.
- `modx_view_code {kind, id|name, start_line?, end_line?}` — code as numbered lines.

Kinds without a numeric id are addressed by `key` instead (`modx_describe` shows `key_type`
and `key_field`): `system_setting` (the setting key), `context` (the context key),
`context_setting` (`"<context_key>:<key>"`, split on the first colon, e.g. `web:site_url`) and
`namespace` (its name). `name` works as an alias of `key`, results carry `key` instead of
`id`, and the key cannot be changed after creation. Values of credentials (an xtype
`text-password`, or a key such as `smtp_pass`, `*_secret`, `*_token`, `api_key`) read as
`"[hidden]"` unless the token has the `admin` scope, and are never shown in a history diff.
Settings of modxMCP itself (namespace `modxmcp`, keys `modxmcp.*`) are read-only through the
API; change them in the MODX manager.

Field names are the same across element kinds: the code of a chunk, snippet, template or
plugin is always `content`, and the name is always `name`. For a static element `content`
is the file. Snippet and plugin code is PHP **without** an opening `<?php` tag; MODX removes
the tag if you send one, which shifts line numbers, so read the code back before editing it
by lines.

New chunks, snippets, templates and plugins are static by default, with files under
`core/elements/`; edit their code through the same object and code tools.
`modx_invoke {kind, id, op: "make_static"}` converts an existing element.

## Resources

- `modx_resource_tree {parent?, depth?}` — the page tree; `modx_list {kind: "resource"}`
  searches title, alias and long title and filters with `where` (`parent`, `template`,
  `published`, …).
- A resource's title is `pagetitle`; `name` in a lookup may be its alias, URI or title.
- Page content is the `content` field and can be edited by lines with `modx_edit_code`.
- TV values are the `tvs` relation: `modx_get` returns `relations.tvs` as `{name: value}`
  for the TVs of the page's template; write with
  `modx_update {kind: "resource", id, relations: {tvs: {name: value}}}` — only the named
  TVs change.
- `pub_date` and `unpub_date` are `"YYYY-MM-DD HH:MM:SS"` strings, `""` for none.
- `modx_delete` moves a resource **and its children** to the trash; `modx_undo` brings the
  whole branch back.
- `modx_bulk_resources {operation, ids}` changes several resources as one changeset:
  `publish`, `unpublish`, `set_template`, `move`, `reorder`, `delete`, `undelete`.
- New pages are created with the site's defaults; pass `published: false` to keep a page
  hidden until it is ready.

## Files

- Files live in media sources: `modx_list {kind: "media_source"}` shows their ids and
  directories. Only local sources work.
- `modx_list_files {source, path?}`, `modx_read_file {source, path}` (returns `revision`),
  `modx_write_file {source, path, content, expected_revision?}`,
  `modx_move_path {source, path, to}`, `modx_delete_path {source, path}`.
- To read the code of an installed add-on use `component` instead of `source`:
  `modx_list_files {component: "minishop2"}`, `modx_read_file {component: "minishop2",
  area: "core", path: "…"}`. Component code is read-only.
- Paths are relative to the source directory. Configuration and credential files are not
  accessible, and a source that covers the whole site is closed unless the administrator
  opened it.
- File changes are journaled and undone like object changes; `modx_delete_path` on a
  directory keeps every file it removed. Use `dry_run` first.
- Writing `.php` and other server-executed files needs the `develop` scope and the PHP must
  parse. Binary content is passed with `encoding: "base64"`.
- Code of chunks, snippets, templates and plugins is edited with the object tools even when
  the element is static; do not edit their files directly.

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
- `modx_duplicate {kind, id|name, new_name?, fields?}` — a copy with its writable
  relations. A page copy is unpublished and has no children.

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

## Finding and replacing across code

- `modx_search {query}` — literal search in the code of chunks, snippets, templates and
  plugins (add `kinds: ["resource"]` for page content). Returns line numbers you can use
  with `modx_view_code` and `modx_edit_code`.
- `modx_find_usages {kind, id|name}` — where a chunk, snippet, TV, template, category or
  page is used. Run it before renaming or deleting. Matching is textual: a name built at
  run time is not found.
- `modx_replace {find, replacement, dry_run: true}` shows what would change and returns
  `revisions`; call it again with `expected_revisions: <that map>` to apply. Everything is
  one changeset: if any object changed in between or the result would break PHP, nothing
  is written.

## Checking the result

- `modx_check_page {id}` or `{url_path: "/…"}` requests a page of this site as a visitor and
  returns status, time, title and the error-log entries written during the request. MODX
  drops unknown tags from the output, so a missing snippet or a broken tag is found in
  those log entries, not in the page.
- `modx_read_log` returns the tail of the MODX error log and an `end_offset`. Read it
  before a risky change, then call it with `after_offset` afterwards to see only new errors.
- `modx_maintenance {operation}` (admin): `clear_cache`, `refresh_uris`, `remove_locks`,
  `flush_permissions`, `empty_trash`. Writes already refresh the cache. `empty_trash` is
  permanent and cannot be undone.

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
