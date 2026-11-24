# 52 — modx_check_page renders drafts and unpublished pages

Branch: `task/52-check-draft` (already created by the architect from `main`; work on it).
Read first: `src/Action/CheckPageAction.php`, `src/Verify/PageFetcher.php` and
`CurlPageFetcher.php`, `src/Draft/*` (`Drafts`, `DraftPreview`, `PreviewKey` — the existing
shareable preview, which shows changed fields as text and needs HTTPS), `src/Manager/Recorder.php`
and the plugin body `elements/plugins/recorder.php` (how the component's plugin dispatches events),
`src/Request/RequestStore.php` (guarded files in `core/modxmcp-data`), and in
`modx-example-2-8-8/`: `modRequest::handleRequest`, `modRequest::getResource` (where unpublished
pages are filtered: `view_unpublished`, session state), `modX::sendForward`, `OnHandleRequest`,
`OnWebPageInit`, `OnPageNotFound`, and how the page cache is written (`modRequest`,
`modResource::_process`, `cacheable`).

## Why

The AI can create a draft or an unpublished test page, but `modx_check_page` makes an anonymous
request and gets 404, so it cannot see what the page renders, which tags stay unparsed and which
errors it causes. The shareable preview link does not render the page and needs HTTPS.

## Design (decided — do not change)

- `modx_check_page` accepts `id` of a draft or of an unpublished (not deleted) resource; today such
  ids fail with 404. For those, and only those, the action:
  1. creates a one-time render key: 32 random bytes, stored hashed with the resource id, an expiry
     of 60 seconds and the creating token id, in a guarded file under
     `core/modxmcp-data/render/` (same file guard and atomic writing as the request store);
  2. fetches the page's URL (as today) with the header `X-MCP-Render: <key>`;
  3. reports as usual (`status`, `title`, timing, `unparsed_tags`, new log entries) plus
     `rendered_as: "draft" | "unpublished"`.
- The component's plugin, on the earliest web-context event that lets it change how MODX finds the
  resource (read the MODX source and pick it; explain why), checks the header: the key must exist,
  not be expired, match the requested resource id, and is deleted on first use whatever the
  outcome. Only then does it let MODX serve that one unpublished resource for this request (the
  least invasive way MODX allows — e.g. an in-request permission or criteria change; no user
  login, no session, no change to the database), with `cacheable` off for the request so nothing
  of the unpublished page reaches the page cache, and headers `X-Robots-Tag: noindex, nofollow` and
  `Cache-Control: no-store`. Any invalid, reused, expired or mismatching key: the plugin does
  nothing (the normal 404). No key ever appears in a URL or a log.
- The key travels from the site to itself; it works over plain HTTP because it is single-use,
  expires within a minute and is bound to one resource. Say so in the report and in the tool
  description; refuse (`validation_failed`) when the page URL of the resource is not on the site's
  own host (a context with another `site_url`).
- `check_page` on a published page is unchanged. Scope as today. A `draft_only` token may check its
  own drafts (follow `DraftPolicy`).
- Help: `getting_started.md` (drafts section: "check the draft with modx_check_page") and the
  `change_page` / `new_page_type` prompts: replace "ask the person to open the preview" with
  "check it with `modx_check_page {id}`, then give the person the manager preview".

## Tests (required)

PHP: key creation (hash only on disk, guard, expiry, binding, deleted on use, refused when
reused/expired/mismatched/garbage, concurrent creation), the plugin's decision function (pure,
given the header, the resource and the store), refusal for another host, prompts/help text.
Endpoint/harness: extend the fake web request just enough to show that a draft id is fetched with
the header and the result carries `rendered_as`. State plainly that real MODX serving of an
unpublished page with the key must be verified on the stand.

## Acceptance

`npm run check`, `npm test`. Commit in small steps. Report as usual: which event and which MODX
mechanism you used to serve the unpublished page, quoting the MODX source lines.

## Report

Based on: main at d976a71

Checks: `npm run check` passes; `npm test` 5956 PHP checks (5908 before) and 43 Node tests (43 before).

### Which event and mechanism

`OnWebPageInit`, from the component's plugin `modxMCP`. In `modRequest::handleRequest` (MODX 2.8.8) it is the last event before the page lookup, and the alias is already resolved to an id there:

```
$this->modx->beforeRequest();
$this->modx->invokeEvent("OnWebPageInit");

if (!is_object($this->modx->resource)) {
    if (!$this->modx->resource = $this->getResource($this->modx->resourceMethod, $this->modx->resourceIdentifier)) {
        $this->modx->sendErrorPage();
```

`getResource()` is where the page is hidden (`if (!$this->modx->hasPermission('view_unpublished') || $this->modx->getSessionState() !== modX::SESSION_STATE_INITIALIZED) { $criteria->where(array('published' => 1)); }`). An anonymous visitor cannot pass that and I found no in-request permission switch that does not touch the user or session. But `getResource()` is skipped when a plugin has already put an object into `$modx->resource`. So the gate (`src/Render/RenderGate.php`) loads the resource itself, the way `getResource()` does (deleted = 0, context check via `modContextResource`, `checkPolicy('view')`, TV values set as fields), sets `$modx->resource` and `$modx->resourceGenerated = true`, and calls `set('cacheable', 0)` on the in-memory object only. `modX::_postProcess` then skips `generateResource` (`... && $this->resource->get('cacheable')`), so nothing reaches the page cache, and nothing is written to the database, user or session. Earlier events were rejected: before `OnWebPageInit` the id is not yet known (alias resolution in `handleRequest` happens after `OnHandleRequest`, and `resourceMethod` is overwritten after it). Aliases of unpublished pages do resolve (the context alias map and `findResource` filter only `deleted`), so no 404 happens before `OnWebPageInit`. Binding: the gate compares the key's resource id to `$modx->resourceIdentifier` (only when `resourceMethod === 'id'`), i.e. to the page MODX is really about to serve.

### Implemented

- `src/Render/RenderKeys.php` (new): key store in `core/modxmcp-data/render/`; 32 random bytes shown as 64 hex; the file is named by the SHA-256 and holds the guard, hash, resource id, token id and expiry (60 s); written with `xb`, mode 0600, directory 0700, symlinked directory refused; `consume()` claims the file with an atomic rename, so a key works once; stale files (older than 5 minutes) are cleaned when issuing; `grant()` is the pure decision (header value, requested id) and consumes any well-formed key whatever the outcome.
- `src/Render/RenderGate.php` (new): the plugin handler described above; never throws; leaves published, deleted, other-context, unresolved-alias and "another plugin already chose a resource" cases to MODX; sends `X-Robots-Tag: noindex, nofollow` and `Cache-Control: no-store`.
- `src/Verify/HeaderPageFetcher.php` (new) and `CurlPageFetcher.php`: `fetchWithHeaders()` (the original `PageFetcher::fetch` signature is untouched, so existing fetchers keep working); header names/values with control characters or colons are refused.
- `src/Action/CheckPageAction.php`: for `id` of an unpublished, not deleted resource it issues a key, fetches with `X-MCP-Render` and adds `rendered_as: "draft"|"unpublished"` ("draft" when a `DraftStore` link exists). Refused with `validation_failed` when the URL host differs from the host of the `site_url` system setting, before any key is made or request sent; also refused if the fetcher cannot send headers. Published pages and `url_path` are unchanged. Description mentions the 60-second, single-resource key and plain HTTP.
- `src/Draft/DraftPolicy.php`: `check_page` added to `ACTIONS`; in the action a `draft_only` token may use `id` only, and only for an unpublished draft created by its own token (else `forbidden_scope`).
- `elements/plugins/recorder.php`, `src/Manager/Recorder.php` (`pluginEvents()` = `events()` + `OnWebPageInit`, used by `syncPlugin()`), `_build/build.transport.php` (packages `pluginEvents()`). The plugin returns before loading any component code when the event is `OnWebPageInit` and the header is absent, so ordinary web requests only pay for a plugin call. `Recorder::events()` itself is unchanged.
- Help `getting_started.md` (drafts section, draft-only section, "Checking the result"), prompts `change_page` and `new_page_type` now say to check with `modx_check_page` and then give the manager preview.
- Tests in `tests/php/run.php` (key store, plugin decision on the fake, action with a header-capable fetcher, draft-only token, help text), `tests/php/FakeModx.php` (`FakeObject::getMany()` returns none, `checkPolicy()` allows).

### Deviations and changed existing tests

- Existing assertions changed: (1) `syncPlugin` tests now expect `OnWebPageInit` among the plugin's events (`$rWantedPlugin`); the third one used `OnWebPageInit` as the "foreign event that must be removed", it now uses `OnWebPagePrerender`; (2) the draft-only exact tool list gains `modx_check_page`; (3) the prompt assertion for `new_page_type` expected `modx_check_page` to be absent and now expects `` `modx_check_page {id}` `` present.
- Existing fixture changed: resource 1 of the page-check fixture got `'published' => 1` (real resources always have the field; the fake one had none and would count as unpublished).
- The plugin file is now 7 lines (the existing "a few lines" test allows up to 7).
- A plugin event cannot be added by only deploying files: the plugin body and its event rows live in the database. On the stand the new `OnWebPageInit` event and the new plugin code appear after `Recorder::syncPlugin()` (package install/upgrade resolver) or by hand. Until then `check_page` on an unpublished page returns the normal 404.

### Verified only against the fake

Everything about real MODX: that `OnWebPageInit` is fired with `resourceMethod === 'id'` and a numeric `resourceIdentifier` for both friendly-URL and `?id=` requests, that `$modx->resource` set there is rendered (template, TVs, snippets), that `cacheable = 0` keeps the page out of the cache and that the headers are sent, `checkPolicy('view')` for pages in resource groups (such a page gives the normal 404 instead of an unauthorized page), derived classes (static, weblink, miniShop2 products), and the actual HTTP loopback with the curl fetcher (headers sent, plain HTTP). The fake only shows the decision logic, the stored files and the action with an injected fetcher.

### Open questions

- If the site's web server strips unknown request headers (some FastCGI/proxy setups), `X-MCP-Render` will not arrive and the result is a 404; no fallback was added.
- `Cache-Control: no-store` is sent by the gate; MODX's later output may add its own cache headers for `content_dispo` resources.
