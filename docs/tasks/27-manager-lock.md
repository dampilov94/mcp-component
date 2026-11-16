# 27 — Do not write over a resource that a person has open in the manager

Branch: `task/27-manager-lock`. Spec: section 8.10, paragraph "Объект открыт в админке".
Read first: `modx-example-2-8-8/core/model/modx/modresource.class.php` (`addLock`, `getLock`,
`removeLock`), the manager controller `manager/controllers/default/resource/update.class.php`,
the resource processors that call `addLock()` themselves (`delete`, `unpublish`, `undelete`, …),
and every caller of `Objects::assertRevision()` in `src/`.

## Why

MODX forms have no optimistic check. If a person has a resource open and the API changes it,
the person's next "Save" silently writes their stale form over the API's change. MODX marks an
open resource with a lock in the `locks` registry (TTL `lock_ttl`, default 360 s). Only
resources have such locks in MODX 2.8; elements do not (their `locked` column is a permission
flag, not an "open now" marker), so this brief concerns the kind `resource` only.

## Design (decided — do not change)

- `Kind::openInManager(\modX $modx, $key)` — default `null`. `ResourceKind` returns
  `['user_id' => int, 'username' => string]` when `$resource->getLock()` is non-zero, else null.
  It must only read (`getLock()` does not remove the message); never add or remove a lock.
  A lock found before an API call is a person's, even when its user id equals the API's service
  user: the processors the API runs take and release their own lock inside the call.
- `Objects::assertNotOpen(Context $context, Kind $kind, $key, array $args)`: when the object is
  open and `$args['force']` is not true, throw `ApiException('locked', "<kind> <key> is open in
  the manager by <username>. Their next save would overwrite this change. Ask them to close it,
  or repeat with force:true.", ['reason' => 'open_in_manager', 'user_id' => …, 'username' => …])`.
  With `force: true` it adds a warning to `$context->warnings` and continues.
- Call it everywhere `assertRevision()` is called for an object that is about to be changed or
  deleted (update, edit_code, delete, replace, duplicate's target if it overwrites, invoke
  operations that write, bulk_resources per item, file tools are not concerned). In a batch tool
  an open item is reported the way that tool already reports a refused item; it must not abort
  the other items unless the tool is all-or-nothing today.
- Dry runs: do not throw; report `open_in_manager: {user_id, username}` in the plan.
- Add `force` (boolean, "Write even though the object is open in the manager.") to the common
  input of write actions (`WriteAction::commonInput()`), unless the action already has a `force`
  with another meaning (`modx_undo`) — there, see below.
- `modx_undo`: an object that is open in the manager is a conflict in the plan with the reason
  `open in the manager by <username>`; the existing `force` undoes over it (same flag, same
  semantics as other conflicts). This applies to the history page too (same action).
- The existing `locked` error for a concurrent MCP request keeps its code; it gets
  `details.reason = "mcp_request"` so clients can tell the two apart.
- The manager recorder (`Context::forManager` + tool `manager`) never calls `assertNotOpen`.
- `core/components/modxmcp/docs/getting_started.md`: two sentences in the rules list.

## Tests (required)

Give `FakeObject`/the fake site a way to set and read a resource lock (`getLock()` returning
the locking user id; a helper to set/clear it). In `tests/php/run.php`, block "Open in the
manager": every listed tool refused with `locked` + details for a locked resource and passing
for an unlocked one; `force: true` passes with a warning; dry run reports and does not throw;
bulk: the open item refused, the others done; undo: conflict in the plan, refused on confirm
without force, done with force; the kind `chunk` is never affected; a lock owned by the
service user still blocks; `openInManager` leaves the lock in place (read twice). Through the
kernel (`tests/endpoint.test.js`): HTTP 409, code `locked`, `details.reason`.

## Acceptance

`npm run check`, `npm test`. Report as usual; state exactly how the real `getLock()` treats an
expired lock (read the registry code) since the fake cannot show it.
