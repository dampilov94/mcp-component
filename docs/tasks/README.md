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
| 03 | [modx_search](03-search.md) | `task/03-search` | ready for the executor |

Do the briefs in order unless told otherwise; each starts from the then-current `main`.
