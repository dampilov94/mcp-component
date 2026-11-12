# 09 — Show usages in the delete dry run

Branch: `task/09-delete-usages`. Spec: section 3 (`delete` — "с показом, где он используется").

## Goal

`modx_delete {…, dry_run: true}` must tell the model what still refers to the object, so it
does not delete something in use. The real delete is unchanged.

## Design (decided — do not change)

1. Move the lookup out of `FindUsagesAction` into `src/Code/Usages.php`:
   `Usages::supports(Kind $kind): bool` and
   `Usages::find(Context $context, Kind $kind, $object, $limit): array` returning exactly
   what `modx_find_usages` returns today minus `kind`/`id`/`name` (`objects`, `truncated`,
   `usages`, `extra`). `FindUsagesAction` becomes a thin wrapper; its behaviour, output and
   tests must not change.
2. In `DeleteAction`, dry-run branch only, when `Usages::supports($kind)`: call
   `Usages::find(..., 10)` and add to the result
   ```json
   "usages": { "objects": 3, "truncated": false,
               "items": [ { "kind": "template", "id": 3, "name": "Base", "match_count": 1 } ],
               "extra": { … } }
   ```
   (`items` = the usages without their `matches`), and, when anything refers to the object,
   a `warning` string: for code usages "Still used in N object(s); deleting it will break
   them."; for a template with resources "N resource(s) use this template."; for a TV with
   stored values "N resource(s) have a value for this TV."; for a category with members
   "The category is not empty."; for a resource with children "N child resource(s) go to
   the trash with it." Several apply → join with a space.
3. Kinds `Usages` does not support: no `usages` key, no warning.
4. A failure inside the lookup must not break the dry run: catch `\Throwable`, omit
   `usages`, add a warning to `$context->warnings`.
5. `modx_delete`'s description gains one clause: the dry run lists where the object is
   still used.

## Tests (required)

`tests/php/run.php`: dry run of a used chunk shows the users and the warning and deletes
nothing; an unused chunk has `objects: 0` and no warning; template with resources; TV with
values; category with members; resource with children; a kind without usage support
(`plugin`, `user_group`) has no `usages`; `modx_find_usages` results are byte-identical to
before (existing tests pass untouched).

## Acceptance

`npm run check`, `npm test`. Files: `src/Code/Usages.php` (new), `src/Action/FindUsagesAction.php`,
`src/Action/DeleteAction.php`, tests.
