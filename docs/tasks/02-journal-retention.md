# 02 — Journal retention

Branch: `task/02-journal-retention`. Spec: section 8.8 ("Хранение"), 8.1 (tables), 8.2 (blob store).

## Goal

The journal must not grow without bound. Old changesets and the snapshots only they refer
to are removed automatically, by age and by total size.

## Design (decided — do not change)

1. Settings (add to `_build/data/transport.settings.php`, area `modxmcp:limits`, and to
   both `lexicon/*/setting.inc.php` with a name and a description):
   - `modxmcp.history_retention_days`, default `30`; `0` = keep forever.
   - `modxmcp.history_max_bytes`, default `209715200` (200 MB); `0` = no size limit.
2. `Journal\BlobStore`: add
   - `each()` — a generator yielding `[hash, size in bytes, mtime]` for every stored blob
     (walk the two-level directory; ignore anything that is not `<64 hex>.php`);
   - `delete($hash)` — remove one blob, returning whether it existed. Remove the two-letter
     directory when it becomes empty.
3. `Journal\Journal`: add
   - `oldestChangesetIds($olderThan, $limit)` — ids of changesets with `created_at <
     $olderThan`, oldest first;
   - `deleteChangesets(array $ids)` — delete those changesets and their changes; delete
     checkpoints whose `last_changeset_id` is lower than the smallest remaining changeset id
     **and** that are older than the retention cut-off;
   - `referencedBlobs()` — every distinct non-null `before_revision`, `after_revision` and
     `extra_blob` still present in the changes table, as a set (array keyed by hash).
   SQL must run on MySQL and SQLite (no `DELETE … JOIN`, no `LIMIT` in `DELETE`; use
   `WHERE id IN (…)` with integers you built yourself).
4. New class `Journal\Retention` with one public method `run(Context $context, $now = null)`
   returning `['changesets' => n, 'blobs' => n, 'bytes' => n]` (what was removed):
   1. By age: if retention days > 0, delete changesets older than `now - days*86400`, in
      batches of 500, until none are left.
   2. By size: if max bytes > 0, sum blob sizes; while the total is over the limit and any
      changeset exists, delete the 100 oldest changesets and garbage-collect (step 3),
      recomputing the total. Never delete the newest changeset.
   3. Garbage-collect blobs: delete every blob whose hash is not in
      `Journal::referencedBlobs()` **and** whose mtime is older than 1 hour (a blob written
      by a transaction that has not committed its rows yet must survive).
   Deleting a changeset that others refer to through `undoes` / `undone_by` is fine: those
   columns are informational.
5. Trigger: at the end of `Write\Transaction::commit()` when a changeset was written, call
   a private `maybePrune()` that
   - runs at most once per hour, using a stamp file `prune.stamp` in `$context->dataDir()`
     (touch it before running) and a non-blocking `flock` on `prune.lock` there, the same
     pattern as `RequestStore::cleanup()`;
   - catches every `\Throwable`, logs it with `modX::LOG_LEVEL_ERROR`, and never lets
     retention fail or slow the write beyond its own work.
6. `modx_history` and `modx_undo` already cope with missing blobs (`not_reversible`, "stored
   states … not available"); do not change them.

## Out of scope

A manual "prune now" tool, a manager button, retention of the request store, any change to
what is journaled.

## Tests (required)

In `tests/php/run.php`, a new block at the end (before "Kernel helpers"), on a **fresh**
fake site directory so existing checks are not disturbed (`fakeSite()` + a new `$actor`-like
context; copy the few lines that build them):

- create 3 changesets; set `created_at` of the first two to 40 days ago with a direct
  SQLite `UPDATE` through `$site->pdo`; `Retention::run()` with 30 days removes exactly
  those two, their change rows, and only the blobs no remaining change refers to (make the
  blobs old enough with `touch()`); the third changeset still shows in `modx_history` and
  `modx_history_diff` still works for it.
- a blob shared by a deleted and a kept change survives.
- a fresh unreferenced blob (mtime now) survives; the same blob with an old mtime is
  removed.
- size limit: with `history_max_bytes` set below the current total and retention days 0,
  the oldest changesets go first and the newest changeset always remains.
- `0` / `0` removes nothing.
- the hourly trigger: after a write with an old or missing stamp, pruning ran (stamp is
  fresh); with a fresh stamp it did not run (plant an expired changeset and check it is
  still there). A `Retention` failure (e.g. make the history directory unreadable, or
  inject a failing BlobStore through a seam you add) does not fail the write.
- `BlobStore::each()` / `delete()` unit checks next to the existing "blobs:" checks.

## Acceptance

`npm run check` and `npm test` pass. Changes limited to `src/Journal/BlobStore.php`,
`src/Journal/Journal.php`, new `src/Journal/Retention.php`, `src/Write/Transaction.php`,
`_build/data/transport.settings.php`, the two lexicon setting files, and
`tests/php/run.php`.

## Report

Implemented on `task/02-journal-retention`, based on updated `main` (`fcce907`). Read the
task 01 Review and compared the architect's changes after `fee7e88` before starting.

- `_build/data/transport.settings.php` and `lexicon/en/setting.inc.php`,
  `lexicon/ru/setting.inc.php`: added the two limits with the specified defaults, area,
  names and descriptions of their zero values.
- `src/Journal/BlobStore.php`: added two-level blob enumeration (hash, physical file size
  including the PHP guard, mtime) and deletion with empty-shard cleanup. Enumeration
  ignores temporary files, misplaced names and symlinks; filesystem errors are reported.
- `src/Journal/Journal.php`: added oldest-id batches, deletion of changes/changesets and
  obsolete checkpoints, and a distinct set of before/after/extra blob references.
- `src/Journal/Retention.php`: age batches of 500, size batches of 100 preserving the
  highest changeset id, and unreferenced blob collection with a strict one-hour grace
  period. Returns actual removed changeset/blob/byte counts; zero/zero disables all cleanup.
- `src/Write/Transaction.php`: after a journaled commit, attempts hourly pruning under a
  non-blocking lock and touches the stamp before running. Cleanup errors are logged and
  cannot roll back the completed write, even if logging itself throws.
- `tests/php/run.php`: blob unit checks and isolated retention fixtures cover age and size
  batches, shared snapshots and extra blobs, history/diff after cleanup, checkpoints,
  boundary times, disabled limits, newest preservation, stamp/lock behavior, no-op commits,
  and an injected SQLite deletion failure that leaves the successful write intact.

Validation: `npm run check` passed (12 JavaScript files, 110 PHP files on PHP 7.4.33,
release metadata). `npm test` passed: 375 PHP checks and 24 Node/HTTP tests, no skips.
`git diff --check` passed. No deployment, stand scripts, push or release operations.

Interpretations / deviations:

- `deleteChangesets(array $ids, $olderThan = null)` has an optional second argument because
  checkpoint deletion needs the retention cut-off and `Retention::run()` accepts a supplied
  clock. The one-argument form derives the cut-off from settings and current time. The
  method returns the number of changesets actually deleted for the removal counters.
- When no changesets remain, all checkpoints older than the cut-off are obsolete and are
  removed; fresh checkpoints survive. With age retention disabled, checkpoints are kept.
- The newest-changeset exception applies to the size phase, where the brief places it;
  the age phase can expire every changeset. Oldest ordering is `created_at`, then `id`.
- Failure isolation is tested with a SQLite trigger that rejects deletion, without adding
  a production injection seam or changing FakeModx. This report is the only changed file
  outside the acceptance list, as requested by the executor workflow.

Only verified with fake MODX and real local SQLite/filesystem operations. The architect
should verify MySQL deletion/count behavior, installed settings and lexicons, MODX error
logging and continued writes on cleanup failure, and stamp/flock behavior under concurrent
PHP workers on the stand. The existing history and undo actions were not changed.

Open questions: confirm the empty-journal checkpoint interpretation above. The specified
mtime grace protects newly written blobs; the unchanged `BlobStore::put()` reuses existing
blobs without refreshing mtime, so concurrent reuse of an old unreferenced blob is not
covered by that grace. This concurrency policy was not expanded beyond the decided design.
