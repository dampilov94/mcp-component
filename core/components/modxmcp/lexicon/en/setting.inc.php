<?php
$_lang['setting_modxmcp.enabled'] = 'Enable MODX MCP';
$_lang['setting_modxmcp.enabled_desc'] = 'Globally enables or disables the MODX MCP API component. When disabled, all API requests are rejected.';


$_lang['setting_modxmcp.service_user_id'] = 'Service user ID';
$_lang['setting_modxmcp.service_user_id_desc'] = 'The MODX user ID under which MCP executes processors and administrative actions. The user must exist and be active.';

$_lang['setting_modxmcp.debug'] = 'MCP debug mode';
$_lang['setting_modxmcp.debug_desc'] = 'When enabled, the API returns internal error details in the response. It is recommended to keep this disabled in production.';

$_lang['setting_modxmcp.audit_log'] = 'MCP audit log';
$_lang['setting_modxmcp.audit_log_desc'] = 'When enabled, create/update/delete operations and TV updates are written to the MODX log for auditing and diagnostics.';

$_lang['setting_modxmcp.max_payload_bytes'] = 'Maximum payload size';
$_lang['setting_modxmcp.max_payload_bytes_desc'] = 'Maximum allowed size of the JSON request body in bytes. Protects the component from oversized or malformed requests.';

$_lang['setting_modxmcp.allow_root_filesystem_read'] = 'Allow root Filesystem read';
$_lang['setting_modxmcp.allow_root_filesystem_read_desc'] = 'When enabled, MCP may browse and read files through the root Filesystem media source. Keep disabled if you only want safe component-code inspection.';

$_lang['setting_modxmcp.component_code_roots'] = 'Component code roots';
$_lang['setting_modxmcp.component_code_roots_desc'] = 'Comma-separated list of directories that MCP may scan for installed component code, for example core/components,assets/components.';

$_lang['setting_modxmcp.core_path'] = 'Core path';
$_lang['setting_modxmcp.core_path_desc'] = 'Filesystem path to the modxMCP core component directory. Defaults to {core_path}components/modxmcp/.';

$_lang['area_modxmcp:main'] = 'modxMCP: Main';
$_lang['area_modxmcp:limits'] = 'modxMCP: Limits';
$_lang['area_modxmcp:security'] = 'modxMCP: Security';
$_lang['area_modxmcp:paths'] = 'modxMCP: Paths';

$_lang['setting_modxmcp.auto_static'] = 'Auto static elements';
$_lang['setting_modxmcp.auto_static_desc'] = 'When enabled, MCP create/update converts DB-only chunks/snippets/templates/plugins to uniquely named files under the configured core_path/elements/ using native MODX static storage (source=0). Existing static elements keep their file path and Media Source.';


$_lang['setting_modxmcp.allow_run_processor'] = 'Allow run_processor';
$_lang['setting_modxmcp.allow_run_processor_desc'] = 'When enabled, the run_processor MCP action may execute ANY MODX processor directly. Powerful escape hatch — off by default. Prefer dedicated actions when they exist.';

$_lang['setting_modxmcp.allow_query_db'] = 'Allow query_db';
$_lang['setting_modxmcp.allow_query_db_desc'] = 'When enabled, tokens with the develop scope may run read-only SELECT queries against the site database with the modx_query_db tool. Credential tables are refused. Off by default.';

$_lang['setting_modxmcp.max_response_bytes'] = 'Maximum response size';
$_lang['setting_modxmcp.max_response_bytes_desc'] = 'JSON response limit in bytes (default 4194304, minimum 1024). An oversized response returns an error even if its action has completed; inspect request status before retrying.';

$_lang['setting_modxmcp.request_retention_seconds'] = 'Request result retention';
$_lang['setting_modxmcp.request_retention_seconds_desc'] = 'Seconds to retain responses for replay by request ID (default 86400, minimum 60). Expired IDs remain reserved so the action cannot execute again. State lives under core/modxmcp-data/requests outside MODX cache and the component package.';

$_lang['setting_modxmcp.trusted_proxies'] = 'Trusted proxies';
$_lang['setting_modxmcp.trusted_proxies_desc'] = 'Comma-separated trusted proxy IPs or CIDR ranges (IPv4/IPv6). Only these REMOTE_ADDR peers may assert a single X-Forwarded-Proto: https when require_https is enabled. Empty means no header trust. Proxies must replace client headers, and REMOTE_ADDR must identify the proxy itself.';

$_lang['setting_modxmcp.require_https'] = 'Require HTTPS';
$_lang['setting_modxmcp.require_https_desc'] = 'Reject POST requests without server-reported HTTPS or X-Forwarded-Proto from a trusted proxy. Configure trusted_proxies before upgrading an installation that terminates TLS upstream. Disabled by default.';

$_lang['setting_modxmcp.allowed_ips'] = 'Allowed client IPs';
$_lang['setting_modxmcp.allowed_ips_desc'] = 'Comma-separated IPs or CIDR ranges (IPv4/IPv6), matched against REMOTE_ADDR. Empty allows all peers. X-Forwarded-For is not an authorization source; invalid rules are ignored.';

$_lang['setting_modxmcp.lint_php'] = 'Check PHP before saving';
$_lang['setting_modxmcp.lint_php_desc'] = 'Snippet and plugin code is checked for syntax errors before it is written. Code with an error is not saved.';
$_lang['setting_modxmcp.history_max_blob_bytes'] = 'Maximum journal snapshot size';
$_lang['setting_modxmcp.history_max_blob_bytes_desc'] = 'A change to an object whose snapshot is larger than this size (in bytes) is carried out but cannot be undone.';
$_lang['setting_modxmcp.history_retention_days'] = 'Journal retention in days';
$_lang['setting_modxmcp.history_retention_days_desc'] = 'Remove changesets older than this many days during hourly cleanup on writes. Default: 30. Set to 0 to keep history regardless of age.';
$_lang['setting_modxmcp.history_max_bytes'] = 'Maximum journal storage size';
$_lang['setting_modxmcp.history_max_bytes_desc'] = 'Limit snapshot storage in bytes (default: 209715200, 200 MB). Cleanup removes the oldest changesets, keeping the newest; unreferenced blobs younger than one hour are protected. Set to 0 for no size limit.';
