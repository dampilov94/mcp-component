# 01 — `modx_duplicate`

Branch: `task/01-duplicate`. Spec: section 3 (tool table, "Объекты"), section 7 (write contract).

## Goal

A tool that makes a copy of one object of any kind that can be created through the API:
`modx_duplicate {kind, id|name, name?, fields?, dry_run?}`.

## Design (decided — do not change)

Do **not** use MODX's own `duplicate` processors: they differ per kind and the resource one
copies children. Build the copy generically from the kind declaration and create it through
the normal create path, so it is validated, journaled and undoable like any `modx_create`.

1. New class `src/Action/DuplicateAction.php` extending `WriteAction`, name `duplicate`,
   group `objects`, registered in `Action/Registry.php` right after `DeleteAction`.
2. Input: `kind` (required), `id` or `name` of the source (same resolution as
   `UpdateAction`: `Objects::resolve()`), optional `name` (name of the copy), optional
   `fields` (object; values that override the copied ones, validated with
   `Objects::normalize($kind, $fields, false)`), optional `dry_run`. No `expected_revision`.
3. Values of the copy: for every field of the kind that is neither `read_only` nor
   `derived`, the source's current API value (`Kind::value()`), then:
   - the name field (`Kind::nameField()`) = `name` if given, else `"<source name> copy"`;
     if an object with that name already exists for a kind whose names must be unique
     (every kind except `resource`), try `"<name> copy 2"`, `"… copy 3"` up to 20, then fail
     with `validation_failed`. Add a method to the `Kind` base for this:
     `uniqueNames()` returning `true`, overridden to `false` in `ResourceKind`.
   - static elements: the copy must **not** share the source's file. If the kind has the
     fields `static` / `static_file` and the source is static, set `static` to false and
     `static_file` to `""`; `content` is the source's effective content (which
     `Kind::value()` already returns).
   - resources: set `published` to false, `alias` to `""` (MODX generates one from the
     title) and do not copy `uri` / `uri_override` (`uri_override` false). Children are not
     copied.
   - finally apply `fields` on top.
   Put kind-specific adjustments behind one new `Kind` method
   `duplicateOverrides($source)` returning `api field => value` (base: static handling when
   the kind has those fields; `ResourceKind` adds its three), not in the action.
4. Relations: copy every relation whose spec has `writable => true`.
   - list relations: the value returned by `Kind::presentRelation()`;
   - map relations (`'map' => true`, i.e. resource `tvs`): copy only the values actually
     **stored** for the source, not the defaults `presentRelation()` fills in. Add a `Kind`
     method `storedRelation(\modX $modx, $object, $name)` for this: base implementation =
     `presentRelation()`; `ResourceKind` overrides it for `tvs` to return `{tv name: value}`
     for rows of `modTemplateVarResource` only (names resolved through the template's TVs;
     skip rows whose TV is not on the template).
   Relations that are not writable are not copied; add one warning to `$context->warnings`
   naming them when the source has rows in any (e.g. "Not copied: property_sets").
5. Run the lint as `CreateAction` does (`Objects::lint`), no `skip_lint` argument: a source
   that does not parse must fail with `lint_failed`.
6. Dry run (`isDryRun`): return via `$this->plan()` with `kind`, `source_id`, `would_create`
   = `{name: …}` plus the list of relations that would be copied; create nothing.
7. Real run: inside `Writer::run`, `Objects::save($context, $tx, $kind, null, $columns,
   $relations)`. Result: `$this->written($kind, $newId, $info, ['source_id' => $sourceId,
   'name' => $newName])`.
8. Description (for the model), keep it short: copies one object including its writable
   relations; a copy of a resource is created unpublished and without its children; a copy
   of a static element is not static.
9. Scope: inherited from `WriteAction` (`content`); `Objects::kind($context, $kind, true)`
   enforces the kind's write scope.

## Out of scope

Copying children of a resource, copying non-writable relations, files, `media_source`
(works generically; no special handling).

## Tests (required)

In `tests/php/run.php`, a new block after the "Bulk" block of the resource tests, using the
existing `$call`, `$dev`, `$editor`, `$site` helpers:

- chunk: copy has a new id, name `"<name> copy"`, same `content` and `description`,
  `changeset_id` set; a second duplicate yields `"<name> copy 2"`; explicit `name` is used;
  an explicit `name` that exists fails with `validation_failed` (do not auto-number an
  explicit name).
- `fields` override wins over copied values.
- static chunk (see the existing "Static elements" block for how one is set up): the copy is
  not static, has the file's content, and the source file is untouched.
- plugin with events: the copy has the same `events` relation.
- snippet whose stored code does not parse (store it directly in the fake): `lint_failed`,
  nothing created.
- resource with a stored TV value and a child: copy is unpublished, has the stored TV value,
  an alias different from the source's, the same parent, and no children; the source is
  unchanged.
- dry run creates nothing; a `read` token gets `forbidden_scope`; a `content` token can
  duplicate a resource but not a chunk.
- `modx_undo` of the duplicate's changeset removes the copy.

Update the expected tool lists/counts in `tests/php/run.php` and `tests/endpoint.test.js`
(one more write tool; read-token lists are unchanged).

## Acceptance

`npm run check` and `npm test` pass; no change outside `src/Action/DuplicateAction.php`,
`src/Action/Registry.php`, `src/Kind/Kind.php`, `src/Kind/ResourceKind.php`, the two test
files and (if the fake really lacks something MODX has) `tests/php/FakeModx.php`.

## Report

Implemented on `task/01-duplicate`:

- `src/Action/DuplicateAction.php`: generic copy through `Objects::save()` inside
  `Writer::run()`, source lookup, automatic names through suffix 20, explicit name collision
  checks, validated field overrides, writable relations, omitted-relation warnings, PHP
  lint, dry run / plan-only and the standard journaled write result.
- `src/Action/Registry.php`: registered `DuplicateAction` immediately after `DeleteAction`.
- `src/Kind/Kind.php`: added `uniqueNames()`, `duplicateOverrides()` and `storedRelation()`;
  static elements become non-static copies with their effective content.
- `src/Kind/ResourceKind.php`: allows repeated titles, resets publication and URL fields,
  and reads only stored TV values belonging to the source template, preserving empty maps.
- `tests/php/run.php`: added the duplicate block after Bulk and manifest assertions.
  Covers the requested cases plus exhausted names, field validation, non-writable relation
  warnings, default-only and detached TVs, repeated resource titles and plan-only tokens.
- `tests/endpoint.test.js`: updated full manifest counts to 24 and checked duplicate's
  placement and write effect; read-token expectations are unchanged.
- `tests/php/FakeModx.php`: generates an alias from the resource title on create when the
  supplied alias is empty. Previously the fake only generated one when alias was absent.

Validation: `npm run check` passed (11 JavaScript files, 109 PHP files on PHP 7.4.33,
release metadata). `npm test` passed: 348 PHP checks and 24 Node/HTTP tests, no skips.
The first sandboxed test run could not listen on localhost (`EPERM`); the approved rerun
outside the sandbox passed. No real site operations were performed.

Interpretations / deviations:

- The brief uses `name` both for source lookup and for the new name. Without `id`, it is
  the source name and the copy gets the automatic suffix; with `id`, it is the explicit
  copy name. `fields` is applied last, including the kind's name field. This is documented
  in the input schema and tested; no new argument was introduced.
- The dry-run relation names are returned as a top-level `relations` list alongside
  `would_create: {name: ...}`. The brief does not specify a key for that list.
- Resource `uri` is cleared to an empty string along with `uri_override: false`, so the
  source's frozen URI is not copied. Stored TV maps are cast to arrays internally for the
  existing writable-relation validator; the kind still returns `{}` for an empty map.
- The branch was created from the clean current local `main`, without switching back or
  pulling, following the user's explicit instruction not to touch `main`. This report is
  the only changed file outside the implementation/test acceptance list, as requested.

Only verified against fake MODX; the architect should check on the stand: real create
processor validation/events/cache refresh, generated resource aliases and URIs, static
file isolation, plugin event copying, stored-only TV copying (including default-only and
detached TVs), parent journaling, and undo. The fake does not reproduce all MODX processor
side effects or third-party plugins.

Open questions: confirm the overloaded `name` interpretation above. Also, the prescribed
copy retains `pub_date`; the existing resource processor behavior can publish immediately
for a past publish date even with `published: false`. Scheduling policy was left unchanged
because the decided design only resets the listed publication/URL fields. Check this case
on the stand and decide separately whether copies should clear the publication schedule.
