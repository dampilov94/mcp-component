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
