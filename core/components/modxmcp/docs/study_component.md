# Studying & using a newly-installed component

When the user installs an add-on (a new TV input type like colorpicker, a snippet pack, a custom
component) and wants you to use it correctly, learn it from its own source — don't guess.

## 1. See what's installed
- `modx_check_integrations` — add-ons modxMCP has dedicated tools for (miniShop2, MIGX, VersionX, VirtualPage).
- `modx_list_installed_components` — all installed components (needs the package-management group enabled).
- `modx_list_tv_input_types` — TV input types incl. ones a component just registered.

## 2. Read its source
- `modx_get_component_files {component:"<name>"}` — list the component's files.
- `modx_read_component_file {component:"<name>", path:"..."}` — read a file.

Where to look:
- `model/schema/*.xml` and `model/<ns>/*` — its classes/tables.
- `processors/mgr/...` — its manager processors (the actions its own UI calls).
- `elements/` (snippets/plugins/chunks/tv) — e.g. a plugin on `OnTVInputRenderList` that registers a
  TV input type, or the input-render templates that show which `input_properties` it reads.
- `docs/` / `lexicon/` — descriptions and field labels.
- system settings (`modx_list_system_settings {namespace:"<name>"}`) — its configuration.

## 3. Use it
- **TV input type** (e.g. colorpicker): create the TV with that `field_type` and the
  `input_properties` you learned from its source (see the `tv_input_types` topic).
- **Snippets/chunks**: call/configure them by editing elements — MCP can read and write those directly.
- **Its own processors**: if it has manager processors you need, enable the
  `modxmcp.allow_run_processor` setting and call `modx_run_processor` with
  `{processor:"mgr/...", properties:{...}, processors_path:"<core_path>components/<name>/processors/"}`.
  Read the processor file first to get the exact parameter names.

## Rule of thumb
Read first, then act. The schema + processor source tell you the exact class keys, fields and
parameters — far more reliable than guessing.
