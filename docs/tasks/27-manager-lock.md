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

## Report

Based on: main at c07fa20

Implemented:
- `src/Kind/Kind.php`: `openInManager(\modX, $key)`, default `null`.
- `src/Kind/ResourceKind.php`: `openInManager()` reads `getLock()` (never adds or removes a lock) and resolves the username (empty string if the user row is gone).
- `src/Kind/Objects.php`: `assertNotOpen()` (throws `locked` with `details.reason = open_in_manager`, `user_id`, `username`; `force` adds a warning and continues) and a helper `openPlan()` that gives `['open_in_manager' => {...}]` for dry-run plans.
- `src/Action/WriteAction.php`: `force` added to `commonInput()`. `WriteFileAction` removes it again (file tools are not concerned); `UndoAction` keeps its own `force`.
- `UpdateAction`, `EditCodeAction`, `DeleteAction`: `assertNotOpen()` right after `assertRevision()` (skipped in a dry run, which reports `open_in_manager` in the plan instead).
- `ReplaceAction`: `force` argument; after the revision check every selected object is checked (the tool is one changeset, so one open page stops everything); the dry run marks open objects.
- `BulkResourcesAction`: `force` argument; see deviations.
- `InvokeAction`: `force` argument; the guard runs in both write branches (normal and `informational`) when the operation has an object, and in plans/dry runs.
- `src/Journal/Undo.php`: an existing object that is open in the manager gets the conflict `open in the manager by <username>` (appended with `; ` to any other conflict); items carry `open`. `UndoAction` adds a warning per such object when it is undone with `force`.
- `src/Write/Lock.php`: the MCP-request `locked` error gets `details.reason = "mcp_request"`.
- `docs/getting_started.md`: one rule bullet (two sentences).
- Tests: `tests/php/FakeModx.php` (`FakeObject::getLock()`, `modX::lockResource/unlockResource/resourceLock`, a lock registry that is persisted like other rows); `tests/php/run.php` block "Open in the manager" (42 checks); `tests/php/endpoint.php` (`lock` CLI command) and `tests/endpoint.test.js` (one subtest: HTTP 409, `locked`, `details.reason`, then `force` with a warning).

Checks: `npm run check` passes before and after. PHP checks 4732 -> 4774; node tests 30 -> 31 (all pass). No existing assertion was edited.

Deviations / readings:
- Bulk: `bulk_resources` is all-or-nothing today, so an open resource refuses the whole call (nothing changed, not even the unlocked ones) with `details.items` listing every open resource, instead of "refused item, others done". The test asserts this.
- Duplicate: no guard added. Duplicate only creates a new object and never overwrites a target, so there is nothing to protect.
- `force` is not offered on `modx_write_file` (it inherits `commonInput()`), because the brief says file tools are not concerned.
- Invoke: ResourceKind declares no operations, so the invoke guard cannot be exercised on a resource in the fake; only that the argument exists and that other kinds are unaffected is tested. It is the same one-line call as elsewhere.
- The recorder test is a source check (the `Manager/` classes contain neither `assertNotOpen` nor `openInManager`), not a behavioural one.
- Existing warning for `undo`: warnings for a forced undo are added inside the write closure.

Real `getLock()` and expired locks (read in `modresource.class.php` and `registry/moddbregister.class.php`): `getLock()` subscribes to `/resource/<md5(id)>` in the `locks` register and reads one message with `remove_read => false`. The message payload is executable PHP that begins with `if (time() > <expires>) return null;`, where `expires = send time + lock_ttl` (default 360 s). An expired lock therefore evaluates to null, `intval(null)` is 0, so it counts as no lock. Side effects of that read: `_readMessage()` deletes a message whose `expires` is in the past even with `remove_read => false`, and a "Message was null or expired" line is logged at INFO level. A live lock is left in place. `getValidMessages` only filters on `valid <= now`, not on expiry.

Verified only against the fake (check on the stand): the real `getLock()` and the lock registry (the fake has no TTL); that a manager-opened resource really shows up as a lock for the person's user id; that the processors the API runs (which call `addLock`/`removeLock` themselves) do not leave a lock that this check then reads as a person's on the next call; the username resolution from `modUser`.

Open questions: should a no-op update (nothing really changes) on an open page also be refused? It is today, because the check runs before anything is compared.
