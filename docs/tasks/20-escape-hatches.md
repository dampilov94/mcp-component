# 20 — `modx_run_processor` and `modx_query_db`

Branch: `task/20-escape-hatches` (stack on `task/19-packages` if it is not merged yet).
Spec: sections 3 ("Служебные"), 6 (both off by default), 8.7 (irreversible).
1.x reference: `runProcessorPassthrough()` in
`core/components/modxmcp/model/traits/diagnostics.trait.php`.

## Goal

Two escape hatches for what the dedicated tools do not cover, both disabled unless the
administrator turns them on.

## Design (decided — do not change)

### `modx_run_processor {processor, properties?, processors_path?, confirm?}`

- Setting `modxmcp.allow_run_processor` (exists, default 0). Off → the tool is absent from
  the manifest and its call is `unavailable` with reason `disabled_by_setting`
  (`Action::unavailableReason()`).
- Scope `admin`, group `maintenance`, `readOnly()` false, `destructive()` true,
  `reversible()` false.
- `processor`: `^[a-z0-9_]+(/[a-z0-9_]+)*$` (lowercase path, no `..`, no leading slash).
  `processors_path`: optional; must resolve (realpath) inside
  `<core_path>components/<namespace>/processors/` of an installed namespace — anything else
  `validation_failed`. Without it the core processors are used.
- Refuse any processor under `security/user/`, `security/access/`, `security/group/`,
  `security/role/`, `system/settings/`, `context/setting/`, `workspace/packages/`,
  `workspace/providers/`, `system/config`, `security/login`, `security/logout`, and
  everything whose path ends in `/remove`, `/delete` or `/purge` unless `confirm: true` is
  given. The refusal for the first group names the dedicated tool/kind to use instead.
  (Reason: those processors bypass the API's safety rules — modxMCP's own settings, the
  Administrator group, the service user.)
- Without `confirm: true`, or for a plan-only token: return `performed: false`, the
  resolved processor file path relative to the site root, and the warning that the call is
  not journaled and cannot be undone.
- With `confirm: true`: run it; return `performed: true`, `success`, `message`, `object`
  (the processor's object, or its list for get-list processors, bounded by the response
  limit — the kernel already enforces `max_response_bytes`), `errors` (field errors).
  A processor error is **not** a tool error here: `success: false` with the messages.
- Record one changeset without changes (task 19's mechanism): summary
  `run_processor <processor>`, `reversible` false. Append the error-log entries written
  during the call, like `modx_install_package` does.

### `modx_query_db {sql, params?, limit?}`

- New setting `modxmcp.allow_query_db` (default 0, area `modxmcp:security`, lexicon
  entries in both languages). Off → unavailable as above.
- Scope `develop`, group `maintenance`, read-only.
- Only one statement, and only `SELECT` (or `WITH … SELECT`): strip comments, refuse `;`
  outside string literals, refuse statements that do not start with `SELECT`/`WITH`
  (case-insensitive, after whitespace), refuse `INTO OUTFILE`, `INTO DUMPFILE`,
  `LOAD_FILE(`, `FOR UPDATE`, `LOCK IN SHARE MODE`, `SLEEP(`, `BENCHMARK(`, `GET_LOCK(`
  anywhere outside string literals. This is a guard against accidents, not the security
  boundary: also run the statement inside a read-only transaction
  (`SET SESSION TRANSACTION READ ONLY` + `START TRANSACTION` … `ROLLBACK`, restoring the
  session to read-write afterwards) and set `max_execution_time` to 10000 ms for the
  statement where the server supports it (MySQL ≥ 5.7.8: the optimizer hint
  `/*+ MAX_EXECUTION_TIME(10000) */` after `SELECT`). On SQLite (tests) skip what does not
  exist.
- `{prefix}` in the SQL is replaced by the table prefix.
- `params`: positional values (strings, numbers, booleans, null) bound with `?`.
- `limit`: rows returned, default 100, max 1000; fetch at most `limit + 1` rows to report
  `truncated`.
- Result: `columns` (names), `rows` (lists of values in column order), `returned`,
  `truncated`, `time_ms`. Values longer than 2000 characters are cut with `…`; binary
  values (invalid UTF-8) become `"[binary N bytes]"`.
- **Never return credentials:** columns of the tables `modx_users` (`password`,
  `cachepwd`, `salt`, `hash_class`, `session_stale`, `remote_key`, `remote_data`),
  `modx_user_attributes` (`sessionid`), the component's own `modxmcp_tokens` (`hash`), and
  values of `modx_system_settings` / `modx_context_settings` rows that are secret by the
  rules of task 16 are replaced by `[hidden]` when the token is not `admin`. Since a query
  can alias anything, implement this conservatively: if the SQL references (as a word,
  case-insensitive, with or without the prefix and backticks) any of those tables and the
  token is not `admin`, refuse the query (`forbidden_scope`) — do not try to parse which
  columns it selects. For `admin`, return values as they are except the `modxmcp_tokens.hash`
  table, which is refused for everyone.
- Errors from the database → `validation_failed` with the driver message (no stack).

## Out of scope

Writing SQL, multiple statements, schema changes, exporting data.

## Tests (required)

- run_processor: off by default (absent from manifest, `unavailable`); on: scope `admin`
  only; path pattern; `processors_path` inside/outside a namespace (use a temp dir and a
  fake namespace); every refused prefix names the alternative; `/remove` without and with
  confirm; plan without confirm; success and processor error; changeset recorded
  (`reversible` false) and skipped by `modx_undo last`; log entries reported.
- query_db: off by default; on: `develop` allowed, `content`/`read` refused; SELECT and
  WITH work on SQLite; `{prefix}`; params; limit/truncated; long and binary values; every
  refused construct (`;` second statement, `/* */` and `--` comments hiding them, string
  literals containing `;` or `INTO OUTFILE` are fine); credential tables refused for
  `develop` (with prefix, without, with backticks, uppercase), `modxmcp_tokens` refused
  for `admin` too; database error → `validation_failed`.

Update tool lists/counts in both test files (both tools are absent unless enabled — test
the manifest with the settings on and off).

## Acceptance

`npm run check`, `npm test`. Files: `src/Action/RunProcessorAction.php`,
`src/Action/QueryDbAction.php`, `src/Action/Registry.php`, `_build/data/transport.settings.php`,
the two lexicon setting files, tests, `tests/php/FakeModx.php`.

## Report

Based on: main at f25bafd

Counts: before, `npm run check` OK (146 PHP files, 18 JS files), PHP 2235 checks, Node 25 tests. After: check OK (148 PHP files), PHP 3679 checks, Node 27 tests (all pass). No existing assertion changed: both tools are absent unless their setting is on, so every existing tool count (34 for admin, 18 for read) stays; the new counts (36) are asserted only with the settings on.

Implemented, file by file:

- `src/Action/RunProcessorAction.php` (new): `modx_run_processor`, a `WriteAction` (scope `admin`, group `maintenance`, destructive, irreversible), gated by `modxmcp.allow_run_processor` through `unavailableReason()` (`disabled_by_setting`). Order of checks: path pattern (re-checked with `\z` in `run()`, because the schema validator's `$` accepts a trailing newline), refusal list, `processors_path`, processor file exists (class file first, then `.php`, as `modX::runProcessor` looks; a file that is a symlink out of the directory is not found), destructive name without `confirm: true`, then plan or run. The run goes through `Writer::run` with an informational changeset (`run_processor <processor>`, `... (failed)` when `success` is false), takes the lock `processor:run`, captures and discards stray output, and appends the error-log entries written during the call (`PackageKind::logOffset()` / `logSince()`). A processor error, a missing response and an exception inside a processor are all `success: false` results with the message (exception: class and message, no stack). `object` is the processor object or, for get-list processors (JSON body with `results`), the list plus `total`; it is made valid UTF-8 and cut to half of `modxmcp.max_response_bytes` (whole list items first), `truncated: true` and a warning.
- `src/Action/QueryDbAction.php` (new): `modx_query_db`, scope `develop`, group `maintenance`, read-only, gated by `modxmcp.allow_query_db`. `tokens()` is a small SQL tokenizer (strings, backtick and double-quoted names, `--`/`#`/block comments), `guard()` the checks, `run()` the execution. Output as in the brief; additionally the rows are bounded by half of `max_response_bytes` (then `truncated: true`).
- `src/Action/Registry.php`: both actions registered after `uninstall_package`.
- `_build/data/transport.settings.php`, `lexicon/en|ru/setting.inc.php`: setting `modxmcp.allow_query_db` (0, `combo-boolean`, `modxmcp:security`) with names and descriptions in both languages.
- Tests: `tests/php/FakeModx.php` (`runProcessor()` takes the options argument and runs registered stub processors that record `(path, data, options)`; `FakeResponse::getFieldErrors()`), `tests/php/run.php` (section "Escape hatches", 1444 checks), `tests/php/endpoint.php` (env `MODXMCP_TEST_ALLOW_ESCAPE` turns both settings on), `tests/endpoint.test.js` (off by default: `unavailable` with reason `disabled_by_setting`; on: 36 tools for admin, read token unchanged, a few real calls).

run_processor: where I refuse more than the brief

- The whole `security/` tree (the brief lists user, access, group, role, login, logout), with the specific alternative for the listed ones and a general message for `security/profile`, `message`, `forgotpassword`, `forms`, and also `security/resourcegroup` and `security/documentgroup` (alternative: kinds `resource_group` and `resourcegroup_access`).
- `browser/` (file and directory processors write files around the file tools' guards). Alternative named: `modx_list_files`, `modx_read_file`, `modx_write_file`, `modx_move_path`, `modx_delete_path`.
- All prefixes are plain string prefixes, so `system/configuration/x` and `securityx/y` are refused too.
- "Destructive" is any path segment that starts with `remove`, `delete`, `purge`, `empty`, `clear`, `flush`, `truncate`, `uninstall`, `reset`, `wipe`, `destroy` or `drop` (so `resource/emptyrecyclebin`, `removemultiple`, `system/errorlog/clear`), not only a last segment `remove`/`delete`/`purge`.
- `processors_path` must be absolute (relative paths are `validation_failed`), is resolved with `realpath()` and must be inside `<core_path>components/<namespace>/processors/` of a namespace that exists in the database; namespace `modxmcp` is excluded; names that are not `[A-Za-z0-9_-]+` are skipped. The path is passed to MODX in its resolved form.
- The refusal list applies also inside a `processors_path` (a component's own `security/user/` is refused).

query_db: where I refuse more than the brief

- Whole-statement rules on tokens: any unquoted `INTO` (so also `INTO @var`), `INSERT`, `UPDATE`, `DELETE`, `LOCK`, `REPLACE` not followed by `(`, `FOR SHARE`, `FOR UPDATE`, and a list of other verbs (`DROP`, `ALTER`, `CREATE`, `GRANT`, `LOAD`, `CALL`, `PRAGMA`, ...). Side-effect functions are refused quoted or not and with any spacing or comment before `(`: `LOAD_FILE`, `SLEEP`, `BENCHMARK`, `GET_LOCK`, `RELEASE_LOCK`, `RELEASE_ALL_LOCKS`, `IS_FREE_LOCK`, `IS_USED_LOCK`, `MASTER_POS_WAIT`, `SOURCE_POS_WAIT`, `WAIT_FOR_EXECUTED_GTID_SET`, `WAIT_UNTIL_SQL_THREAD_AFTER_GTIDS`, `LOAD_EXTENSION`, `READFILE`, `WRITEFILE`, `PG_SLEEP`. The price: a column or alias with one of those names unquoted is refused.
- Executable comments `/*! */`, `/*M! */` and any optimizer hint `/*+ */` are refused. A `WITH` statement must also have a top-level `SELECT`.
- The guard runs twice, with and without backslash escapes in string literals (the SQL mode is unknown), and refuses what either reading refuses. `--` counts as a comment only when followed by whitespace (MySQL rule); otherwise it is read as code.
- Names are checked in every word, backtick name and double-quoted text (a double-quoted text can be an identifier under ANSI_QUOTES), also in dotted parts, case-folded, with the real table prefix, no prefix and `modx_`.
- For tokens below admin, besides the brief's tables I also refuse `user_settings`, `transport_providers` (`api_key`), `session` (session ids) and `media_sources` (properties may hold keys), and the schema `information_schema`. For everyone, besides `modxmcp_tokens`: the schemas `mysql`, `performance_schema` and `sys`. The refusal code for all of these is `forbidden_scope`. A bare word like `users` (a column alias named `users`) is refused too.
- One trailing `;` (followed only by whitespace or comments) is accepted, because models add it; any other `;` outside a string is refused (the brief says to refuse `;` outright, so tell me if a trailing one should be refused as well).
- `{prefix}` is replaced before the checks; a table prefix with characters other than `[A-Za-z0-9_]` makes the tool refuse to run.
- The number of `?` placeholders must equal the number of params (`validation_failed`), because MySQL and SQLite behave differently when they do not.

Deviations and conservative readings:

- Timeout: the brief names the optimizer hint `/*+ MAX_EXECUTION_TIME(10000) */`. I use `SET SESSION max_execution_time = 10000` (value read first, restored in `finally`) for both `SELECT` and `WITH`, because the hint belongs after the outermost `SELECT`, which is hard to find in a `WITH` statement, and user hints are refused anyway. It is skipped silently when the server does not know the variable.
- On MySQL the rows are read unbuffered (`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` off, restored afterwards) so that `limit + 1` rows are read without holding a huge result in memory. This is not in the brief.
- `run_processor` without `confirm` for a destructive name is refused (`validation_failed`), not turned into a plan, as the brief says "refuse ... unless confirm: true". Every other processor without `confirm` returns the plan.
- For `run_processor`, exceptions thrown inside a processor become `success: false`, not a tool error, and still leave a changeset.
- Output of `run_processor` has two extra fields: `file` also on the run result, and `total`/`truncated` when they apply.
- Numbers come back as the PDO driver returns them (strings with emulated prepares on MySQL and on SQLite before PHP 8.1, so the tests compare cells loosely).
- I did not touch the existing `allow_run_processor` description text.

MODX source vs brief:

- `modX::runProcessor()` appends the given `processors_path` to the processor path without any check and only removes `..` from the action with a regex, so the brief's lowercase pattern (no dots at all) and `realpath()` checks are what protect it. It also tolerates `security//user/x` (the file system collapses `//`), which the pattern refuses.
- `modProcessorResponse::getResponse()` is the response array (not always a JSON string); I handle both. Field errors are objects with `field` and `message` (`getFieldErrors()`), and only filled when the response is an error.
- `modX::runProcessor()` returns `''`/null when the processor file does not exist; I check for the file first and answer `not_found`.
- xPDO connections are normally in silent error mode: failures come back as false and the message is in `errorInfo()`; the query tool reads it from there (also for `bindValue`).

Verified only against the fake MODX / SQLite (please check on the stand):

- Everything MySQL-specific in `QueryDbAction::run()`: `SET SESSION TRANSACTION READ ONLY` + `START TRANSACTION` ... `ROLLBACK` + `READ WRITE` restore, `max_execution_time`, unbuffered reads, `getColumnMeta()`, binary values, and that a write that slips through the guard really fails in the read-only transaction (try e.g. a `SELECT` of a function that writes, or temporarily trust nothing: the guard test list is all offline). On SQLite those steps are skipped.
- That `runProcessor()` with a real core processor (a get-list, a get, a failing one) gives the response shapes assumed here (`getObject()`, the `results`/`total` of get-list, field errors), that custom `processors_path` runs, and that the processors run under the service user from an API request.
- That `core_path . 'components/<namespace>/processors'` is where installed add-ons keep processors on the stand, and that `processors_path` as a config option is the core processors directory.
- Symlink checks (they depend on the file system; the test skips them when symlinks cannot be created).

Open questions:

- Admin tokens receive the real values of `modx_users` (password hashes, `cachepwd`, `salt`) from `modx_query_db`, as the brief decided. Should `password`/`cachepwd`/`salt` stay unreachable even for admin (they could be refused by name for everyone, as `modxmcp_tokens` is)?
- Should a processor that is not under any refused prefix but writes files (`system/import`, `system/phpthumb`) also be refused? I left them.
- A component's own processor can itself call `$modx->runProcessor('security/user/update')`; that cannot be guarded here, so `processors_path` is as trusted as the installed add-ons.
- `docs/getting_started.md` (built-in help) does not mention the two tools or the new setting; left for the documentation pass.

## Review

Accepted and merged. Verified on the stand with `scripts/stand/check-escape.cjs` after the
owner turned both settings on in the manager (`modxmcp.allow_query_db` had to be created by
hand: the stand is updated over FTP, not by reinstalling the package): `query_db` with
params and `WITH` on MySQL; writes, a second statement, `INTO OUTFILE`, the password and
token tables, `mysql.*` and `SLEEP` refused; a write through the API still works afterwards.
`run_processor`: refusals name the dedicated tool, plan without confirm, a real getlist, a
processor error as `success: false`, the call in the history as irreversible. With the
settings off both tools are absent and `modx_capabilities` says why.

The wider refusals in the report are accepted. Changes by the architect:

1. **Password hashes for admin tokens.** The brief let an admin read `modx_users` (with
   `password`, `cachepwd`, `salt`) through `query_db`. No other tool returns those values,
   so the escape hatch must not either: `users`, `session` and `modxmcp_tokens` are now
   refused for every token. The existing test that read hashes was replaced by refusal checks.
2. `run_processor` refuses `system/import` (it creates resources outside the journal).

Open questions answered: a trailing `;` stays accepted; `system/phpthumb` stays allowed;
an add-on processor calling core processors itself cannot be guarded — `run_processor`
stays off by default for that reason.
