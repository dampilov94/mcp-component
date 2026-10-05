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

1. **Model dispatch** — add an entry to the appropriate group in `actionRegistry()`:

   ```php
   'my_action' => 'myAction',
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
   must equal the registry action key.

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
- Run `npm run check` after changes. It checks JavaScript syntax, PHP syntax, release-version
  consistency and the current CHANGELOG entry. It performs no live-site writes.
- The checker uses `PHP_BIN` when set, otherwise portable runtimes under `.local/tools/php-*/`
  (`php.exe` on Windows, `bin/php` elsewhere), otherwise `php` on PATH. The prepared Windows
  workspace has PHP 7.4 and 8.4. These syntax checks do not prove MODX runtime compatibility.
- Match the surrounding code style (it mixes `array()` and `[]`; new code uses `array()`).

## 8. Build / deploy / test

Install Node dependencies with `npm ci --ignore-scripts`. Run local checks before uploading.
Target MODX 2.8.x and PHP 7.4; keep code compatible with PHP 8.x where possible. By the owner's
decision, this batch does not require a separate PHP 8 integration stand. Local PHP 8 syntax
checks alone must not be described as verified runtime compatibility.

For this review batch, the owner performs functional checks on the test site. Do not create
test accounts or add unnecessary test suites. Run syntax checks on changed files before upload.
The component is an administrator/developer API; separate restricted API profiles are out of
scope. Its manager pages, menus and AJAX processors require the core `settings` permission.

Keep credentials in ignored `.env` / `.mcp.json`, and local runtimes, backups and diagnostic
artifacts in ignored `.local/`. For development, configure the MCP server to execute
`node <absolute-path-to-repo>/client/index.js` so it uses the working copy. The published
README connection example remains suitable for released builds.

Before uploading a changed component file, save its current server copy and compare it with
the local baseline. Use FTPS where available. Test one review item at a time, record the checks
and rollback path, and build the new transport package after the agreed batch is complete.

- **Quick iteration on an installed site:** upload the changed files under
  `core/components/modxmcp/` and `assets/components/modxmcp/` via FTP, then call the
  `virtualpage_clear_cache` action (or clear MODX cache) and exercise the endpoint.
- **HTTP contract:** POST application/json with an object containing action, optional type,
  and object-valued data. Optional top-level request_id enables replay protection; the Node
  client supplies IDs for write tools and accepts _request_id for exact retries. After uncertain
  writes, inspect get_request_status; never automatically retry with a new ID. Persistent state
  is core/modxmcp-data/requests, outside the shipped component and MODX cache. Keep this directory
  private and include it in site backups; deleting it removes the history that prevents replay.
  Runtime state/response files have PHP exit guards, and response bodies are base64-encoded
  to keep arbitrary returned code outside the PHP parser. This is not encryption.
  New actions should be classified conservatively by isReadOnlyTool in client/index.js so an
  action with side effects receives an ID. Server/client limits and error behavior are documented
  in the getting_started help and README. Runtime state is not a release artifact.

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

`_build/build.config.php` `PKG_VERSION`, `modxMCP::VERSION`, `package.json`, both root versions
in `package-lock.json`, and the release entry in `CHANGELOG.md` move together. During this
review batch, retain 1.9.0 until the fixes are complete and the next release is prepared.

## 11. Current action surface (high level)

Elements (chunk/snippet/template/plugin/tv/category) + resources CRUD; resource TVs; system
settings; media sources & component files; `search_code` / `find_usages` / `list_resources`;
`make_static` + `modxmcp.auto_static`; VersionX; VirtualPage; miniShop2 options + product fields;
miniShop2 product **links** (msLink / msProductLink); MIGX **configs** (migxConfig);
Access Control (groups/roles/policies/resource-groups/context+resourcegroup access);
`list_tv_input_types`; `check_integrations`; `install_package`; `regenerate_token`; CMP dashboard.

To enumerate exactly, read `actionRegistry()` and the `toolDefinitions`
array — they are the source of truth.
