# 49 — Rename a published page with a journaled Redirector rule

Branch `task/49-redirector` from local main after task 48. The official Redirector 2.0.11
transport is at `.local/upgrade-check/redirector-2.0.11-pl.transport.zip` from
[MODX Extras](https://extras.modx.com/package/redirector). Read `modRedirect`, the native
`mgr/redirect/create` processor, the OnPageNotFound/FormSave plugin, `ResourceKind`, `InvokeAction`
and `Writer`. Keep package metadata.

## API and target

- Register the optional `redirector` integration and an admin-only, read-only `redirect` kind
  for inspecting native Redirector rules (by numeric ID). Define numeric ID, `pattern`, `target`,
  context, active and optional traffic counters. Exclude live traffic counters from canonical
  state so visits do not prevent undo. Normal list/get and history use the existing journal kind.
  Writes to legacy rules and arbitrary redirects are still done in Redirector's manager.
- Add `resource.rename_with_redirect` via `modx_invoke` with `args:{alias,expected_revision?}`.
  Require admin, enabled/installed Redirector and its active OnPageNotFound plugin, MODX friendly
  URLs, an existing published non-deleted `modDocument` with no descendants and an unfrozen URI.
  Accept a single validated alias segment (Unicode letters/numbers plus `_` and `-`). Leave the
  normal alias edit unchanged. Dry run and plan-only return prior URI and proposed alias without
  writes. By design the actual new URI is what the native processor derives during the save.
- Under the resource writer lock, check expected revision, page eligibility, original URI and
  collisions. Use the native full resource update path and compare the resulting URI with the
  old URI. Reject unchanged/equivalent paths, invalid paths and competing published resources.
  Return `old_uri`, `new_uri`, `redirect_id` and the normal changeset ID. An error after the
  native update must restore both the resource and rule state with the existing writer.

## Exact rules and rollback

- Store the old address as an anchored, escaped literal pattern, because the native plugin
  treats unmatched exact patterns as SQL regexes. Use the form that matches its decoded
  request path, relative to that context. The new target is the native new relative URI in
  the same context. Validate both paths, disallow MODX tags, query/fragment, dangerous
  delimiters/classes, traversal and external targets. Do not let the vendor processor fetch
  a URL or evaluate a caller-provided target. Create the generated rule with `mgr/redirect/create`,
  through the standard resource plus rule writer snapshot, never replacing an existing rule.
- Refuse if an existing relevant redirect would take over the old address, including exact
  raw/literal patterns and an applicable active regex in its context or global. Prefer using
  native MariaDB/MySQL regex semantics with parameter binding (keep offline fake behavior
  representative), and fail closed if the rules cannot be checked. Serialize the checked scope
  for this operation and recheck at the relevant stage. Temporarily clear the vendor session
  tracker around the save in try/finally so it cannot create an unjournaled duplicate; restore
  the prior value and presence. Do not change native settings or manager behavior.
- Protect the resulting resource and redirect snapshots with existing history size limits.
  Undo restores the old URI and removes its recorded redirect. If the prior rule state or
  integration can no longer be safely restored, report a conflict/refusal. Update en/ru
  capability labels and help. No vendor/producer support, bulk moves, descendant redirects,
  web links, external redirects or Redirector installation on fordev in this task.

## Verification

Run `npm run check` and `npm test` on PHP 7.4. Cover preview, role/group gates, published and
leaf guards, generated URI and rule, collision/no-op, exact nonmatching paths, session restore,
unchanged state on failure, counters, and undo. Architect will install official Redirector on
disposable real MODX and check the HTTP 301/Location, a neighboring path and the post-undo view.
fordev has no Redirector, so check optional absence and existing tools there.
