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

## Report

Based on: task/13-check-page at 916a398

- `Graph/GraphBuilder.php`: ported the complete graph algorithm and scanners, focus/filter/
  remap logic, properties/TV wiring, vendor detection, setting use and miniShop2 helper.
  Four code kinds use KindRegistry/effectiveContent; TV defaults retain their special
  read-only file path. Legacy empty-file/database fallback is preserved.
- `DependencyGraphAction.php` / `Registry.php`: read tool with the legacy options/output;
  integer limits retain the old clamping semantics and invalid direction/format fallbacks.
  Full manifest is 32 tools; no kind changes.
- Manager graph processor loads src/autoload and uses the same builder, retaining the
  settings permission, checkbox string handling and max_nodes=2500. graph.js is unchanged.
- Moved/reworked graph help and linked it in the index. Replaced all old tool names; dropped
  the obsolete claim that find_usages is a naive substring search. No unported-tool workflow
  remained to remove. Builder diagnostic strings (including the legacy delete_element hint)
  remain byte-compatible rather than being editorially rewritten.
- Fake adds JSON property reads/storage, IN criteria, permission and addPackage stand-ins.
  Tests instantiate the actual manager processor against a minimal modProcessor base and
  compare its results with the tool for identical options.

Expected fixture values were derived by reading the legacy loops: node order is template,
4 chunks, 2 snippets, 2 TVs, plugin; reference insertion/deduplication gives 16 edges, one
missing chunk and one unused chunk. Exact nodes (including degrees, category/vendor/settings,
TV types, plugin events and template resource count), edges, diagnostics and stats are
asserted. Optional resources add only the linked page plus link/template edges; every input
option, named/default properties, nested TV JSON, miniShop2 status use and Cyrillic names
are covered without requiring or running the 1.x model.

Validation: `npm run check` passed (13 JavaScript files, 129 PHP files on PHP 7.4.33 and
release metadata). `npm test` passed: 956 PHP checks, 24 Node/HTTP tests, no skips.
Search across core/assets/build/tests, excluding the retained model directory, found no
`modxmcp/model`, `model/modxmcp.class` or `new modxMCP(` references. Remaining model-internal
requires are only porting source; no runtime consumer loads them. Model files were not deleted.

Preserved legacy limitations: tagName and @CHUNK name patterns are ASCII-only (quoted PHP
calls can still resolve Cyrillic names); an empty/missing static file falls back to stale
DB code; orphan resource LIKE checks use unescaped name prefixes, and optional resource
nodes can be omitted by the pre-cap without truncated becoming true. These heuristics were
not fixed, per the faithful-port requirement. Global stats remain global in filtered views.

Only fake MODX/local files were exercised. Verify real xPDO JSON properties, media-source
static paths, miniShop2 model loading and the unchanged visual manager consumer on the stand.
No blocking questions, deployment or push.
