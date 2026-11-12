# 17 — Kind `user`

Branch: `task/17-users`. Spec: sections 3.1 ("Доступ"), 6 (scopes), 8.2.
MODX source to read first (`modx-example-2-8-8/core/model/modx/`): `processors/security/user/`
(`create`, `update`, `delete` — note: the remove processor of users is `delete`, `_validation.php`),
`moduser.class.php`, `moduserprofile.class.php`, maps `mysql/moduser.map.inc.php`,
`moduserprofile.map.inc.php`, `modusergroupmember.map.inc.php`, `modusersetting.map.inc.php`.

## Goal

Read and manage manager/web users with the generic tools. No new tool.

## Design (decided — do not change)

### The kind

`src/Kind/UserKind.php`: name `user`, class `modUser`, processors `security/user/`, delete
processor `delete`, integer key, group `access`, **read scope `admin`** (users carry personal
data), write scope `admin`, name field `username`.

Fields (columns of `modUser` unless marked *profile*; take exact names from the maps):

| API field | Notes |
|---|---|
| `id` | read-only |
| `username` | required, list |
| `active` | boolean, list |
| `sudo` | boolean, list, **read-only** — never changed through the API |
| `primary_group` | integer, list |
| `class_key` | read-only |
| `fullname`, `email`, `phone`, `mobilephone`, `comment` | *profile*, string; `email` and `fullname` list |
| `blocked` | *profile*, boolean, list |
| `blockeduntil`, `blockedafter`, `lastlogin`, `thislogin` | *profile*; datetime format (`'format' => 'datetime'`); the last two derived |
| `logincount` | *profile*, derived integer |
| `password` | **write-only**, see below |

Never exposed, never written through fields: `password` (hash), `cachepwd`, `salt`,
`hash_class`, `remote_key`, `remote_data`, `session_stale`, profile `sessionid`.

### Profile fields

Profile fields live in `modUserProfile` (fk `internalKey`). Add to the `Kind` base a field
flag `'via' => '<relation name>'`: such a field is read from the one related row of that
relation (`Kind::value()` asks a new hook `viaRow($object, $relation)`; the user kind returns
`$object->getOne('Profile')`), is sent to the processors like any other field (the user
processors take profile properties in the same request — verify in the source), cannot be
used in `where` (no `no_filter` needed: refuse `via` fields there), and is not a column of
`modUser` (exclude it wherever columns of the model class are assumed: `Objects::normalize`
must still produce `column => value` for the processor, but snapshot/`changed()` must not
expect it in `fields`).

The profile is relation `profile` (class `modUserProfile`, fk `internalKey`, `in_state`
default true, not shown by `modx_get` as a relation — add a spec flag `'hidden' => true` for
that) so its changes are part of the user's revision and an undo restores them. `changed()`
then reports a profile change as `profile`; map it to the changed `via` field names instead
(compare the profile row of both states field by field).

### Checkboxes and sudo — a MODX trap

`security/user/update` calls `setCheckbox('active')`, `setCheckbox('blocked')` and
`setCheckbox('sudo')`: a missing property becomes **false**. Because the service user runs
as sudo, the processor would then strip `sudo` from every user the API updates. The update
data must always carry the current `active`, `blocked` and `sudo` values (sudo from the
object, never from the caller). Add a test that updating an admin user's email keeps
`sudo` true.

### Password

- `password` is accepted by `create` (required there) and `update` (optional), never
  returned by any tool, and must not appear in the journal's `summary`, warnings or errors.
- Map it to the processors' own mechanism (read `_validation.php`: on create
  `passwordgenmethod` = `spec` with `specifiedpassword` / `confirmpassword`,
  `passwordnotifymethod` = `s`; on update `newpassword` = `true` plus the same). Never let
  MODX generate or e-mail a password.
- MODX's minimum length rule (`password_min_length`) passes through as its own error.
- The snapshot keeps the stored hash columns (so an undo restores the old password); the
  diff of any of them shows `[hidden]` (the column-name pattern already covers `password`,
  `salt`, `hash`; make sure `cachepwd` and `hash_class` are covered too, via
  `secretColumns`). `changed()` reports a password change as `password`.

### Safety rules (refuse with `validation_failed`, before anything is touched)

- The service user (`modxmcp.service_user_id`) cannot be updated, deleted or duplicated.
- A user with `sudo` cannot be deleted or duplicated, and their `active` / `blocked` /
  `username` / `password` cannot be changed (profile contact fields may be).
- `class_key` and `sudo` cannot be set on create (MODX defaults apply).
- MODX's own refusals (deleting yourself, duplicate username/e-mail) pass through.

### Relations

- `groups` — class `modUserGroupMember`, fk `member`, present `['user_group', 'role']`,
  read-only (membership is changed with `modx_invoke … add_member` on `user_group`).
  Note: membership rows are then part of two snapshots (user and group); this is fine
  here because both sides are touched by the membership operations — make
  `UserGroupKind`'s `add_member` / `remove_member` also touch the user (kind `user`).
- `settings` — class `modUserSetting`, fk `user`, `delete_only` (restored by an undo of a
  delete).

### Duplicate

`modx_duplicate` of a user must not copy the password hash: the copy needs `fields.password`
(validation_failed otherwise) and `new_name` (the username); e-mail must be changed through
`fields.email` if MODX requires unique e-mails (follow the source).

## Out of scope

User settings as a kind, extended profile fields (`extended` JSON), user ACLs, sending
e-mails, `activatemultiple` and the like.

## Tests (required)

Extend the fake with `modUser`, `modUserProfile` (one-to-one, `getOne('Profile')`), the
user processors' relevant behaviour (checkbox reset on update **including sudo**, password
properties, unique username and e-mail, refusal to delete oneself) and cascades.

- admin: create (password not echoed anywhere: assert on the JSON of the result, history and
  diff), get, list (`email` listed; `where` on a profile field refused), update of profile
  and user fields, delete, undo of each, duplicate.
- the sudo trap test above; service-user and sudo-user rules; `class_key`/`sudo` on create.
- password change: diff shows `password` changed with `[hidden]`; undo restores the old hash.
- `develop`, `content`, `read` tokens: `forbidden_scope` even for `list` / `get`.
- `access` group disabled → unavailable.
- membership via `user_group` `add_member` journals both the group and the user; undo of a
  user delete restores memberships and user settings.

Update expected kind lists/counts in both test files.

## Acceptance

`npm run check`, `npm test`. Files: `src/Kind/UserKind.php`, `src/Kind/KindRegistry.php`,
`src/Kind/Kind.php` / `src/Kind/Objects.php` / `src/Journal/Snapshot.php` /
`src/Journal/ObjectSubject.php` / `src/Action/GetAction.php` / `src/Action/ListAction.php`
only for the `via` / `hidden` support, `src/Kind/UserGroupKind.php` (touch the user),
`src/Action/DuplicateAction.php` only if the password rule needs it, tests,
`tests/php/FakeModx.php`.
