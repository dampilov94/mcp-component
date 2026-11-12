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
