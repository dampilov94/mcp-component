# 28 — modx_audit_site

Branch: `task/28-audit-site`. Spec: section 9.4. Read first: `src/Graph/GraphBuilder.php`
(broken references and orphans are already computed there — reuse it, do not re-implement tag
parsing), `src/Action/DependencyGraphAction.php`, `src/Action/FindUsagesAction.php`,
`src/Lint/PhpLint.php`, `Kind::effectiveContent()` / `StaticFiles`, `src/Action/CheckPageAction.php`
(for group and style).

## Goal

One read-only tool that lists concrete problems of the site, each tied to an object, so that an
AI (or a person) can fix them one by one.

## Design (decided — do not change)

`src/Action/AuditSiteAction.php`, tool `modx_audit_site`, read-only, scope `read`, same group as
`modx_check_page`. Logic in `src/Verify/SiteAudit.php` (one method per check), the action is thin.

Input: `checks` (array of check names, default all), `limit` (findings per check, default 50,
max 200). Output:

```json
{ "summary": { "<check>": { "count": 3, "truncated": false, "scanned": 120 } },
  "findings": [ { "check": "broken_link", "severity": "error|warning|info",
                  "kind": "resource", "id": 12, "name": "…", "message": "…", "details": {…} } ],
  "notes": ["…"] }
```

`count` is the real number found (keep counting past `limit`; stop collecting details), unless a
scan cap was hit — then `truncated: true` and a note. Findings use `id` or `key` exactly as other
tools do for the kind (`Subjects::keyField` / `publicKey`).

Checks:

| name | severity | finding |
|---|---|---|
| `broken_link` | error | a `[[~N]]` link (in a resource's content or an element's code) to a resource that does not exist; `warning` when it exists but is deleted or unpublished |
| `missing_element` | error | a call of a chunk or snippet that does not exist (from the graph's `missing`) |
| `unused_element` | info | a chunk, snippet, TV or template nothing refers to (graph orphans; a template counts as used when a resource uses it, a TV when a template has it and a tag or value uses it — follow the graph's existing rules and state them in `notes`) |
| `static_file_missing` | error | a static element whose file is missing or unreadable |
| `duplicate_alias` | warning | two non-deleted resources with the same `uri` in one context (report the group once, with all ids) |
| `php_syntax` | error | a snippet or plugin whose code does not pass `PhpLint` (line and message in details; disabled plugins too) |
| `missing_template` | error | a non-deleted resource whose `template` id is neither 0 nor an existing template |

Bounds: scan at most 5000 resources for the per-resource checks (newest-edited first when
capped) and every element; read code through the kinds so static files are honoured; never
load all resource content at once (page through ids). All text handling Unicode-safe.

Help: add the tool to `docs/index.md` and a short section "Audit" to `getting_started.md`.
Update the exact tool list/count assertions in `tests/endpoint.test.js` and the manifest checks.

## Tests (required)

`tests/php/run.php`, block "Site audit": a fake site seeded with exactly one instance of each
problem plus healthy neighbours (Cyrillic names included); each check finds exactly its
instance with the right object, severity and details; `checks` filter; `limit` with `count`
above it; an empty site returns empty findings and zero counts; a read-scope token can call
it; output validates against the schema. Extend the fake only as needed.

## Acceptance

`npm run check`, `npm test`. Report as usual, including what each check considers "used" and
any difference you found between the graph's rules and this table.

## Report

Based on: task/27-manager-lock at 172556b

Implemented:
- `src/Verify/SiteAudit.php`: the seven checks. One pass over resources in pages of 100 ids (links, aliases and templates come from the same rows; at most `SiteAudit::$maxResources` = 5000, the most recently edited first when there are more) and one pass over every element, read 200 at a time through the kinds (links in code, static files, PHP syntax). `missing_element` and `unused_element` come from `GraphBuilder::build(['format' => 'summary'])`. Counting continues past `limit`; only the first `limit` findings of a check are kept in full.
- `src/Graph/GraphBuilder.php`: one new public method `linkTargets($content)` that returns the `[[~id]]` targets using the graph's own private tag parser (no tag parsing was re-implemented). `build()` is untouched.
- `src/Action/AuditSiteAction.php`: tool `modx_audit_site`, read scope, group `verify` (same as `modx_check_page`), registered right after `CheckPageAction`. Input `checks` (enum of the seven names) and `limit` (1..200, default 50).
- Help: `docs/index.md` (quick reference line) and a new "Audit" section in `docs/getting_started.md`.
- Tests: `tests/php/run.php` block "Site audit" (23 checks): an empty site; one seeded instance of every problem with healthy neighbours and Cyrillic titles, code and a Cyrillic static-file text; each finding's object, severity and details; scanned counts; read-only (tables and journal unchanged); `checks` filter; `limit` with `count` above it and the note; unknown check; limit 201; read token sees the tool; the resource cap (newest-edited first, `truncated: true`, note).

Counts: `npm run check` passes before and after. PHP checks 4774 -> 4800 (4 of those are the changed manifest assertions below, the rest new); node tests 31 -> 31 (unchanged, all pass).

Existing assertions edited (tool list/count only, as allowed):
- `tests/php/run.php`: read-token tool list gains `modx_audit_site` before `modx_request_status`; `manifest: full tool count` 34 -> 35; `escape: ... admin manifest is the old one` 34 -> 35; `escape: with both on the admin sees 36 tools ...` 36 -> 37 (label text changed to "37 tools").
- `tests/endpoint.test.js`: read manifest list gains `modx_audit_site`; admin manifest 34 -> 35; bridge tool list 34 -> 35; admin tools with escape hatches 36 -> 37; read tools 18 -> 19.

What each check considers (and where it differs from the table):
- `broken_link`: `[[~N]]` in the content of every resource (deleted resources included, since the table does not exclude them) and in the code of chunks, snippets, templates and plugins; one finding per object and target. Error when the target row does not exist, warning when it is deleted or unpublished (`deleted` wins in the message).
- `missing_element`: exactly the graph's `missing` rows: explicit `[[$chunk]]` / `[[snippet]]` tags in element code, heuristic property and PHP references are not reported. Resource content is NOT scanned for it (the graph does not). A note says so.
- `unused_element`: the graph's orphans, unchanged. A chunk/snippet/TV is used if element code, default properties, a property set, a system setting or a miniShop2 order status refers to it; a TV also if a template has it attached; chunks and TVs named in resource content count as used only when the graph verified orphans (sites with at most 5000 resources; the note says when it did not). A template is unused when no non-deleted resource uses it. Plugins are never reported. Difference from the table: the table says a TV is used when "a template has it AND a tag or value uses it"; the graph says attached OR referenced (an attached TV with no tag is used). I followed the graph, as the brief says. "Value uses it" (a TV value stored on a resource) is not considered by the graph.
- `static_file_missing`: chunks, snippets, templates, plugins (through `effectiveContent()`: missing file, or a stream path, or unreadable file -> `reason` missing/unreadable) and TVs (file checked directly via `getSourceFile()`). Details carry the stored `static_file` value, not the absolute server path.
- `duplicate_alias`: non-deleted resources with the same non-empty `uri` and `context_key`; compared byte-exact (no case folding, so a database with a case-insensitive collation may treat more as duplicates). One finding per group, on the lowest id, `details.ids` lists all.
- `php_syntax`: snippets and plugins (disabled ones too), static files honoured, through `PhpLint::check` (the `modxmcp.lint_php` write-time setting is ignored here). A snippet whose static file is missing is skipped (it is reported as `static_file_missing`).
- `missing_template`: non-deleted resources whose `template` is neither 0 nor an existing template.
- `summary.truncated` means a scan cap was hit (per-resource checks only); findings cut off by `limit` show up as `count` above the listed number plus a note. `scanned` is the number of objects the check looked at.

Deviations: none from the design. Extras: `SiteAudit::$maxResources` is a public static so the cap can be tested; `GraphBuilder::linkTargets()` as described.

Verified only against the fake (check on the stand): the xPDO queries (`select()` of partial columns, `sortby('editedon','DESC')` with `limit`, `id:IN`) on real MySQL and performance of the graph build on a large site; real static-file paths and permissions; that the graph's resource-content verification (`content LIKE`) behaves on a real database collation; names with Cyrillic in tags are not recognised by the graph's tag parser (ASCII-only names), so Cyrillic element names are never "referenced" and would show up as unused: an existing graph property, not changed.

Open questions: should `broken_link` skip deleted resources as sources (they are not live)? Should `duplicate_alias` compare case-insensitively?
