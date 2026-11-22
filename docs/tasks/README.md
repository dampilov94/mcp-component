# Task briefs for the executor

One brief = one branch = one reviewable change. The architect writes the briefs and merges;
the executor implements them. Rules for both roles are in `AGENTS.md` ("Two-agent workflow").

## Executor: how to do a task

1. Read `AGENTS.md` in full, then the brief. Read the code the brief points to before
   writing anything. `docs/spec-2.0.md` is the specification (in Russian); the brief names
   the sections that matter.
2. Create the branch named in the brief from the current `main`:
   `git switch main && git pull --ff-only && git switch -c task/NN-name`.
3. Implement only what the brief asks. If something outside its scope looks wrong, write it
   down in your report instead of fixing it. If the brief is ambiguous or contradicts the
   code, choose the most conservative reading, say so in the report, and continue.
4. Add tests as the brief specifies. Run `npm run check` and `npm test`; both must pass.
5. Commit to your branch (one or a few commits, imperative English subject, no attribution
   lines). Do not push, do not merge, do not deploy, do not run `scripts/stand/*`.
6. Append a `## Report` section to the brief file, in the same commit or a final one:
   - what you implemented, file by file, in a few lines;
   - every deviation from the brief and why;
   - what you could verify only against the fake MODX and want checked on the stand;
   - open questions.
   Then tell the user the branch is ready for review.

Do not edit other briefs, `docs/spec-2.0.md`, `CHANGELOG.md`, version numbers, `.env`,
`.mcp.json`, `.local/` or anything under `scripts/stand/`.

## Architect: review loop

Read the diff against `main`, run the checks, deploy the branch to the stand
(`python3 scripts/stand/deploy.py`), run the matching `scripts/stand/check-*.cjs` plus any
check the brief adds, then either merge into `main` (updating `CHANGELOG.md` and the status
below) or append a `## Review` section to the brief with numbered change requests.

## Status

| # | Brief | Branch | Status |
|---|---|---|---|
| 01 | [modx_duplicate](01-duplicate.md) | `task/01-duplicate` | merged |
| 02 | [Journal retention](02-journal-retention.md) | `task/02-journal-retention` | merged |
| 03 | [modx_search](03-search.md) | `task/03-search` | merged |
| 04 | [modx_replace](04-replace.md) | `task/04-replace` | merged |
| 05 | [modx_find_usages](05-find-usages.md) | `task/05-find-usages` | merged |
| 06 | [modx_read_log](06-read-log.md) | `task/06-read-log` | merged |
| 07 | [modx_maintenance](07-maintenance.md) | `task/07-maintenance` | merged |
| 08 | [Access kinds](08-access-kinds.md) | `task/08-access-kinds` | merged |
| 09 | [Usages in the delete dry run](09-delete-usages.md) | `task/09-delete-usages` | merged |
| 10 | [modx_invoke and membership](10-invoke.md) | `task/10-invoke` | merged |
| 11 | [TV operations](11-tv-operations.md) | `task/11-tv-operations` | merged |
| 12 | [make_static and auto-static](12-make-static.md) | `task/12-make-static` | merged |
| 13 | [modx_check_page](13-check-page.md) | `task/13-check-page` | merged |
| 14 | [modx_dependency_graph](14-dependency-graph.md) | `task/14-dependency-graph` | merged |
| 15 | [Object keys that are not numeric ids](15-object-keys.md) | `task/15-object-keys` | merged |
| 16 | [Settings kinds](16-settings-kinds.md) | `task/16-settings-kinds` | merged |
| 17 | [Kind user](17-users.md) | `task/17-users` | merged |
| 18 | [Access rules](18-access-rules.md) | `task/18-access-rules` | merged |
| 19 | [Packages](19-packages.md) | `task/19-packages` | merged |
| 20 | [run_processor and query_db](20-escape-hatches.md) | `task/20-escape-hatches` | merged |
| 21 | [miniShop2 products and options](21-minishop2-products.md) | `task/21-minishop2-products` | merged |
| 22 | [miniShop2 links and orders](22-minishop2-links-orders.md) | `task/22-minishop2-links-orders` | merged |
| 23 | [MIGX](23-migx.md) | `task/23-migx` | merged |
| 24 | [VirtualPage](24-virtualpage.md) | `task/24-virtualpage` | merged |
| 25 | [History page](25-history-page.md) | `task/25-history-page` | merged |
| 26 | [Recording manager edits](26-manager-recorder.md) | `task/26-manager-recorder` | merged |
| 27 | [Open in the manager](27-manager-lock.md) | `task/27-manager-lock` | merged |
| 28 | [modx_audit_site](28-audit-site.md) | `task/28-audit-site` | merged |
| 29 | [modx_run](29-run.md) | `task/29-run` | merged |
| 30 | [Tokens page and panel](30-tokens-panel.md) | `task/30-tokens-panel` | merged |
| 31 | [Resource drafts](31-drafts.md) | `task/31-drafts` | merged |
| 32 | [Fenom](32-fenom.md) | `task/32-fenom` | merged |
| 33 | [Scenarios (prompts)](33-prompts.md) | `task/33-prompts` | merged |
| 34 | [MCP over HTTP](34-mcp-http.md) | `task/34-mcp-http` | merged |
| 35 | [Static paths, cleanup, fixes](35-security-cleanup.md) | `task/35-security-cleanup` | merged |
| 36 | [property_set, lexicon_entry, source properties](36-missing-kinds.md) | `task/36-missing-kinds` | merged |
| 37 | [File boundaries and static file cleanup](37-file-boundaries.md) | `task/37-file-boundaries` | merged |
| 38 | [MCP warnings and response limits](38-mcp-warnings-limits.md) | `task/38-mcp-warnings-limits` | merged |
| 39 | [Property diffs, history keys and prompts](39-properties-history-prompts.md) | `task/39-properties-history-prompts` | merged |
| 40 | [Replace 1.x with a fresh 2.0 installation](40-clean-install-guide.md) | `task/40-clean-install-guide` | merged |
| 41 | [Token labels and credential reads](41-token-and-credential-guards.md) | `task/41-token-and-credential-guards` | merged |
| 42 | [Alpha2 review follow-ups](42-review-followups.md) | `task/42-review-followups` | merged |
| 43 | [Draft-only token](43-draft-only.md) | `task/43-draft-only` | merged |
| 44 | [Shareable draft preview](44-draft-preview.md) | `task/44-draft-preview` | merged |
| 45 | [miniShop2 product drafts](45-product-drafts.md) | `task/45-product-drafts` | in progress |

## Doing several briefs in a row

Do the briefs in number order. The architect may not have merged the previous one yet;
then **stack**: create the next branch from the previous task branch instead of `main`
(`git switch task/03-search && git switch -c task/04-replace`). Each branch then contains
the earlier tasks plus its own commits, and the tool and kind counts asserted in the tests
simply accumulate. Rules for a stack:

- one branch per brief, never several briefs in one branch, never commits for a later
  brief on an earlier branch;
- do not rewrite or rebase a branch once you have reported it;
- start each report with the line `Based on: <branch or main> at <short sha>`;
- if a later brief needs a fix to code from an earlier one, make it on the later branch
  and list it under deviations;
- if `main` moved because the architect merged something with changes, do not merge or
  rebase onto it yourself — keep stacking; the architect integrates.

Lessons from briefs 01–08 (all of the architect's corrections came from these):

- The fake MODX is ASCII-clean and tidy; real sites are not. Use Unicode-aware functions
  for anything that compares or folds text (`mb_*`, `/u` patterns), and include Cyrillic
  in your test data.
- Do not add checks the brief does not ask for on return values of MODX calls: several
  MODX methods return false in normal situations (e.g. `modCacheManager::refresh()`).
- Files MODX writes are not always the bytes you gave it: snippet and plugin files get an
  opening PHP tag (`Kind::fileContent()` / `codeFromFile()` handle it). Anything that
  reads or writes a static file must go through the kind.
- MySQL reserved words (`key`, `order`, `group`, `rank`) must be quoted where xPDO does not
  quote them (sort columns: `$modx->escape()`); the fake now fails on them like MySQL.
- Think about how a token could read or change through a side door what a rule forbids
  (copying, changing a type, undo). Test those doors.
- When MODX's real behaviour or data format matters, read it in `modx-example-2-8-8/`
  rather than trusting the brief's summary, and say in the report where the two differ.

Stop and report after each brief only if you are blocked; otherwise continue with the next
one and tell the user at the end which branches are ready.

Lessons from briefs 25–26:

- A page that shows details "below the table" hides them off screen on a full page; open
  details next to what was clicked.
- MODX fires some events it never registered (`OnContextUpdate`); a plugin can only be attached
  to events present in the `modEvent` table. Check `_build`/setup data, not only `invokeEvent`.
- When the journal differs from an object's current state, the difference belongs to whoever is
  acting now only if their action has no before-event; otherwise it was made earlier by someone
  unknown.

Lessons from briefs 27–29:

- MODX processors can leave state behind that looks like a person's action (the resource delete
  processor locks children and never unlocks them). Anything that reads such state must tell
  "there before the call" from "left by the call".
- A setting added in `_build/data/transport.settings.php` exists on a site only after a package
  install; on the stand (deployed file by file) it must be created by hand before checking.

Lessons from briefs 33–34:

- Shared hosting often runs PHP under FastCGI behind Apache, which drops the `Authorization`
  header before PHP sees it; anything a client must authenticate with goes in a custom header too.
- A long executor run is lost when the host machine sleeps; commit work in progress early.

## Handoff (2026-11-22)

- Task 44 is merged into local `main`. fordev has the component source from `5a96106`.
  Verified all 13 files and updated `.local/deploy-fordev.json`. Backups are in
  `.local/fordev-before-5a96106/`. fordev confirmed refusal over plain HTTP and the existing MCP
  workflow. A disposable MODX 2.8.8 instance confirmed issuance and anonymous redemption over TLS.
- Task 43 was deployed to fordev from `3a22cf5` and merged into local `main`.
  The 14 changed files were verified, the original files were saved in
  `.local/fordev-before-3a22cf5/`, and `.local/deploy-fordev.json` was updated. The existing
  admin connection and manager approval path were checked after deployment. The draft-only
  workflow was verified on disposable MODX 2.8.8 with Chrome. PHP 7.4 checks passed with
  5511 PHP checks and 41 Node tests.
- Task 42 was deployed to fordev from `634cb85` and merged into local `main`.
  Verified all 19 deployed files and updated `.local/deploy-fordev.json`. Backups are in
  `.local/fordev-before-634cb85/`. Both attempts' staging files were removed. MIGX, VirtualPage,
  Unicode TV bindings and MCP over HTTP passed on fordev. Chrome checks used disposable MODX
  2.8.8. The PHP 7.4 suite passed 5463 PHP checks and 40 Node tests.

- Tasks 37–41 were previously deployed to fordev from `6c683cd` and merged into local `main`.
  The 24 files were verified, with the old files backed up in `.local/fordev-before-6c683cd/`
  and `.local/deploy-fordev.json` updated. Compare text line endings when using a Windows checkout.
- `npm run check` and `npm test` passed in WSL on PHP 7.4 (5459 PHP checks and 39 Node tests).
  Disposable real MODX 2.8.8 checks covered the prior static file and undo, Unicode token labels,
  credential masking and the `query_db` guard. On fordev, direct HTTP checks covered warning
  isolation, property previews and diffs, and history by string key. All test fixtures were undone.
- The `2.0.0-alpha3` transport and its SHA-256 file are in `_packages/`. On disposable MODX
  2.8.8 with PHP 7.4, upgrading from alpha2 preserved the token, journal and retained request
  response, which the new resolver indexed. Removing `1.10.0-pl` and then installing alpha3
  preserved existing site content and allowed a new admin token. On Devshop, the package list
  shows alpha3 installed. The existing MCP connection could read site info, the dependency
  graph and history.
