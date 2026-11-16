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
