# 15 — Object keys that are not numeric ids

Branch: `task/15-object-keys`. Spec: section 3.1 (kinds `system_setting`, `context`,
`context_setting`, `namespace` — they have no numeric id).

## Goal

Today the core assumes every object is identified by an integer `id` (`(int) $key`
throughout `Journal\Snapshot`, `Write\Transaction`, `Kind\Objects`, the actions and the
client). This task removes that assumption so that brief 16 can add kinds whose key is a
string. **No new kind and no new tool in this task**; every existing behaviour, result and
test must stay exactly as it is.

## Design (decided — do not change)

### The key of a kind

Add to the `Kind` base (defaults reproduce today's behaviour):

| Method | Default | Meaning |
|---|---|---|
| `keyType()` | `'integer'` | `'integer'` or `'string'` |
| `normalizeKey($raw)` | `(int) $raw` | canonical PHP value of a key; string kinds return `(string)` |
| `keyCriteria($key)` | `['id' => (int) $key]` | xPDO criteria that select the object |
| `keyOf($object)` | `(int) $object->get('id')` | key of a loaded object |
| `keyColumn()` | `'id'` | column used as the last sort key in `modx_list` |
| `processorKey($key)` | `['id' => $key]` | properties that identify the object to its update/remove processors |
| `createdKey(array $response, array $columns)` | `(int) $response['id']` (throw if empty) | key of an object just created, from the create processor's response object and the columns sent |

and `findByKey(\modX $modx, $key)` = `getObject(modelClass(), keyCriteria($key), false)`.

`Kind::find($modx, array $ref)` accepts `['id' => …]`, `['key' => …]` or `['name' => …]`:
`id` and `key` both go through `findByKey` (after `normalizeKey`), `name` as today. For a
string-key kind `name` is an alias of `key` unless the kind overrides `find`.

### Where the integer assumption goes away

Replace every `(int) $key` / `(int) $id` / `array('id' => $id)` on an object key with the
kind's methods. At least:

- `Journal\Snapshot`: `capture`, `captureDeleteExtra`, `restore` use `findByKey`; the
  state's `key` is `(string) normalizeKey($key)`; relation criteria use
  `keyOf($object)`; the `id` passed to MODX events is the key.
- `Journal\ObjectSubject`: keeps the normalised key; `key()` returns it as a string; add
  `publicKey()` returning the key in its API type (int for integer kinds).
- `Journal\Subject`: add `publicKey()` to the interface; `Files\FileSubject::publicKey()`
  returns its string key.
- `Write\Transaction`: `touch`, `created`, `keepDeleteExtra` take any key; the `id` in the
  commit info's `changes` comes from `publicKey()` (drop the `ctype_digit` heuristic).
- `Journal\Subjects`: add `publicKey(Context $context, $kindName, $key)` for places that
  only have journal rows (`HistoryAction`, `HistoryDiffAction`, `UndoAction`): the kind's
  key type decides; unknown kinds and files → the string as stored. Remove the remaining
  `ctype_digit` heuristics there.
- `Kind\Objects`: `resolve` (below); `save($context, $tx, $kind, $key, …)` uses
  `processorKey($key)` for the update data and `createdKey()` after a create;
  `assertRevision` and the rest take keys.
- `Kind::alsoAffected()` returns keys; `ResourceKind` is unchanged in effect.
- `ListAction`: the tie-break sort uses `keyColumn()`.

### Addressing objects in the tools

- `Objects::resolve($context, $kind, array $args)`: integer kinds accept `id` or `name`
  (as today; a `key` argument → `validation_failed` "this kind is addressed by id");
  string kinds accept `key` (or `name` as an alias; an `id` argument →
  `validation_failed` "this kind is addressed by key"). Messages name the argument to use.
- Every tool that takes `id`/`name` to pick one object gains an optional
  `key` string argument (`maxLength` 255) with the description "Key of the object, for
  kinds without a numeric id (see modx_describe).": `get`, `update`, `delete`, `duplicate`,
  `invoke`, `view_code`, `edit_code`, `find_usages`.
- Results: where a result carries `id` for an integer kind, a string-key kind carries
  `key` instead (never both, never `id: 0`). `WriteAction::written()` and every action
  that builds such a result go through one helper, e.g. `Kind::keyField()` returning
  `'id'` or `'key'`. Output schemas declare `key` as an optional string next to `id`.
- `modx_describe` adds `key_type` (`"integer"` / `"string"`) and `key_field` (`"id"` /
  `"key"`) to a kind's description.
- `modx_history` / `modx_history_diff` / `modx_undo`: an object entry has `id` for
  integer kinds and `key` for string kinds (files keep `id` with their path string, as
  today — do not change that).

### Client (`client/index.js`)

The remembered-revision logic keys on `kind` + `id` and only for integer ids. Extend it to
`key`: an object is tracked under `kind:id` or `kind:key:<key>`; the write is matched by
the same argument the caller used (`id` or `key`). No other client change.

## What must not change

Run the complete test suite before starting and after finishing: the same checks must pass
with the same assertions. Do not edit existing assertions; if one fails, the refactoring is
wrong. (Adding assertions is fine.) No change to tool names, counts, descriptions other
than the new `key` argument, or to any existing result for the existing (integer) kinds.

## Tests (required)

Since no real string-key kind exists yet, add a **test-only** kind in `tests/php/` (not in
`src/`): `TestSettingKind` — model class `modSystemSetting`, key = column `key`
(string), name field `key`, fields `key` (required, list), `value`, `area`; processors
`system/settings/` (`create`, `update`, `remove`; extend the fake so they exist and
identify the object by the property `key`). Register it only in the test contexts (give
`KindRegistry` a public method to add a kind instance; production never calls it).

Give the fake a notion of primary keys per class (today rows without an `id` are matched
by their whole content, which breaks updates of such rows): `modSystemSetting` → `key`.

Checks, all through the real actions:

- create, get by `key`, list (sorted by key, `total`, paging), update with
  `expected_revision`, delete, dry runs; results carry `key`, not `id`.
- `id` given for the test kind and `key` given for a chunk → `validation_failed` with the
  messages above; `name` works as an alias of `key` for the test kind.
- journal: history and diff entries show `key`; undo of an update, of a create and of a
  delete (the setting comes back with the same key and value); undo conflict detection;
  a change made behind the journal's back is recorded as `external`.
- a key that looks like a number (`"404"`) stays a string everywhere (results, history,
  undo).
- `modx_describe` shows `key_type` / `key_field` for the test kind and for `chunk`.
- failure after save → rollback restores the setting; nothing journaled.
- `tests/client.test.js`: the revision last seen is sent for an object addressed by `key`.

## Acceptance

`npm run check`, `npm test`; all previously existing assertions untouched. Files: `src/Kind/Kind.php`,
`src/Kind/Objects.php`, `src/Kind/KindRegistry.php`, `src/Kind/ResourceKind.php` (only if
`alsoAffected`/`find` need the new methods), `src/Journal/*`, `src/Files/FileSubject.php`,
`src/Write/Transaction.php`, the actions listed above plus `HistoryAction`,
`HistoryDiffAction`, `UndoAction`, `ListAction`, `DescribeAction` (if needed),
`client/index.js`, tests, `tests/php/FakeModx.php`.

## Report

Based on: main at 31fe026

Counts: before `npm run check` OK (129 PHP files, 15 JS files), PHP 962 checks, Node 24 tests. After: check OK (130 PHP files), PHP 1022 checks, Node 25 tests. No existing assertion was edited or removed.

Implemented, file by file:

- `src/Kind/Kind.php`: `keyType`, `normalizeKey`, `keyCriteria`, `keyOf`, `keyColumn`, `processorKey`, `createdKey`, `findByKey`, plus two small helpers `keyField()` ("id"/"key") and `publicKey($key)` (int for integer kinds, string otherwise). `find()` accepts `id`, `key` or `name` (name is an alias of key for string kinds). `defaultSort()` ends with `keyColumn()`. `opMakeStatic`, `presentRelation` use `keyOf`. `describe()` adds `key_type` and `key_field`.
- `src/Kind/Objects.php`: `resolve()` per key type with the two refusal messages ("addressed by key, not id: use the key argument" / "addressed by id (or name), not key: use the id argument"); new `resolveKey()` and `keyText()`; `save()` uses `findByKey`, `processorKey`, `createdKey`; `assertRevision` takes any key.
- `src/Kind/KindRegistry.php`: public `register(Kind)`. `src/Kind/StaticFiles.php`: re-resolve through `resolveKey`.
- `src/Journal/Snapshot.php`, `ObjectSubject.php`, `Subject.php` (adds `publicKey()`), `Subjects.php` (adds `publicKey()` and `keyField()` for journal rows), `src/Files/FileSubject.php` (`publicKey()`), `src/Write/Transaction.php` (changes use `publicKey()`; `ctype_digit` heuristic removed).
- Actions: `Action::keyArgument()` shared definition of the `key` argument; added to get, update, delete, duplicate, invoke, view_code, edit_code, find_usages. Results use `$kind->keyField()`; `WriteAction::written()` now takes the Kind (not the kind name). Output schemas declare optional `key`; `id` is no longer in `required` for get, view_code, find_usages (a string-key kind has no id). History, history_diff, undo entries use `Subjects::keyField/publicKey`. duplicate reports `source_key` instead of `source_id` for string kinds.
- `client/index.js`: objects tracked as `kind:id` or `kind:key:<key>` (`trackedKey()`), same for results.
- Tests: `tests/php/TestSettingKind.php` (test-only), `tests/php/FakeModx.php` (primary key per class, `modSystemSetting` -> `key`; `system/settings/*` processors), `tests/php/run.php` (new "keys:" section, 60 checks covering everything the brief lists), `tests/client.test.js` (mock accepts `key`; new test for the revision sent for an object addressed by key).

Deviations and conservative readings:

- `ListAction` was not edited: the tie-break is in `Kind::defaultSort()`, which now uses `keyColumn()`; `ResourceKind` keeps its own `defaultSort` with `id`. `ResourceKind` and `DescribeAction` are unchanged (`describe()` lives in `Kind`).
- `id` was removed from the `required` list of the get, view_code and find_usages output schemas, otherwise string-key kinds could not satisfy them.
- Unknown kinds in history/undo now show the stored string even when it is all digits (the brief says to drop the heuristic); known integer kinds still show an int.
- `modx_history` has no `key` filter (the brief lists no change to it); `id` there still filters by `object_key` as a string, so a string-kind object cannot be filtered by key yet.
- `InvokeAction`: the message "This operation takes no id or name." is kept as is; `key` is rejected under the same rule.

Verified only against the fake MODX (to check on the stand once brief 16 lands real kinds): everything about string keys; `modSystemSetting` rows and the `system/settings/*` processors are fakes. The `modSystemSetting` primary key `key` in real xPDO `getObject` criteria is assumed to work as `array('key' => ...)`.

Open questions:

- A create processor that throws after saving (the fake's `failProcessor` on `.../create`) leaves the new object unregistered in the transaction, so rollback cannot remove it. This is existing behaviour for integer kinds too; I did not test or change it.
- The test kind makes `key` a writable field, so `update` could try to rename a key; the transaction would then track the old key only. Brief 16's real kinds should make the key field read-only on update (or reject it).
- The built-in help (`core/components/modxmcp/docs/`) does not mention `key` yet; left for brief 16.

## Review

Accepted and merged. Every existing assertion is unchanged and passes; all six earlier
stand checks pass against this branch, so behaviour for integer kinds is unchanged on a
real site. The string-key path is exercised on the stand with brief 16.

The deviations in the report are accepted. Open questions:

- A create processor that throws after saving leaves an unregistered object: true, and it
  predates this task. Recorded for a later fix.
- Key immutability on update is required by brief 16 for its real kinds.
- Help text for `key` comes with brief 16, when a real string-key kind exists.
