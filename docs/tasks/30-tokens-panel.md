# 30 — Manager: a "Токены" page and a reworked component panel

Branch: `task/30-tokens-panel` (already created by the architect from `main`; work on it).
Spec: sections 6 ("Токены и области доступа"), 8.9 and 15 item 7. Read first: the home page
(`controllers/index.class.php`, `templates/home.tpl`, `assets/components/modxmcp/js/home.js`,
`processors/mgr/{createtoken,revoketoken,getstatus,savegroups}.class.php`), the history page
from brief 25 (`controllers/history.class.php`, `templates/history.tpl`, `js/history.js`,
`processors/mgr/history/*`, `src/Manager/HistoryService.php`) — follow its structure, styles,
lexicon handling and "textContent only" rule — and `src/Auth/TokenStore.php`, `src/Auth/Scope.php`,
`src/Capabilities.php`, `src/Journal/Journal.php`.

## Goal

Tokens get their own page where an administrator can see who uses which access, create a token
for a client with the right rights and copy a ready configuration, and revoke or adjust tokens.
The component's main page becomes an overview: is it working, what happened recently, what is
switched on, where to go.

## Design (decided — do not change)

### Token store

Add to `TokenStore` (MySQL and SQLite compatible, no schema change):

- `get($id)` — public record or null.
- `update($id, array $changes)` — only `label` (string, ≤190), `plan_only` (bool),
  `denied_groups` (string[], each must be in `Capabilities::TOGGLEABLE`), `expires_at` (unix time
  in the future, or null for no expiry). The scope and the hash never change: rights are raised
  or lowered by creating a new token. A revoked token cannot be updated. Unknown keys are refused.
- `create()` keeps its signature; validate `denied_groups` the same way.

### Service

`src/Manager/TokenService.php` (constructor `(\modX $modx, $user)`), the page's only entry into
the data, returning arrays the processors pass through:

- `tokens()` — every token, newest first: the public record plus `created_by_name` (username or
  `(deleted user #N)`, `—` for 0), `status` (`active` | `expired` | `revoked`), `last_used_ago`
  is not needed (the page formats times), and `changesets` = number of journal changesets of
  that token (one grouped query, not one per token).
- `create(array $input)` — `label` (required, trimmed, non-empty), `scope`, `plan_only`,
  `expires_days` (0 = never, max 3650), `denied_groups`; returns the record plus `token` (shown
  once) and `config`: a ready `.mcp.json` text (pretty JSON, 2 spaces) for this site:
  `{"mcpServers":{"modx":{"command":"npx","args":["-y","github:dampilov94/mcp-component"],"env":{"MODX_MCP_SITE_URL":"<site_url>assets/components/modxmcp/api.php","MODX_MCP_TOKEN":"<token>"}}}}`
  (build the URL from `site_url` and `assets_url` the way the home page builds the endpoint).
- `update($id, array $input)` — the store's `update` with the same input names as `create`
  (`expires_days` instead of a time; an empty string = keep, 0 = never).
- `revoke($id)`.
- Every write logs a manager action (`modxmcp_create_token`, `modxmcp_update_token`,
  `modxmcp_revoke_token`) with the token id — never the token value or its hash.
- Validation errors are `ApiException('validation_failed', …)`; the processors turn them into
  `failure()` as the history processors do (reuse `HistoryService::errorReport` or move it to a
  small shared helper).

### Processors

`processors/mgr/tokens/{getlist,create,update,revoke}.class.php`, thin, `settings` permission.
Remove `processors/mgr/createtoken.class.php` and `revoketoken.class.php` (their only caller,
the token block of the home page, goes away).

### Page "Токены"

`controllers/tokens.class.php` (`ModxmcpTokensManagerController`, action `tokens`),
`templates/tokens.tpl`, `assets/components/modxmcp/js/tokens.js` (plain ES5 + `MODx.Ajax`,
`textContent` for all data), lexicon `modxmcp_tokens_*` in ru and en (natural Russian), menu entry
`modxmcp_tokens` under `modxmcp` in `_build/build.transport.php` (like `modxmcp_history`).

1. A short explanation of the four scopes in plain words (one line each: what a client with that
   scope can and cannot do), and of "только план".
2. The table: label, prefix, scope (as a coloured badge), только план, denied groups, created
   (time and by whom), last used, expires, changesets (a link to the history page filtered by
   that token is not possible — the history filters by source; show the number only), status.
   Revoked and expired tokens greyed and grouped below the active ones, collapsed under
   "Показать отозванные и истёкшие (N)".
3. Per active token: "Изменить" opens an inline form under the row (label, только план, expiry,
   denied groups as checkboxes of the toggleable groups with their human labels — reuse the
   labels the home page uses for groups, move them to the lexicon); "Отозвать" with a confirm
   that names the label.
4. "Создать токен": label, scope (select with the one-line explanations), только план, expiry
   (Никогда / 30 / 90 / 365 days), denied groups (collapsed "Ограничить группы"). After creation
   a box that cannot be missed: the token, a "Скопировать" button, the ready `.mcp.json` text in a
   `<pre>` with its own "Скопировать", and the sentence that the token is shown once. The box
   stays until the person closes it or reloads; the table reloads under it.
5. Copy buttons use `navigator.clipboard.writeText` with a fallback (select the text in the
   `<pre>`) when the clipboard API is unavailable (plain-HTTP sites).

### Panel (the component's main page)

Rework `index.class.php`, `home.tpl`, `home.js`:

1. Status line: enabled or not (with how to enable), version (`Kernel::VERSION`), API endpoint
   (copyable), active tokens count with a link to "Токены" (and a red hint "Нет активных токенов —
   создайте токен, чтобы подключить клиент" when 0).
2. "Последние изменения": the five newest journal changesets (time, source badge, author,
   summary) from `HistoryService::changesets()`, plus counts for the last 24 hours by source
   (ИИ / админка / вне журнала), and a link "Вся история".
3. Navigation tiles: Токены, История, Граф связей — each with one line of explanation.
4. "Возможности" (the existing group toggles, unchanged in behaviour; labels now from the
   lexicon) and "Интеграции" (unchanged).
5. "Опасные возможности": read-only state of `modxmcp.allow_run`, `allow_run_processor`,
   `allow_query_db`, `history_track_manager`, `lint_php`, `require_https`, `allowed_ips` (empty =
   "любой адрес"), each with one plain sentence of what it means and the hint that these are
   changed in System settings (namespace modxmcp). A setting that does not exist on the site
   (file-only deployment) shows "не создана" — never crash on a missing setting.
6. The token block and its JS are removed from the home page.

`getstatus.class.php` returns the same overview data (status, counts, recent changesets, the
dangerous settings) so it stays useful; the panel itself is rendered server-side as today.

## Out of scope

Editing settings from the panel, per-token history filtering, token rotation.

## Tests (required)

`tests/php/run.php`, block "Tokens page": `TokenStore::update` (each field, unknown key refused,
scope refused, revoked refused, invalid group refused, expiry in the past refused, null clears
expiry); `TokenService::tokens` (status of each kind, `created_by_name` incl. deleted user,
`changesets` counts with tokens that have none); `create` (validation, `config` is valid JSON with
the exact structure and the URL built from the site options, the token in `config` equals the
returned token and authenticates); `update` via `expires_days` (keep / 0 / N); `revoke`; no
token value or hash appears in any manager action log the fake records. The overview data of
`getstatus` (dangerous settings incl. a missing one, recent changesets, 24h counts by source).
Templates and JS: `node --check` only; keep them readable.

## Acceptance

`npm run check`, `npm test`. Report as usual; list every UI string you added and anything that
needs a look in a real manager (layout, clipboard on the stand, which runs on plain HTTP).

## Report

Based on: main at 66e0902

### Implemented

- `src/Auth/TokenStore.php`: `get($id)`, `update($id, $changes)` (label, plan_only, denied_groups, expires_at; unknown keys, scope, hash, revoked or unknown token, bad values refused with `InvalidArgumentException`), `create()` validates `denied_groups`.
- `src/Manager/TokenService.php`: `tokens()` (status, `created_by_name`, `changesets` in one grouped query; a site without journal tables gets zeros), `create()` (+ `token`, `config`), `update()`, `revoke()`, `endpoint()`, `config()`; manager actions `modxmcp_create_token` / `_update_token` / `_revoke_token` with the token id only.
- `src/Manager/Overview.php` (new, not named in the brief): the overview data for the panel and for `getstatus` (status, version, endpoint, active tokens, five newest changesets, 24-hour counts by source, the seven dangerous settings with a missing one reported as `exists: false`).
- `processors/mgr/tokens/{getlist,create,update,revoke}.class.php`; removed `createtoken` and `revoketoken`; `getstatus` now returns the overview. Errors go through `HistoryService::errorReport` (reused, not moved).
- Page: `controllers/tokens.class.php`, `templates/tokens.tpl`, `js/tokens.js`, menu `modxmcp_tokens` (menuindex 3) in `_build/build.transport.php`.
- Panel: `controllers/index.class.php`, `templates/home.tpl`, `js/home.js` rewritten (the token block and its JS are gone; group labels come from the lexicon `modxmcp_group_<key>`).
- Lexicon ru and en: `modxmcp_tokens*`, `modxmcp_group_*`, new `modxmcp_cmp_*` keys (about 120 per language).
- Tests: block "Tokens page" in `tests/php/run.php` (66 new checks). `tests/php/FakeModx.php`: `modX::logManagerAction()` (records to `$managerActions`) and `modX::log()` also records to `$logLines`.

### Deviations and decisions

- `Overview` is an extra class; the brief names only `TokenService`.
- The endpoint is built from `site_url` and the assets URL (`modxmcp.assets_url`, else `assets_url` + `components/modxmcp/`): an `assets_url` starting with `/` is joined to the scheme and host of `site_url`, an absolute one is used as is. On a standard site it gives the same address as the old home page (`<site_url>assets/components/modxmcp/api.php`).
- `json_encode` pretty-prints with 4 spaces; `config` is re-indented to 2 spaces.
- `expires_days` outside 0..3650 or not a whole number is refused (the old processor silently clamped).
- The history link per token was not made (the brief says to show the number only). The number counts journal changesets with that `token_id`.
- The "dangerous" states are neutral: bold when on, plain when off (no red/green, since "on" is good for `lint_php`).
- Old `modxmcp_cmp_*` keys that the panel no longer uses (`history_*`, `token*`, `regenerate*`) are left in the lexicons.
- `TokenStore::insert()` still cuts a label with `substr()` at 190 bytes; the service refuses labels over 190 characters first, so it never happens through the page. Not changed.
- README, DEVELOPMENT.md, spec and CHANGELOG were not touched.

### Existing assertions

None changed or removed. The old processors had no tests.

### Verified only against the fake / needs a look in a real manager

- Smarty template `home.tpl`, the controllers, `lexicon->fetch` for the two prefixes, the connector routing to `mgr/tokens/*` and the menu entry (after a package build; on the file-by-file stand the menu item must be created by hand).
- Layout of both pages, the inline edit form under a row, the `<details>` for groups, the confirm text.
- Clipboard: `navigator.clipboard` is used only in a secure context, otherwise the text is selected and `document.execCommand('copy')` is tried; check on the plain-HTTP stand.
- How `MODx.Ajax` shows `failure()` messages (the pages show `r.message` in the form, but MODX may also show its own dialog).
- Real MODX `getOption` for a setting that does not exist returns null with a null default (relied on for "не создана"); the fake behaves so.

### UI strings added

All in `lexicon/{ru,en}/default.inc.php`: menu `modxmcp_tokens`, `modxmcp_tokens_desc`; page `modxmcp_tokens_*` (title, heading, intro, scope names and one-line explanations, plan-only text, column titles, statuses, buttons, form labels and placeholders, expiry options, group hint, error and confirm texts, result box texts); panel `modxmcp_cmp_*` (status, version, tokens, copy, recent changes, tiles, capabilities, integrations states, dangerous capabilities with one sentence per setting); group labels `modxmcp_group_*`.

### Open questions

- Should the old unused `modxmcp_cmp_*` keys be deleted?
- The dangerous list is read from options at page load; a value changed in System settings shows after a page reload (the config is not cached beyond the request).
