# 06 — `modx_read_log`

Branch: `task/06-read-log` (stack on the previous task branch if it is not merged yet).
Spec: section 3 ("Проверка"), 7.2 step 11. 1.x reference to port:
`core/components/modxmcp/model/logreader.class.php` and `readErrorLog()` in
`model/traits/diagnostics.trait.php`.

## Goal

Let the model see new errors after it changed something:
`modx_read_log {limit?, contains?, after_offset?}` reads the MODX error log from the end,
bounded, with a cursor for "only what is new".

## Design (decided — do not change)

1. `src/Verify/LogReader.php` (namespace `ModxMCP\Verify`): port the bounded **reverse**
   reader from 1.x (never load the whole file). Pure: takes a path and limits, no MODX.
2. `src/Action/ReadLogAction.php` extends `Action`: name `read_log`, read-only, scope
   `develop` (the log holds paths and SQL), group `verify`. Registered before
   `RequestStatusAction`.
3. Log file: `$modx->getCachePath() . 'logs/error.log'`. A missing file is not an error:
   return an empty result with `size: 0`.
4. Input: `limit` (entries, default 50, 1..500); `contains` (substring, case-insensitive,
   max 200 chars); `after_offset` (integer ≥ 0, a byte offset returned by an earlier call).
5. An **entry** starts at a line beginning with `[YYYY-MM-DD HH:MM:SS]`; following lines
   that do not start that way belong to it (stack traces). Parse the MODX form
   `[time] (LEVEL @ location) message` into `time`, `level`, `location`, `message`; a
   header line that does not fit → `time` + `message` only; text before the first header
   in the window → one entry with only `message`.
6. Bounds: read at most `modxmcp.max_read_bytes` (default 262144) from the end, or from
   `after_offset` to the end if that is smaller. Each `message` is cut to 2000 characters
   (character boundary, `…`). Invalid UTF-8 is replaced, never fatal.
7. Output:
   ```json
   { "size": 81234, "end_offset": 81234, "entries": [ … newest last … ],
     "returned": 12, "truncated": false, "reset": false }
   ```
   - `end_offset` = file size when read; pass it as `after_offset` next time.
   - With `after_offset`: only entries that start at or after that byte. If
     `after_offset` is greater than the current size the log was cleared or rotated:
     `reset: true` and behave as if no offset was given.
   - `truncated: true` when the byte bound or `limit` cut older matching entries off.
   - `contains` filters whole entries; `limit` applies after filtering, keeping the newest.
8. Description, short: tail of the MODX error log, newest last; pass the returned
   `end_offset` as `after_offset` after a change to see only new errors.

## Out of scope

The audit log, other log files, clearing the log, adding `new_log_errors` to write results.

## Tests (required)

Pure tests of `LogReader` next to the other pure blocks in `tests/php/run.php` (temp
files): multi-line entries; a file larger than the byte bound (older entries cut,
`truncated`); a window that starts mid-entry (the partial entry is dropped, not shown
broken); `contains`; `limit` keeps the newest; `after_offset` returns only newer entries;
offset beyond size → `reset`; missing file; invalid UTF-8; long message cut; CRLF lines.
Action tests on the fake site: add `getCachePath()` to the fake `modX` (returns
`core_path . 'cache/'`); `develop` token works, `read` and `content` tokens get
`forbidden_scope`; the tool is absent from a read token's manifest.

Update tool lists/counts in both test files.

## Acceptance

`npm run check`, `npm test`. Files: `src/Verify/LogReader.php`, `src/Action/ReadLogAction.php`,
`src/Action/Registry.php`, tests, `tests/php/FakeModx.php` (`getCachePath` only).
Do not delete or change the 1.x `model/logreader.class.php`.
