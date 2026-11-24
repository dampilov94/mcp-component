# 51 — Log repeats, TV media sources and output properties, API pause, history by token

Branch: `task/51-small-wins` (create it from `task/50-file-lines`, stacked). Four small, separate
changes; commit each on its own. Read first: `src/Action/ReadLogAction.php`,
`src/Verify/LogReader.php`, `src/Kind/TvKind.php` (relations, `input_properties`),
`src/Manager/Overview.php`, `controllers/index.class.php`, `templates/home.tpl`, `js/home.js`,
`processors/mgr/getstatus.class.php`, `src/Manager/HistoryService.php`, `src/Journal/Journal.php`
(filters), `templates/history.tpl`, `js/history.js`, `templates/tokens.tpl`, `js/tokens.js`, and
in `modx-example-2-8-8/`: `processors/element/tv/update.class.php` (`setMediaSources`,
`setOutputProperties`) and the map of `modMediaSourceElement`.

## 1. `modx_read_log`: `dedupe`

`dedupe: true` folds entries with the same level, location and message into one entry with
`count`, `first_time`, `last_time` (order: by last occurrence, newest last). `limit` then counts
folded entries; the scan still respects `max_read_bytes`. The result says how many raw entries were
read (`raw_entries`). Default off (output unchanged).

## 2. TV: media sources and output properties

- The relation `media_sources` of `tv` becomes writable as an object `{context_key: source_id}`
  (e.g. `{"web": 2}`), replacing the rows for the given contexts only; a context not mentioned is
  left alone; `null` for a context removes its row. Validate the context and the source exist.
  Write it the way the core TV update processor does (`setMediaSources`), journaled with the TV.
  Reading shows the same object. It stays captured before a TV delete, as today.
- `output_properties` becomes a field of `tv` like `input_properties` (object, written through
  `prop_` properties as the core processor expects), so an output type's options can be set.

## 3. Panel: pause the API

A button on the component's main page: "Приостановить API" / "Включить API", which switches
`modxmcp.enabled` (confirm before pausing; explain that every client gets "modxMCP is disabled"
until it is switched back; the manager pages keep working). A processor
`mgr/settings/toggleapi` (permission `settings`), logged as a manager action. The status line
shows the state prominently.

## 4. History by token

`Journal` and `HistoryService::changesets()` get a filter `token_id`; the history page accepts
`?token_id=N` in its URL and as a filter field (shows the token label), and the changeset count on
the Tokens page becomes a link to the history of that token. `modx_history` (API) is unchanged.

## Tests (required)

`tests/php/run.php`: dedupe (counts, times, ordering, limit, unchanged default); TV media sources
per context (set, replace, remove, other contexts untouched, validation, undo, delete-and-undo
keeps them) and output properties (write, read, undo); the toggle processor's service logic (both
directions, logged) — keep the logic in a small testable service; history `token_id` filter (and
with other filters). Templates/JS: `node --check`.

## Acceptance

`npm run check`, `npm test`. Report as usual, with what needs a look in a real manager.

## Report

Based on: task/50-file-lines at b23f5c3 (branch `task/51-small-wins`, stacked on brief 50).

Counts: PHP checks 5858 -> 5908 (+50), Node tests 43 -> 43 (no change in this brief). `npm run check` and
`npm test` pass. No existing assertion was changed in this brief.

### 1. `modx_read_log` dedupe (commit "Add dedupe to modx_read_log")

- `src/Verify/LogReader.php`: `tail(..., $dedupe = false)`; `fold()` groups entries by level, location and
  message; each group keeps the first entry's fields, drops `time`, gets `count`, `first_time`, `last_time`;
  groups are ordered by their last occurrence (newest last); the `limit` is applied to the folded list;
  `raw_entries` (entries read after the `contains` filter, before folding) is added to the result.
- `src/Action/ReadLogAction.php`: `dedupe` argument and the optional `raw_entries` output; description.
- Help: `docs/getting_started.md`. Tests: "Log reader: dedupe" block (counts, times, order, limit, filter, byte
  budget, default unchanged, through the action).

### 2. TV media sources and output properties (commit "Write TV media sources per context and output properties")

- `src/Kind/TvKind.php`: `media_sources` is now a writable map relation `{context_key: source_id}` (was
  `delete_only`); `presentRelation()` shows the same object; `writeRelation()` validates every context
  (`modContext`) and source (`modMediaSource`) first, then replaces the row of each given context (null removes it);
  other contexts are untouched. `output_properties` is a field like `input_properties`; `processorData()` sends it as
  `prop_<name>` (create always sets it, update only when there is at least one, as in MODX).
- `tests/php/FakeModx.php`: `sources.modMediaSourceElement` primary key (all four columns, as in the MODX map) and
  `prop_` handling in the fake TV processors. Docs: `docs/tv_input_types.md`.
- Tests: "TV media sources per context and output properties": set on create, replace one context, add, null removes,
  same map no change, invalid context/source/type/list refused with nothing changed, dry run, undo of each write,
  journal names the relation, delete + undo (same revision), duplicate copies the rows, describe, output_properties
  write/read/replace/undo/unrelated update.

Deviation: the brief says to write it "the way the core processor does (`setMediaSources`)". I read
`element/tv/update.class.php`: it first removes **all** the TV's source rows and re-adds only the given ones, which
contradicts "a context not mentioned is left alone", and because `source` is part of the row's primary key
(`modMediaSourceElement` map: source, object_class, object, context_key are all `pk`) an existing row cannot be
updated in place. So `writeRelation()` does the processor's per-row work (remove the context's row, create a new one)
directly with xPDO for the given contexts only, inside the transaction, and the cache is refreshed by the generic
relation code. Consequences: the relation is now part of every TV's state, so the revision of a TV that has media-source
rows differs from before the change (clients re-read it), and journal entries recorded earlier have no `media_sources`
key (a TV with rows may show it as changed against such an old entry). MODX leaves the rows behind when a TV is deleted
(no composite in the TV map), so "delete + undo" in the fake is exercised by removing the rows explicitly in the test.
An empty `output_properties` map cannot clear the stored options (the processor only sets them when a `prop_` is sent);
same limitation as `input_properties`.

### 3. API pause (commit "Add a pause button for the API to the main page")

- `src/Manager/ApiSwitch.php` (new service): `set($enabled = null)` stores `modxmcp.enabled` as `1`/`0` (creates the
  system setting row if missing), reloads the config, refreshes the cache, logs the manager action
  `modxmcp_pause_api` / `modxmcp_resume_api`; asking for the state the API already has changes and logs nothing.
- `processors/mgr/settings/toggleapi.class.php` (new, permission `settings`): optional `enabled` (1/0), otherwise flips.
- `controllers/index.class.php`, `templates/home.tpl`, `assets/components/modxmcp/js/home.js`, lexicons en/ru: button
  "Приостановить API" / "Включить API" (the page sends the wanted state, so a double click cannot undo itself), a
  confirmation before pausing (`Ext.Msg.confirm`, `window.confirm` as fallback), a red banner and a status row that say
  the API is paused; the page reloads after success. Data goes into the page with textContent only (no new data is
  inserted by JS).
- Tests: "Pausing the API from the main page": both directions, log, idempotence, flip, row creation and update.

### 4. History by token (commit "Filter the history by token and link to it from the Tokens page")

- `src/Journal/Journal.php`: filter `token_id`. `src/Manager/HistoryService.php`: `changesets()` accepts `token_id`
  (positive integers only); `tokenFilter()` turns the URL parameter into `{id, label}` (a vanished token has an empty
  label). `processors/mgr/history/getlist.class.php` passes `token_id`. `controllers/history.class.php` reads `?token_id=N`
  and gives `ModxmcpHistory.token` to the page. `history.js`: a chip with the token label and a link "show all tokens"
  (the chip also disappears when the filters are reset after an undo); `tokens.js`: the changeset count is a link to
  `?a=history&namespace=modxmcp&token_id=ID` (plain `0` when there are none). `modx_history` (API) is unchanged (a test
  asserts that it still refuses a `token_id`).
- Tests: filter alone and combined (kind/object/query/status/source), pagination, empty/zero/non-numeric = no filter,
  total, the journal, `tokenFilter()` incl. rejected values.

### Verified only against the fake MODX / by syntax

Everything. Nothing in this brief ran in a real manager. Needs a look on the stand / in a real manager: the pause button
(confirm dialog, reload, banner, API answering "modxMCP is disabled" while paused and working after resume, the setting
`modxmcp.enabled` row on a site where it exists as an install-time setting), the manager-action log entries, the history
page chip and the link from the Tokens page, TV media sources on real MODX 2.8.8 (row replacement given the composite
primary key, the cache after the write, the TV edit form showing the source, delete + undo recreating the rows), and
`output_properties` of an image TV as the manager sees them.

### Open questions

- Should `modx_get` of a TV with no media-source rows show `{}` (it does now) rather than omit the relation?
- Whether the status text "Paused" should also appear in the manager's top bar (not done; outside the brief).
