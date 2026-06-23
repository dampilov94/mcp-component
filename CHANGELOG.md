# Changelog

## 1.8.19 (2026-06-23)

- Docs/steering: `getting_started` rewritten around a numbered **recommended workflow**
  (orient → locate → look-before-change/`describe_object` → cheap edits → dry-run destructive →
  verify) so a model that's weak at MODX follows the safe, token-efficient path; `index` help
  landing refreshed to surface `project_overview`, `suggest_tv_type`, `describe_object` and the
  current topics. Helps reduce "which of ~180 tools do I use?" load.

## 1.8.18 (2026-06-23)

- MIGX guide expanded (verified against the MIGX 3.0.2 source): documents `inputTV` — reuse an
  existing TV as a MIGX field's input (for resource pickers limited by parent/template, richtext,
  media, or nested MIGX) instead of hand-writing `@SELECT` — with the required `ForMigx` naming
  convention for those helper TVs (e.g. `listNewsForMigx`). Also clarifies that inline
  `input_properties` (formtabs/columns + optional contextmenus/actionbuttons/columnbuttons/
  filters/extended) is as capable as a named config for TV-stored MIGX, so the inline-only,
  all-JSON workflow is the recommended path (named configs only for cross-TV reuse / MIGXdb).

## 1.8.17 (2026-06-23)

- `suggest_tv_type` — describe a field need (English or Russian) and get ranked candidate TV
  `field_type`s with reasons + a ready-to-edit create_element skeleton (with the extra keys that
  type needs) for the top pick. Deterministic bilingual keyword rules; helps a model unsure about
  MODX pick the right TV type. (group: tv_inputs)
- `list_tv_input_types` now also reports a `colorpicker` custom type when its namespace is
  installed, even if the OnTVInputRenderList event was swallowed (e.g. a broken plugin on it).

## 1.8.16 (2026-06-23)

- MIGX authoring guide (`migx` help doc) rewritten to lead with a complete, copy-pasteable
  INLINE MIGX TV example (a gallery: fields + columns as JSON strings in `input_properties`,
  no separate config object) so a model can build a working MIGX TV on a fresh site without an
  existing config to copy. Field/column reference + the renderChunk gotchas (server-side render,
  `renderchunktpl` key, mandatory virtual `dataIndex`) kept. Verified live: the documented
  example creates a valid `field_type:"migx"` TV end-to-end.

## 1.8.15 (2026-06-23)

- TV authoring guidance (mission: help any model pick the right input type): `list_tv_input_types`
  now returns per-core-type `use` (when to pick it) + `requires` (extra create_element keys like
  elements/media_source); the `tv_input_types` help doc rewritten into a "task → type" decision
  guide with correct examples.
- Robustness: `list_tv_input_types` no longer 500s when a third-party plugin on the manager event
  `OnTVInputRenderList` fatals headlessly (e.g. Ace's `addLexiconTopic() on null`). The event
  invocation is now guarded (Exception + Throwable) — a misbehaving plugin's custom types are
  skipped instead of crashing the action.

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
