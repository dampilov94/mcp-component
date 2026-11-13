# 24 — VirtualPage: events, handlers, routes

Branch: `task/24-virtualpage`. Spec: section 3.1 (VirtualPage). Source (read-only; never
execute or modify): VirtualPage 2.0.6-beta as installed on the stand, copied to
`.local/reference/virtualpage-2.0.6/core/` (`model/virtualpage/*.class.php`, the maps in
`model/virtualpage/mysql/`, `processors/mgr/{event,handler,route,misc,settings}`, the
service class and its plugin). 1.x reference known to work against VirtualPage on real
sites: `core/components/modxmcp/model/traits/virtualpage.trait.php` (event/handler/route CRUD,
handler type and entry checks, unique routes, the plugin-event wiring in
`ensureVirtualPagePluginEvent()`, `matchVirtualPageRoutePattern()`, cache clearing) and
`docs/legacy-help/` (VirtualPage is mentioned in `getting_started.md` there).

## Goal

Manage virtual pages (URL routes answered by a resource, snippet, chunk or template without a
real resource) with the generic tools, plus a route tester.

## Design (decided — do not change)

`src/Integration/VirtualPage.php` like `Migx.php` (group `virtualpage`, `load()` registering
the package). Three kinds, group `virtualpage`, read `read`, write `develop`, integer keys:

| Kind | Class | Fields (exact columns from the maps) |
|---|---|---|
| `vp_event` | `vpEvent` | `id`, `name` (required, unique, list: a MODX system event name such as `OnPageNotFound`), `description`, `rank` (int), `active` (bool, list). |
| `vp_handler` | `vpHandler` | `id`, `name` (required, unique, list), `type` (list; API values `resource`, `snippet`, `chunk`, `template`, `dynamic_resource` mapped to the stored integers — take the mapping from the source and the 1.x trait), `entry` (int: id of the resource/snippet/chunk/template the handler uses; must exist for its type — 1.x `assertVirtualPageHandlerEntry`), `content` (string, code: used by `dynamic_resource`), `description`, `cache` (bool), `rank`, `active` (bool, list). |
| `vp_route` | `vpRoute` | `id`, `route` (required, list), `method` (column `metod`; list; one or more of GET/POST/PUT/PATCH/DELETE/HEAD/OPTIONS as stored — normalise as 1.x does), `handler` (int, required, list, must exist), `event` (int, required, list, must exist), `description`, `rank`, `active` (bool, list), `properties` (object, JSON column — check the column type). A route + method combination must be unique (1.x `assertUniqueVirtualPageRoute`). |

`modx_list` for `vp_route` adds a computed `label`: `"<method> <route> → <handler name>
(<event name>)"`.

**How to write.** Read the VirtualPage processors and the 1.x trait. 1.x writes the objects
directly and then wires the MODX plugin event and clears VirtualPage's cache; do what the
source shows the manager processors do around a save (plugin events, cache), through the
processors if they run headless, otherwise directly — say which and why. Every side effect on
other objects (e.g. a `modPluginEvent` row of VirtualPage's plugin for a new event name) must
be journaled: touch the plugin (kind `plugin`) so its `events` relation change is recorded.

**Deletes and cascades.** Check what the remove processors do: deleting an event or handler
that routes use must either be refused (if VirtualPage refuses it) or journal the routes it
removes (`deleteCascade` as `vp_route` objects). Undo must restore exactly.

**Cache.** After every write VirtualPage's own cache must be cleared the way its processors /
service do it (`afterWrite`).

### Route tester

Operation `resolve` of kind `vp_route` through `modx_invoke` (read-only, no object): args
`{path, method?}` (method default `GET`). Returns the active routes that match (in the order
VirtualPage would try them — read its router/FastRoute usage), each with the extracted
parameters, the handler and the event, and which one VirtualPage would answer with. Port
`matchVirtualPageRoutePattern()` from 1.x if the source does not offer a usable matcher;
say which you used. A path that matches nothing → `{"matches": []}`, not an error.

### Help

Write `core/components/modxmcp/docs/virtualpage.md` (2.0 calls only): what the three objects
are, how a route ties a URL pattern and method to a handler through an event (usually
`OnPageNotFound`), an end-to-end example (handler on a chunk, route `/catalog/{id:\d+}`,
`resolve` to test it, `modx_check_page {url_path: "/catalog/5"}` to see it served), and the
caching and plugin notes the source makes necessary. Add it to `docs/index.md`.

## Out of scope

VirtualPage's own settings, import/export, the manager UI.

## Tests (required)

Fake `vpEvent`, `vpHandler`, `vpRoute`, VirtualPage's plugin and processors as the source
behaves; `addPackage`; VirtualPage unavailable → kinds unavailable.
- each kind: create, get, list, update, delete, duplicate, undo; validation (unknown handler
  type, entry that does not exist for its type, route+method duplicate, unknown handler or
  event id in a route); `dynamic_resource` content with Cyrillic.
- a new event name wires VirtualPage's plugin and the plugin change is journaled and undone.
- delete cascades or refusals as the source does them; undo exact.
- `resolve`: static route, parameter route with a regex, method mismatch, inactive route
  ignored, two matches in order, no match.
- cache cleared after writes (observable on the fake).
- `modx_help {topic: "virtualpage"}` contains no 1.x tool name (`modx_virtualpage_`).

Update kind lists/counts in both test files.

## Acceptance

`npm run check`, `npm test`. Files: `src/Integration/VirtualPage.php`, three kind classes,
`src/Kind/KindRegistry.php`, the help topic and `docs/index.md`, tests,
`tests/php/FakeModx.php`; generic code only for a hook the source forces (say which).

## Report

Based on: main at 584a92f

Counts: before, `npm run check` OK (157 PHP files, 22 JS files), PHP 4437 checks, Node 29 tests. After: check OK (162 PHP files), PHP 4628 checks, Node 30 tests (all pass). No existing assertion was changed, weakened or deleted: VirtualPage is not installed on the sites the existing tests use, so the existing kind lists and counts are unchanged; the new kinds are asserted in the new sections.

### Implemented, file by file

- `src/Integration/VirtualPage.php` (new): group, `load()` (registers the package; probe `model/virtualpage/vproute.class.php`), `clearCache()`, the handler type map (0 resource, 1 snippet, 2 chunk, 3 dynamic_resource; alias `template` = 3), the entry classes, method normalisation, and a port of the FastRoute copy VirtualPage ships (Std route parser with optional `[...]`, the capturing-group refusal, static before variable routes, registration order, HEAD falling back to GET, `*` fallback). Used by `assertRoute()` (refuse what FastRoute would), `registrationProblems()` (warnings) and `dispatch()` (the tester).
- `src/Kind/AbstractVirtualPageKind.php` (new): shared base like `AbstractMs2Kind`: direct writes inside the transaction, `rank` default = number of rows (as the manager's create processors set it), `afterWrite()` = `VirtualPage::clearCache()`, package registered in `prepare()`.
- `src/Kind/VpEventKind.php`, `VpHandlerKind.php`, `VpRouteKind.php` (new): fields as in the brief (route `method` is column `metod`, `properties` a `json` field, route `label` computed). Name uniqueness (event, handler), entry must exist for the type (checked on create and when type/entry change), route and method unique, handler and event must exist, route pattern validated with the FastRoute port. Deletes of an event or handler journal their routes through `deleteCascade()` (`vp_route` objects). `assertUndoable()` refuses an undo onto a name / route+method another object took. `vp_handler` `type` is shown by name and accepted by name in `where` (via `listCriteria()`). `vp_route` has the read-only operation `resolve` (`modx_invoke`).
- `src/Kind/KindRegistry.php`: the three kinds added after `migx_config`.
- `core/components/modxmcp/docs/virtualpage.md` (new) and `docs/index.md`: the help topic (2.0 calls only).
- `tests/php/FakeModx.php`: `FakeVirtualPageService` (counts `clearAllCache()`), `getService('virtualpage')` while the package is registered, `FakeCache::clean()`, the composites `vpEvent`/`vpHandler` -> `vpRoute`. `tests/php/run.php`: a section of 191 checks. `tests/php/endpoint.php` + `tests/endpoint.test.js`: `MODXMCP_TEST_VIRTUALPAGE` and one end-to-end test (25 kinds for an admin, 20 for a read token with it; the 22 / 17 counts without it are unchanged).

No generic code (Kind, Objects, actions, Snapshot) was changed.

### Deviations and source-vs-brief differences

1. **No plugin wiring.** The brief says to wire VirtualPage's plugin to a new event name and journal it (from 1.x `ensureVirtualPagePluginEvent()`). The 2.0.6 source does not: its manager processors (`mgr/event/*`) only clear the cache, and the service class has no `doEvent()` (the 1.x fallback looks for a plugin named `vpEvent`, which this version does not ship). Following the source, saving an event changes nothing outside its row, and a warning says when no enabled plugin listens to the event. Consequently the brief's test "a new event name wires the plugin and it is journaled and undone" is replaced by tests that the plugin events are never touched and that the warning appears and disappears. If the stand shows that creating an event in the manager does wire the plugin (some other code path I could not see), this needs a follow-up.
2. **Dispatching events.** The 2.0.6 plugin classes (`systems/`) exist only for `OnHandleRequest`, `OnPageNotFound` (both call `dispatch()`) and `OnBeforeCacheUpdate` (clears the cache). Routes of any other event are never tried; a write to such an event or a route on it gives a warning (no refusal).
3. **Handler types.** The source has four types (the manager lists resource, dynamic_resource, snippet, chunk). The brief's `template` is not a fifth type: 1.x mapped it to 3 and the entry of type 3 is a template, so `template` is accepted as an alias and read back as `dynamic_resource`.
4. **Methods.** The manager offers GET, POST, GET,POST; 1.x accepted only those. The brief lists seven; FastRoute accepts any, so the seven are accepted, stored upper case in the order GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS. Default GET when omitted on create.
5. **Uniqueness is stricter than the manager's.** The manager refuses an equal route and an equal method string. FastRoute throws (for every request of the event) when two routes of one event have the same text and a method in common, so a route is refused when another route with the same text shares any method (`GET` vs `GET,POST` is refused). The comparison is the database's (case-insensitive on MySQL), like the manager's.
6. **Static route shadowing** (a static route registered after a variable route that matches it makes FastRoute throw for the whole event) is a warning, not a refusal, computed from the active routes of the event in rank order.
7. **Entry check only on change.** The entry is checked when a handler is created or its type or entry change, not on unrelated edits (1.x checked on every update). Entry 0 is allowed (warning for types that need one).
8. **Writes are direct, not through the manager processors**: they check the permission `vpsetting_save` (an API user need not hold it), overwrite `rank` on create, the event and handler remove processors do not clear the cache, and they have no headless advantage; the only side effect they have is the cache. Same as 1.x and as task 23.
9. **Router.** The tester uses my port of FastRoute's parser/dispatch (the reference copy is PHP 8 only and must not be executed). Ordering across events is an approximation: `OnHandleRequest` routes before `OnPageNotFound` routes (their firing order), other events after, then FastRoute's order inside an event (method itself, then HEAD->GET, then `*`; static before variable; then rank, id). `resolve` returns `path`, `method`, `matches` (each with `answers`), `answered_by` and `notes` besides `matches`.
10. **Dry run imprecision.** `modx_update` dry run compares raw stored values, so resending the same `type` (stored 2, sent `chunk`) or a differently spelled `method` is reported under `would_change`; the real write's `changed` list is exact. Fixing it would need a generic hook; not done.
11. The route `properties` empty object / null is stored as NULL (the map default), a non-empty object as JSON text.

### Side effects not journaled

VirtualPage's cache (`default/virtualpage` and the rebuilt route lists) is cleared after every write and every undo; it is derived data, not journaled. Nothing else is touched (no events fired: the manager processors fire none; no plugin rows).

### Verified only against the fake MODX (check on the stand)

- That `virtualpage::clearAllCache()` can run from the API request (`getService('virtualpage', 'virtualpage', <core>/model/virtualpage/)`, its constructor loads a lexicon topic and the `fastroute` bootstrap include) and does not throw; the fallback cleans `default/virtualpage` directly.
- Raw `fromArray(…, false, true)` and `toArray('', true)` on the json column `properties` of `vpRoute` (same approach as `migxConfig`), stable revisions for it, and `get()` returning an array for it.
- xPDO cascade: removing an event or handler removes its `Routes` (composites in the maps), and that restoring routes after their parent with the recorded ids works.
- That the real FastRoute accepts what `assertRoute()` accepts (and the stand's PHP version works with the shipped FastRoute v2), the handler `type` numbers, and the `resolve` order against a real request through `modx_check_page`.
- The event warning's "no enabled plugin listens" check against the plugin and event rows of a real site; which plugin runs VirtualPage on the stand.

### Open questions

- Should creating an event attach VirtualPage's plugin after all (the brief's design)? The source says no; which plugin would it be on 2.0.6?
- Should a delete of an event or handler that routes use be refused instead of cascading? xPDO cascades and the manager does not refuse, so it cascades (journaled).
- Case-insensitive route comparison (MySQL collation) vs the router's case-sensitive matching: `/Catalog` and `/catalog` are treated as the same route for the uniqueness rule.
