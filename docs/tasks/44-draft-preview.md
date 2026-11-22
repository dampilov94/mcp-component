# 44 — Shareable draft preview

Branch `task/44-draft-preview`, based on local `main` after task 43. Read tasks 31 and 43,
`DraftStore`, `DraftPolicy`, `Snapshot`, `Auth/Security`, and the manager drafts block.

## Workflow

In the existing manager drafts table, a user with `settings` can generate a shareable preview
for an open, unpublished, unscheduled `modDocument` draft (including drafts made by other
existing scopes). The URL is good for 30 minutes and can be opened without a MODX account.
Show only changed plain-text fields from the task 43 editorial allowlist, compared against the
saved base snapshot from draft creation. Reject issuance when no fields can be shared, including
old drafts with no base snapshot. Empty text may be a real change. As in task 43, treat markup
and template delimiters as outside the plain-text subset. The original preview in the manager
and the normal draft approval and discard routes remain available.

## Design

- Use one fixed preview endpoint in the component assets. The manager processor is POST-only,
  requires the current connector session and `settings`, validates a numeric draft ID and
  returns the HTTPS URL once per explicit share action. Reuse the site's configured endpoint
  base and require the share endpoint to be on the same HTTPS origin as the site. Do not log
  URL fragments or bearer values. Add an en/ru button and a plain copyable field in the manager.
- Put the bearer in the link fragment. A small first-party preview page reads and removes it
  from the URL, then POSTs it in a bounded request body to the same endpoint. GET serves only
  the shell. No external dependencies, MODX template rendering, public listing, or remote URL
  input. The page inserts field values as text. Show an expired/unavailable state when needed.
- Use a site-local HMAC-SHA-256 with a randomly generated 32-byte secret provisioned only from
  the manager path. Store it in the protected `core/modxmcp-data` area, using a file guard,
  restrictive permissions and an atomic write under a lock. Read-only validation never creates
  keys. No schema migration. Bind the signed value to the exact draft link ID, draft resource,
  expiry and a digest of the exposed fields. Verify signature and time before fetching content,
  recompute the digest, and reject missing, edited or non-draft targets. Bind the digest to the
  draft's current editable fields too, so changes in an editable HTML field also expire links.
  A draft removed and restored via undo has a new link row and cannot revive an old share.
- Require HTTPS to redeem the bearer using the existing trusted-proxy-aware detection. Neither
  endpoint method should start a manager session. Use no-store, no-referrer, noindex and a
  restrictive Content Security Policy. Reject invalid links uniformly without exposing IDs,
  database errors or private state. Use a small payload limit before doing protected reads.
  Plain HTTP sites keep the existing manager preview workflow.
- Update the user guide and built-in help. Share issuance is manager-only. There are no changes
  to the MCP manifest, scope settings, public page rendering, TVs or the package version.

## Verification

Run `npm run check` and `npm test` on PHP 7.4. Cover issue and read, changed-only output, empty
field, missing base state, unsafe state, changed or removed draft, UTC TTL and HMAC validation
with locally generated fixtures, and HTTPS and permission gating. Architect should check the
native manager and preview flow with a local MODX fixture before integrating. On an HTTP dev
stand only check normal routes, files and the explicit HTTPS requirement.
