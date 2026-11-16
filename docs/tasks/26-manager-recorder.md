# 26 — Recording edits made in the manager

Branch: `task/26-manager-recorder` (already created by the architect, stacked on
`task/25-history-page`; work on it, do not branch again). Spec: section 8.10 ("Правки людей в
админке и в обход журнала"). Read first: `src/Write/Transaction.php`, `src/Write/Writer.php`,
`src/Context.php` (`forManager`, `managerUserId` from brief 25), how `UpdateAction`,
`CreateAction` and `DeleteAction` touch objects and their cascades (`alsoAffected()`), and
`Kind::eventNames()`.

## Goal

When a person saves or deletes something in the MODX manager, the change lands in the journal
at that moment, with the user as its author, and can be inspected and undone like a change made
through the API. Today such a change is only noticed at the next API write to the same object
and recorded as `external`, without author or time.

## Design (decided — do not change)

### Recorder

`src/Manager/Recorder.php`, a final class with one public entry point
`Recorder::handle(\modX $modx, $eventName, array $params)` called by the plugin, and
`Recorder::events()` returning every MODX event name it listens to (derived from the kinds).

The recorder reuses the write transaction instead of journaling by itself:

- **Before-event** (object exists): build `Context::forManager($modx, $modx->user, $corePath)`,
  open a `Transaction` with tool `manager`, `touch()` the object and what the kind reports as
  also affected (exactly what the corresponding API action touches before it changes the
  object), and keep the open transaction in a static map keyed by `kind:key`.
- **After-event**: take the transaction for that object from the map (for a creation there is
  none: open one and register the object with `created()`), `commit()` it with a summary such as
  `save chunk 12 in the manager` / `delete resource 7 in the manager`, then `release()` it.
  Brief 25 already makes a manager context journal `source: manager` with `user_id`.
- An after-event that has no before-event (see the table) and no open transaction: open a
  transaction, `touch()` now — which records nothing by itself — and compare with the journal's
  last known state through the existing outside-change detection. To give that detection the
  author, extend `Transaction::recordOutsideChange()`: when the context has `managerUserId`,
  the changeset is recorded with `source: manager`, that `user_id`, the summary
  `<kind> <key> was changed in the manager` and no "author unknown" note. An object the journal
  has never seen stays unrecorded in this case (there is no earlier state to compare with).
- A before-event whose save then fails never gets an after-event: its transaction is simply
  left to end with the request. Nothing is journaled. Do not add shutdown handlers.

Rules the recorder must keep:

1. **It never breaks or slows a manager action.** Everything in `handle()` is inside one
   `try`/`catch (\Throwable)`; an error is logged with `[modxmcp]` and swallowed. A lock held by
   an MCP request (`ApiException` `locked`) is not an error: skip recording, the outside-change
   detection will pick the change up later.
2. **It records only what people do.** Add a static counter to `Transaction` (incremented in
   the constructor, decremented in `release()`, with a static `Transaction::depth()`), and make
   the recorder ignore every event while a transaction that is not its own is open. That covers
   API requests (their processors fire the same events), the undo from the history page and
   nested saves. The recorder's own open transactions must not count against it: track them.
3. It does nothing unless the setting `modxmcp.history_track_manager` is on (default on) and
   `$modx->user` is a logged-in user (id > 0). It does nothing in the `mgr`-less CLI/setup
   (`xPDO::OPT_SETUP`).
4. Nothing is written when the state did not change (the transaction already guarantees it).
5. Guards for the modxMCP component's own settings and token data apply as for any transaction;
   do not add exceptions.

### Events

Extend `Kind::eventNames()` with optional keys `before_save` and `before_delete` (keep `save`,
`delete`, `param`), and add `Kind::managerEvents()` returning extra after-only events as
`event name => how to find the object(s)` for cases below. Fill them from the real MODX source
in `modx-example-2-8-8/` — read the processors and `modcategory.class.php`, do not trust this
table blindly, and report differences:

| Kind | before / after save | before / after delete | after-only |
|---|---|---|---|
| chunk | OnBeforeChunkFormSave / OnChunkFormSave | OnBeforeChunkFormDelete / OnChunkFormDelete | |
| snippet | OnBeforeSnipFormSave / OnSnipFormSave | OnBeforeSnipFormDelete / OnSnipFormDelete | |
| template | OnBeforeTempFormSave / OnTempFormSave | OnBeforeTempFormDelete / OnTempFormDelete | |
| plugin | OnBeforePluginFormSave / OnPluginFormSave | OnBeforePluginFormDelete / OnPluginFormDelete | |
| tv | OnBeforeTVFormSave / OnTVFormSave | OnBeforeTVFormDelete / OnTVFormDelete | |
| category | OnCategoryBeforeSave / OnCategorySave | OnCategoryBeforeRemove / OnCategoryRemove | |
| resource | OnBeforeDocFormSave / OnDocFormSave | OnBeforeDocFormDelete / OnDocFormDelete | OnResourceUndelete, OnDocPublished, OnDocUnPublished, OnResourceSort (with OnResourceBeforeSort as its before), OnEmptyTrash (with OnBeforeEmptyTrash) |
| user | OnBeforeUserFormSave / OnUserFormSave | OnBeforeUserFormDelete / OnUserFormDelete | OnUserActivate, OnUserDeactivate |
| media_source | OnMediaSourceBeforeFormSave / OnMediaSourceFormSave | OnMediaSourceBeforeFormDelete / OnMediaSourceFormDelete | |
| context | | | OnContextUpdate (and whatever create/remove fire, if anything) |

For each event, read in the MODX source which parameters it passes (`id`, `mode`, the object
under a kind-specific name, lists of ids for sort and trash) and resolve the object key from
them. A before-save with `mode` "new" has no object yet: ignore it and treat the after-event
as a creation. Sorting and emptying the trash touch many resources: touch each affected
resource (emptying the trash: every resource that is deleted now, before; they are gone after),
with a cap of 500 objects — above it, skip recording and log one line.

Kinds without save events (system settings, access rules, lexicon, namespaces, add-on kinds)
are out of scope; they keep being detected as `external`.

### Plugin

- `core/components/modxmcp/elements/plugins/recorder.php`: the plugin body, three or four
  lines — locate `src/autoload.php` through `modxmcp.core_path`, return if it is missing, call
  `\ModxMCP\Manager\Recorder::handle($modx, $modx->event->name, $scriptProperties)`. No logic.
- `_build/build.transport.php`: package a `modPlugin` named `modxMCP` (description from the
  lexicon-free plain text "Records changes made in the manager into the modxMCP journal."),
  with that file's content as code, attached to `Recorder::events()` (priority 0), so the
  package owns the plugin and updates it on upgrade.
- `src/Manager/Recorder.php::syncPlugin(\modX $modx)`: create or update that plugin object and
  make its events equal to `Recorder::events()` — used by the install resolver
  (`_build/resolvers/resolve.token.php`, after the tables) so that an upgrade which adds kinds
  also adds their events, and callable by the architect on a file-only deployment. It changes
  only the plugin named `modxMCP` and its `modPluginEvent` rows.
- The plugin and its events must be invisible to the recorder itself and harmless to the API:
  the journal should not record the plugin's own creation by `syncPlugin`.
- Setting `modxmcp.history_track_manager` (combo-boolean, default 1, area `modxmcp:main`) in
  `_build/data/transport.settings.php`, with lexicon entries in both languages.

## Out of scope

Refusing an API write while a person has the object open (next brief), changes to the history
page, recording kinds that have no events, static files edited outside MODX.

## Tests (required)

Extend `tests/php/FakeModx.php` only as far as needed: the fake processors must fire the
before/after events the real ones fire (they currently only collect names in `$modx->events`);
give the fake a way to register a plugin callback so tests can route events into
`Recorder::handle()`, and a current user.

`tests/php/run.php`, a new block "Manager recorder":

- a manager save of an existing chunk: one changeset, `source: manager`, `user_id` of the user,
  `tool: manager`, before/after states right (`modx_history_diff` shows the changed field),
  `modx_history` shows the username as author; undo through `modx_undo` restores it;
- creation and deletion in the manager (delete: related rows captured, undo brings the object
  back with its id);
- a resource delete in the manager that trashes descendants: all of them in one changeset;
- a save that changes nothing: no changeset; a save that fails after the before-event: no
  changeset, and a later successful save is still recorded correctly;
- the same processors run through an API action (`modx_update` etc.): exactly one changeset,
  `source: mcp` — the recorder stayed silent; the same for an undo from `HistoryService`;
- an after-only event (publish from the tree) for a journaled resource: recorded as `manager`
  with the user; for a never-journaled resource: nothing recorded, no error;
- the object was changed outside the journal before the manager save: two changesets, first
  the outside change, then the manager one with the right before-state;
- an MCP lock on the object during the manager save: the save succeeds, nothing recorded,
  nothing thrown; a later API touch records the change as outside (current behaviour);
- setting off: nothing recorded; no logged-in user: nothing recorded;
- an exception inside the recorder (make the fake journal fail) does not reach the caller;
- `Recorder::events()` contains each event of the table exactly once and no event twice;
  `syncPlugin` creates the plugin with exactly those events, is idempotent, removes events that
  are no longer listed, and leaves other plugins alone; neither run leaves journal entries.

## Acceptance

`npm run check`, `npm test`. No existing assertion edited. Report as usual, and list precisely
which event parameters you took from the MODX source for each event, since those can only be
verified in a real manager.
