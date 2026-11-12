# 10 — `modx_invoke` and membership operations

Branch: `task/10-invoke`. Spec: section 3.2 ("Особые операции (`invoke`)").
MODX source to read first: `modx-example-2-8-8/core/model/modx/processors/security/group/user/`
(`create`, `remove`), `…/security/resourcegroup/` (`updateresourcesin`, `removeresource`),
and `joinGroup()` / `leaveGroup()` in `modx-example-2-8-8/core/model/modx/modresource.class.php`.

## Goal

One generic tool for operations of a kind that are not create/read/update/delete, plus the
first operations: group membership.

`modx_invoke {kind, op, id?, name?, args?, dry_run?}`

## Design (decided — do not change)

### Declaring operations

`Kind::operations()` changes its return format (nothing uses the old one yet) to
`op name => spec`:

```php
'add_member' => array(
    'description'  => 'Add a user to the group.',
    'read_only'    => false,
    'needs_object' => true,                       // default true; false = an operation of the kind itself
    'input'        => Schema::obj(array('user' => Schema::int(null, array('minimum' => 1)),
                                        'role' => Schema::int(null, array('minimum' => 1))), array('user')),
    'handler'      => 'opAddMember',              // public method on the kind class
),
```

Handlers:

- read-only op: `handler(Context $context, $object, array $args): array`
- write op: `handler(Context $context, Transaction $tx, $object, array $args): array`

`$object` is the xPDO object, or `null` when `needs_object` is false. The handler returns
the operation's own result keys (may be empty). A write handler must `touch` every other
object it changes; the target object is already touched by the action.

`Kind::describe()` lists operations as `{op, description, read_only, needs_object, input}`.

### `src/Action/InvokeAction.php`

- Extends `WriteAction` (so a request ID protects it), name `invoke`, group `objects`,
  registered after `DuplicateAction`. Override `scope()` to `read`: read-only operations
  must be usable by read tokens; write operations check the kind's write scope at run time.
  `destructive()` true (it can run destructive operations).
- Input: `kind` (required), `op` (required, `^[a-z][a-z0-9_]{0,63}$`), `id`, `name`,
  `args` (open object, default `{}`), `dry_run`.
- Steps:
  1. `Objects::kind($context, $kind, false)`; unknown op → `not_found` listing the kind's
     ops ("see modx_describe").
  2. Write op: the token must have the kind's write scope (`forbidden_scope` otherwise).
  3. `needs_object` true → `Objects::resolve()` (id or name required); false → `id`/`name`
     must be absent (`validation_failed`).
  4. Validate `args` against the op's `input` with `Schema::validate` (path prefix
     `args`). The kernel hands actions associative arrays; convert with
     `json_decode(json_encode($args ?: new \stdClass()))` first.
  5. Read-only op: call the handler, return
     `['kind' => …, 'op' => …] + (id when there is an object) + handler result`.
  6. Write op with `isDryRun`: do not call the handler; return via `$this->plan()` with
     `kind`, `op`, `id`, `validated: true`.
  7. Write op: `Writer::run`; touch the target object (when there is one); call the
     handler; summary `"<op> <kind> <id>"`. Result: `kind`, `op`, `id`, handler result,
     `changeset_id`, and — when there is an object — its `revision` after and `changed`
     (from the commit info, as `WriteAction::written()` does; `revision` must be the current
     one even when the object itself did not change).
- Output schema: `kind`, `op` required; open object.
- Description, short: runs a special operation of a kind; `modx_describe {kind}` lists the
  operations with their arguments.

### First operations

`UserGroupKind`:

- `add_member {user, role?}` — through processor `security/group/user/create` (read it for
  the exact property names; `role` defaults as MODX defaults it). Already a member →
  MODX's own error passes through.
- `remove_member {user}` — through `security/group/user/remove`.
- `member_count` (no arguments) — read-only, returns `{"members": <number of members>}`.
  It exists so that the read-only path of `modx_invoke` has a real operation.

`ResourceGroupKind`:

- `add_resource {resource}` and `remove_resource {resource}`. Prefer the processors; if
  their inputs are tied to the manager tree widget (read `updateresourcesin`), use
  `modResource::joinGroup()` / `leaveGroup()` on the resource instead and say so in the
  report. Either way MODX's `OnResourceAddToResourceGroup` /
  `OnResourceRemoveFromResourceGroup` events must fire (if the methods do not fire them,
  invoke them as the processor does). Touch the **resource** too (kind `resource`): its
  `resource_groups` relation changes. Adding twice / removing a non-member → `validation_failed`.

The four write operations return `{}` plus what the action adds; the group's `members` / `resources`
relation shows the change in `changed`, and `modx_undo` reverts it.

## Out of scope

Any other operation, users as a kind, ACL entries.

## Tests (required)

`tests/php/run.php` (extend the fake's processors / objects as the real ones behave):
describe lists the ops with schemas; add/remove member and resource change the relations,
are journaled as one changeset each (group, plus the resource for resource groups) and are
undone by `modx_undo`; duplicate add and removing a non-member fail and journal nothing;
argument validation (`args.user` missing, wrong type, unknown argument); unknown op;
op on a kind without operations; `needs_object` rules; dry run changes nothing; plan-only
token; scopes: `develop` token cannot run these write ops (`forbidden_scope`), a read token
sees the tool in its manifest; `access` group disabled → `unavailable`.
The read-only path is exercised with `member_count`: a read token can call it.

Update tool lists/counts in both test files.

## Acceptance

`npm run check`, `npm test`. Files: `src/Action/InvokeAction.php`, `src/Action/Registry.php`,
`src/Kind/Kind.php` (operations format + describe), `src/Kind/UserGroupKind.php`,
`src/Kind/ResourceGroupKind.php`, tests, `tests/php/FakeModx.php`.

## Report

Based on: task/09-delete-usages at 6884565

- `InvokeAction.php` / `Registry.php`: generic operation dispatch, per-operation schemas,
  read access/write scope checks, object requirements, previews, Writer commits and current
  revisions even for no-op writes. `Kind.php` describes the new operation declarations.
- `UserGroupKind.php`: processor-based add/remove member and read-only member_count.
- `ResourceGroupKind.php`: joinGroup/leaveGroup plus the processor's event names/parameters;
  group and resource are both touched and journaled. The add processor expects tree-widget
  strings, so the permitted model-method alternative was used for both operations.
- Fake/test changes model memberships, events and processor role default 0; test all paths,
  errors, undo, scopes, plan-only and object-less/no-op contracts using a test-only kind.
  The fake's locale-sensitive alias strtolower corrupted Cyrillic UTF-8 on PHP 7.4; changed
  it to mb_strtolower while adding Cyrillic resource/user fixtures.
- `npm run check` and `npm test` passed: 826 PHP checks, 24 Node/HTTP tests; 30 tools.

Source detail / limitation: group/user/create defaults role to false (stored as 0), not
Member. The real add/remove processors also change modUser.primary_group; the fake mirrors
that side effect. Users are explicitly outside this brief and have no journal kind, so
undo here restores group membership but not primary_group. No extra user kind or direct
site writes were introduced. The architect should decide how to cover that side effect.

Only fake MODX was exercised. Verify processor permissions/defaults, primary-group behavior,
resource-group events and both-object undo on the stand. No deployment or push.

## Review

Accepted and merged with the rest of the stack (09–14). Verified on the stand with
`scripts/stand/check-operations.cjs`; every earlier stand check was rerun.

No changes.
