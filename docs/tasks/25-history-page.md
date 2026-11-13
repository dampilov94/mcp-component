# 25 — Manager page "История" (history)

Branch: `task/25-history-page`. Spec: section 8.9 ("Страница «История» в админке"), 8.4–8.6.
Patterns to follow: the graph page (`core/components/modxmcp/controllers/graph.class.php`,
`templates/graph.tpl`, `assets/components/modxmcp/js/graph.js`,
`processors/mgr/graph.class.php`, `assets/components/modxmcp/connector.php`) and the home page
(`controllers/index.class.php`, `templates/home.tpl`, `assets/components/modxmcp/js/home.js`).
Read them first; reuse their structure, styles and `MODx.Ajax` usage.

## Goal

A person with the manager permission `settings` can see everything that was changed through
the API (and recorded from outside), look at what a change did, and undo it — without an AI.

## Design (decided — do not change)

### Service (testable without the manager)

`src/Manager/HistoryService.php` with a constructor `(\modX $modx, $user)` (the manager
user object) and three methods returning arrays shaped like the API's results:

- `changesets(array $filter, $limit, $start)` — the data of `modx_history` (same keys) plus
  `total`, with filters `source`, `kind`, `id`/`key`, `tool`, `status`, `since`, `until`
  (unix times) and `query` (substring of the summary or of an object's name). Each entry also
  carries `author`: the token label for `mcp` (look the token up; `"(revoked token)"` if it is
  gone), the manager username for `manager`, `"unknown"` for `external`.
- `changeset($id)` — the data of `modx_history_diff`.
- `undo(array $selector, $confirm, $force)` — the behaviour of `modx_undo` (selectors
  `changeset_id` or `to_checkpoint`, plan without `confirm`, `undo_conflict` without `force`),
  through `Action\UndoAction` itself, not a copy.
- `checkpoints($limit)` — the checkpoints, newest first, with label, time, author and the
  number of MCP changesets after each.

The service builds a `Context` for the manager user: add a named constructor
`Context::forManager(\modX $modx, $user, $corePath)` with a token record
`{id: null, label: "manager: <username>", scope: "admin", plan_only: false, denied_groups: []}`
and a new public property `managerUserId`. When it is set, `Transaction::commit()` records the
changeset with `source: "manager"` and `user_id`, `token_id` null. Everything else (locks,
guards such as the modxMCP-settings rule, `assertUndoable`) applies unchanged.
`modx_history` (the API tool) must show such changesets with `source: "manager"` and the
username as the author (add `author` to its entries too, for all sources, same rules).

### Processors

`processors/mgr/history/getlist.class.php`, `get.class.php`, `undo.class.php`,
`checkpoints.class.php`: thin wrappers around the service; `checkPermissions()` =
`settings`; return the service's arrays with `$this->success('', …)` (getlist in the grid
form `outputArray($rows, $total)`); an `ApiException` becomes `$this->failure($message)` with
the code and details in the response object.

### Page

`controllers/history.class.php` (`ModxmcpHistoryManagerController`, action `history`),
`templates/history.tpl`, `assets/components/modxmcp/js/history.js` (plain JS + `MODx.Ajax`, as
`home.js` / `graph.js`; no new libraries), lexicon entries in `lexicon/{ru,en}/default.inc.php`
for every UI string (Russian is the main language of the users; write natural Russian).

Layout:
1. Filters: source (all / ИИ / админка / вне журнала), kind, object id or key, text search,
   date range; a "Обновить" button.
2. A table: time, source, author, tool, summary, objects (kind + name, first three and
   "+N"), status (`committed` / `undone` with a link to the undo changeset), reversible. Paging
   (50 per page).
3. Clicking a row opens the diff below the table: per object the operation and the changed
   fields; code diffs shown with `-`/`+` lines coloured; `[hidden]` values shown as such.
4. Buttons on an opened changeset: "Отменить это изменение" → calls undo without confirm,
   shows the plan (objects and actions in words: «вернуть прежнее состояние», «восстановить
   удалённое», «удалить созданное»), conflicts highlighted with their reason; "Подтвердить
   отмену" performs it; if there are conflicts, the confirm button is replaced by
   "Отменить поверх более поздних изменений" with an explicit warning, which sends `force`.
   Not reversible changesets show why instead of the button.
5. A side list of checkpoints with "Откатить до этой точки" (same plan → confirm flow,
   selector `to_checkpoint`).
6. After an undo the table reloads and shows the new changeset at the top.

Links: a block on the home page (like the graph block) and the build script registering a
menu entry `modxmcp_history` under `modxmcp` (like `modxmcp_graph` in
`_build/build.transport.php`); add both lexicon keys for the menu.

## Out of scope

Recording manager edits (next brief), editing objects from the page, exporting the journal,
retention settings UI.

## Tests (required)

`tests/php/run.php`, a new block: the service on a fake site with changesets from an MCP
token, from `external` detection and from a manager undo:
- `changesets` filters (each), paging, `total`, `author` for each source (and a revoked token);
- `changeset` equals `modx_history_diff` for the same id;
- `undo`: plan, confirm, conflict without force (`undo_conflict`), force; the resulting
  changeset has `source: "manager"`, `user_id`, `token_id` null, and shows in `modx_history`
  with the username as author; guards still apply (try to undo a recorded change of a
  modxMCP setting → refused);
- `checkpoints` with counts;
- `Context::forManager` cannot be produced from the HTTP API (it is not reachable from the
  kernel: assert the kernel never sets `managerUserId`).
JavaScript and the template have no automated tests; keep the JS small and readable, and
`node --check` it (the check script does).

## Acceptance

`npm run check`, `npm test`. Files: `src/Manager/HistoryService.php`, `src/Context.php`,
`src/Write/Transaction.php`, `src/Action/HistoryAction.php` (author), the four processors,
the controller, template, JS, lexicons, `_build/build.transport.php`, `templates/home.tpl`,
tests.
