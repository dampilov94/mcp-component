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
