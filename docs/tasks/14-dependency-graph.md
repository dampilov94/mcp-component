# 14 — `modx_dependency_graph`

Branch: `task/14-dependency-graph`. Spec: sections 3 ("Код"), 13 (carried over from 1.10).
1.x source to port: `core/components/modxmcp/model/traits/graph.trait.php` (whole file) and
the 1.x help topic `docs/legacy-help/graph.md`. Consumer that must keep working unchanged:
`assets/components/modxmcp/js/graph.js` via `core/components/modxmcp/processors/mgr/graph.class.php`.

## Goal

Bring the dependency graph into the 2.0 core as a tool, and make the manager's graph page
use the same code, so the 1.x model is no longer loaded by anything.

## Design (decided — do not change)

1. `src/Graph/GraphBuilder.php` (namespace `ModxMCP\Graph`): a faithful port of
   `buildDependencyGraph()` and every helper it uses (`focusSubgraph`, `scanModxTags`,
   `parseModxTag`, `tagName`, `scanPropertyRefs`, `scanPhpElementCalls`, `scanTvInputRefs`,
   and anything they call in other traits — find them by following the calls). One public
   method `build(array $options): array` with the same options and the **same output
   structure and values** as 1.x. Constructor takes `\modX`.
   - Faithful means: do not rename keys, change node ids/types, reorder semantics, or "fix"
     heuristics. Where the old code relied on the facade (`$this->modx`, private helpers,
     `readEffectiveContent`), use the 2.0 equivalent (`Kind::effectiveContent()` through
     `KindRegistry`) with identical results. Keep reading static files without
     synchronising them into the database.
   - If you find a real bug, keep the behaviour and describe it in the report.
2. `src/Action/DependencyGraphAction.php`: name `dependency_graph`, read-only, scope
   `read`, group `code`, registered after `FindUsagesAction`. Input schema = the options
   the 1.x `dependencyGraph($data)` accepted, with the same names, defaults and limits
   (derive them from the code; put the defaults in the descriptions). Output = the builder's
   result (declare the top-level keys you find; open objects below).
   Description, short, taken from the 1.x help topic's first paragraph.
3. `core/components/modxmcp/processors/mgr/graph.class.php`: load
   `src/autoload.php` and call `GraphBuilder` instead of requiring the 1.x model and
   `modxMCP`. Same request parameters, same response, same permission check. After this
   change nothing may `require` anything under `core/components/modxmcp/model/`; verify
   with a search and state the result in the report. Do **not** delete `model/` (it stays
   as porting source for later stages).
4. Rewrite `docs/legacy-help/graph.md` for 2.0 and move it to
   `core/components/modxmcp/docs/graph.md`; add it to the component's `docs/index.md`.
   Replace 1.x tool names with 2.0 ones (`modx_find_usages`, `modx_search`, `modx_replace`,
   `modx_delete … dry_run`, `modx_site_info`); drop what has no 2.0 equivalent and list it
   in the report.

## Out of scope

`audit_site`, changing the manager page or `graph.js`, performance work, new node types.

## Tests (required)

`tests/php/run.php`, on a fresh fake site with a small fixture you build: a template that
uses two chunks and a snippet through tags (cached and uncached, with properties naming a
chunk in `&tpl`), a snippet that calls `getChunk()` / `runSnippet()`, a plugin, a TV with
input options referring to a chunk or snippet (as `scanTvInputRefs` understands), a TV
attached to the template, one unused chunk, one tag referring to a missing chunk, one
static element whose file (not its database column) holds the reference, two resources
using the template. Assert the exact nodes, edges, "broken" and "unused" markers the 1.x
algorithm yields — derive the expected values by reading the 1.x code and explain in the
report how you checked them. Also: every option (`include_resources`, node limit, focus
with depth and direction, …); a read token can call the tool; the manager processor
returns the same graph as the tool for the same options (instantiate the processor class
against the fake, or test the shared builder call it makes — say which).

Update tool lists/counts in both test files (a read tool).

## Acceptance

`npm run check`, `npm test`. Files: `src/Graph/GraphBuilder.php`,
`src/Action/DependencyGraphAction.php`, `src/Action/Registry.php`,
`processors/mgr/graph.class.php`, the moved help topic and the component's `docs/index.md`,
tests, `tests/php/FakeModx.php`.
