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
