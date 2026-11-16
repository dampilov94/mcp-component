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

## Report

Based on: task/25-history-page at 78f12e2 (the branch also holds the brief commit 23629fb on top).

Checks: before `npm run check` 169 PHP files, 24 JS files; `npm test` 4693 PHP checks, 30 node tests. After: 171 PHP files, 24 JS files, 4732 PHP checks, 30 node tests. All pass.

### Implemented

- `src/Manager/Recorder.php` (new): `handle()`, `events()`, `syncPlugin()`, `pluginCode()`. The event table is built from `Kind::eventNames()` and the new `Kind::managerEvents()`. Own open transactions are kept in a static map (`kind:key`, `kind:new`, `resource:sort`, `resource:trash`); a transaction left by a refused save is released when the next before-event for the same object arrives (flock would otherwise block the new one in the same process).
- `src/Write/Transaction.php`: static counter and `Transaction::depth()` (decremented once, `release()` is idempotent); `recordOutsideChange()` records `source: manager`, `tool: manager`, the user, summary `<kind> <key> was changed in the manager` and an empty note when the context has `managerUserId`.
- `src/Kind/*`: `before_save` / `before_delete` added to chunk, snippet, template, plugin, tv, resource; `Kind::managerEvents()` (default empty), filled for resource (undelete, publish, unpublish, sort, trash), category, media_source, user (activate, deactivate), context.
- `core/components/modxmcp/elements/plugins/recorder.php`: the plugin body (4 lines of code).
- `_build/build.transport.php`: packages the plugin `modxMCP` with the events of `Recorder::events()` (related objects `PluginEvents`); `_build/resolvers/resolve.token.php` calls `Recorder::syncPlugin()` after the tables; setting `modxmcp.history_track_manager` in `_build/data/transport.settings.php`, lexicon en and ru.
- Tests: fake processors for chunk, snippet, template, plugin, tv and resource now fire the before and after events with the real parameters (the fields are already set in memory at the before-event, like the real ones); `modX::$listeners` routes events into `Recorder::handle()`; `$failBeforeSave`; fake `resource/publish`, `resource/unpublish`; `resource/emptyrecyclebin` passes `ids`. New block "Manager recorder" in `tests/php/run.php` (39 checks) covering every point of the brief's test list.

### Event parameters taken from the MODX 2.8.8 source

- chunk, snippet, template, plugin, tv (create and update processors, `modObjectCreate/UpdateProcessor`): before `mode` (`new`/`upd`), `data`, `id` (0 for new), object under `chunk`/`snippet`/`template`/`plugin`/`tv`, `object`; after `mode`, `id`, object. Remove processors: `id`, object under the same name, no `mode`.
- resource: `OnBeforeDocFormSave` / `OnDocFormSave`: `mode`, `id` (0 before a creation), `resource`. `OnBeforeDocFormDelete` / `OnDocFormDelete`: `id`, `resource`, `children`. `OnResourceUndelete`, `OnDocPublished`, `OnDocUnPublished`: `id`, `resource` (`docid` too for publish). `OnResourceBeforeSort`: `nodes` (list of `id`, `context`, `parent`, `order`; ids are strings). `OnResourceSort`: `nodes`, `nodesAffected`. `OnBeforeEmptyTrash` / `OnEmptyTrash`: `ids` (and `resources`). Used: `resource`, `id`, `nodes`, `ids`.
- category: `modCategory::save()` fires `OnCategoryBeforeSave` / `OnCategorySave` with `mode`, `category` (object, no id parameter; the id is read from the object); `remove()` fires `OnCategoryBeforeRemove` / `OnCategoryRemove` with `category`, `ancestors`.
- media source: parameter name is `source` (the processors' `objectType`), not `media_source`. Events as in the brief.
- user: `OnBeforeUserFormSave` / `OnUserFormSave` (`mode`, `id`, `user`), `OnBeforeUserFormDelete` / `OnUserFormDelete` (`id`, `user`), `OnUserActivate` / `OnUserDeactivate` (`id`, `user`, `mode` `upd` or `multiple`).
- context: `modContext::save()` / `remove()` fire `OnContextBeforeSave` / `OnContextSave` (`context`, `mode`) and `OnContextBeforeRemove` / `OnContextRemove` (`context`); the update processor also fires `OnContextUpdate` (`context`, `properties`). The key is read from the object.

### Deviations

1. Context: the brief lists only `OnContextUpdate`. The model fires before/after save and remove events for creation, update and removal (also from the grid and the create/remove processors, which fire nothing else), so the recorder listens to those four as well as to `OnContextUpdate`. `events()` therefore has 4 more entries than the brief's table.
2. category, media_source, user and context events live only in `managerEvents()`, not in `eventNames()`: `eventNames()` is also what undo fires through `Snapshot::restore()`, and giving those kinds `save`/`delete` there would start firing `OnUserFormSave`, `OnMediaSourceFormSave` and so on on every undo (the model events of category and context fire on restore by themselves anyway). `Kind::managerEvents()` accepts the roles `before_save`, `save`, `before_delete`, `delete`, `touch`, `before_sort`, `sort`, `before_trash`, `trash`.
3. A before-save with mode `new` is not ignored entirely: the recorder touches what the kind reports as affected by a creation (for a resource, the parent whose `isfolder` changes), so that the parent goes into the same changeset as the new resource.
4. The recorder also stays silent unless `$modx->context->get('key') === 'mgr'` (when the property exists). A processor run from the front end under a logged-in web user is not a manager edit. Not in the brief.
5. Existing assertion edited: in `tests/php/run.php` the allow-list of files that may mention `managerUserId` / `forManager` ("manager context: only the context, the transaction and the service touch it") now also lists `Manager/Recorder.php`, which the brief requires to build a manager context. No other existing assertion was changed.
6. Sort: only the nodes sent in `nodes` (plus their parents and current parents) are touched before the sort; siblings that MODX renumbers (`nodesAffected`) outside that list are not, so their change shows up later as an outside change.
7. `Transaction::recordOutsideChange()` attributes an outside change to the user for every manager context, as the brief says, including the undo run by `HistoryService` (the same `Context::forManager`). See the open question.

### Verified only against the fake MODX (please check on the stand)

- The real event parameters listed above, in particular: the key of a category and a context from the object, the `source` parameter of media sources, `nodes` of the sort, `ids` of the trash.
- That in a real manager save the before-event fires after the processor has put the new field values into the object in memory but before the save, and that `Snapshot::capture()` (a fresh `getObject`) then reads the stored state, not the in-memory one (the fake models this by setting fields before the event; xPDO has no identity map as far as I read).
- The locks held between before and after events (one lock file per touched object, released at commit or at the end of the request) and what a concurrent MCP request sees (`locked`).
- That the recorder's own category save inside an element save (a new category created by the element processor) produces a separate changeset for the category.
- The packaged plugin vehicle (`RELATED_OBJECTS` of `PluginEvents`) and `syncPlugin()` against a real `modPlugin`/`modPluginEvent`; `build.transport.php` was only syntax-checked. The `modxmcp.core_path` placeholder `{core_path}` in the plugin body is resolved by `getOption()` the same way other component code relies on.
- Empty trash and user activation, context and media source events were exercised only by feeding hand-built parameters (`Recorder::handle()`), or not at all (user, media source, context: only in the events list).

### Open questions

- Should an outside change found by the history page's own undo (`Context::forManager` by `HistoryService`) be attributed to the undoing user? Today it is, because the brief keys it on `managerUserId`; a separate flag set only by the recorder would avoid that.
- While a manager save is between its before- and after-event, the touched object is locked for MCP requests (they get `locked`). That follows the design, but a manager request that dies between the two events keeps the lock only until the process ends.
