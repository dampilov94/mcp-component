# VirtualPage — URLs answered without a resource

VirtualPage answers a request path that has no resource of its own: a snippet, a chunk, a resource or a page made up
from a template produces the output. It is built from three kinds of objects; they exist when VirtualPage is
installed and the `virtualpage` group is on (`modx_capabilities` says why they are not).

| Kind | What it is |
|---|---|
| `vp_event` | A MODX system event whose routes VirtualPage tries, usually `OnPageNotFound`. |
| `vp_handler` | What answers: a resource, snippet, chunk, or a dynamic resource (a template plus a body). |
| `vp_route` | A URL pattern and methods, tied to a handler through an event. |

A request reaches a route like this: MODX fires the event (`OnPageNotFound` when no resource matches the path),
VirtualPage's plugin runs, and the active routes of that event are tried for the request path and method. The first
match wins; its handler produces the page. A route is used only if the route, its event and its handler are all
active, VirtualPage itself is switched on (system setting `virtualpage_active`) and a plugin that runs VirtualPage
listens to the event.

## Handlers

```
modx_create {kind: "vp_handler", fields: {name: "Карточка товара", type: "chunk", entry: 12}}
```

`type` is one of `resource` (the request is forwarded to the resource `entry`), `snippet` or `chunk` (the output of
the snippet or chunk `entry`) and `dynamic_resource` (a page of the template `entry`, whose body is `content`;
`template` is accepted as another name). `entry` must exist for the type; 0 is allowed but a resource, snippet or
chunk handler with entry 0 answers nothing, and a warning says so. `cache: true` lets VirtualPage cache the output.
`content` is code: edit it with `modx_view_code` and `modx_edit_code`.

## Routes

```
modx_create {kind: "vp_route", fields: {route: "/catalog/{id:\d+}", method: "GET", handler: 3, event: 1}}
```

- `route` starts with `/` and uses FastRoute syntax: `{name}` matches one segment, `{id:\d+}` takes a regex (no
  capturing groups), `[/{page}]` at the end is optional. The path is compared as it is: case-sensitive, and
  `/catalog/5/` does not match `/catalog/{id:\d+}`.
- `method` is one or more of GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS, comma separated (default GET). A route
  and method together are unique; two routes with the same text cannot share a method.
- The URL parameters reach the handler as placeholders with the prefix `vp.` (`[[+vp.id]]`), next to the route's
  `properties` (an object of extra values).
- Routes are tried in the order of `rank` (a new route gets the number of routes). Static routes win over variable
  ones, and a static route that an earlier variable route matches makes FastRoute fail for the whole event: the
  write succeeds with a warning, fix it at once.

## Testing a route

```
modx_invoke {kind: "vp_route", op: "resolve", args: {path: "/catalog/5", method: "GET"}}
```

returns the active routes that match, in the order VirtualPage tries them, each with the extracted `parameters`,
the handler and the event; `answers: true` marks the route that would answer, and `answered_by` is its id. A path
that matches nothing gives `matches: []`. Notes say when VirtualPage is switched off or when the event is tried only
if no resource answers. To see the real page, request it: `modx_check_page {url_path: "/catalog/5"}`.

## End to end

```
modx_create {kind: "vp_event", fields: {name: "OnPageNotFound"}}                       # skip if it exists: modx_list {kind: "vp_event"}
modx_create {kind: "chunk", fields: {name: "product_page", content: "<h1>Товар [[+vp.id]]</h1>"}}
modx_create {kind: "vp_handler", fields: {name: "Товар", type: "chunk", entry: <chunk id>}}
modx_create {kind: "vp_route", fields: {route: "/catalog/{id:\d+}", handler: <handler id>, event: <event id>}}
modx_invoke {kind: "vp_route", op: "resolve", args: {path: "/catalog/5"}}
modx_check_page {url_path: "/catalog/5"}
```

## Plugins and cache

- Saving an event attaches nothing: VirtualPage's own processors do not either. The plugin that runs VirtualPage
  must listen to the event; a warning says when no enabled plugin does. Attach it with `modx_update {kind: "plugin",
  id: …, relations: {events: […]}}` (the list replaces the plugin's events: read it first). VirtualPage 2.0.6
  dispatches routes only on `OnHandleRequest` and `OnPageNotFound`; routes of other events are never tried.
- After every change VirtualPage's own cache (the route lists) is cleared and rebuilt, as its manager does. That
  cache is not part of the journal; `modx_undo` clears it again.

## Delete and undo

Deleting an event or a handler also deletes the routes that use it. They are journaled in the same changeset, and
`modx_undo` brings the event or handler back together with its routes. An undo is refused when another object has
taken the name (event, handler) or the route and method (route) in the meantime.
