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
