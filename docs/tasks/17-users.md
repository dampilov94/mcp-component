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

## Report

Based on: main at 226e6dc

Counts: before, `npm run check` OK (135 PHP files), PHP 1327 checks, Node 25 tests. After: check OK (136 PHP files), PHP 1553 checks, Node 25 tests (all pass). One existing assertion changed, see deviations; none weakened or deleted.

Implemented, file by file:

- `src/Kind/UserKind.php` (new, registered in `KindRegistry`): kind `user`, `modUser`, `security/user/`, delete processor `delete`, group `access`, read and write scope `admin`, name field `username`. Fields as in the brief; `password` is `write_only` + `required`; profile fields are `via => 'profile'`. Relations `profile` (hidden, in the snapshot), `groups` (read-only, `user_group` + `role`), `settings` (`delete_only`). `processorData()` turns `password` into `passwordgenmethod=spec`, `specifiedpassword`, `confirmpassword`, `passwordnotifymethod=s` (+ `newpassword=true` on update). `secretColumns()` answers `password, cachepwd, salt, hash_class, remote_key, remote_data, session_stale`. `maskRelationRows()` drops the profile `sessionid`. `assertObjectWritable()`: service user (`modxmcp.service_user_id`, default 1) cannot be updated, deleted or duplicated; a sudo user cannot be deleted or duplicated. `assertUpdatable()` (new hook): on a sudo user a changed `username`, `active`, `blocked` or any `password` is refused; the same value is no change. `duplicateOverrides()` sets `primary_group` to 0 (the copy has no memberships).
- `Kind.php`: field flags `via` and `write_only`, relation flag `hidden`; hooks `viaRow()` and `assertUpdatable()` (no-op defaults); `viaRelations()`, `rawValue()`; `value()` / `processorValue()` follow `via`; `value()` of a write-only field is always `''`; `describe()` skips hidden relations and shows `write_only`.
- `Snapshot.php`: `stateValue()` reads a field of a state, following `via` into the relation row; `changed()` uses it (also for the list of a create), compares the rows of a `via` relation without the `via` columns (so a profile change is named by the changed fields, an unlisted profile column shows as `profile`) and does not list a via-backing relation as created. `ObjectSubject::describeChange()` uses `stateValue()`.
- `Objects::save()` does not send write-only fields in the full record (the stored value is a hash). `UpdateAction`: calls `assertUpdatable()` and reads the dry-run current value through `stateValue()`. `GetAction` / `ListAction`: write-only fields are never returned and cannot be requested, hidden relations are not shown, `where` refuses `via` and write-only fields. `DuplicateAction`: write-only fields are not copied (so `password` is required through the normal required-field check), hidden relations are not reported as "not copied".
- `UserGroupKind`: `add_member` / `remove_member` touch the user (kind `user`) before the processor.
- Tests: `FakeModx.php` gets `modUser` / `modUserProfile` (`getOne('Profile')`), cascades of a user (profile, memberships, settings), composite key of `modUserSetting`, and `security/user/{create,update,delete}` with the validation of `_validation.php` (username, password rules incl. `password_min_length`, e-mail incl. `allow_multiple_emails`, dates), the delete refusals (oneself, last Administrator), the password properties and checkboxes, and the option `fake_unconditional_checkbox`. `run.php`: new section "Kind user (task 17)", 226 checks (describe, create/get/list/update/delete/duplicate, undo of each, password never in any result, history or diff, sudo and service-user rules, scopes, disabled group, membership through `user_group`). `endpoint.test.js`: an admin token sees 16 kinds, a read token still 15 (the `user` kind is admin-only, so it is not listed for it).

Deviations and conservative readings:

- The existing assertion `count(changes(add_member changeset)) === 1` (task 10 section) is now `=== 2`: the brief makes `add_member` journal the user as well, so the changeset holds the group and the user. Nothing else of that block changed.
- Extra file edits beyond the brief's list: `UpdateAction`, `Objects::save()`, `DuplicateAction` (the brief allowed it only if needed; it is: write-only, hidden relations), `ObjectSubject`, `GetAction`/`ListAction` as the brief said. A new hook `assertUpdatable()` was added because no existing hook sees both the object and the columns of an update.
- Duplicate: `primary_group` of the copy is 0 (not in the brief; the memberships are not copied, so a copied primary group would be wrong).
- Fields `blockeduntil` / `blockedafter` are writable datetimes, as in the brief; clearing is `""`.

MODX source vs brief:

- The checkbox trap does not exist in 2.8.8 as described. `modProcessor::setCheckbox()` acts only `if ($force || isset($this->properties[$k]))`, so an absent `active` / `blocked` / `sudo` is left alone, and `sudo` is changed only `if ($sudo !== null)`. The update data nevertheless always carries the current `active`, `blocked` and `sudo` (Objects::save sends the whole record; `sudo` is a read-only but not derived field, so it is sent from the object, never from the caller), which is harmless and protects against any MODX version where `setCheckbox` is unconditional. The fake follows the source by default; with `fake_unconditional_checkbox` it resets an absent checkbox, and the sudo test runs under both settings.
- `modUser::set('sudo')` returns false outside setup, and `set('password')` hashes: snapshots and undo write raw (`fromArray(..., true, true)`), so the stored hash is restored as is.
- The update processor's `cleanup()` returns the user without password and salt; the create processor answers with the whole user (hash included) and, with `passwordnotifymethod=s`, a message with the plain password. The kind never reads that response beyond the id.
- MODX requires an e-mail on create and on every update (`checkEmail`); a user whose stored e-mail is empty cannot be updated through the API until `fields.email` is given. Passed through, not worked around.
- Password rules: byte length against `password_min_length` (default 8) and the character class `[^'<>();"{}/\]`; passed through.

Verified only against the fake MODX (please check on the stand): all processor property names and refusals above; that `$user->getOne('Profile')` returns a fresh row after an update (with `cache_db` on xPDO might return a cached related object; the snapshot itself reads the rows directly, only `get`/`list` values go through `getOne`); restoring `modUser` (with `sudo`, `session_stale`, `remote_data` raw), `modUserProfile`, `modUserGroupMember` and `modUserSetting` rows through `fromArray`/`save`; that undo of a user create removes the profile and settings through xPDO composites; the effect of `security/group/user/create` on `primary_group`.

Open questions:

- A group delete removes its memberships (and may change members' `primary_group`) without journaling the users, so a later undo of a user change can report a conflict. Cascading a group delete over its members (`deleteCascade`) was not asked for.
- Logins change the profile (`sessionid`, `lastlogin`, `thislogin`, `logincount`) outside the journal, so a user's revision moves after every login; an undo then reports a conflict, as for any outside change.
- `remove_member` / `add_member` do not protect the service user's own memberships.
- The journal's `history` shows the username (label) of a user change to any token with the history tool, regardless of read scope; `history_diff` is already refused for a token without the admin scope.

## Review

Accepted and merged. Verified on the stand with `scripts/stand/check-users.cjs`: a test user
created, its profile and password changed (the password never appears in any tool result),
membership journaled for both the user and the group, delete and undo restoring user,
profile and membership exactly, the sudo service user protected.

The finding that the checkbox/sudo trap does not exist in 2.8.8 is correct (the brief was
wrong); keeping the current values in the update data is the right defence anyway.

Changes the architect made on the branch:

1. **Logins changed a user's revision** (the report's open question): every login rewrites
   `lastlogin`, `thislogin`, `logincount`, `sessionid` in the profile and MODX sets
   `session_stale` on the user. New relation flag `ignore_columns`: such columns are not part
   of the state and keep their current values when a one-row relation is restored;
   `UserKind::stateColumns()` drops `session_stale`.
2. **The service user could be removed from its groups** through `user_group`
   `remove_member`, which could cut the API off. Refused now.

Noted, not changed: deleting a user makes MODX itself log "Could not get table class for
class: modAccess" (an abstract composite of `modPrincipal`); harmless. `modx_history`
shows usernames as labels to any token with the history tool.

Architect's own mistake during the review: the first version of the stand check picked the
first listed user as "the service user" and changed a real user's comment; it was undone
at once through the journal (changeset 580 → 581). The check now tries only refusals on
real users.
