# Changelog

## Unreleased

- Harden the Node/PHP HTTP contract: validate tool arguments, request objects and JSON/UTF-8
  envelopes; catch PHP Throwable; buffer unsolicited PHP output; bound request/response sizes;
  add configurable client timeout and MCP cancellation, refuse redirects, and report errors
  as tool failures even when HTTP status is 200. Keep server details behind debug/error IDs.
- Add optional request_id and automatic IDs for write tools. Retained responses replay without
  executing the action again; mismatched arguments, pending/unknown results and expired IDs
  cannot run again. get_request_status inspects state/results; _request_id supplies a retry ID.
  Persistent private state lives outside cache/component files, with configurable response
  retention and expired-ID tombstones. Existing clients without IDs remain supported.
- Harden `delete_media_folder`: canonical local Media Source roots, strict descendant paths,
  no traversal/absolute/stream paths, no symlinks in ancestors or the inspected tree. Add
  dry-run counts/entry previews and optional expected_revision for reviewed tree metadata.
  Use core directory removal with explicit full-folder options, verify actual completion,
  report partial failures, and preserve the successful manager event/action and MCP audit.
- `make_static` / auto-static now leave existing static elements and their Media Source,
  paths and files intact. New files use the configured core_path, portable core-path tags,
  native source=0 and names including the element ID. Reserve filenames exclusively and add
  a suffix for occupied names instead of overwriting; attach storage metadata only after the
  new file is ready, checking the element did not change. Create responses include final storage.
- `edit_element_lines` / `replace_across`: stage static-file writes with a recovery copy,
  restore the file on failed saves/transactions, catch PHP errors during save, and move MCP
  cache refresh/success audit after commit. Resolve the actual static source through MODX.
- `view_element` returns a full-content `revision`; line edits accept `expected_revision`.
  Replacement previews return `revisions`, accepted as `expected_revisions` to limit the
  apply step to reviewed IDs. Recheck under per-element/file locks and a database row lock;
  reject non-transactional element tables instead of promising a rollback they cannot perform.
- Replacement batches preflight revisions, report previously committed IDs on failure and
  refresh cache after partial saves. Static code search no longer calls `getContent()`, which
  could synchronise source files into the DB during a read/preview.
- Require the MODX `settings` permission for both modxMCP manager pages and menu entries,
  matching the existing AJAX processors. Restricted manager users cannot open the token
  dashboard or dependency graph through a direct manager URL.

## 1.9.0 (2026-08-10)

- **`dependency_graph`** — a structural map of how the site's elements wire together: which
  template pulls which chunk/snippet/TV, which snippet renders which chunk, where each TV is
  attached. Returns `nodes` + `edges` (`[from, to, kind]`) and, for free, two things a text
  search cannot give: **`missing`** (tags pointing at a chunk/snippet that does not exist —
  genuinely broken references) and **`orphans`** (elements nothing references — dead code).
  References are detected in MODX tags, chunk-valued properties — inline `&tpl=`…`, an element's
  **default properties** and **named property sets** (miniShop2 wires `msProducts` →
  `tpl.msProducts.row` there, not in any content) — `$modx->getChunk()`/`runSnippet()` in PHP,
  MIGX `inputTV`/`renderchunktpl`, `@CHUNK` bindings and the template↔TV relation. Chunks used
  by something that isn't an element (a system setting, a miniShop2 order-status e-mail, which
  references chunks by id) get a `used_by` field and are never called orphans. `focus`/`depth`/`direction` return just the neighbourhood of one
  element — precise where `find_usages` is a substring match — and `format:"summary"` is a
  cheap site health check. Token-safe by the same rule as `project_overview`: it scales with
  the element count, never with content (resources are counts, not nodes). Orphan candidates
  are cross-checked against resource content so a chunk pasted into a page isn't falsely
  listed (auto-skipped above 5000 resources; `stats.orphans_verified` says which). Read-only:
  static elements are read via `getFileContent()`, avoiding the re-save `getContent()` can
  trigger. New `graph` help topic; `getting_started`/`index` updated to route to it.
- **Visual graph on its own manager screen** — Components → modxMCP → «Граф связей» (a second
  menu item and manager action, `?a=graph&namespace=modxmcp`; the settings page links to it and
  is otherwise unchanged). Three layouts, because one undifferentiated hairball is unreadable:
  **Слои** (default — each type gets its own horizontal row, in the manager's own tree order:
  resources, then templates, TVs, chunks, snippets, plugins, with tinted bands and sticky row
  headers), **Кластеры** (each type pulled into its own cloud) and **Свободно** (pure force).
  Full-height force-directed map with arrows showing direction of use,
  nodes sized by reference count, hover-to-highlight, drag/zoom/pan, type filters with counts,
  a search box with results, clickable sidebar lists of broken references and unused elements,
  and a details card (category, degrees, `used_by`, incoming/outgoing lists, edit link).
  Filters for the two kinds of noise that drown a real site: **hide vendor elements** (nodes
  carry a `vendor` flag — their category, or their name, matches an installed add-on namespace;
  on a stock install that is ~60% of the graph) and **hide unused**. Plus a density slider,
  since the useful spacing depends on how big the site is.
  The central interaction is **isolation**: double-click a node to show only its neighbourhood,
  with 1–3 hop depth and an uses / used-by switch — that is the "what breaks if I touch this"
  view. Broken references render as hollow red nodes, orphans get a dashed halo. Repulsion uses
  a uniform grid so large sites stay interactive. No external libraries (the manager ships none
  and the transport package stays self-contained). The screen and the MCP action share ONE
  builder, so what the owner sees and what the AI reasons over cannot drift apart.

## 1.8.20 (2026-06-25)

- Fix: `edit_element_lines` and `replace_across` now fire the core save events
  (`OnBefore/On{Type}FormSave`) like a normal element update, so **VersionX (and any other
  save-event plugin) creates a version on a line/replace edit** — previously these saved the
  content directly (`$el->save()` / file write) and bypassed the events, so no version was made
  (create and full `update_element` did version, line edits didn't). They now save the full
  field set through the element update processor (content field overridden), and still write the
  static file first for static elements (so the event sees the new content). Token efficiency is
  unchanged — the model still sends only the delta; the server reconstructs and saves properly.

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
