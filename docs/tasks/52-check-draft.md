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
