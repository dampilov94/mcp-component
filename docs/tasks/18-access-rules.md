# 18 — Access rules: `access_policy`, `policy_template`, `context_access`, `resourcegroup_access`

Branch: `task/18-access-rules` (stack on `task/17-users`). Spec: section 3.1 ("Доступ").
MODX source to read first (`modx-example-2-8-8/core/model/modx/`):
`processors/security/access/policy/` (`create`, `update`, `remove`),
`processors/security/access/policy/template/` (`create`, `update`, `remove`),
`processors/security/access/usergroup/context/` and `…/usergroup/resourcegroup/`
(`create`, `update`, `remove`), `processors/security/access/flush.class.php`, maps
`mysql/modaccesspolicy.map.inc.php`, `modaccesspolicytemplate.map.inc.php`,
`modaccesspermission.map.inc.php`, `modaccess.map.inc.php`, `modaccesscontext.map.inc.php`,
`modaccessresourcegroup.map.inc.php`.

## Goal

Four kinds to read and manage MODX access control with the generic tools, safely.

## Design (decided — do not change)

All four: group `access`, read scope `admin`, write scope `admin`, integer key, processor
paths as above, delete processor `remove`.

| Kind | Class | Fields |
|---|---|---|
| `access_policy` | `modAccessPolicy` | `id`, `name` (required, list), `description`, `parent` (int), `template` (int, required, list), `class`, `lexicon`, `permissions` — see below |
| `policy_template` | `modAccessPolicyTemplate` | `id`, `name` (required, list), `description`, `template_group` (int, list), `lexicon`; relation `permissions` (class `modAccessPermission`, fk `template`, present `['name', 'description', 'value']`, read-only) |
| `context_access` | `modAccessContext` | `id`, `target` (string context key, required, list), `principal` (int user group id, required, list), `authority` (int, required, list), `policy` (int, required, list); `principal_class` fixed to `modUserGroup` |
| `resourcegroup_access` | `modAccessResourceGroup` | `id`, `target` (int resource group id, required, list), `principal`, `authority`, `policy`, `context_key` (string, list) — as context_access |

Name field: `name` for policies and templates. The two ACL kinds have no name: use `id` as
the name field (lookups by `name` are refused with a message to use `id`), and add a computed
derived string field `label` built as `"<group name> → <target> (<policy name>, authority N)"`
for lists (`list` true, `computed` true).

`access_policy.permissions`: the column `data` is a JSON object `{permission: bool}`. Expose
it as API field `permissions` of type `object`. `Schema` / `Objects::normalize` support only
string/integer/boolean today — add `object` (a JSON object; in the API an associative array
/ `stdClass`), encoded to JSON for the processor exactly as the policy update processor
expects it (read `update.class.php`: it may take `data` as a JSON string). Every value must
be a boolean (`validation_failed` otherwise). An update of `permissions` **replaces** the map.
`modx_get` returns it as an object, keys sorted.

`context_access` / `resourcegroup_access` lists accept `where` on `target`, `principal`,
`policy`.

### Safety rules (refuse with `validation_failed` before anything is touched)

Mistakes here can lock everyone out of the manager. The API never changes:

1. ACL entries whose `principal` is user group **1** (Administrator) — not update, delete,
   or duplicate them, and not create a new one for group 1.
2. Any policy referenced by an ACL entry of group 1 (look it up in both ACL tables), and the
   template of such a policy — not update, delete or duplicate-over them.
3. Policies and templates whose name is one of MODX's built-in ones (take the list from the
   install data in `modx-example-2-8-8/setup/includes/` or the policy fixtures you find;
   cite the file in the report). They may be duplicated (the copy is not built in).

After every successful write to any of the four kinds, flush permissions the way
`security/access/flush` does (call a `Kind::afterWrite()` implementation; do not run the
processor if it needs request context it does not have — then reproduce its effect and say
so).

### Undo

Deleting a policy cascades in MODX? Check the remove processor; if it removes ACL entries
that use the policy, journal them like the context's settings in task 16
(`deleteCascade()` → each ACL entry as a deleted `context_access` / `resourcegroup_access`).
`policy_template` delete likewise for its policies and permissions if MODX cascades.

## Out of scope

ACLs for categories, media sources, namespaces; policy import/export; user group
inheritance; a tool to check "can user X do Y".

## Tests (required)

Fake processors and cascades as the source behaves; built-in names from the cited file.

- each kind: create, get, list (with `label` for ACLs), update, delete, duplicate, undo.
- `permissions`: object in and out; non-boolean value refused; update replaces the map;
  diff shows old and new maps.
- every safety rule, for every write path (update, delete, duplicate, create for rule 1,
  undo restoring a forbidden change if reachable).
- permissions flushed after writes (observable on the fake).
- `develop` token: `forbidden_scope` for reads too; `access` disabled → unavailable.
- delete cascades and their undo.

Update expected kind lists/counts in both test files.

## Acceptance

`npm run check`, `npm test`. Files: four new kind classes, `src/Kind/KindRegistry.php`,
`src/Schema.php` and `src/Kind/Objects.php` / `src/Kind/Kind.php` for the `object` field
type, tests, `tests/php/FakeModx.php`.

## Report

Based on: main at c724d23 (branched from `main`, not stacked: task 17 is merged).

Counts: before, `npm run check` OK (136 PHP files, 17 JS files), PHP 1553 checks, Node 25 tests. After: check OK (142 PHP files), PHP 1948 checks, Node 25 tests (all pass). One existing assertion changed, see deviations; none weakened or deleted.

Implemented, file by file:

- `src/Kind/AccessPolicyKind.php`, `PolicyTemplateKind.php`, `ContextAccessKind.php`, `ResourceGroupAccessKind.php` (new, registered in `KindRegistry` after `user`), plus `AbstractAclKind.php` (shared base of the two rule kinds: fields, computed `label`, rule 1, undo guard, flush) and `AccessRules.php` (built-in name lists, "group 1 uses this policy" lookups, cascade helpers). All four: group `access`, read and write scope `admin`, integer key.
- `Kind.php`: field type `object` (a JSON object of name => boolean; `Kind::objectValue()` returns it with sorted keys, always as an object), hooks `hasNames()`, `beforeCreate(Context, columns)` (may refuse; returns a closure run after the object exists) and `assertUndoable(modx, key, target)`.
- `Objects.php`: `normalizeObject()` (every value must be boolean, a list or non-object is `validation_failed`, keys sorted, an empty array is the empty object because `json_decode(..., true)` cannot tell them apart), `sameValue()` (dry run compares maps as maps), `resolve()` refuses `name` for kinds without names, `save()` runs `beforeCreate`. `UpdateAction` dry run uses `sameValue()`; `ListAction` refuses `where` on an object field; `DuplicateAction` handles kinds without names (`new_name` refused, copy made from the fields plus overrides); `ObjectSubject::describeChange()` shows an object field as old/new objects; `Snapshot::restore()` and `Undo::plan()` call `assertUndoable()` (so the rollback and undo paths are both covered). `Schema.php` is unchanged: its validator already knows `object`.
- Tests: `FakeModx.php` gets the four processor families (source behaviour below), `FakeCache::flushPermissions()`, recursive composite removal (child policies), `FakeObject::$xpdo`, and json decoding of a policy's `data`. `run.php`: new section "Access kinds (task 18)", 395 checks. `endpoint.test.js`: an admin token now sees 20 kinds (a read token still 15).

MODX source vs brief (files under `modx-example-2-8-8/core/model/modx/`):

- `processors/security/access/policy/update.class.php` does not read `data`: it reads `permissions` as a list of `{name, enabled}` (array or JSON) and builds `data` from it. `policy/create.class.php` ignores any given data and sets `data` from the template's permissions (all on). So `processorData()` converts the map to that list on update, and a create with `permissions` is followed by an update call inside the same transaction (via the new `beforeCreate` closure). An update that does not touch `permissions` sends no `permissions` at all (otherwise the stored text `{}` would turn into `[]` on every unrelated update).
- `mysql/modaccesspolicy.map.inc.php`: `Children` is a composite, so deleting a policy deletes its child policies (recursively); a rule that uses the policy is only an aggregate and stays (dangling). The brief expected ACL entries to be removed: they are not. Child policies are journaled through `deleteCascade()` as deleted `access_policy` objects; there is nothing to journal for rules.
- `modaccesspolicytemplate.map.inc.php`: a template's composites are `Permissions` and `Policies`: deleting a template deletes its permissions (part of the template's snapshot through the `permissions` relation) and its policies and their children (journaled as `access_policy`). `policy/template/remove` is the plain remove processor.
- `usergroup/context/create.class.php` `afterSave()`: creating a context rule for a group other than Administrator also creates a rule for the Administrator group on that context (policy "Context") when none exists. This is a group 1 rule created by MODX itself. It is journaled together with the create (closure in `ContextAccessKind::beforeCreate`), and the undo guard allows removing a group 1 rule (but never creating or changing one), so undoing the create takes it away again. Not in the brief; the resource group create has no such side effect.
- The context and resource group update processors require `principal`, `target`, `policy` and `authority` on every update (all four sent by `Objects::save`); principal 0 fails MODX's `== null` check.
- `security/access/flush` only calls `cacheManager->flushPermissions()`; `afterWrite()` of the four kinds calls exactly that (not the processor). The real policy processors already flush; the extra flush is harmless.
- Built-in names are in `core/packages/core/modAccessPolicy/*.vehicle` and `core/packages/core/modAccessPolicyTemplate/*.vehicle`; `setup/includes/` does not exist in the local tree. Policies (12): Resource, Administrator, Load Only, Load, List and View, Object, Element, Content Editor, Media Source Admin, Media Source User, Developer, Context, Hidden Namespace. Templates (7): ObjectTemplate, AdministratorTemplate, ElementTemplate, ContextTemplate, ResourceTemplate, MediaSourceTemplate, NamespaceTemplate.

Deviations and conservative readings:

- Existing assertion changed: `user: the kind list of an admin token ends with user` (task 17 section) now checks that `namespace, user` sit at positions 14-15, because the four new kinds follow `user` in the list. Reason: the brief adds kinds. `endpoint.test.js`: admin token kind count 16 to 20.
- Group 1 means id 1 and also a group named `Administrator` with another id (`AccessRules::adminGroupIds`). The "policy used by group 1" lookup covers all five ACL tables (context, resource group, category, namespace, media source), not only the two named. A rule with principal 1 is refused whatever its `principal_class`.
- Rule 3 also compares names case-insensitively and with `mb_*`; an object named `load only` is built in. Create or rename to a built-in name is not specifically refused (MODX's unique index/name check handles real clashes).
- Rule 2 duplicate: duplicating a policy or template that group 1 uses is allowed (the copy has a new name and is free), like the built-ins; the brief's "duplicate-over" was read that way. Pointing another group's rule at a protected policy is allowed (it changes no protected object).
- A template is protected when built in or when it is the template of a policy group 1 uses. Delete additionally refuses when any policy MODX would remove with it (children included) is protected, so a custom template that holds a built-in policy can be edited but not deleted. Delete of a policy refuses when a descendant is protected.
- `uniqueNames()` and `hasNames()` are false for the rule kinds; `duplicate` without `new_name` copies the rule and takes `fields` for what must differ (an identical copy is MODX's "already exists" refusal). Lookup, update and delete by `name` give `validation_failed` "use the id argument".
- `label` of a resource group rule shows the resource group's name (id if gone), of a context rule the context key; principal 0 shows "(anonymous)". `principal_class` is exposed as a read-only field.
- A policy duplicate or create with `permissions` costs two processor calls (create, then update); both in one transaction.
- `policy_template` duplicate does not copy the permissions (the relation is read-only, as the brief says); a warning "Not copied: permissions" is shown.

Verified only against the fake MODX (please check on the stand): all processor property names and refusals; that `modAccessPolicyUpdateProcessor` accepts `permissions` as an array of `{name, enabled}` and sets `data`; that the create processor's Administrator side effect has the shape described and `afterSave` is not skipped by `runProcessor`; xPDO composite removal of child policies and a template's policies (the fake removes them recursively through `remove()`); that `toArray('', true)` returns the raw JSON text of `data` and `fromArray(..., true, true)` restores it; `cacheManager->flushPermissions()` exists and is callable from the API request; the classes `modAccessCategory`, `modAccessNamespace`, `sources.modAccessMediaSource` are loadable (used only in count/collection lookups for rule 2, as `UserGroupKind` already does).

Open questions:

- Deleting a policy leaves rules that point at it (MODX behaviour). Should the delete dry run list such rules, or should deleting a policy that any rule uses be refused?
- `user_group` delete (kind from task 8/10) cascades the group's rules (`acl_*`, delete-only) and could delete group 1's rules if MODX allowed deleting group 1; the fake and the processor refuse deleting Administrator, so nothing was changed there. A context delete likewise removes its `acl` rows (including group 1's) by design.
- The context rule kind lists the Administrator group's rules too (read-only). Hide them from lists?
- `docs/getting_started.md` (built-in help) does not mention the four kinds yet; left alone as out of scope.
