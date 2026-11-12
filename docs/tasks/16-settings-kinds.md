# 16 — Kinds `system_setting`, `context`, `context_setting`, `namespace`

Branch: `task/16-settings-kinds`. Needs task 15. Spec: section 3.1, section 6 (scopes).
MODX source to read first (`modx-example-2-8-8/core/model/modx/`): processors
`system/settings/` (`create`, `update`, `remove`), `context/` (`create`, `update`,
`remove`), `context/setting/` (`create`, `update`, `remove`), `workspace/namespace/`
(`create`, `update`, `remove`), and the maps `mysql/modsystemsetting.map.inc.php`,
`modcontext.map.inc.php`, `modcontextsetting.map.inc.php`, `modnamespace.map.inc.php`.
1.x reference for processor properties that are known to work:
`core/components/modxmcp/model/traits/administration.trait.php` (`createSystemSetting`,
`updateSystemSetting`, `runContextAction`, `runWorkspaceAction`) and
`registry.trait.php`.

## Goal

Four kinds with string keys, usable with the existing generic tools. No new tool.

## Design (decided — do not change)

| Kind | Class | Key (API) | Group | Read / write scope |
|---|---|---|---|---|
| `system_setting` | `modSystemSetting` | `key` = column `key` | `settings` (not toggleable) | read / develop |
| `context` | `modContext` | `key` = column `key` | `contexts` (toggleable) | read / admin |
| `context_setting` | `modContextSetting` | `"<context_key>:<key>"`, e.g. `web:site_url` | `contexts` | read / develop |
| `namespace` | `modNamespace` | `key` = column `name` | `namespaces` (toggleable) | read / admin |

Fields (take exact columns and required processor properties from the source; report any
difference from this list):

- `system_setting`: `key` (required, list, not changeable after creation: an update that
  tries to change it → `validation_failed`), `value` (list), `xtype` (list; default
  `textfield`), `namespace` (list; default `core`), `area` (list), `editedon` (derived,
  datetime).
- `context`: `key` (required, list, not changeable), `name` (list), `description`,
  `rank` (integer). Relation `settings` (class `modContextSetting`, fk `context_key`,
  present `['key', 'value']`, read-only) — part of the snapshot, so undoing a context
  delete restores its settings. MODX refuses to remove `web` and `mgr`: pass through.
- `context_setting`: `context_key` and `key` (both required, list, not changeable),
  `value` (list), `xtype`, `namespace`, `area`. The composite key is split on the **first**
  colon; `modx_list` accepts `where: {context_key: "web"}`.
- `namespace`: `key` (column `name`; required, list, not changeable), `path`,
  `assets_path`.

Name field = the key for all four, so `name` is an alias of `key` in lookups, and the
`query` argument of `modx_list` searches the key only (never the value).

After a successful write of a setting or context the configuration MODX has in memory and
its settings cache must be current: do what the 1.x methods do after their processor calls
(`reloadConfig()` / the cache partitions they refresh). Put this in the kinds
(a small hook called by `Objects::save()` and the delete path, e.g. `afterWrite(\modX)`),
not in the generic code.

### Secrets

Some settings hold credentials. A setting is **secret** when its `xtype` is
`text-password`, or its key matches
`/(^|[_.])(pass|password|passwd|secret|token|api_?key|private_?key|salt)([_.]|$)/i`.

- `modx_get` / `modx_list` return `"[hidden]"` as the `value` of a secret setting unless
  the token has the `admin` scope, plus `"secret": true` on the object.
- `modx_history_diff` shows `{"old": "[hidden]", "new": "[hidden]"}` for the value of a
  secret setting for **every** scope. Implement through a kind hook the object subject
  asks (e.g. `Kind::secretColumns(array $state)` returning column names), replacing the
  hard-wired column-name pattern only where the kind answers; the existing pattern stays
  for other kinds.
- Writing a secret setting is allowed with the kind's write scope; the write result never
  echoes values anyway.
- Snapshots keep the real value (undo must work).

### modxMCP's own settings

Settings in the namespace `modxmcp` (and context settings with a key starting
`modxmcp.`) control this API's own limits and gates. Through the API they are **read-only
for every scope**: create, update, delete and duplicate → `validation_failed`
"modxMCP settings are changed in the MODX manager, not through the API." Reading follows
the secret rule. Also refuse to create a setting whose key starts with `modxmcp.`, and
`modx_undo` must refuse to restore one (`not_reversible` with the same explanation) — put
the guard where `Snapshot::restore` asks the kind (e.g. `Kind::assertRestorable($state)`),
default no-op.

## Out of scope

Lexicon entries for setting names/descriptions, user settings, user group settings,
context access rules, packages.

## Tests (required)

Replace the test-only kind of task 15 in the tests with the real `system_setting` kind
where that keeps the task-15 checks meaningful (keep those checks passing); extend the
fake with the processors (identify objects as the real ones do) and primary keys
(`modContext` → `key`, `modContextSetting` → `context_key` + `key`, `modNamespace` →
`name`), and with MODX's refusals for `web` / `mgr` and for namespaces/contexts in use if
the source has any.

- each kind: create, get, list (+ `where`, `query`), update, delete, duplicate
  (`new_name` = the new key), history, diff, undo of each operation.
- key immutability; composite key parsing (`web:some.key`, a setting key that itself
  contains colons, missing colon → `validation_failed`).
- context delete + undo restores its settings.
- scopes per the table; `contexts` / `namespaces` groups disabled → kinds unavailable;
  `settings` cannot be disabled.
- secrets: by xtype and by each key pattern, non-secret look-alikes (`passage_count`,
  `tokenizer`) are not hidden; read/content/develop see `[hidden]`, admin sees the value;
  diff hidden for admin too; list hides as get does.
- modxMCP settings: every write path refused for an admin token, including undo of a
  recorded change to one (plant the journal rows through an earlier allowed state, or call
  the restore path directly) and creation of a `modxmcp.*` key in another namespace.
- the in-memory configuration is current after a write (`$modx->getOption()` returns the
  new value in the same request — make the fake's `reloadConfig()` re-read settings).

Update expected kind lists/counts in both test files.

## Acceptance

`npm run check`, `npm test`. Files: four new kind classes, `src/Kind/KindRegistry.php`,
`src/Kind/Kind.php` and `src/Kind/Objects.php` / `src/Journal/*` only for the hooks named
above, `src/Capabilities.php` only if a group constant is missing, tests,
`tests/php/FakeModx.php`.

## Report

Based on: main at 9bec25d

Counts: before, `npm run check` OK (130 PHP files, 15 JS files), PHP 1022 checks, Node 25 tests. After: check OK (135 PHP files), PHP 1327 checks, Node 25 tests (all pass). No existing assertion was weakened or deleted.

Implemented, file by file:

- New kinds in `src/Kind/`: `SystemSettingKind` (group `settings`, develop), `ContextKind` (`contexts`, admin; relation `settings`, delete-only `acl` and `source_elements`), `ContextSettingKind` (`contexts`, develop; composite key split on the first colon, `fk` added for the create processor), `NamespaceKind` (`namespaces`, admin; key = column `name`; delete-only relations `system_settings`, `context_settings`, `user_settings`, `lexicon_entries`, `extension_packages`, `acl`). Registered in `KindRegistry`. Shared parts: `AbstractSettingKind` (string key, `afterWrite` = `reloadConfig()`, secrets, own-settings guard, `namespace` defaulted to `core` on create because the processors require one) and `Secrets` (secret rule, own-settings rule, messages).
- `Kind.php`: hooks with no-op defaults: `processorData()`, `afterWrite()`, `secretColumns()`, `maskRelationRows()`, `redact()`, `assertColumns()`, `assertObjectWritable()`, `assertRestorable()`; field flags `immutable` (shown by describe) and `no_filter`.
- `Objects.php`: `normalize()` calls `assertColumns()`; new `assertImmutable()`; `save()` applies `processorData()` and calls `afterWrite()`.
- `Snapshot::restore()`: `assertRestorable()` on the target state, and on the current state when the target is "removed"; `afterWrite()` after a restore. `Undo::plan()` also asks for target and current state, so a refusal happens at plan time, before anything is touched. `ObjectSubject::describeChange()` asks `secretColumns()` (kind answers replace the name pattern; other kinds unchanged) and `maskRelationRows()`.
- Actions: `GetAction` and `ListAction` call `redact()` (value `[hidden]`, `secret: true`); `ListAction` honours `no_filter`; `UpdateAction` checks immutability and `assertObjectWritable`; `DeleteAction` calls `assertObjectWritable` (also for the dry run) and `afterWrite`; `DuplicateAction` calls `assertObjectWritable($source, 'duplicate')`.
- Tests: `tests/php/TestSettingKind.php` removed. The task-15 "keys" section now runs on the real `system_setting` kind. Moved or changed in it: (1) `test_setting` renamed to `system_setting` everywhere; (2) the key `404` can no longer be created, because the MODX create processor refuses keys starting with a digit (source, `verifySettingKey`), so it is planted with `store()` as if made in the manager, and the check "a numeric-looking key is a string in history" now reads the delete entry of `404` (after the existing numeric delete) instead of its create entry; (3) the create result's `changed` list is now `key, value, xtype, namespace, area` (the real kind has more fields). Every other check is unchanged. New "Settings kinds" section: 305 checks (describe, groups, all CRUD/duplicate/history/diff/undo paths of the four kinds, immutability, composite keys, context delete + undo, secrets by xtype and each key pattern with look-alikes, scopes, own-settings guard on every path incl. planted-journal undo, configuration currency). `FakeModx.php`: composite primary keys, `reloadConfig()` re-reading settings, `getContext()`, processors `system/settings/`, `context/`, `context/setting/`, `workspace/namespace/` with the source's refusals (digit-leading keys, namespace must exist, `web`/`mgr`, `core` namespace, cascades), `core` namespace seeded in `fakeSite()`, `tableDump()`. `tests/endpoint.test.js`: kind count 11 to 15; the kind list in `run.php` extended.
- `docs/getting_started.md`: short paragraph on key-addressed kinds, hidden credentials and read-only modxMCP settings.

Deviations and conservative readings:

- MODX's `modContextRemoveProcessor::afterRemove` deletes every resource of the context and their TV values; that cannot be undone. `ContextKind` refuses to delete a context that still has resources (`validation_failed`, also in the dry run). Not in the brief.
- Removing a namespace removes (xPDO composites) its system settings, context settings, user settings, lexicon entries, extension packages and ACLs. These are kept as delete-only relations so undo is complete (the brief did not list them). `modAction` is also a composite but has no class file in 2.8.8, so it is not captured.
- The modxMCP guard goes beyond settings: the namespace `modxmcp` itself cannot be created, updated, deleted, duplicated or restored; a context that holds a `modxmcp.*` or namespace-`modxmcp` setting, and a namespace that holds a `modxmcp.*` setting, cannot be deleted (the cascade would bypass read-only). An update that moves a setting into the `modxmcp` namespace is refused. Key comparison for the rule is case-insensitive.
- `modx_list` `where` on `value` of settings is refused (new field flag `no_filter`), otherwise a non-admin could probe a hidden value by filtering. Not in the brief.
- `context_setting` has `uniqueNames() === false`: `duplicate` takes `new_name` as the new setting key inside the same context and leaves the duplicate check to the processor (the generic name lookup would need a composite key).
- Key immutability treats an update that sends the identical value as no change; any other value, including a case change, is `validation_failed`.
- The brief names `Objects::save()`/`Snapshot` for hooks; I also edited `UpdateAction`, `DeleteAction`, `DuplicateAction`, `GetAction`, `ListAction`, `Undo.php` (not in the brief's file list) because the guards must also cover dry runs and the redaction needs the token's scope. An extra base class `AbstractSettingKind` and helper `Secrets` were added to the "four classes".
- No guard inside `Objects::save()`: a throw there would make the transaction roll back a protected setting through `restore()`, which refuses.

MODX source vs brief:

- The create processors require a non-empty `namespace` (no model default is applied), hence the `core` default in `processorData()`.
- `context/setting/create` identifies the context by `fk` (not `context_key`); update and remove use `context_key`.
- Keys starting with a digit are refused by both setting create processors.
- `modNamespaceRemoveProcessor::beforeRemove` returns `false` for `core`, so the passed-through message is the generic "Processor ... failed."
- Real `editedon` is `ON UPDATE CURRENT_TIMESTAMP`; it is a derived, read-only field and dropped from the state (like the resource's).
- Creating a context also makes ACL rows (administrator and anonymous access); they are not journaled. Undoing the create removes the context through xPDO, which removes its ACLs too. Not covered by a check.

Verified only against the fake MODX (please check on the stand): all processor property names and refusals; that `reloadConfig()` after a write makes `$modx->getOption()` current and refreshes the cache (the fake processors deliberately do not reload on their own, so the tests exercise the kind hook, including after undo); xPDO `getObject`/`remove` with string and composite primary keys (`modContextSetting`, `modContext`, `modNamespace`) and the composite cascades on delete; restoring `modLexiconEntry`, `modUserSetting`, `modAccessContext`, `sources.modMediaSourceElement` rows through `fromArray`/`save`; the response objects of the create processors carrying `key`/`name`/`context_key`; that the context update processor does not touch settings when `settings` is not sent.

Open questions:

- Should deleting a context with resources be possible with an explicit flag instead of refused? Left refused.
- `modx_history` has no `key` filter (task 15 open point); a setting's history can only be filtered by `kind` and `name` today.
- The "secret" key pattern is the brief's; a key like `forgotten_password_tpl` counts as secret (hidden), which may surprise.
