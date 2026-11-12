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
