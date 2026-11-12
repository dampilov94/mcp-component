# 07 — `modx_maintenance`

Branch: `task/07-maintenance` (stack on the previous task branch if it is not merged yet).
Spec: section 3 ("Служебные"), 8.7 (irreversible actions). 1.x reference:
`clearCacheAction`, `refreshUris`, `removeLocks`, `emptyRecycleBin`, `flushPermissions` in
`core/components/modxmcp/model/traits/` (grep for them). Real processors to read first:
`modx-example-2-8-8/core/model/modx/processors/system/`, `…/resource/emptyrecyclebin.class.php`,
`…/security/access/flush.class.php` (a local MODX 2.8.8 copy in the repository folder).

## Goal

One tool for site maintenance operations: `modx_maintenance {operation, confirm?}`.

## Design (decided — do not change)

1. `src/Action/MaintenanceAction.php` extends `Action` (not `WriteAction`: nothing here is
   journaled). `readOnly()` false, `destructive()` true, `reversible()` false, scope
   `admin`, group `maintenance`. Registered before `RequestStatusAction`.
2. Input: `operation` (required enum) and `confirm` (boolean).

   | Operation | What it does | Needs `confirm` |
   |---|---|---|
   | `clear_cache` | `$modx->getCacheManager()->refresh()` (the whole cache) | no |
   | `refresh_uris` | processor `system/refreshuris` | no |
   | `remove_locks` | processor `system/remove_locks` | no |
   | `flush_permissions` | processor `security/access/flush` | no |
   | `empty_trash` | processor `resource/emptyrecyclebin` — permanently removes every resource in the trash | **yes** |

3. `empty_trash`:
   - without `confirm: true`, or with a plan-only token: do nothing and return
     `performed: false`, `would_remove` = number of resources with `deleted = 1`, and up to
     20 of them as `{id, pagetitle}`.
   - with `confirm: true`: run the processor; return `performed: true`, `removed` = the
     count taken before running. Add to `$context->warnings`: "Resources removed from the
     trash cannot be restored by modx_undo."
4. The other operations with a plan-only token: `performed: false`, nothing run.
5. Run processors through `Objects::process()` so a processor error becomes
   `validation_failed` with MODX's message.
6. Output: `{ "operation": …, "performed": bool, … operation-specific keys }`; output
   schema declares `operation` and `performed`, open for the rest.
7. Description, short: maintenance operations; list them in one sentence; say that
   `empty_trash` is permanent and needs `confirm: true`, and that none of them is undoable.
   Object writes already refresh the cache, so `clear_cache` is rarely needed.

## Out of scope

Journaling these operations, flushing sessions, package operations, `run_processor`.

## Tests (required)

`tests/php/run.php`, new block after the resource blocks. Extend the fake's
`runProcessor` with the four processors, behaving as the real ones do in outline:
`resource/emptyrecyclebin` removes every `modResource` with `deleted = 1` (and their TV
values); the other three succeed and are recorded in a public list on the fake so tests can
assert they ran. Check: each operation runs the right thing; `clear_cache` increments the
fake cache counter; `empty_trash` without confirm removes nothing and reports the right
`would_remove` (trash two resources first, one with a child); with confirm removes exactly
those; plan-only token performs nothing for any operation; `develop`, `content`, `read`
tokens get `forbidden_scope`; the tool is only in an admin manifest; a failing processor
(make the fake return an error for one) surfaces as `validation_failed`.

Update tool lists/counts in both test files (admin manifest only).

## Acceptance

`npm run check`, `npm test`. Files: `src/Action/MaintenanceAction.php`, `src/Action/Registry.php`,
tests, `tests/php/FakeModx.php`.

## Report

Based on: task/06-read-log at 0b12561

- `MaintenanceAction.php` / `Registry.php`: admin-only destructive, irreversible tool;
  dispatches the specified operations through MODX/cache manager, previews trash without
  confirmation, honors plan-only for every operation and warns after confirmed purging.
- `FakeModx.php`: records maintenance processor calls, supports refusal injection, and
  removes trashed resources with TV/resource-group links and trash events in outline.
- Both test files: 29 full-manifest tools; read lists unchanged. Isolated PHP cases verify
  cache/processor selection, cascade trash preview/purge, scopes, plan-only, errors and no
  journal entries.
- Read the real MODX 2.8.8 processors and 1.x implementations before implementation.
  `npm run check` and `npm test` passed: 487 PHP checks and 24 Node/HTTP tests.

Interpretation: the preview's list key is `resources` (the brief leaves its name open).
A false cache refresh result is surfaced as validation_failed. Otherwise no deviations.
Only fake MODX was exercised: verify service-user permissions, registry lock removal,
URI regeneration, permission flushing, cache behavior and purge events on the stand.
The real purge processor may skip resources denied by delete policy; `removed` deliberately
reports the pre-run count as specified, not independently verified deletion count.
No blocking questions, site operations or push.
