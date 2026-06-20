# Getting started

**Model.** Everything is an *action* called via a `modx_*` tool. Responses are the data directly
(the success envelope is stripped). Errors come back with a clear message — read it and fix the call.

**Elements vs other objects.** For elements/resources the element tools take a `type`
(`chunk`/`snippet`/`template`/`resource`/`tv`/`category`/`plugin`) plus fields. Most other areas
(settings, ACL, miniShop2, contexts, …) have their own dedicated `modx_*` tools.

**Capability groups.** Optional groups (miniShop2, MIGX, VersionX, VirtualPage, Access Control,
contexts, property sets, package management, namespaces, lexicon) can be switched off in the
manager (Components → modxMCP). If a tool you need is missing, the group is probably disabled —
tell the user to enable it there. `modx_list_actions` shows all groups incl. disabled ones.

**Discovery first.** Before unfamiliar work: `modx_list_actions`, `modx_check_integrations`,
`modx_list_tv_input_types`, and `modx_help` topics. To understand an installed add-on, read its
source with `modx_get_component_files` + `modx_read_component_file` (see the `study_component` topic).

**Static files.** `make_static` (and the `modxmcp.auto_static` setting) store an element's code as
a file under `core/elements/` so it can be edited over FTP / kept in git.
