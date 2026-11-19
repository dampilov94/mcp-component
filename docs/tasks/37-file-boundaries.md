# 37 — File boundaries and static file cleanup

Branch `task/37-file-boundaries` from `main` at `7b654c7`. This brief covers file protection from
review item 6 and the remaining MODX-managed static cleanup from brief 35. Keep the action schemas
and existing read access. Other review findings belong in separate briefs.

- Apply a common write policy to media sources and static files. Protect modxMCP's code and data,
  MODX's own code, and server configuration files. The `content` scope cannot write in installed
  component directories, even through a media source. Check both the path as supplied and its
  resolved location.
- Apply the policy to writes, both sides of moves, directory deletes, and restores. Check empty
  directories during recursive operations as well as the files they contain.
- Suppress MODX's automatic deletion of the old static file during component-driven saves and
  restores. Keep it on disk because the element snapshot alone cannot account for that separate
  file. Preserve the MODX setting outside these calls and keep journaled cleanup of managed
  files for element deletion.
- Cover the policy with disposable filesystem fixtures, a simulated MODX automatic deletion,
  and an undo through the normal action. Run `npm run check` and `npm test` on Linux/WSL.

## Report

Based on: main at `7b654c7`.

- `Files/Path.php` holds the path normalisation already used by static elements. `WritePathPolicy`
  supplies the protected write locations and server configuration names to both file and static
  operations. `FileRoot` checks the supplied and resolved path, refuses link aliases for writes and
  checks both sides of directory moves, including empty subdirectories. `FileSubject` applies
  the same protected paths when restoring a snapshot.
- `StaticFiles::withoutNativeCleanup()` temporarily disables native old-file removal for the
  element class during an update or a journal restore. On restore, the snapshot owns the file
  write and MODX setup mode keeps the row save from touching it again. An old file stays on disk
  even if it falls within the write roots, since its removal would be outside the snapshot.
- Added offline coverage for component paths, recursive changes, a protected file behind a
  journal path, and keeping both files during a static path change and its undo. The fake uses
  the same request-local `getOption`/`setOption` pattern as xPDO and simulates native cleanup of
  an existing absolute path. `npm run check` and `npm test` passed under WSL with PHP 7.4.33
  (5433 PHP checks, 37 Node tests).
- On the stand, verify that the native processor honours the option during a path change and
  undo using disposable static-element fixtures. The offline fake only simulates that removal.
