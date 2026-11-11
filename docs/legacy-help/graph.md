# Dependency graph — how the site's elements wire together

`modx_dependency_graph` answers *structural* questions that a text search answers badly:
**what does this template actually pull in? who would I break by changing this chunk? what is
dead code? what is already broken?**

It complements the other two orientation tools:

| Tool | Answers |
|---|---|
| `modx_project_overview` | what EXISTS (counts, templates↔TVs, tree, integrations) |
| `modx_dependency_graph` | how it is CONNECTED (who uses what) |
| `modx_search_code` | where a literal STRING appears |

Token cost scales with the number of **elements**, never with content — resources are counts,
not nodes. A 100 000-page site returns the same small graph as a 10-page one.

## The three ways to call it

**1. Health check first — cheapest call, no graph at all:**

```json
{"format": "summary"}
```

Returns only `stats`, `missing` and `orphans`. Do this on an unfamiliar site: it immediately
tells you whether the site has broken references or dead elements.

**2. Focused — before you edit or delete something (the most useful mode):**

```json
{"focus": "chunk:header", "depth": 1, "direction": "in"}
```

`direction:"in"` = *what uses this* (check before renaming/deleting).
`direction:"out"` = *what this uses* (check before reading a template's parts).
`depth` is 1–5 hops. `focus` accepts a bare name (`"header"`) or `type:name` to disambiguate.

Prefer this over `modx_find_usages` when you need precision: find_usages is a substring text
search (`header` also matches `headerNav`), the graph resolves real references.

**3. Whole graph — to understand an unfamiliar site's architecture:**

```json
{}
```

Add `types:["template","chunk","snippet"]` to drop noise, `max_nodes` to cap.

## Reading the response

- `nodes` — `[{i, type, id, name, category, static, in, out}]`. `i` is the node's index and is
  what the edges reference. `in`/`out` are the reference degrees **across the whole site** (they
  stay accurate even in a focused view — a chunk with `in: 12` is used in 12 places).
- `edges` — `[from_i, to_i, kind]`, meaning **from USES to**.
- `missing` — a tag points at a chunk/snippet that does **not exist**. These are real bugs:
  the tag renders as empty on the front end.
- `orphans` — nothing references it. Candidates for deletion, but read `reason` first.
- `vendor: true` on a node — the element came with an installed add-on (its category, or its own
  name, matches an installed namespace), not from this site's own build. On a stock install with
  a few add-ons this is the majority of the graph, so it is usually the first thing to filter out
  when you want to see what the SITE is made of. `stats.vendor` counts them.

### Edge kinds

| kind | source |
|---|---|
| `tag` | `[[$chunk]]`, `[[snippet]]`, `[[*tv]]` in element content |
| `attached` | TV is attached to a template (the authoritative relational link) |
| `property` | a chunk-valued property: inline `` &tpl=`rowChunk` ``, an element's **default properties**, or a **named property set** |
| `php` | `$modx->getChunk('x')` / `runSnippet('x')` in snippet or plugin code |
| `migx` | MIGX `inputTV` (a `…ForMigx` helper TV) or a `renderchunktpl` column |
| `binding` | `@CHUNK name` in a TV's `elements` / `default_text` |
| `link` | `[[~id]]` link to a resource (only with `include_resources:true`) |

Default properties matter more than they look: miniShop2's `msProducts` points `&tpl` at
`tpl.msProducts.row` in its **default properties**, not in any content. Those references are
edges too — without them half a shop's chunks would look dead.

### Non-element references

Some things use a chunk without being an element, so there is no edge to draw. Those nodes get a
**`used_by`** field naming the user instead — currently system settings and miniShop2 order-status
e-mail templates (`body_user`/`body_manager`, which reference chunks **by id**). A node with
`used_by` is never listed as an orphan.

## Accuracy — what it does and does not see

- **Reported as `missing` only for explicit `[[$chunk]]` / `[[snippet]]` tags.** Property and PHP
  references become edges when they resolve, but are never reported as broken — they are
  heuristics and would produce false alarms.
- **`[[*name]]` that is not a TV is ignored** — it is a resource field (`[[*pagetitle]]`).
- **Dynamic names are invisible.** `$modx->getChunk($tplName)` cannot be resolved statically, so a
  chunk used only that way may appear as an orphan.
- **Orphans are cross-checked against resource content** (a chunk pasted straight into a page
  body is not an orphan). On sites over 5000 resources that check is skipped for cost —
  `stats.orphans_verified:false` tells you so, and `verify_orphans:true` forces it.
- **`orphans` is a CANDIDATE list, never proof.** A third-party add-on can reference an element
  from its own database tables, which nothing generic can see. The response carries an
  `orphans_note` spelling out exactly what was and was not checked. **Always confirm with
  `modx_delete_element {dry_run:true}` before deleting anything.**
- Duplicate element names: the first one found wins.

## Typical workflows

**Before deleting a chunk** — `{"focus":"chunk:oldPromo","direction":"in"}`; empty result plus an
entry in `orphans` means it is genuinely unused. Then `modx_delete_element` with `dry_run:true`.

**Before renaming** — the same call lists every element that must be updated; feed those names to
`modx_replace_across` (with `dry_run:true` first).

**Understanding a page's rendering** — `{"focus":"template:Article","direction":"out","depth":2}`
gives the whole chain of chunks, snippets and TVs that produce that page.

**Fixing a site you just inherited** — `{"format":"summary"}`, then fix every `missing` entry.

## The visual graph (for the human)

The same graph is drawn interactively in the manager on its own screen —
**Components → modxMCP → Граф связей** (`?a=graph&namespace=modxmcp`). It offers three layouts —
*Слои* (one horizontal row per type, in the manager's own tree order: resources, then
templates, TVs, chunks, snippets, plugins), *Кластеры* (a cloud per type) and *Свободно* (plain
force). Colour-coded nodes sized
by reference count, arrows showing direction of use, hover to highlight neighbours, click for a
details card with an edit link, sidebar lists of broken references and unused elements, and
**isolation**: double-click a node (or use the card) to see only its neighbourhood, with
1–3 hop depth and an uses / used-by direction switch.

If the owner asks "show me how the site is wired", point them there — one builder feeds both the
screen and this action, so the picture and this data can never disagree.
