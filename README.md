# modxMCP — MCP server + MODX component

Lets an AI client (Claude Code, etc.) read and manage a **MODX Revolution 2.x** site over
the Model Context Protocol: elements (chunks/snippets/templates/plugins/TVs/categories),
resources, resource TV values, system settings, media sources & files, installed-component
file inspection, VersionX history/rollback, miniShop2 product options, and VirtualPage.

Also includes: **code search** (`search_code`, `find_usages`) across element/resource content
incl. static files; **static-file conversion** (`make_static` + the `modxmcp.auto_static`
setting); **Access Control** (user groups, roles, policies, resource groups, context/
resource-group access — 37 actions backed by core security processors); a manager **CMP**
(Components > modxMCP) with a status dashboard and a regenerate-token button; and the
`regenerate_token` action to rotate the API token from the client. It also reports the
**add-ons** it can work with (`check_integrations` + CMP panel), lists **TV input types**
(`list_tv_input_types`, incl. custom ones like MIGX), and can **install packages** from a
provider (`install_package`, gated behind `modxmcp.allow_package_install`).

Two parts:

- **MODX component `modxmcp`** — a token-protected HTTP endpoint (`assets/components/modxmcp/api.php`)
  + logic (`core/components/modxmcp/`). Installed as a normal MODX transport package.
- **MCP client `modx-mcp`** — a Node stdio MCP server (`client/index.js`) your AI client runs;
  it forwards tool calls to the site's `api.php` with the token.

```
AI client ⇄ (stdio)  modx-mcp (Node)  ⇄ (HTTPS + X-MCP-Token)  api.php ⇄ MODX
```

## 1. Install the MODX component on a site

Use the prebuilt **`_packages/modxmcp-1.0.0-pl.transport.zip`** (validated on a clean MODX
2.8.8), or build your own (see "Building the package" below).

1. MODX manager → **Extras → Installer → Upload Package** → upload the `.transport.zip`.
2. Install it. On install the package:
   - registers namespace `modxmcp` + files (`core/` + `assets/`),
   - creates the `modxmcp.*` system settings,
   - **generates a random `modxmcp.api_token`** and leaves the component **disabled**.
3. System Settings (namespace `modxmcp`):
   - set **`modxmcp.enabled` = Yes** to activate the endpoint,
   - copy **`modxmcp.api_token`** (you'll give it to the client),
   - optionally review `modxmcp.service_user_id` (default 1 = admin), `modxmcp.audit_log`,
     `modxmcp.max_payload_bytes`, and the security flags below.
4. Verify: `POST https://your-site/assets/components/modxmcp/api.php` with header
   `X-MCP-Token: <token>` and body `{"action":"list_elements","type":"template"}` returns JSON.

## 2. Configure the MCP client (`.mcp.json`)

Install from the git repo (no npm publish needed). In your project's `.mcp.json`:

```json
{
  "mcpServers": {
    "modx-assistant": {
      "type": "stdio",
      "command": "npx",
      "args": ["-y", "github:<user>/<repo>"],
      "env": {
        "MODX_MCP_SITE_URL": "https://your-site.com/assets/components/modxmcp/api.php",
        "MODX_MCP_TOKEN": "<paste modxmcp.api_token here>"
      }
    }
  }
}
```

- `MODX_MCP_SITE_URL` — the site's `api.php` URL (required).
- `MODX_MCP_TOKEN` — the `modxmcp.api_token` value (required). Keep it out of git; the client
  also reads a root `.env` file as a fallback.
- Reconnect the MCP server in your client. The `modx_*` tools become available.

## 3. Security model

- **Disabled by default** (`modxmcp.enabled = No`) — nothing responds until you turn it on.
- **Token auth** — `X-MCP-Token` is compared to `modxmcp.api_token` with `hash_equals`.
- Runs as **`modxmcp.service_user_id`** (admin) — treat the endpoint as a high-privilege admin API; serve it over **HTTPS** only.
- **`modxmcp.allow_root_filesystem_read` = No** by default — the root Filesystem media source is
  not browsable; use the component-file read tools for installed package code.
- **`modxmcp.component_code_roots`** limits which paths component-file tools can read
  (default `core/components,assets/components`).
- **`modxmcp.max_payload_bytes`** caps request size; **`modxmcp.audit_log`** logs write actions.
- **`modxmcp.allow_package_install` = No** by default — the `install_package` action (which
  downloads + installs packages from a provider over the network) is disabled until enabled.

## 4. Building the package

A prebuilt zip ships in `_packages/`. To rebuild (e.g. after editing the component), run the
build on any MODX 2.x install (the build script needs the MODX core at runtime):

```bash
# place this repo anywhere under a MODX docroot, or set MODX_CONFIG_CORE:
php _build/build.transport.php
# or open _build/build.transport.php in a browser
```

It locates `config.core.php` (walking up, or via the `MODX_CONFIG_CORE` env var) and writes
`core/packages/modxmcp-<version>-<release>.transport.zip`. Bump `PKG_VERSION` in
`_build/build.config.php` per release.

## 5. Layout

```
core/components/modxmcp/      MODX-side logic (model class + lexicons)
assets/components/modxmcp/    api.php endpoint
_build/                       transport package builder (build.transport.php, settings, token resolver)
client/index.js               Node MCP stdio server (the npm bin "modx-mcp")
package.json                  git-installable npm package
```

## Extending it

See **[DEVELOPMENT.md](DEVELOPMENT.md)** — a full guide (for humans and AI assistants) on how
the pieces fit together and how to add a new action end-to-end. Hand it to whatever AI you use to
keep building the component. Tip: copy/symlink it to `CLAUDE.md` or `AGENTS.md` inside
`mcp-component/` so your AI tool auto-loads it when working here.

## Notes

- Edit `modx_versionx_*` tools to roll back AI-made changes; `modxmcp.audit_log` records writes.
- The client requires Node >= 18.
