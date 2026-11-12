# 13 — `modx_check_page`

Branch: `task/13-check-page`. Spec: section 9.3.

## Goal

After changing a template or a page the model must be able to look at the result:
`modx_check_page {id | url_path, context?}` requests the page from the site itself and
reports status, timing, unparsed MODX tags and new error-log entries.

## Design (decided — do not change)

1. `src/Verify/PageFetcher.php` — interface with one method
   `fetch($url, $timeoutSeconds, $maxBytes): array` returning
   `['status' => int, 'headers' => [lower-case name => value], 'body' => string,
     'time_ms' => int, 'truncated' => bool]` or throwing `\RuntimeException` on a transport
   failure. `src/Verify/CurlPageFetcher.php` implements it with the curl extension:
   GET, **no** redirect following, `CURLOPT_PROTOCOLS` limited to HTTP and HTTPS, connect
   timeout 5 s, total timeout as given, body cut at `$maxBytes` (write callback), TLS
   verification on, user agent `modxMCP-check/<Kernel::VERSION>`, no cookies, no
   credentials. curl missing → `\RuntimeException('The PHP curl extension is required.')`.
2. `Context` gets `pageFetcher()` returning a `CurlPageFetcher` by default and a public
   setter `setPageFetcher(PageFetcher $fetcher)` used by tests.
3. `src/Action/CheckPageAction.php`: name `check_page`, read-only, scope `read`, group
   `verify`, `openWorld()` false (it only talks to this site), registered after
   `ReadLogAction`.
4. Input (exactly one of `id`, `url_path`):
   - `id` — resource id; the URL is `$modx->makeUrl($id, '', '', 'full')`. An unknown id,
     or an empty URL → `not_found` / `validation_failed`.
   - `url_path` — path on the site, must start with `/`, max 1000 chars, no scheme, no
     `//` at the start, no control characters, no `..` segment; the URL is the context's
     site URL (below) without its path + `url_path`.
   - `context` — context key, default `web`; its site URL is the context setting
     `site_url` if set, else the system `site_url`.
   - `max_bytes` — body bytes to fetch, default 500000, 1000..2000000.
5. **Only this site may be requested.** Build the set of allowed hosts from the system
   `site_url` and the `site_url` / `http_host` of every context (lower-cased host names,
   without port). The final URL's scheme must be `http` or `https` and its host must be in
   the set, else `validation_failed`. Redirects are not followed: for a 3xx report the
   `location` header and whether it stays on an allowed host (`redirect_same_site`).
6. Around the request: `$before = LogReader::tail(<error log>, 1, …)['end_offset']`;
   after it, `LogReader::tail(<error log>, 20, max_read_bytes, '', $before)` → `log`.
7. Output:
   ```json
   { "url": "https://site/page.html", "status": 200, "time_ms": 182, "bytes": 15320,
     "truncated": false, "content_type": "text/html; charset=UTF-8",
     "title": "Page title",
     "unparsed_tags": [ { "tag": "[[$missingChunk]]", "line": 41 } ], "unparsed_count": 1,
     "log": { "new_entries": 1, "entries": [ … as modx_read_log … ] } }
   ```
   - `title`: text of the first `<title>…</title>` (entities decoded, trimmed, max 300
     chars), only for `text/html`; absent otherwise.
   - `unparsed_tags`: occurrences of `[[` … `]]` left in an HTML/text body — MODX normally
     replaces all of them, so any left is a missing element or a syntax error. Match
     `\[\[[^\[\]\r\n]{1,300}\]\]`; report the first 20 with their 1-based line in the
     fetched body, `unparsed_count` = total. Skip matches inside `<script>…</script>` and
     `<style>…</style>` blocks (JavaScript arrays like `[[1,2],[3,4]]` are not tags).
   - For a non-text content type: no `title`, no tag scan.
   - A transport failure (DNS, timeout, TLS) is **not** a tool error: return `status: 0`,
     `error: "<message>"`, `time_ms`, and the `log` block.
8. Description, short: fetch one page of this site as an anonymous visitor and report
   status, time, unparsed MODX tags and error-log entries written during the request; only
   this site's own addresses.

## Out of scope

Following redirects, authenticated requests, rendering JavaScript, checking links or
assets, arbitrary URLs.

## Tests (required)

All with a fake `PageFetcher` set through `setPageFetcher` (no network in tests). Add
`makeUrl()` and context settings to the fake as needed.

- by `id` and by `url_path`; context-specific site URL.
- host guard: `url_path` that tries to change host (`//evil.example/x`, `/\evil`,
  `https://evil.example`), a context whose `site_url` points elsewhere is allowed only for
  that host; scheme other than http/https refused; `..` and control characters refused.
- 200 HTML with title, entities, a long title; two unparsed tags with correct lines; tags
  inside `<script>` and `<style>` ignored; more than 20 tags → 20 listed, exact count.
- 404 and 500 are reported, not thrown; 302 reports `location` and `redirect_same_site`
  for an internal and an external target.
- non-HTML content type: no title, no scan. Truncated body flagged.
- transport failure → `status: 0` with `error`.
- log entries written between the two log reads appear in `log` (have the fake fetcher
  append to the fake's error log); none → `new_entries: 0`.
- exactly-one-of validation; unknown resource id.
- `CurlPageFetcher` itself: no network test; only a check that the class reports a missing
  curl extension cleanly is not possible here either — leave it untested and say so.

Update tool lists/counts in both test files (a read tool).

## Acceptance

`npm run check`, `npm test`. Files: `src/Verify/PageFetcher.php`, `src/Verify/CurlPageFetcher.php`,
`src/Action/CheckPageAction.php`, `src/Action/Registry.php`, `src/Context.php`, tests,
`tests/php/FakeModx.php`.

## Report

Based on: task/12-make-static at c7a7ebf

- PageFetcher/CurlPageFetcher: injectable interface and bounded anonymous HTTP(S) GET,
  TLS verification, 5-second connect timeout, no redirects/cookies/credentials, limited
  write callback and transport exceptions. Context exposes the default fetcher and setter.
- CheckPageAction/Registry: resource/path URLs, context origins and host allowlist,
  validation, status/timing/body metadata, Unicode title extraction, bounded tag listing,
  script/style exclusion and before/after log cursor. Full manifest now has 31 tools.
- Fake makeUrl/context-setting fixtures and tests cover paths/hosts, Cyrillic title/tags/log,
  HTTP errors, redirects, caps, binary bodies, transport failures, selector validation.
- `npm run check` and `npm test` passed: 929 PHP checks and 24 Node/HTTP tests.

Decisions where unspecified: fetch timeout is 15 seconds; URL credentials and decoded
backslashes/traversal/control characters are rejected as well. Text bodies are scanned
(including text/plain); only text/html yields a title, while non-text bodies are not scanned.

All page requests used a fake fetcher: CurlPageFetcher, its missing-extension path, real DNS,
TLS, body abort and remote HTTP behavior remain untested as requested. Verify those and real
context URL generation on the stand. Log output is the newest 20 entries within max_read_bytes,
not a claim to count all errors during a busy request. No blocking questions or push.

## Review

Accepted and merged with the rest of the stack (09–14). Verified on the stand with
`scripts/stand/check-operations.cjs`; every earlier stand check was rerun.

No code changes. On the stand MODX removed an unknown chunk tag from the output and logged
errors for a JavaScript array it took for a snippet call: the log entries are the useful
signal, `unparsed_tags` is rarely non-empty. The description now says so.
