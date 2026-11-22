# 42 — Remaining alpha2 review items

Based on: main at `6273a6e`. Branch `task/42-review-followups`.

## Report

- **7. Request parsing.** Check the endpoint gates and authenticate a POST before reading its
  body. The bridge reuses the same read for protocol selection and validation. MCP headers let
  the server choose a JSON-RPC refusal before seeing the body. An authenticated JSON-RPC request
  without these headers is still recognised from its decoded body.
- **9. Request retention.** Track retained responses by completion day and expire at most 32
  indexed entries per pass. A full bucket becomes eligible after its day falls outside the
  retention window, so automatic cleanup can lag the configured TTL by up to a day plus the
  time to drain the backlog. Reads and replays still expire an individual result at its actual
  deadline. Package setup indexes existing retained results once. File-only deployments retain
  on-access expiry for older responses until that pass runs. Keep state and lock files for
  previously used IDs so cleanup cannot enable replay.
- **10. SQL result size.** Bound the outer MySQL/MariaDB `LIMIT` to the requested row count plus
  one for detecting truncation. Preserve a smaller limit and the caller's offset, including
  parameterized counts. This bounds rows sent by the server, while query execution can still
  require a large scan or sort. Verified four cases on local MODX 2.8.8 with MariaDB.
- **11. Memory.** Use multibyte substring operations for log truncation and search excerpts.
- **13. File revisions.** The bridge remembers revisions by media source and normalized relative
  path, including file changes returned by moves and deletes. An explicit revision takes precedence.
- **14. Graph, TV and audit.** Count named MIGX config references and VirtualPage handler entries
  in the graph and usages. Recognize Unicode TV bindings and expand the miniShop2 core path.
  Use current tool names, translate the graph screen via the manager lexicon, lint replacement
  Fenom code in duplicates, and fold URI case for audit grouping.
- **14. Static path resolution.** Accept both the configured site root and its canonical alias.
  For a missing absolute file without a media source, MODX 2.8 still prefixes
  `element_static_path`. The guard checks the path the native processor will use. If only the
  intended absolute path is allowed, the error explains the fallback and suggests `make_static`
  or an existing file. No native resolver override is used.

## Verification

- `npm run check` and `npm test` passed in WSL on PHP 7.4.33 (5463 PHP checks, 40 Node tests,
  no skips). The graph reference tests use fixtures, not installed MIGX/VirtualPage models.
- On disposable MODX 2.8.8 with MariaDB 10.6, verified the SQL cap for plain SELECT, a literal
  LIMIT with OFFSET, a parameterized LIMIT with an offset, and a smaller existing limit. The
  graph template also rendered with native Smarty in English and Russian with all labels present.
- Chrome on disposable MODX 2.8.8 verified graph search, TV binding details, filtering, isolate,
  reset, free layout and fit to view.
- On disposable MODX 2.8.8 with MIGX 3.0.2-beta1 and the fordev copy of VirtualPage 1.0.9-beta,
  the graph, `modx_find_usages` and deletion previews found the chunk referenced by each add-on.
  A Cyrillic TV `@CHUNK` binding also appeared in the graph. The fixture session was undone.
- On fordev, backed up the original files and verified all 19 deployed files by SHA-256. Repeated
  the two add-on checks and the Cyrillic TV binding check against the installed models. Undid the
  fixture session and verified that all temporary records were gone. Direct MCP over HTTP listed
  35 tools and returned `modx_site_info`. The manager UI was checked on the disposable instance.
