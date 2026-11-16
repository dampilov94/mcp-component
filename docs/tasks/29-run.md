# 29 — modx_run: execute a snippet or render a chunk / tags

Branch: `task/29-run`. Spec: section 9.2. Read first: `src/Action/RunProcessorAction.php` (the
pattern for a gated, irreversible action with an informational changeset),
`src/Action/CheckPageAction.php` and `src/Verify/LogReader.php` (reporting new error-log lines),
and in `modx-example-2-8-8/`: `modX::runSnippet`, `modX::getChunk`, `modX::switchContext`,
`modParser::processElementTags`, and how `modResponse::outputContent` runs the two parser passes.

## Goal

After changing code, the AI can see what it produces: run a snippet with properties, render a
chunk with placeholders, or parse a string of MODX tags, in a chosen context and for a chosen
resource — and get the output together with any new error-log lines.

## Design (decided — do not change)

`src/Action/RunAction.php`, tool `modx_run`, logic in `src/Verify/Runner.php`.

- Gate: setting `modxmcp.allow_run` (combo-boolean, default 0, area `modxmcp:security`, lexicon
  in both languages); off → the tool is unavailable (`disabled_by_setting`), exactly like
  `run_processor`. Scope `develop`. Not read-only; `reversible: false`; open world.
- Input: exactly one of `snippet` (name), `chunk` (name), `text` (string with MODX tags, max
  20000 chars); `properties` (object, for snippet/chunk); `context` (context key, default `web`);
  `resource` (id; becomes `$modx->resource` and `resourceIdentifier` for the run, must exist and
  belong to that context — else `validation_failed`); `max_output` (bytes, default 20000, max
  200000).
- A plan-only token and `dry_run` do not execute: they return what would run
  (`planned`/`dry_run`), like other writes.
- Execution: switch to the context (`switchContext`), set the resource if given, start output
  buffering, run, then **always** restore the previous context, resource and buffer level in a
  `finally`. Snippet: `runSnippet`; chunk: `getChunk`; text: the parser passes MODX itself uses
  for a page (cacheable pass, then the uncacheable pass with removal of unprocessed tags
  off, so leftovers stay visible). Echoed output and the returned value are both reported:
  `output` (string; a non-string return value is JSON-encoded and `output_type` says
  `string|json|null`), `echoed` (what the code printed), `truncated`, `bytes`.
- Errors of the code under test are results, not API errors: a `\Throwable` thrown by the
  snippet returns `{ok: false, error: {type, message, file, line}}` with the file path made
  relative to the site base and the partial output kept. Missing snippet/chunk →
  `not_found`.
- `duration_ms`, and `new_log_errors` + up to 20 `log_lines` appended to the MODX error log
  during the run (same mechanism as `modx_check_page`).
- `unparsed_tags`: tags left in the output (`[[…]]`), up to 20 — the usual sign of a missing
  element.
- Journal: an informational, irreversible changeset `run snippet <name>` / `run chunk <name>` /
  `run text`, as `run_processor` does. What the code changes on the site is not tracked; say so
  in the tool description and add the warning `Changes made by the executed code are not
  journaled.` to every executed run.
- Help: `getting_started.md` gets a short "Checking the result" section covering `modx_run`
  and `modx_check_page`; `docs/index.md` lists the tool.

## Tests (required)

Extend the fake with `runSnippet` (snippet bodies as PHP closures registered by the test),
`getChunk`, a minimal parser for `[[*field]]`, `[[+placeholder]]`, `[[$chunk]]`,
`[[snippet]]`, `switchContext`, `resource`. Block "Run" in `tests/php/run.php`: gate off →
unavailable, and absent from the manifest; scope; each of the three modes; properties reach
the code; context and resource are set during the run and restored after it, also when the
code throws; echoed vs returned output; a thrown exception is a result with type/message/line
and a relative file; truncation; unparsed tags listed; new log lines reported; exactly one
informational changeset, not reversible, and `modx_undo` refuses it; plan-only token and
`dry_run` do not execute (the closure must not have been called); validation of the
one-of rule and of a resource from another context. Kernel test: the tool appears only with
the setting on. Update exact tool lists/counts where asserted.

## Acceptance

`npm run check`, `npm test`. Report as usual; list which parser calls you used and what you
could not verify without a real parser (pdoTools/Fenom sites replace the parser class).

## Report

Based on: task/28-audit-site at af2a5c0

Implemented:
- `src/Verify/Runner.php`: runs one snippet / chunk / text and reports. Switches the context, sets `$modx->resource` and `$modx->resourceIdentifier`, opens an output buffer, runs, and in a `finally` closes every buffer the code left open (kept as `echoed`) and puts context, resource and identifier back (a failed restore is reported as `restore_error`). Snippet: `$modx->runSnippet($name, $properties)`; chunk: `$modx->getChunk($name, $properties)`; text: `$modx->getParser()` then `$modx->parser->processElementTags('', $text, false, false, '[[', ']]', [], parser_max_iterations)` and the same with `true, false` (the cacheable pass, then the uncacheable pass without removal of unprocessed tags; the third pass of `modResource::parseContent`, which removes them, is deliberately left out). A `\Throwable` becomes `ok: false` with `error {type, message, file, line}`: the file is relative to `base_path` (`[core]/...` for the core path, the bare file name for anything else) and absolute site paths are stripped from the message. Output is made valid UTF-8, cut by bytes on a character boundary (`mb_strcut`), `bytes` is the real size, `truncated` covers output and echoed. A non-string return value is JSON-encoded with `output_type` `json` (`string|json|null`). `unparsed_tags` (up to 20, real count in `unparsed_count`), `duration_ms`, `new_log_errors` (real count of lines appended to the error log during the run) and `log_lines` (the first 20 of them), read with `LogReader` like `modx_check_page`.
- `src/Action/RunAction.php`: `modx_run`; scope `develop`, group `verify`, `reversible: false`, open world, gated by `modxmcp.allow_run` through `unavailableReason()` (`disabled_by_setting`). Checks, in order: exactly one of snippet/chunk/text; `properties` not with text; context exists; resource exists and has that `context_key`; snippet/chunk exists (`not_found`). `dry_run` and a plan-only token return `performed: false`, `would_run`, the warning, and run nothing. Otherwise the run is inside `Writer::run` with an informational changeset `run snippet <name>` / `run chunk <name>` / `run text` (suffix ` (failed)` when the code threw), lock `run`, and the warning `Changes made by the executed code are not journaled.` is added to every executed run. Registered after `AuditSiteAction`.
- Setting `modxmcp.allow_run` (default 0, `combo-boolean`, `modxmcp:security`) in `_build/data/transport.settings.php` and both lexicons (`setting_modxmcp.allow_run`, `_desc`).
- Help: `docs/getting_started.md` ("Checking the result": `modx_run` paragraph next to `modx_check_page`), `docs/index.md`.
- Tests: `tests/php/FakeModx.php` gains `FakeContext`, `FakeParser` (`[[*field]]`, `[[+ph]]`, `[[$chunk? &a=`b`]]`, `[[snippet? &a=`b`]]`, `[[!...]]` only in an uncacheable pass, unresolved tags stay, a record of passes), and on `modX`: `context` (default `mgr`, like the manager session the recorder tests assume), `resource`, `resourceIdentifier`, `placeholders`, `snippetRunners` (closures registered by the test), `runCalls`, `switchContext`, `getParser`, `runSnippet` (the snippet buffer joins echo and return value like `modSnippet::process`, a throwing snippet leaves its buffer open), `getChunk`. `tests/php/run.php` block "Run" (56 checks); `tests/php/endpoint.php` reads `MODXMCP_TEST_ALLOW_RUN` (sets the setting and stores the `mgr`/`web` contexts); `tests/endpoint.test.js` has one new test: tool absent and `unavailable`/`disabled_by_setting` with the setting off; present after `modx_audit_site`, effect flags, `develop` scope, read token refused, a text run with a warning and an unparsed tag, one-of validation, with it on.

Counts: `npm run check` passes before and after. PHP checks 4800 -> 4856; node tests 31 -> 32 (all pass). No existing assertion changed: with the setting off (the default) the tool is not in any manifest, so no tool list or count moved.

Deviations / readings:
- `destructive: true` in the tool's effect (the brief names read-only false, reversible false, open world only). Code that really executes can delete data; the flag is what clients use to ask for confirmation. Say so if it should stay false.
- `echoed` cannot hold what a snippet prints: MODX's `modSnippet::process()` has its own `ob_start()` and returns `echo . return value` joined (and `runSnippet()` returns that), so inside `output` the two are indistinguishable. `echoed` is therefore what escaped MODX's own buffer: text left in buffers an exception or an unbalanced `ob_start()` left open, or printed by a plugin during the run. The tool description and `getting_started.md` say this. A test shows echo-and-return joined in `output` with `echoed` empty, and the partial output of a throwing snippet in `echoed`.
- `unparsed_tags` entries are `{tag, line}` (the `modx_check_page` shape), not bare strings, plus `unparsed_count`.
- `new_log_errors` is the number of new log entries (any level), not only ERROR ones, as `check_page` reports `new_entries`.
- Unknown `context` is `validation_failed`; the brief names only `not_found` for a missing snippet/chunk.
- `properties` together with `text` is `validation_failed`.
- Text mode is parsed once with the two passes; chunk and snippet modes get only what `getChunk`/`runSnippet` do, so a `[[!uncacheable]]` tag inside a chunk's own output stays unprocessed (they are not run through the second pass).
- Fix to the previous branch's test (listed as the rules ask): the placeholder resource row in the "Open in the manager" block of `tests/php/run.php` had no `content`, which made the fake print three "Undefined index: content" notices; it now has `content => ''`.

Parser calls used (all from `modx-example-2-8-8/core/model/modx/`): `modX::switchContext`, `modX::getParser`, `modX::runSnippet`, `modX::getChunk`, `modParser::processElementTags` with the same arguments and `parser_max_iterations` that `modResource::parseContent()` uses for its first two passes. Context validity is checked beforehand with `getObject('modContext')`, because `modX::_initContext()` leaves `$modx->context` null for an unknown key.

Verified only against the fake (check on the stand): everything about the real parser. The fake parser is a few lines and does not model: output filters and modifiers (`:default`, `:ifempty`, ...), property sets (`@set`), `@INLINE`/`@FILE` bindings, `[[*tv]]` template variables, `[[++setting]]`, lexicon `[[%x]]`, `[[~id]]` link tags, snippet caching, the real `modSnippet`/`modChunk` property merge, and `OnParseDocument`. On a site where pdoTools/Fenom replace the parser class (`pdoParser`), `processElementTags` and `getChunk` behave differently (Fenom syntax `{...}`, `pdoTools` chunk prefixes `@INLINE`, `@FILE`); a text run there needs checking, as does the context switch (real `switchContext` reloads the user, calls `setPlaceholders($config, '+')` and leaves placeholders of the other context's config set after the restore, which this tool does not clean). Also unverified: `mb_strcut` on real output, the error-log line format of a real run, that `include` errors of a real snippet (`ParseError`, `Error`) are caught as `\Throwable` while PHP fatal errors cannot be, and that the recorder stays silent for changes made by executed code (it should: the run is inside a transaction, `Transaction::depth() > 0`).

Open questions: is `destructive: true` wanted? Should chunk output get the uncacheable pass too? Should `modx_run` be allowed in the `mgr` context (it currently is, as any existing context)?
