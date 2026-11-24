# 54 — Rename a chunk, snippet or TV together with its references

Branch: `task/54-rename` (create it from `task/53-ms2-references`, stacked).
Read first: `src/Code/Usages.php` (and `FindUsagesAction`), `src/Graph/GraphBuilder.php`,
`src/Graph/IntegrationReferences.php`, `src/Code/Fenom.php`, `src/Action/ReplaceAction.php` (how a
multi-object change is planned, revision-checked and journaled as one changeset),
`src/Action/InvokeAction.php` (operations with a plan and `confirm`), the element kinds.

## Why

Cleaning up an old site means renaming elements; today a rename silently breaks every call.

## Design (decided — do not change)

- Operation `rename` on `chunk`, `snippet` and `tv` (`modx_invoke {kind, id|name, op: "rename",
  args: {new_name, confirm?}}`). Without `confirm` it returns the plan: the new name is checked
  (free, valid for the kind) and every reference that will be rewritten is listed per object with
  line, old and new text; references that cannot be rewritten safely are listed separately as
  `manual` (a name built at run time, a reference inside a file of a component, a static file
  outside the writable roots, …). With `confirm: true` it renames the element and rewrites every
  listed reference in one changeset (one undo reverts all), refusing if any object changed since
  the plan (revisions in the plan, like `modx_replace`).
- What is rewritten — exact references only, by the same rules `find_usages` uses to find them:
  - chunk: `[[$name]]` with any prefix/suffix (`!`, `?props`, `:filter`), Fenom `{include 'name'}`,
    `{include 'chunk:name'}`, `{insert}`, `{extends}`, `| chunk`, `$_modx->getChunk('name')` /
    `parseChunk`, PHP `getChunk('name')` in snippets/plugins, `&tpl=\`name\`` and the other
    template-parameter keys the graph knows, `'tpl' => 'name'` in Fenom arrays, `@CHUNK name` in TV
    input options/default, MIGX configuration fields the graph reads, property values of elements
    and property sets that equal the name under a template key;
  - snippet: `[[name]]` / `[[!name]]` calls, Fenom `| snippet` and `runSnippet('name')`, PHP
    `runSnippet('name')`, VirtualPage handler entries by name if any;
  - tv: `[[*name]]`, `[[+name]]` only inside the templates/chunks the graph links to that TV,
    Fenom `{$_modx->resource.name}` / `{$_modx->resource['name']}`, PHP `getTVValue('name')`,
    MIGX `inputTV` references; TV values on resources are stored by id and are untouched.
  - in element code, resource content and TV values that hold code (the same places
    `find_usages` searches).
- The rename itself goes through the kind's update (so MODX events run); static elements keep their
  file name (only the element is renamed; say so in the plan).
- Help: a line in `getting_started.md`; mention in the `audit` prompt for cleaning up old names.

## Tests (required)

Each reference form above rewritten and nothing else (a longer name that starts with the old
name, a different element type with the same name, text in a comment of a different form, a
Cyrillic name); plan vs confirm; manual list; revision conflict between plan and confirm; one
changeset; undo restores every object; name already taken; TV placeholder only in linked
templates/chunks. Update exact counts only where they change.

## Acceptance

`npm run check`, `npm test`. Report as usual.

## Report

Based on: task/53-ms2-references at 3c3ae56

Checks: `npm run check` passes; `npm test` 6125 PHP checks (6035 before this brief) and 43 Node tests (43 before). No existing assertion was changed in this brief (the diff of `tests/` only adds lines).

### Implemented

- `src/Code/ReferenceRewriter.php` (new): the text rules, pure functions. For a chunk, snippet or TV it finds the exact references and replaces only the characters of the name. Rules mirror `GraphBuilder`, `Fenom::references()` and `Usages`:
  - chunk: `[[$name]]` with any `!` prefix and `?` `:` `@` suffix; `&tpl|...chunk|...wrapper=\`name\`` inside tags; Fenom `{include|insert|extends 'name'}` (also `chunk:`), `'name' | chunk`, `->getChunk|parseChunk('name')`, `'tpl' => 'name'` arrays; PHP `getChunk|parseChunk('name')` in snippets and plugins; `@CHUNK name` in TV `elements` and `default_text`.
  - snippet: `[[name]]` / `[[!name]]` (and suffixes), Fenom `'name' | snippet`, `->runSnippet('name' | '!name')`, PHP `runSnippet('name')`.
  - TV: `[[*name]]` (also `[[!*name]]`, with filters), `[[+name]]` only in linked elements, `&includeTVs` / `&processTVs` lists (items that equal the name), Fenom `$_modx->resource.name` / `['name']`, `getTVValue('name')` in PHP and Fenom.
  - Left alone: longer names (a Unicode-aware boundary, so Cyrillic names work), other element types with the same name, text in `[[- -]]` comments, Fenom `{* *}` and `{ignore}`, Fenom forms in a text that is not Fenom (the same `Fenom::looksLike` rule the graph uses, chunks and templates only), names joined at run time (`'a' ~ $x`, `getChunk('card' . $x)`).
  - Reported as manual with a line and a reason: a name that differs only in case, the name as the value of a parameter that is not a template parameter (any parameter for a snippet), `&tvFilters`, run-time names, a TV placeholder outside the linked elements, invalid UTF-8.
- `src/Code/JsonReferences.php` (new): MIGX names in JSON (`inputTV` for a TV; `renderchunktpl`, `chunk`, `formtabs_chunk`, optionally `@CHUNK name`, for a chunk), also in JSON that is kept as a string inside JSON (TV input options); strings are re-encoded in the style of the original.
- `src/Code/Rename.php` (new): `plan()` and `apply()`. Searches what `find_usages` searches (code of chunks, snippets, templates, plugins and the content of resources, static elements through their effective content) plus default properties of all elements and property sets (template parameters), TV `elements`, `default_text` and input options, MIGX configurations (`formtabs`, `columns`). The apply step makes the plan again inside the transaction, requires `expected_revisions`, touches (locks) every object and compares revisions, refuses with `revision_conflict` on any difference or on a reference that was not in the plan, refuses objects open in the manager (`force` as elsewhere), then writes through `Objects::save()` and renames the element in the same save as its own code (a recursive chunk). Limits: 500 objects, 20 changes shown per object, 200 manual items.
- `src/Kind/RenameOperation.php` (new trait) used by `ChunkKind`, `SnippetKind`, `TvKind`: operation `rename` (`modx_invoke {kind, id|name, op: "rename", args: {new_name, confirm?, expected_revisions?}}`) with plan and confirm, through the existing plan/confirm mechanism of `InvokeAction`, so a plan-only token and `dry_run` never confirm and one changeset is journaled.
- Name check (`ReferenceRewriter::nameProblem`): the model map's validation rule of MODX (read in `modx-example-2-8-8`), at most 50 characters, no whitespace (a tag name ends there), the reserved resource field names for a TV; free (case-only changes of the same element are allowed).
- Help: `getting_started.md` (a bullet in "Checking the result"), the `audit` prompt names `rename` for old names.
- Tests (`tests/php/run.php`): the text rules (every form above and its negatives, Cyrillic, comments, line endings, invalid UTF-8, JSON), the operation on a fake site (plan, dry_run and plan-only, refusals, revision conflict and a new reference between plan and confirm, one changeset, undo of all objects and a static file, TV placeholders in linked elements only, a static file outside the writable roots, property sets, TV options, a snippet and a TV rename), MIGX configurations through the MIGX fake, describe, a draft-only token, help and prompt text.

### Deviations from the brief

- TV values on pages that contain a reference (code in a TV value) are listed as `manual` and not rewritten. They live in `modTemplateVarResource`, and rewriting them would mean writing the `tvs` relation of every page; I kept it conservative. `find_usages` does not look there either.
- `[[+name]]` for a TV is rewritten in: the templates the TV is attached to, templates and chunks that contain a `[[*name]]` tag, and chunks that a tag with `&includeTVs` / `&processTVs` naming the TV takes as `tpl`-like parameters. The brief says "the templates/chunks the graph links to that TV"; the graph links by attachment and by `[[*name]]` tags, the `&includeTVs` rule is my extension (the usual way a row chunk gets TV placeholders). Elsewhere (snippets, plugins, resources, other chunks) a placeholder is manual.
- Fenom `{$_modx->resource.name}` is matched with the `$_modx->` / `$modx->` prefix only (the loose `resource.name` of `find_usages` is not rewritten).
- A system setting whose key looks like tpl/chunk/template and whose value is the chunk name is reported as manual (the graph's own rule); it is not rewritten.
- VirtualPage handler entries are stored by id, so there is nothing to rewrite.
- No lint step after rewriting (the new name is restricted to name characters, so a rewrite cannot add a syntax error; a lint would block the rename on an unrelated, already broken chunk).
- `references_rewritten` and `total_changes` count every reference; a plan lists one change per line (field, line, old, new, count).
- The static file note: a static element is renamed, its file keeps its name (said in `notes` of the plan).

### Verified only against the fake

Everything runs against the fake MODX: real element update processors with a renamed element, MODX events and cache refresh after many saves, `modStaticResource` and static file synchronisation of a renamed static chunk, `Properties` round trip of real default properties (the stored map is rewritten as a whole, so entries are re-normalised and sorted), MySQL collation (names that differ in case are reported as manual), the speed of scanning all properties and all resources on a large site, the real MIGX save (it was only run through the existing MIGX fake), and pdoTools' real Fenom detection (`Fenom::active` was satisfied by a stub file). The stand should check: rename of a chunk used by a template, pdoTools calls and a MIGX TV on a real site, undo of the whole changeset, and the time of a plan on a big site.

### Open questions

- Should TV values on pages be rewritten too (through the `tvs` relation of each page)?
- Should system settings that name a chunk be rewritten (through `system_setting`) instead of only reported?
