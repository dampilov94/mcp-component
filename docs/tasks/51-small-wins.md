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
