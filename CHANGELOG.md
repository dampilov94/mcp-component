# Changelog

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
