# 08 — Access kinds: `user_group`, `role`, `resource_group`

Branch: `task/08-access-kinds` (stack on the previous task branch if it is not merged yet).
Spec: section 3.1 (kinds table, "Доступ"), 6 (scopes), 8.2 (snapshots).
Real MODX to read **before** writing anything (a local MODX 2.8.8 copy in the repository
folder): `modx-example-2-8-8/core/model/modx/processors/security/group/`,
`…/security/role/`, `…/security/resourcegroup/`, and the maps
`modx-example-2-8-8/core/model/modx/mysql/modusergroup.map.inc.php`,
`modusergrouprole.map.inc.php`, `modusergroupmember.map.inc.php`,
`modresourcegroup.map.inc.php`, `modresourcegroupresource.map.inc.php`.

## Goal

Three new kinds that work with the existing generic tools (`modx_list`, `modx_get`,
`modx_create`, `modx_update`, `modx_delete`, `modx_duplicate`, history, undo). No new tool.

## Design (decided — do not change)

One class per kind in `src/Kind/`, listed in `Kind/KindRegistry.php` after
`MediaSourceKind`. All three: `group()` = `access` (a toggleable group — see
`Capabilities::TOGGLEABLE`), `readScope()` = `read`, `writeScope()` = `admin`,
`deleteProcessor()` = `remove`, `processorExtras()` = empty array, no `eventNames()`.

| Kind | Class | Processors | API fields (column) |
|---|---|---|---|
| `user_group` | `modUserGroup` | `security/group/` | `id` (read-only), `name` (required, list), `description`, `parent` (integer, list), `rank` (integer), `dashboard` (integer) |
| `role` | `modUserGroupRole` | `security/role/` | `id` (read-only), `name` (required, list), `description`, `authority` (integer, list; lower = more authority) |
| `resource_group` | `modResourceGroup` | `security/resourcegroup/` | `id` (read-only), `name` (required, list) |

Take the exact column names, the fields each processor requires and the processor file
names from the MODX source above; if a processor expects a property under another name than
the column, handle it in the kind (`processorValue` / a new small hook), not in the generic
code, and say so in the report. If the source contradicts this table, follow the source and
report it.

Relations (all **read-only**: no `writable`; they are part of the snapshot so that an undo
of a delete restores them):

- `user_group.members` — class `modUserGroupMember`, fk `user_group`, present
  `['member', 'role']`; description "Users in the group with their role ids."
- `resource_group.resources` — class `modResourceGroupResource`, fk `document_group`,
  present `document`; description "Ids of resources in the group."
- `role`: none.

Do not add any other relation (access control lists come in a later brief).

MODX's own refusals (deleting the Administrator group or the Super User role, a role that
is in use, duplicate names) must pass through as `validation_failed` with MODX's message.
Do not add checks of your own for them.

## Out of scope

Users, access policies, ACL entries, adding/removing members or resources (later briefs),
any new tool, any change to generic code beyond a small kind hook if the processors need it.

## Tests (required)

Extend the fake's `runProcessor` with create/update/remove for the three processor paths,
mirroring what the real processors require and refuse (name required and unique; refuse to
remove group id 1 and role id 1 the way MODX does — check the source for the exact rule),
and add the cascades MODX has (removing a group removes its `modUserGroupMember` rows;
removing a resource group removes its `modResourceGroupResource` rows).

`tests/php/run.php`, new block:

- with the `access` group enabled (it is, in the default fake options): an `admin` token can
  create, get, list, update, duplicate and delete each kind; results validate against the
  action schemas (the `$call` helper already does that).
- `develop` and `content` tokens can list/get but writes are `forbidden_scope`.
- with `modxmcp.disabled_groups` containing `access`: the three kinds are absent from
  `modx_describe` and `modx_capabilities.kinds`, listed in `unavailable_kinds` with reason
  `disabled_group`, and every tool call on them fails with `unavailable`.
- a token with `denied_groups: ['access']`: same, reason `denied_for_token`.
- `user_group` with two member rows stored in the fake: `modx_get` shows them; deleting the
  group and undoing restores the group with the same id **and** both member rows.
- `resource_group` likewise with two resource rows.
- deleting the protected group / role fails with `validation_failed`; nothing journaled.
- duplicate name fails; `modx_duplicate` produces `"<name> copy"`.

Update the expected kind lists/counts in `tests/php/run.php` and `tests/endpoint.test.js`
(tool lists do not change).

## Acceptance

`npm run check`, `npm test`. Files: three new kind classes, `src/Kind/KindRegistry.php`,
`src/Kind/Kind.php` only for a hook the processors force, tests, `tests/php/FakeModx.php`.
