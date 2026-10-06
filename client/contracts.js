// Tool effects describe the main operation, not permissions. Unknown writes stay conservative.
function isReadOnlyTool(name) {
  return /^modx_(?:(?:ms2|migx|versionx|virtualpage)_)?(?:get|list|read|view|search|find|check|describe|suggest)_/.test(name)
    || ["modx_help", "modx_dependency_graph", "modx_project_overview", "modx_system_info", "modx_virtualpage_resolve_route"].includes(name);
}

function annotationsFor(name) {
  const readOnly = isReadOnlyTool(name);
  const additive = new Set([
    "modx_create_element", "modx_create_system_setting", "modx_create_media_source", "modx_create_media_folder",
    "modx_ms2_create_option", "modx_ms2_create_link_type", "modx_ms2_create_product_link", "modx_ms2_create_category",
    "modx_virtualpage_create_event", "modx_virtualpage_create_handler", "modx_virtualpage_create_route", "modx_migx_create_config",
    "modx_create_property_set", "modx_create_user", "modx_create_context", "modx_create_context_setting", "modx_create_provider",
    "modx_create_namespace", "modx_create_user_group", "modx_create_role", "modx_create_access_policy", "modx_create_access_policy_template", "modx_create_resource_group",
  ]).has(name);
  const openWorld = /(?:media|provider)/.test(name)
    || ["modx_install_package", "modx_uninstall_package", "modx_search_packages", "modx_run_processor", "modx_create_user", "modx_update_user"].includes(name)
    || (/^modx_ms2_/.test(name) && !readOnly);
  return {
    readOnlyHint: readOnly,
    destructiveHint: !readOnly && !additive,
    // Repeating a write can append versions, send events or generate another object.
    // A supplied _request_id protects one request; it does not make every call idempotent.
    idempotentHint: readOnly,
    openWorldHint: openWorld,
  };
}

const integer = { type: "integer", minimum: 0 };
const boolean = { type: "boolean" };
const string = { type: "string" };
const revision = { type: "string", pattern: "^[a-f0-9]{64}$" };
const id = { anyOf: [{ type: "integer", minimum: 1 }, { type: "string", pattern: "^[0-9]+$" }] };
const strings = { type: "array", items: string };
const nativeJson = {
  type: ["object", "array", "string", "number", "boolean", "null"],
  description: "Native MODX/component result. Processor-specific fields vary by object type and installed component version.",
};
function object(properties = {}, required = []) {
  return { type: "object", properties, required, additionalProperties: true };
}
function array(items = {}) { return { type: "array", items }; }

const resultSchemas = {
  modx_list_elements: array(object({ id, name: { type: ["string", "null"] } }, ["id", "name"])),
  modx_get_element: object({ id, snippet: { type: ["string", "null"] }, plugincode: { type: ["string", "null"] }, content: { type: ["string", "null"] }, revision }, ["id"]),
  modx_view_element: object({ type: string, id, static: boolean, revision, total_lines: integer, start_line: integer, end_line: integer, numbered: string }, ["type", "id", "static", "revision", "total_lines", "numbered"]),
  modx_edit_element_lines: object({ type: string, id, static: boolean, revision, edits_applied: integer, total_lines_before: integer, total_lines_after: integer, cache_refreshed: boolean }, ["type", "id", "revision", "edits_applied"]),
  modx_replace_across: object({ find: string, replacement: string, dry_run: boolean, elements: integer, total_occurrences: integer, results: array(object({ type: string, id, occurrences: integer, static: boolean, revision }, ["type", "id", "occurrences", "revision"])), revisions: { type: "object", additionalProperties: revision }, cache_refreshed: { type: ["boolean", "null"] } }, ["find", "replacement", "dry_run", "elements", "results", "revisions"]),
  modx_delete_media_folder: object({ source: id, path: string, relative_path: string, dry_run: boolean, deleted: boolean, files: integer, directories: integer, bytes: integer, entries: array(object({ path: string, type: { enum: ["file", "directory"] }, bytes: integer }, ["path", "type", "bytes"])), truncated: boolean, revision, cache_refreshed: boolean }, ["source", "path", "dry_run", "deleted", "files", "directories", "entries", "revision"]),
  modx_get_request_status: object({ request_id: string, state: { enum: ["not_found", "in_progress", "unknown", "completed", "expired"] }, started_at: integer, completed_at: integer, http_status: { type: "integer", minimum: 100, maximum: 599 }, success: boolean, response: object() }, ["request_id", "state"]),
  modx_get_capabilities: object({ toggleable_groups: strings, disabled_groups: strings, disabled_actions: strings, supported_actions: strings, available_actions: strings, unavailable_actions: strings, unavailable_groups: strings, integrations: { type: "object", additionalProperties: boolean }, fingerprint: string }, ["toggleable_groups", "disabled_groups", "disabled_actions", "fingerprint"]),
  modx_check_integrations: object({ integrations: array(object({ key: string, label: string, installed: boolean, available: boolean, version: { type: ["string", "null"] }, note: string }, ["key", "label", "installed"])) }, ["integrations"]),
  modx_search_code: object({ query: string, count: integer, results: array(object({ type: string, id }, ["type", "id"])) }, ["query", "count", "results"]),
  modx_system_info: object({ modx_version: { type: ["string", "null"] }, modxmcp_version: string, php_version: string, dbtype: { type: ["string", "null"] }, base_path: string, core_path: string }, ["modxmcp_version", "php_version", "base_path", "core_path"]),
  modx_project_overview: object({ counts: { type: "object", additionalProperties: integer } }),
  modx_read_audit_log: object({ total: integer, entries: array(object()) }, ["total", "entries"]),
  modx_read_error_log: object({ total: integer, lines: strings }, ["total", "lines"]),
  modx_clear_cache: object({ cleared: boolean, partitions: { anyOf: [strings, { const: "all" }] } }, ["cleared", "partitions"]),
  modx_regenerate_token: object({ token: string }, ["token"]),
  modx_create_media_file: object({ created: boolean, path: string }, ["created", "path"]),
  modx_update_media_file: object({ updated: boolean, path: string }, ["updated", "path"]),
  modx_delete_media_file: object({ deleted: boolean, path: string }, ["deleted", "path"]),
  modx_rename_media_file: object({ renamed: boolean, path: string, new_name: string }, ["renamed", "path", "new_name"]),
  modx_create_media_folder: object({ created: boolean, path: string }, ["created", "path"]),
};

function resultSchemaFor(name) {
  if (resultSchemas[name]) return resultSchemas[name];
  if (name === "modx_make_static") {
    return { anyOf: [
      object({ type: string, id, static_file: { type: ["string", "null"] }, source: integer, status: { enum: ["converted", "already_static"] } }, ["type", "id", "static_file", "source", "status"]),
      object({ count: integer, results: array(object()) }, ["count", "results"]),
    ] };
  }
  if (name === "modx_delete_element") {
    return { anyOf: [string, object({ dry_run: boolean, would_delete: object({ type: string, id }, ["type", "id"]) }, ["dry_run", "would_delete"])] };
  }
  if (/^modx_(?:(?:ms2|migx|versionx|virtualpage)_)?list_/.test(name)) return { type: ["array", "object"] };
  if (/^modx_(?:(?:ms2|migx|versionx|virtualpage)_)?get_/.test(name)) return object();
  return nativeJson;
}

function outputSchemaFor(name) {
  return {
    type: "object",
    properties: { result: resultSchemaFor(name) },
    required: ["result"],
    additionalProperties: false,
  };
}

module.exports = { isReadOnlyTool, annotationsFor, outputSchemaFor };
