# Getting started

Everything is an *action* called via a `modx_*` tool. Legacy text is the data directly (the
HTTP success envelope is stripped); structured MCP data is in `structuredContent.result`.
Errors come back with a clear message — read it and fix the call.

## Recommended workflow (follow this order)

1. **Orient** — on an unfamiliar site call `modx_project_overview` once: a compact map
   (templates↔TVs, resource/product counts overall + by template/context, a shallow resource
   tree, categories, content types, integrations) with no content, cheap even on huge sites.
   Then `modx_dependency_graph {format:"summary"}` — one more cheap call that reports broken
   references and unused elements before you touch anything.
2. **Locate** — `modx_search_code` (full-text; returns each hit's `line` + `line_text`),
   `modx_find_usages`, `modx_list_resources` / `modx_list_elements` (with a `query` filter).
   To see how something is WIRED rather than where a string appears, use
   `modx_dependency_graph {focus:"chunk:header", direction:"out"|"in"}` — it resolves real
   references instead of substrings (`header` vs `headerNav`). Topic: `graph`.
3. **Look before you change** — read the target (`modx_get_element`, or `modx_view_element` for
   numbered lines of a big element). On an unfamiliar object, `modx_describe_object` gives the
   real field names + types so you don't guess.
4. **Change cheaply** — for a few lines, `modx_edit_element_lines` (send only changed lines, with
   an `expect` anchor and `expected_revision` copied from `modx_view_element.revision`) instead
   of `modx_update_element` (full rewrite). For a site-wide string change, use
   `modx_replace_across`: preview with `dry_run:true`, then pass its `revisions` map unchanged
   as `expected_revisions` when applying the same find/replacement.
5. **Be safe with destructive ops** — `modx_delete_element`, `modx_bulk_resources` and
   `modx_replace_across` and `modx_delete_media_folder` all take `dry_run:true` — preview first, then run for real. Resource
   delete is **soft** (MODX trash) → restore with `modx_undelete_resource`. Before deleting or
   renaming an element, check `modx_dependency_graph {focus:"<type>:<name>", direction:"in"}`
   for everything that depends on it.
6. **Apply & verify** — clear cache if needed (`modx_clear_cache`), then re-read to confirm.

## Key facts

- **Elements vs other objects.** Element tools take a `type`
  (`chunk`/`snippet`/`template`/`resource`/`tv`/`category`/`plugin`) plus fields. Other areas
  (settings, ACL, miniShop2, contexts, media, …) have their own dedicated `modx_*` tools.
- **Capability groups.** Optional groups (miniShop2, MIGX, VersionX, VirtualPage, Access Control,
  contexts, property sets, package management, namespaces, lexicon) can be switched off in the
  manager (Components → modxMCP). If a tool you need is missing, that group is probably disabled —
  ask the user to enable it. `modx_list_actions` shows all groups incl. disabled ones.
- **TV fields.** Unsure which input type? `modx_suggest_tv_type` (describe the need, EN/RU) →
  ranked types + a create skeleton. Details in the `tv_input_types` topic; repeating rows → `migx`.
- **Static files.** `make_static` (and `modxmcp.auto_static`) store an element's code as a file
  under the configured `core_path/elements/` so it can be edited over FTP / kept in git.
  Conversion applies only to DB-only elements. Existing static elements keep their exact
  `static_file`, Media Source and file bytes; repeated conversion returns `already_static`.
  New names include the element ID and get an extra suffix if occupied; occupied files are
  never overwritten. New files use native `source=0` and a portable `[[++core_path]]` path.
  Renaming an existing static element does not relocate its file.
- **Safe partial edits.** `view_element` returns a SHA-256 `revision` of the whole content,
  even when viewing a line window. A stale `expected_revision` rejects the edit: read again
  before retrying. It is optional for older clients; omitting it does not protect against
  changes between your earlier read and the edit request. Saving still rechecks the content
  read by that request under a lock.
- **Static-file recovery.** `edit_element_lines` and `replace_across` stage file changes and
  keep a recovery copy until the database commit. A processor or transaction failure restores
  the original file when rollback succeeds. Missing/unreadable files and non-local sources
  are rejected rather than replaced with an old DB copy. Element tables must support
  transactions (for example InnoDB); no automatic engine conversion is performed.
  Cache refresh and success audit follow the commit. `cache_refreshed:false` means the save
  committed but cache maintenance needs attention; do not repeat the edit just for that.
- **Replacement batches.** With `expected_revisions`, only reviewed element IDs are used and
  all revisions are checked before writing. Elements are still committed individually: a
  later save failure does not undo earlier successful saves, and the error names those IDs.
  Re-preview before retrying a partial batch. Arbitrary concurrent FTP writers, process crashes
  and external side effects of third-party plugins are outside the DB/file rollback guarantee.
- **Media folder deletion.** `modx_delete_media_folder` operates only on local filesystem
  Media Sources. Pass a relative path such as `images/archive`; empty/root paths, `..`,
  absolute paths and stream URLs are rejected. Symlinks in any path component or inside
  the selected tree are refused. The whole tree is inspected before deletion; `limit`
  only bounds the returned entry list (default 200, max 500). `directories` includes the
  selected folder itself. Preview with `dry_run:true`, then pass its `revision` as
  `expected_revision` to compare paths and metadata before deleting. The fingerprint uses
  filesystem identity, sizes and timestamps, not file-content hashes. These locks coordinate
  folder deletion through MCP; avoid concurrent FTP or other writes during removal.
  Deletion includes hidden files and has no rollback. A filesystem error can leave a partially
  deleted tree; errors say so, and success is returned only after the selected folder is gone.
- **HTTP failures and request IDs.** Write tools generate a request ID; `_request_id` can
  supply one explicitly for a retry with identical arguments. On a timeout, cancellation,
  invalid response or broken connection, PHP may have completed the action. Call
  `modx_get_request_status {request_id:"the ID from the error"}` before doing anything again.
  `include_result:true` includes a retained original API response. `completed` means an API
  response was recorded (check its success/error), not that every side effect succeeded.
  `in_progress` means the request lock is held; `unknown` means a pending request ended without
  a recorded response and is blocked from re-execution; `not_found` means no record exists.
  Retrying with the same ID and arguments replays a completed response; different arguments
  with the same ID are rejected. Do not give an uncertain write a fresh ID without checking
  the site. Read tools do not get automatic IDs. Older clients omitting request_id still work,
  but have no replay protection; older PHP endpoints cannot provide this protection.
- **Request retention and limits.** Responses are kept for modxmcp.request_retention_seconds
  (default 86400, minimum 60). Expired IDs remain blocked; only response bodies are purged.
  Records survive clear_cache and token rotation under core/modxmcp-data/requests, outside
  component package files, with private directory/file permissions and guarded PHP data files
  that return 404 on direct HTTP access. Response bodies are base64 inside those files (not
  encrypted). Cleanup runs on later
  requests, not as a scheduled job. Server max_response_bytes defaults to 4 MiB (minimum 1 KiB).
  Client env: MODX_MCP_TIMEOUT_MS (60000), MODX_MCP_MAX_REQUEST_BYTES (1 MiB),
  MODX_MCP_MAX_RESPONSE_BYTES (4 MiB). Limits must be positive integers. No automatic retry
  is performed; redirects are refused. Cancellation stops the HTTP wait, not PHP execution.
- **HTTPS behind a proxy.** With modxmcp.require_https enabled, direct HTTPS must be reported
  by the server HTTPS flag. SERVER_PORT=443 alone is not proof. X-Forwarded-Proto is accepted
  only when REMOTE_ADDR matches modxmcp.trusted_proxies (CSV IPs/CIDRs, IPv4/IPv6); its default
  empty list trusts no forwarded header. Only a single https value is accepted, not a chain.
  For a configured proxy peer, its reported original protocol is authoritative: encrypted
  backend traffic cannot override a forwarded http value or a missing/ambiguous header.
  The proxy must overwrite incoming client headers; REMOTE_ADDR must be the actual proxy peer,
  not a client IP substituted by forwarded-header rewriting. Configure this before upgrading
  an HTTPS-required site that terminates TLS upstream. allowed_ips uses the same strict IP/CIDR
  matcher and REMOTE_ADDR only; empty allows all, malformed rules never become permissive CIDRs.
- **Tool metadata.** Every tool advertises readOnlyHint, destructiveHint, idempotentHint and
  openWorldHint plus an outputSchema. These are advisory descriptions of the main operation,
  not permission checks. Read tools are idempotent; write tools remain non-idempotent because
  saves can append versions/events or create more objects. A supplied request ID protects one
  operation and does not change that classification. Generic processor outputs retain flexible
  payload schemas; stable core tools describe their fields. Modern clients validate the object
  wrapper `structuredContent.result`. Legacy text stays the original JSON payload. Errors use
  isError and do not pretend to satisfy success schemas. After a write output-shape error, inspect
  the reported request ID before retrying; the PHP action may already have completed.
- **Element code.** get_element returns effective local static-file code without re-saving it,
  plus revision for chunks/snippets/templates/plugins. Text includes code only in its canonical
  field (snippet/plugincode/content), without another CODE block. Modern structured and legacy
  text channels represent the same result; clients should consume one representation.
- **Available tools.** Discovery filters supported/available actions reported by PHP. Disabled
  groups stay disabled; missing integration namespaces/model code hide their dedicated tools.
  run_processor is hidden while its setting is off. get_capabilities explains disabled and
  unavailable actions/reasons, and check_integrations retains installation/version information
  with an availability flag. Installing an integration does not automatically enable its group.
  Group, integration-code and processor-gate changes update the capability fingerprint on the
  next ordinary response; no periodic polling is added. Namespace/code probes are lightweight,
  not a guarantee that every database migration or third-party processor is healthy.
- **Batch cache work.** bulk_resources, reorder_resources and batch make_static defer
  component cache refresh until the outer batch exits, including partial failures. Resource
  processors still run validation/save events, with their syncsite/clearCache refresh disabled
  only inside these batches. Standalone native save behavior is retained. Plugin-owned cache
  operations are not intercepted; do not expect another request to see every intermediate
  batch step before the final refresh.
- **Overview and log budgets.** project_overview reuses grouped counts for visible templates,
  root child counts and real contexts; max_tree_nodes is capped at 500. Template TV queries
  only inspect the selected templates. Error/audit logs are read backwards in chunks; limit
  defaults to 100 and is capped at 1000, with a returned-byte budget from max_read_bytes
  (default 256 KiB, minimum 1 KiB). Results stay oldest-to-newest within the tail. Audit action
  filtering scans older chunks as needed instead of loading the whole file. has_more refers
  to older unread log data, not guaranteed additional filter matches. truncated and
  skipped_long_lines expose limits/oversized skipped entries; total is the returned count.
- **Study an add-on.** `modx_get_component_files` + `modx_read_component_file` read installed
  component source; see the `study_component` topic.

Deeper guides via `modx_help`: `graph`, `tv_input_types`, `migx`, `minishop2`, `acl`,
`study_component`.
