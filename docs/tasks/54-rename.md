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
