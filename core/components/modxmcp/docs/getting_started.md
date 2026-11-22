# Getting started

You are working on a live MODX Revolution site. Every change you make is journaled and can
be undone, and the site is edited by people in the manager at the same time.

## Orient

1. `modx_site_info` — versions, object counts, contexts, installed add-ons.
2. `modx_capabilities` — the tools and kinds this token may use. Anything unavailable is
   listed with the reason: scope too low, group switched off, add-on not installed.
3. `modx_describe` — without arguments lists the object kinds; with `kind` returns its
   fields, relations and which of them can be written.

Connection: a client either speaks MCP over HTTP straight to this site (a static
`X-MCP-Token` (or `Authorization: Bearer`) token header) or runs the Node bridge. Over HTTP the tool list is
read when the client connects, so after a token's rights or a tool group change, reconnect
the client; the bridge refreshes it at once. Over HTTP nothing remembers the revisions you
have read between calls, so pass `expected_revision` on every write.

When a tool returns a `Warnings` block, read it alongside the result. The HTTP response limit
(`modxmcp.max_response_bytes`) covers the full MCP response. If a write's result does not fit,
use the returned `request_id` with `modx_request_status` before retrying. Use smaller batches
if the server refuses a batch before executing it.

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

## Drafts

Use a draft when a change of a live page is visible to visitors and should be looked at first.
A draft is an unpublished copy of a `modDocument` page (other classes, such as miniShop2
products, are refused); a page has at most one open draft, and the live page stays as it is
until the draft is applied.

1. `modx_invoke {kind: "resource", id, op: "create_draft"}` — returns `draft_id` and
   `preview_url`. MODX shows the unpublished draft only to a person logged in to the manager,
   so give the link to the owner.
2. Edit the draft like any page: `modx_update` / `modx_edit_code` / the `tvs` relation on
   `draft_id`. A draft cannot have children or its own draft.
3. `op: "apply_draft"` (on the page or on the draft) without `args.confirm` shows the plan:
   changed fields (content as a line diff), TV values, and `original_changed` with the fields,
   when the live page was edited after the draft was made.
4. `op: "apply_draft", args: {confirm: true}` puts `pagetitle`, `longtitle`, `description`,
   `introtext`, `content`, `menutitle`, `link_attributes`, `template`, `richtext` and the TV
   values of the draft on the page and removes the draft, as one changeset (`modx_undo` brings
   back the old page and the draft). If the page changed meanwhile it answers
   `revision_conflict`; `force: true` overwrites. Alias, parent, publication and schedule of the
   page are never copied. `op: "discard_draft", args: {confirm: true}` throws a draft away.

`op: "list_drafts"` lists the open drafts; `modx_get` and `modx_list` show `draft_id` on a page
and `draft_of` on a draft. A page with a draft, and a draft, cannot be deleted until the draft
is applied or discarded. A person can apply or discard a draft from the Drafts block on the
component's page in the manager, which shows the same plan first.

### Draft-only tokens

The **Drafts only** (`draft_only`) token can read resources and run `resource.create_draft` and
`resource.list_drafts` through `modx_invoke`. The list shows drafts created by that token.
After creating a draft, use `modx_update` with the `draft_id` to change `pagetitle`, `longtitle`,
`description`, `introtext`, `content` or `menutitle`. Supply plain text without markup or template
delimiters. The draft must remain unpublished and unscheduled. Give its preview link to the manager,
who can review the changes and apply or discard the draft in the component's **Drafts** block.

### Sharing a preview link

On an HTTPS site, a manager with the `settings` permission can choose **Share preview** in the
component's **Drafts** block. Anyone with the link can view changed plain-text fields for
30 minutes. Copy a fresh link after editing the draft. Applying or discarding it closes the
preview. Use the regular manager preview to see the page with its MODX template and TV values.

## Scenarios

The site offers ready-made scenarios as MCP prompts. In Claude Code call them as commands
`/mcp__modx__<name>`; other clients show them in their prompt menu. A token sees only the
scenarios it can carry out.

- `study_site` — study the site and answer with a short map; changes nothing.
- `audit {focus?}` — run `modx_audit_site`, group the findings, propose fixes, change only what the person picks.
- `change_page {page, change}` — change a page through a draft and apply it after the person agrees (needs the `content` scope).
- `new_page_type {description}` — plan and create a template, TVs and chunks, with a checkpoint (needs the `develop` scope).
- `fix_errors {since?}` — group errors from the MODX log and fix them after confirmation. Requires `modx_read_log`.

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
- File tools and static elements cannot write modxMCP's files, MODX core files or server
  configuration. Writing in installed component directories through a media source requires
  `develop`. File tools refuse writes through symbolic links.
- File changes are journaled and undone like object changes; `modx_delete_path` on a
  directory keeps every file it removed. Use `dry_run` first.
- Writing `.php` and other server-executed files needs the `develop` scope and the PHP must
  parse. Binary content is passed with `encoding: "base64"`.
- Code of chunks, snippets, templates and plugins is edited with the object tools even when
  the element is static; do not edit their files directly. When changing the static path or
  undoing that change, modxMCP leaves the previous file on disk.

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
- **A page that a person has open in the manager is not written over.** The write is refused
  with `locked` and `details.reason: "open_in_manager"` (with the user), because their next
  save would overwrite your change. Ask them to close it; `force: true` writes anyway.
- **PHP and Fenom are checked before they are saved.** A snippet or plugin with a syntax error
  is refused with `lint_failed` and the line number; nothing is written. On a site with
  pdoTools, a chunk or template that pdoTools would treat as Fenom is compiled with the site's
  own Fenom and refused the same way; without pdoTools, or with Fenom switched off for chunks
  (`pdotools_fenom_default`), there is no check.
- A write returns `changeset_id`, the new `revision`, the list of `changed` fields and
  `reversible`. Keep the revision for your next write to the same object.
- If a write times out, do not repeat it with new arguments. Check
  `modx_request_status` with the reported `request_id`, or retry the identical call with
  that ID as `_request_id`.

## Property sets, default properties, lexicon, media source settings

- Kind `property_set` (`properties`: `{name: {type, value, desc?, options?, lexicon?, area?}}`,
  relation `elements`: a list of `{kind, id}` with kind chunk, snippet, template, plugin or tv).
  The same links are the `property_sets` relation (a list of ids) of those elements, so either
  side can be written. `modx_invoke {kind: "property_set", id, op: "assign" | "unassign",
  args: {kind, id}}` attaches or detaches one element. Writing `properties` replaces the whole map.
- Chunks, snippets, templates, plugins and TVs have the field `properties` (their default
  parameters, e.g. the `&tpl` of a pdoTools snippet) in the same shape.
- Kind `lexicon_entry`, key `namespace:topic:language:name` (e.g. `core:default:ru:error`).
  A read gives the value the site shows plus `source` (`file` or `database`), `overridden` and
  `file_value`. `modx_list` needs a `namespace`, `topic`, `language` or `query` and lists the
  entries of the lexicon files too. `modx_update` (or `modx_create`) writes a database override;
  writing the value of the file removes the override; `modx_delete` removes it so the file value
  applies again. All of it is journaled and can be undone.
- Kind `media_source` has `properties` as `{name: value}` (`basePath`, `basePathRelative`,
  `baseUrl`, `allowedFileTypes`, ...): a write merges the given names into the stored settings. A
  `basePath` that leads into `core/config` or `modxmcp-data`, or outside the site and core
  directories, is refused unless `modxmcp.allow_root_filesystem_read` is on. Credential
  properties appear as `[hidden]` below `admin` and in history diffs for every scope.

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
- `modx_run {snippet|chunk|text, properties?, context?, resource?}` runs a snippet, renders a
  chunk or parses a string of MODX tags and returns the output, the time, new error-log lines
  and `unparsed_tags` (usually a missing element). It is off until the administrator sets
  `modxmcp.allow_run`, needs the `develop` scope and really executes the code: what the code
  changes on the site is not journaled and `modx_undo` cannot revert it. An error thrown by the
  code comes back as `ok: false` with type, message and line. A snippet's echo and return value
  are joined in `output`, as MODX does. Use `dry_run: true` to see what would run.
- `modx_read_log` returns the tail of the MODX error log and an `end_offset`. Read it
  before a risky change, then call it with `after_offset` afterwards to see only new errors.
- `modx_maintenance {operation}` (admin): `clear_cache`, `refresh_uris`, `remove_locks`,
  `flush_permissions`, `empty_trash`. Writes already refresh the cache. `empty_trash` is
  permanent and cannot be undone.

## Audit

- `modx_audit_site {checks?, limit?}` lists concrete problems, each tied to an object: `broken_link`
  (a `[[~id]]` to a missing page is an error, to a deleted or unpublished one a warning),
  `missing_element`, `unused_element`, `static_file_missing`, `duplicate_alias`, `php_syntax`,
  `missing_template`, `static_outside_roots` (a static element whose file is outside the folders of
  `modxmcp.static_roots`; the tools do not read such a file), `fenom_syntax` (a chunk or template whose Fenom does not compile; needs
  pdoTools). With pdoTools, Fenom calls (`{include 'chunk'}`, `{'chunk' | chunk}`,
  `{$_modx->runSnippet('name')}`, `makeUrl`) count as references in chunks and templates. `summary` gives the real `count` per check even when `limit` (default 50)
  lists fewer; `truncated` means a scan cap was hit. Findings are candidates to look at, not
  orders; fix them one by one with the object's own tools and run the audit again.

## Undo

- `modx_checkpoint {label}` before a larger task.
- `modx_history` — what was changed, newest first. Filter by `this_session` or `kind`. With
  `kind`, pass `id` for a numeric ID or `key` for a string key.
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

If PHP or Fenom code is refused with `lint_failed`, fix the code. `skip_lint: true` exists for
deliberately incomplete code and should not be used to get past an error on a live site.

If an object was changed after the changeset you are undoing, the plan marks it as a
conflict and the undo stops. Look at the later change before deciding; `force: true`
undoes over it and journals the overwritten state, so that is reversible too.
