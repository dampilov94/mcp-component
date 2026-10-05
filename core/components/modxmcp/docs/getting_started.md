# Getting started

Everything is an *action* called via a `modx_*` tool. Responses are the data directly (the
success envelope is stripped). Errors come back with a clear message — read it and fix the call.

## Recommended workflow (follow this order)

1. **Orient** — on an unfamiliar site call `modx_project_overview` once: a compact map
   (templates↔TVs, resource/product counts overall + by template/context, a shallow resource
   tree, categories, content types, integrations) with no content, cheap even on huge sites.
   Then `modx_dependency_graph {format:"summary"}` — one more cheap call that reports broken
   references and unused elements before you touch anything.
2. **Locate** — `modx_search_code` (full-text; returns each hit's `line` + `line_text`),
   `modx_find_usages`, `modx_list_resources` / `modx_list_elements` (with a `query` filter).
   To see how something is WIRED rather than where a string appears, use
   `modx_dependency_graph {focus:"chunk:header", direction:"out"|"in"}` — it resolves real
   references instead of substrings (`header` vs `headerNav`). Topic: `graph`.
3. **Look before you change** — read the target (`modx_get_element`, or `modx_view_element` for
   numbered lines of a big element). On an unfamiliar object, `modx_describe_object` gives the
   real field names + types so you don't guess.
4. **Change cheaply** — for a few lines, `modx_edit_element_lines` (send only changed lines, with
   an `expect` anchor and `expected_revision` copied from `modx_view_element.revision`) instead
   of `modx_update_element` (full rewrite). For a site-wide string change, use
   `modx_replace_across`: preview with `dry_run:true`, then pass its `revisions` map unchanged
   as `expected_revisions` when applying the same find/replacement.
5. **Be safe with destructive ops** — `modx_delete_element`, `modx_bulk_resources` and
   `modx_replace_across` and `modx_delete_media_folder` all take `dry_run:true` — preview first, then run for real. Resource
   delete is **soft** (MODX trash) → restore with `modx_undelete_resource`. Before deleting or
   renaming an element, check `modx_dependency_graph {focus:"<type>:<name>", direction:"in"}`
   for everything that depends on it.
6. **Apply & verify** — clear cache if needed (`modx_clear_cache`), then re-read to confirm.

## Key facts

- **Elements vs other objects.** Element tools take a `type`
  (`chunk`/`snippet`/`template`/`resource`/`tv`/`category`/`plugin`) plus fields. Other areas
  (settings, ACL, miniShop2, contexts, media, …) have their own dedicated `modx_*` tools.
- **Capability groups.** Optional groups (miniShop2, MIGX, VersionX, VirtualPage, Access Control,
  contexts, property sets, package management, namespaces, lexicon) can be switched off in the
  manager (Components → modxMCP). If a tool you need is missing, that group is probably disabled —
  ask the user to enable it. `modx_list_actions` shows all groups incl. disabled ones.
- **TV fields.** Unsure which input type? `modx_suggest_tv_type` (describe the need, EN/RU) →
  ranked types + a create skeleton. Details in the `tv_input_types` topic; repeating rows → `migx`.
- **Static files.** `make_static` (and `modxmcp.auto_static`) store an element's code as a file
  under the configured `core_path/elements/` so it can be edited over FTP / kept in git.
  Conversion applies only to DB-only elements. Existing static elements keep their exact
  `static_file`, Media Source and file bytes; repeated conversion returns `already_static`.
  New names include the element ID and get an extra suffix if occupied; occupied files are
  never overwritten. New files use native `source=0` and a portable `[[++core_path]]` path.
  Renaming an existing static element does not relocate its file.
- **Safe partial edits.** `view_element` returns a SHA-256 `revision` of the whole content,
  even when viewing a line window. A stale `expected_revision` rejects the edit: read again
  before retrying. It is optional for older clients; omitting it does not protect against
  changes between your earlier read and the edit request. Saving still rechecks the content
  read by that request under a lock.
- **Static-file recovery.** `edit_element_lines` and `replace_across` stage file changes and
  keep a recovery copy until the database commit. A processor or transaction failure restores
  the original file when rollback succeeds. Missing/unreadable files and non-local sources
  are rejected rather than replaced with an old DB copy. Element tables must support
  transactions (for example InnoDB); no automatic engine conversion is performed.
  Cache refresh and success audit follow the commit. `cache_refreshed:false` means the save
  committed but cache maintenance needs attention; do not repeat the edit just for that.
- **Replacement batches.** With `expected_revisions`, only reviewed element IDs are used and
  all revisions are checked before writing. Elements are still committed individually: a
  later save failure does not undo earlier successful saves, and the error names those IDs.
  Re-preview before retrying a partial batch. Arbitrary concurrent FTP writers, process crashes
  and external side effects of third-party plugins are outside the DB/file rollback guarantee.
- **Media folder deletion.** `modx_delete_media_folder` operates only on local filesystem
  Media Sources. Pass a relative path such as `images/archive`; empty/root paths, `..`,
  absolute paths and stream URLs are rejected. Symlinks in any path component or inside
  the selected tree are refused. The whole tree is inspected before deletion; `limit`
  only bounds the returned entry list (default 200, max 500). `directories` includes the
  selected folder itself. Preview with `dry_run:true`, then pass its `revision` as
  `expected_revision` to compare paths and metadata before deleting. The fingerprint uses
  filesystem identity, sizes and timestamps, not file-content hashes. These locks coordinate
  folder deletion through MCP; avoid concurrent FTP or other writes during removal.
  Deletion includes hidden files and has no rollback. A filesystem error can leave a partially
  deleted tree; errors say so, and success is returned only after the selected folder is gone.
- **Study an add-on.** `modx_get_component_files` + `modx_read_component_file` read installed
  component source; see the `study_component` topic.

Deeper guides via `modx_help`: `graph`, `tv_input_types`, `migx`, `minishop2`, `acl`,
`study_component`.
