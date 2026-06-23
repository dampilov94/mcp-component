# Getting started

Everything is an *action* called via a `modx_*` tool. Responses are the data directly (the
success envelope is stripped). Errors come back with a clear message — read it and fix the call.

## Recommended workflow (follow this order)

1. **Orient** — on an unfamiliar site call `modx_project_overview` once: a compact map
   (templates↔TVs, resource/product counts overall + by template/context, a shallow resource
   tree, categories, content types, integrations) with no content, cheap even on huge sites.
2. **Locate** — `modx_search_code` (full-text; returns each hit's `line` + `line_text`),
   `modx_find_usages`, `modx_list_resources` / `modx_list_elements` (with a `query` filter).
3. **Look before you change** — read the target (`modx_get_element`, or `modx_view_element` for
   numbered lines of a big element). On an unfamiliar object, `modx_describe_object` gives the
   real field names + types so you don't guess.
4. **Change cheaply** — for a few lines, `modx_edit_element_lines` (send only changed lines, with
   an `expect` anchor) instead of `modx_update_element` (full rewrite). For a site-wide string
   change, `modx_replace_across`.
5. **Be safe with destructive ops** — `modx_delete_element`, `modx_bulk_resources` and
   `modx_replace_across` all take `dry_run:true` — preview first, then run for real. Resource
   delete is **soft** (MODX trash) → restore with `modx_undelete_resource`.
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
  under `core/elements/` so it can be edited over FTP / kept in git.
- **Study an add-on.** `modx_get_component_files` + `modx_read_component_file` read installed
  component source; see the `study_component` topic.

Deeper guides via `modx_help`: `tv_input_types`, `migx`, `minishop2`, `acl`, `study_component`.
