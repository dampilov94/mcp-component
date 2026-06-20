# modxMCP — Development guide (for humans and AI assistants)

This is everything an assistant needs to extend the **modxMCP** component the same way it's
been built so far. Read it before changing code. Hand this file to whatever AI you use next.

## 1. What this project is

Two cooperating parts:

```
AI client ⇄ (stdio)  modx-mcp (Node, client/index.js)  ⇄ (HTTPS + X-MCP-Token)  api.php ⇄ modxMCP model ⇄ MODX
```

- **`client/index.js`** — a Node stdio MCP server. It exposes *tools* (`modx_*`) to the AI and
  forwards each call to the site's HTTP endpoint with the token. Configured only via env
  (`MODX_MCP_SITE_URL`, `MODX_MCP_TOKEN`).
- **`assets/components/modxmcp/api.php`** — the token-protected HTTP endpoint. Parses the JSON
  body and calls the model.
- **`core/components/modxmcp/model/modxmcp.class.php`** — the brain. One class `modxMCP` with a
  big `processRequest($action, $elementType, $data)` dispatcher. **This is where almost all work
  happens.**

The repo also builds a **MODX transport package** (`_build/`) so the component installs on any
MODX 2.x site like a normal add-on.

## 2. Repo layout

```
client/index.js                         MCP client: tool definitions + generic dispatch
assets/components/modxmcp/
  api.php                               public token endpoint
  connector.php                         manager (CMP) AJAX connector
  js/home.js                            CMP dashboard JS
core/components/modxmcp/
  model/modxmcp.class.php               the modxMCP class (all actions live here)
  controllers/index.class.php           CMP controller (Components > modxMCP)
  templates/home.tpl                    CMP dashboard template
  processors/mgr/*.class.php            manager processors (getstatus, regeneratetoken)
  lexicon/{en,ru}/{default,setting}.inc.php
_build/
  build.config.php                      PKG_VERSION / PKG_NAMESPACE / PKG_RELEASE
  build.transport.php                   package builder (run on a real MODX)
  data/transport.settings.php           system settings shipped with the package
  resolvers/resolve.token.php           generates api_token on install
  resolvers/resolve.integrations.php    logs detected add-ons on install
_reference/                             vendor sources (MIGX, miniShop2) for STUDY ONLY.
                                        gitignored, never packaged. Read; don't edit/ship.
```

## 3. The one thing to learn: add an action end-to-end

Every capability is an **action**. Adding one is three steps:

1. **Model dispatch** — add a `case` in the *first* `switch ($action)` in `processRequest()`
   (the block before the `$elementType` validation, so it runs for non-element actions):

   ```php
   case 'my_action':
       return $this->myAction($data);
   ```

2. **Model method** — implement it on the `modxMCP` class. Return any JSON-serialisable value
   (array/string). Throw `Exception` on error — the endpoint turns it into a clean error.

3. **Client tool** — add one entry to the `toolDefinitions` array in `client/index.js`:

   ```js
   {
     name: "modx_my_action",
     description: "What it does. Be specific — the AI picks tools from this text.",
     inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
   },
   ```

   **No per-tool handler is needed.** The client has a generic fallback: any tool named
   `modx_<x>` is sent as `{ action: "<x>", data: <args> }`. So the tool name minus `modx_`
   must equal the model `case`.

That's the whole loop. `api.php` passes `data` through verbatim (it also copies top-level
`name`/`content`/`id` into `data`, and reads `type` into the `$elementType` argument — avoid a
`type` field in `data` for non-element actions unless you handle it before the element mapping).

## 4. Three implementation patterns (pick the one that fits)

**A. Wrap a core MODX processor** — best when MODX already has a processor (validation for free).
See the ACL block: `aclActionMap()` maps `action → 'security/...'` processor path, and
`runAclAction()` runs it and normalises the result. To add more core-processor actions, just add
rows to that map.

**B. Wrap a *component's* processor** — same idea but pass the component's `processors_path`.
See miniShop2: `runMiniShop2Processor($path, $payload)` loads the ms2 service (so its class map +
processors are available) and runs e.g. `mgr/settings/link/create`. `ms2LinkAction()` is the map.

**C. Operate on an xPDO object directly** — when a component's processors assume the manager UI
context and can't be called headless (e.g. MIGX's XdbEdit processors). See MIGX configs:
`loadMigx()` does `addPackage('migx', .../model/')`, then `listMigxConfigs` / `saveMigxConfig` /
`deleteMigxConfig` use `getObject`/`newObject`/`getCollection`/`set`/`save`/`remove`. Reliable,
but you lose the processor's validation — validate inputs yourself.

> Decide A vs B vs C by **reading the real source** (drop it in `_reference/` and grep it). Don't
> guess processor paths, class names, or field names — wrong guesses only surface at test time.

## 5. Helpers already on the class (reuse them)

- `normalizeProcessorResponse($response)` — decode a processor response to array/object.
- `formatProcessorErrors($response)` — readable error string from a failed processor.
- `getListLimit($data)` / `getListStart($data)` — pagination (default limit 100, max 500, 0=all).
- `logAudit($action, $type, $info)` — writes to the audit log when `modxmcp.audit_log` is on.
- `runWithTransaction($closure)` — wrap multi-step writes.

## 6. System settings & lexicons

- Define a setting in **`_build/data/transport.settings.php`** (`array('modxmcp.x', default,
  xtype, area)`), then read it with `$this->modx->getOption('modxmcp.x', null, $default)`.
- Add its label/description to **`core/components/modxmcp/lexicon/{en,ru}/setting.inc.php`**
  (`setting_modxmcp.x` and `_desc`).
- **Gate dangerous actions behind an off-by-default setting** (see `modxmcp.allow_run_processor`
  / `install_package`). Security defaults must be safe.

## 7. Editing conventions (important)

- **Server files are CRLF.** When patching the model with a script, preserve the existing EOL
  (the `/tmp/*.js` patch scripts detect `\r\n` and keep it). Never re-upload a whole file that's
  been converted to LF.
- After editing the client, run `node --check client/index.js`.
- After editing the model, sanity-check brace balance:
  `node -e "const s=require('fs').readFileSync('<model>','utf8');console.log(s.split('{').length-1, s.split('}').length-1)"`.
  There's a **baseline −1** (one `}` lives inside a string literal in the original file), so a
  balanced edit keeps the delta at exactly −1. There is no local PHP runtime — lint on the server.
- Match the surrounding code style (it mixes `array()` and `[]`; new code uses `array()`).

## 8. Build / deploy / test

There's **no local PHP** — build and test only on a running MODX, over FTP + HTTP.

- **Quick iteration on an installed site:** upload the changed files under
  `core/components/modxmcp/` and `assets/components/modxmcp/` via FTP, then call the
  `virtualpage_clear_cache` action (or clear MODX cache) and exercise the endpoint.
- **Full package rebuild:** bump `PKG_VERSION` in `_build/build.config.php` **and** `version` in
  `package.json`, add a `CHANGELOG.md` entry, upload `_build/` + `core/` + `assets/` to a MODX
  docroot, open `_build/build.transport.php` in a browser (or run via CLI). The zip lands in
  `core/packages/`. Install it from the manager (Package Management).
- **Test the endpoint directly:**
  ```
  curl -s -X POST "$SITE/assets/components/modxmcp/api.php" \
    -H "X-MCP-Token: $TOKEN" -H "Content-Type: application/json" \
    -d '{"action":"list_user_groups","data":{}}'
  ```
  Wrong/absent token must return 401; `modxmcp.enabled = No` must make the endpoint inert.

Test environments for this project: **fordev** = clean MODX (good for install/ACL/core tests);
**buyguns** = has miniShop2 + MIGX + rich content (use it for ms2/MIGX/search tests).

## 9. Security model (don't regress these)

- Enabled on install (`modxmcp.enabled = Yes`) but protected by an auto-generated random
  `modxmcp.api_token` (visible in System Settings); token compared with `hash_equals`.
- Runs as `modxmcp.service_user_id` (admin) — treat as a high-privilege admin API. Works over
  plain HTTP too, but the token then travels in cleartext, so prefer HTTPS (or a trusted network).
- Root filesystem read off by default; component-file reads limited to `modxmcp.component_code_roots`.
- Never commit/hardcode the token. Keep `_reference/` (vendor code) out of the package and out of git.

## 10. Versioning

`_build/build.config.php` `PKG_VERSION` + `package.json` `version` + a `CHANGELOG.md` entry move
together. Each feature batch so far bumped a minor version.

## 11. Current action surface (high level)

Elements (chunk/snippet/template/plugin/tv/category) + resources CRUD; resource TVs; system
settings; media sources & component files; `search_code` / `find_usages` / `list_resources`;
`make_static` + `modxmcp.auto_static`; VersionX; VirtualPage; miniShop2 options + product fields;
miniShop2 product **links** (msLink / msProductLink); MIGX **configs** (migxConfig);
Access Control (groups/roles/policies/resource-groups/context+resourcegroup access);
`list_tv_input_types`; `check_integrations`; `install_package`; `regenerate_token`; CMP dashboard.

To enumerate exactly, read the `case` labels in `processRequest()` and the `toolDefinitions`
array — they are the source of truth.
