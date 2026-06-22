# Changelog

## 1.8.14 (2026-06-22)

- `project_overview` — orient on a whole installed site in ONE compact, token-safe call:
  template↔TV map, resource/product COUNTS (overall + by template + by context), a shallow
  resource tree (roots + child counts, capped), element categories, content types, contexts,
  integrations. Scales with structure not content (a 100k-resource site returns the same small
  payload); per-item browsing stays in list_resources / list_elements. `sections` and
  `max_tree_nodes` params; documented as the "orient first" step in getting_started.

## 1.8.13 (baseline)

Capability baseline (history before this point intentionally collapsed). The server exposes a
broad MODX management surface via the MCP client; capability groups are toggled in the CMP
(Components → modxMCP) and enforced server-side.

- **Elements** (chunk/snippet/template/resource/tv/category/plugin): list/get/create/update/
  delete, `make_static`, line-based `view_element` / `edit_element_lines`, `duplicate_element`.
- **Resources**: `list_resources`, `bulk_resources` (publish/unpublish/set_template/move/delete,
  with dry-run), `duplicate_resource`, `reorder_resources`, trash `undelete_resource` /
  `empty_recycle_bin`, `get`/`update_resource_tvs`.
- **Code navigation**: `search_code` (returns match line + line_text), `find_usages`,
  `replace_across` (site-wide, dry-run preview).
- **Media sources**: source CRUD + file/folder ops (create/update/rename/delete file,
  create/delete folder).
- **System settings**, **TV input types**, **components introspection** (read add-on source),
  **describe_object** (xPDO schema).
- **Toggleable groups**: VersionX, VirtualPage, miniShop2, MIGX, Access Control, contexts,
  property sets, package management, namespaces, lexicon.
- **Ops/diagnostics**: `list_actions`, `get_capabilities`, `help` (RAG docs), `clear_cache`,
  `read_audit_log`, `read_error_log`, `refresh_uris`, `remove_locks`, `system_info`,
  `regenerate_token`, `run_processor` (gated).
- **Endpoint**: token auth, optional IP allowlist + HTTPS enforcement, health/version GET.

Versions are kept in sync across `_build/build.config.php`, `package.json`,
`package-lock.json` and `modxMCP::VERSION` (CI-enforced).
