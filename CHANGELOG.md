# Changelog

## 1.8.9 (2026-06-21)

- `replace_across` — site-wide search & replace across code elements (chunk/snippet/template/
  plugin) in one call: replaces all occurrences of `find` with `replacement`, honouring static
  files vs DB. `case_sensitive` defaults to TRUE; substring match. `dry_run` returns the exact
  lines that would change (a reviewable preview), not just counts — run it first.
- `describe_object` — schema introspection: lists an xPDO class's fields (php/db type, null,
  default) + primary key, by class name or alias (resource/chunk/tv/user/…), so the model uses
  real field names instead of guessing.
- Build workspace hardening: `_build/build.transport.php` now refuses web runs without
  `?key=<modxmcp.api_token>` (CLI unaffected), and a root `.htaccess` denies HTTP access to the
  workspace source while leaving the (token-gated) build entry reachable — for setups where the
  repo/workspace sits inside the docroot.

## 1.8.8 (2026-06-21)

- `search_code` content hits now include `line` (1-based line of the match) and `line_text`
  (the exact matched line, verbatim, EOL-normalised like view_element). For a one-line change
  this closes the search→edit loop with no separate read: feed `line` as start_line/end_line
  and `line_text` as `expect` straight into `edit_element_lines`. Name-only matches return
  `line: null`. Tool description updated to steer models to this flow.

## 1.8.7 (2026-06-21)

- Tool descriptions now steer any model toward efficient editing (client-side only; server
  logic unchanged from 1.8.6):
  - `update_element` states it replaces the WHOLE content and points to view/edit-by-line for
    small changes to large elements.
  - `get_element` points to `view_element` (numbered, windowable) for large elements.
  - `edit_element_lines` spells out the multi-edit contract: all line numbers refer to the file
    as last seen via `view_element` (the original); the server applies edits together bottom-up
    so earlier inserts/deletes never shift later ones; ranges must not overlap; send all spots
    in one call with original numbers.

## 1.8.6 (2026-06-21)

- Token-efficient partial editing of code elements (chunk/snippet/template/plugin) — no more
  resending the whole element to change a few lines:
  - `view_element` — returns the element as numbered lines (like `cat -n`), optionally windowed
    by `start_line`/`end_line`, plus `total_lines`.
  - `edit_element_lines` — apply line edits, sending only the changed lines. Each edit replaces
    the inclusive range `[start_line..end_line]` with `replacement` (multi-line ok; `""` deletes;
    insert = empty range `end_line = start_line - 1`). An optional `expect` (current text of the
    lines) is a safety anchor: verified at the stated position and relocated to its unique match
    if the line numbers drifted — any mismatch aborts the whole call (atomic, nothing written).
    Reads/writes the static file when the element is static, else the DB field; preserves EOL.

## 1.8.5 (2026-06-21)

- Access hardening at the endpoint (both off by default, so existing setups are unaffected):
  - `modxmcp.allowed_ips` — optional client-IP allowlist. Empty = allow all. CSV of exact IPs
    and/or IPv4 CIDR ranges (e.g. `203.0.113.4, 10.0.0.0/8`), matched against `REMOTE_ADDR`.
    `X-Forwarded-For` is intentionally not trusted (spoofable).
  - `modxmcp.require_https` — when on, non-HTTPS requests are rejected (honours a
    reverse-proxy `X-Forwarded-Proto: https` header in addition to direct TLS / port 443).
  - Both checks run before token validation, so disallowed clients never reach the token path.

## 1.8.4 (2026-06-21)

- File reads are now bounded: `read_component_file` and `read_media_source_file` cap the
  returned content at `modxmcp.max_read_bytes` (new setting, default 256KB) and accept an
  optional `offset` + `bytes` window for ranged reads. The response reports `size` (total),
  `offset`, `returned_bytes` and a `truncated` flag, so a huge file can no longer blow up the
  client's context.
- The write-audit trail moved out of `core/cache/` (which MODX wipes on a cache refresh) to
  `core/components/modxmcp/logs/audit.log`, so it survives cache clears.

## 1.8.3 (2026-06-21)

- Internal: action dispatch is now driven by a single registry (`actionRegistry()`) that is
  the one source of truth for the dispatch table, the introspection list (`list_actions`),
  the acl/context/workspace processor maps and capability enforcement. Previously the big
  `switch`, `listSupportedActions()` and the three processor maps each listed actions
  independently and could drift (an action dispatched but missing from the list would bypass
  capability checks). No behavioral change — the action surface is byte-for-byte identical
  (verified: 157 actions across 18 groups unchanged).

## 1.8.2 (2026-06-21)

- Fix: the CMP "save capabilities" action no longer silently re-enables package
  management, namespaces and lexicon. The savegroups processor used a hardcoded
  7-group whitelist while the model exposes 10 toggleable groups, so saving dropped
  the 3 missing groups from `modxmcp.disabled_groups` (turning them on). The
  whitelist is now taken from the model (`getCapabilities()`), a single source of truth.
- Fix: `list_elements` now actually filters by `query` and paginates. The Node client
  was not forwarding `query`/`limit`/`start`, and the core element getlist processors
  ignore a `query` property anyway — so the name filter is now done in the model
  (resources match pagetitle/longtitle/alias), and the client forwards the params.
- Fix: the MCP client now reports its real name/version (`modx-mcp` / package.json
  version) instead of the stale `modx-codex-server` / `7.1.0`.

## 1.8.1 (2026-06-20)

- Built-in documentation (RAG-lite): new always-on `help` tool / action returns on-demand guides
  (markdown shipped in core/components/modxmcp/docs/) — topics: getting_started, tv_input_types
  (how to create every TV field type incl. custom ones), study_component (how to learn & use a
  newly-installed add-on from its source), minishop2, migx, acl. Docs load into the model only
  when requested.

## 1.8.0 (2026-06-20)

- Capability toggles: each optional group (VersionX, VirtualPage, miniShop2, MIGX, Access
  Control, property sets, contexts) can be switched off in the CMP (Дополнения > modxMCP) or
  via `modxmcp.disabled_groups`. A disabled group is rejected server-side AND hidden from the
  client's tool list, so its tools stop costing tokens. Every API response carries a
  capability fingerprint; when it changes (CMP toggle), the client fires tools/list_changed
  on its next call — changes apply automatically, no polling and no manual reconnect.
- All optional groups are now toggleable and **off by default** (lean tool list out of the box;
  enable what you need in the CMP). Re-added as toggleable groups: package management
  (install/uninstall/providers/search), Namespaces, Lexicon Management.
- CMP capability UI: split into "Компоненты" (VersionX/VirtualPage/miniShop2/MIGX) and
  "Возможности MODX"; the Save button is enabled only when something changed and disabled again
  after saving.
- A call to a disabled capability returns a clear "enable it in Components > modxMCP" message.

## 1.7.0 (2026-06-20)

- Enabled on install: `modxmcp.enabled` now defaults to Yes (still protected by the
  auto-generated random token), and `modxmcp.api_token` is a visible textfield so it can be
  copied from System Settings / the CMP. The CMP shows the full token (click-to-select).
- Integrations rethought: `check_integrations` / the CMP panel now list only add-ons with
  DEDICATED modxMCP tooling — miniShop2, MIGX, VersionX, VirtualPage. Snippet/chunk-only
  add-ons are no longer flagged (MCP reads and calls those via the generic element tools).
- Contexts: list/get/create/update/delete (modContext) + their settings (modContextSetting:
  list/get/create/update/delete).
- Access changes apply immediately: ACL write tools now flush permissions automatically
  (the manager's "Flush Permissions"), plus an explicit `flush_permissions` action.
- Media (file) sources: create/update/delete (was read-only).
- Package Management: `uninstall_package` (by signature, gated like install).
- Media source parameters: create/update_media_source now take `properties` as a simple
  {name: value} map, merged into the source's params (basePath, baseUrl, ...).
- Providers: `list_providers`, `search_packages` (search a provider's catalogue before
  installing), and create/update/delete_provider (gated like install) — e.g. add modstore.pro.
- ms2 categories: create/update routed through the core resource processors (class_key=
  msCategory) — the ms2 category processors don't run cleanly headless. Verified live.
- list_tv_input_types: skip add-on render-dir paths and add MIGX's migx/migxdb explicitly.
- Package install is always available now: removed the `modxmcp.allow_package_install` gate
  and setting (install/uninstall/providers no longer require it).
- Auto-static (`modxmcp.auto_static`) now defaults to ON.
- Package readme rebuilt from the current concise README (the build workspace had a stale copy).
- Namespaces: list/create/update/delete (modNamespace).
- Lexicon Management: list entries/topics, set (override) and revert lexicon entries.
- Token efficiency: write actions now return just the object (the
  {success,message,total,errors} envelope is stripped); list rows drop never-useful columns
  (password hashes, salt, remote_*); list_elements defaults to limit 100 (0 = all).
- Trimmed the tool surface (155 -> 140) to cut the per-session tool-list cost: removed
  package management (install/uninstall, providers, search, list_installed kept), Namespaces,
  and Lexicon Management.

## 1.6.2 (2026-06-18)

- CMP (Components > modxMCP) is now bilingual — all panel labels, the regenerate-token
  button and its JS confirm/error messages are lexicon-driven (en + ru), so the UI follows
  the manager language (Russian on a Russian manager).
- Docs: clarified that the endpoint works over plain HTTP as well (token travels in
  cleartext, so HTTPS / a trusted network is preferred — not a hard requirement).
- README rewritten in Russian (single concise guide) + a .mcp.json.example to copy.

## 1.6.1 (2026-06-18)

- VirtualPage: added delete operations — `virtualpage_delete_event`,
  `virtualpage_delete_handler`, `virtualpage_delete_route` (by id or name). Completes the
  VP CRUD surface (list/get/create/update were already present).

## 1.6.0 (2026-06-18)

- Users — list/get/create/update/delete (modUser via core processors). `create_user`
  maps a plain `password` to the manager's password fields (or auto-generates).
- Element property sets — list/get/create/update/delete (modPropertySet) + assign/unassign
  a set to an element (modElementPropertySet).
- miniShop2 categories (list/create/update msCategory) and orders (list/get/update —
  update triggers ms2 status-change logic).
- Introspection & ops:
  - `list_actions` — the action names this server build supports, grouped (detect skew).
  - `clear_cache` — full or per-partition cache refresh.
  - `read_audit_log` — read back the write-audit trail (now also written as queryable
    JSONL to core/cache/modxmcp/audit.log, in addition to the MODX system log).
  - `run_processor` — generic processor escape hatch, gated behind the new
    `modxmcp.allow_run_processor` setting (off by default).
- Error handling: expected/validation errors (bad id, "not installed", disabled gate,
    unknown action) now return their message with HTTP 400 via a dedicated
    ModxMCPClientException; unexpected errors stay masked as 500. Hardened runAclAction
    against a missing processor and normalizeProcessorResponse against non-string responses.

## 1.5.0 (2026-06-18)

- miniShop2 product links — 8 actions: link TYPES (msLink: name + relation kind
  many_to_many/one_to_many/many_to_one/one_to_one + description) via list/get/create/
  update/delete, and product-to-product LINKS (msProductLink) via list/create/delete.
  Backed by miniShop2's own mgr processors.
- MIGX configurations — 5 actions: list/get/create/update/delete migxConfig (name,
  formtabs, columns, buttons, filters, permissions, category, published). Operates on the
  migxConfig object directly (MIGX's XdbEdit processors need manager context).

## 1.4.0 (2026-06-18)

- `list_tv_input_types` — list available TV input/widget types (core + custom types
  registered by other components, e.g. MIGX) via the OnTVInputRenderList event. Use the
  keys as `field_type` when creating a TV. (TV `input_properties`, `elements`, `templates`
  and `media_source` were already settable on create/update.)
- `check_integrations` — read-only report of which popular add-ons (miniShop2, MIGX,
  pdoTools, Tickets, mSearch2/mFilter2, VersionX, …) are installed and what modxMCP can do
  with each. Also shown as an "Integrations" panel in the CMP, and logged at install time.
- `install_package` — install a transport package from a provider (default modx.com) by
  name. Gated behind the new `modxmcp.allow_package_install` setting (off by default).

## 1.3.0 (2026-06-18)

- Access Control (ACL) — 37 actions backed by core MODX security processors:
  - User groups: list/get/create/update/delete; membership: list/add/update/remove.
  - Roles: list/get/create/update/delete.
  - Access policies + policy templates: list/create/update/delete; permissions: list.
  - Resource groups: list/create/update/delete + assign/remove a resource.
  - Context access (modAccessContext) and resource-group access (modAccessResourceGroup):
    list/grant/update/revoke.
- Tier 3 — manager CMP (Components > modxMCP): read-only status dashboard (endpoint,
  enabled, token state, auto_static/audit flags) plus a "Regenerate API token" button
  (mgr connector + processors, requires the `settings` permission).
- New `regenerate_token` MCP action — rotate `modxmcp.api_token` from the client
  (returns the new token once; invalidates the old one).

## 1.2.0 (2026-06-18)

- Tier 2 — static-file workflow + list filters:
  - `make_static` — convert a chunk/snippet/template/plugin to a static file under
    core/elements/ (sets static=1 + source=Filesystem). Single `{type,id}` or batch `{items}`.
  - New setting `modxmcp.auto_static` (default off) — auto-convert to static on every
    create/update of an element.
  - `list_elements` now accepts `query` (name filter), `limit`, `start`.

## 1.1.0 (2026-06-17)

- New code-navigation tools (Tier 1):
  - `search_code` — full-text search across element + resource content and names; matches
    static elements by reading their static file.
  - `find_usages` — content matches for an element name + (for templates) resources using it.
  - `list_resources` — list the resource tree by parent/context with a pagetitle/alias/uri filter.

## 1.0.0-pl (2026-06-17)

- First packaged release of **modxMCP** as a distributable MODX transport package + a
  git-installable Node MCP client.
- Transport package: registers the `modxmcp` namespace, installs `core/` + `assets/` files,
  creates 9 `modxmcp.*` system settings, **auto-generates a random `modxmcp.api_token`** on
  install, and leaves the component **disabled** (`modxmcp.enabled = No`) for safety.
- Node client (`client/index.js`, bin `modx-mcp`): configured entirely via env
  (`MODX_MCP_SITE_URL`, `MODX_MCP_TOKEN`); no site-specific defaults.
- Build/install verified end-to-end on a clean MODX 2.8.8 (namespace, settings, token
  generation, file placement, token-authenticated endpoint, 401 on bad token).
