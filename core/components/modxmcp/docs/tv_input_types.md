# Creating TV (template variable) fields — pick the right input type

A TV is a custom field on resources. Create one with `modx_create_element` (`type:"tv"`). The
hardest part is choosing the **right input type** (`field_type`) for the task and wiring its
extras. Use the table below; `modx_list_tv_input_types` returns the same `use`/`requires` hints
live (plus any add-on types installed on this site).

## Pick by task

| The editor needs to enter… | `field_type` | also send |
|---|---|---|
| A short line (title, label, CSS class) | `text` | — |
| Multi-line plain text | `textarea` (or `textareamini`) | — |
| Formatted body content (WYSIWYG) | `richtext` | — |
| Raw HTML/code, not output-filtered | `rawtext` / `rawtextarea` | — |
| A number | `number` | `input_properties` `min/max/step` (optional) |
| A date | `date` | `input_properties.format` (optional) |
| An email / a URL | `email` / `url` | — |
| Yes/no or several on-off options | `checkbox` | `elements` for multiple options |
| Pick ONE from a fixed list | `listbox` or `radio` | `elements` |
| Pick SEVERAL from a list | `listbox-multiple` | `elements` |
| Pick/upload an image | `image` | `media_source` (id) |
| Pick/upload a file | `file` | `media_source` (id) |
| Link to another page (resource) | `resourcelist` | `input_properties.parents` (optional) |
| Tags | `tag` / `autotag` | — |
| Repeating rows / galleries / structured lists | `migx` | a MIGX config — see the `migx` help topic |
| A color | `colorpicker` (if the add-on is installed) | `input_properties` per that add-on |
| A value hidden from the form | `hidden` | — |

## Fields you send in `data`

- `name` (required), `caption`, `description`, `category` (id).
- `field_type` — the input type from the table (mapped to the TV `type`).
- `default_text` — default value.
- `elements` — choices for option types (see below).
- `input_properties` — object of type-specific settings.
- `templates` — array of template ids to attach the TV to (so it shows on those resources).
- `media_source` — media source id (for `image`/`file`).

Attach to templates at create time (`"templates":[1,2]`) or later via `modx_update_element`. A
TV not attached to the resource's template won't appear on that resource.

## Option types (`listbox`, `listbox-multiple`, `radio`, `checkbox`)

Put choices in `elements` as `Label==value` pairs separated by `||`:

```
{"action":"create_element","type":"tv","data":{
  "name":"badge","caption":"Badge","field_type":"listbox",
  "elements":"None==||New==new||Sale==sale","default_text":"","templates":[1]
}}
```

Data-bound options are possible via `@`-bindings in `elements` (e.g.
`@SELECT pagetitle, id FROM modx_site_content WHERE parent=5`).

## image / file

```
{"action":"create_element","type":"tv","data":{
  "name":"hero","caption":"Hero image","field_type":"image","media_source":1,"templates":[1]
}}
```
`media_source` is the id of the source the picker browses (see `modx_list_media_sources`).

## resourcelist

`field_type:"resourcelist"` stores a resource id; `input_properties:{"parents":"5"}` limits the
picker to children of resource 5.

## Custom / add-on types (colorpicker, MIGX, …)

Add-ons register extra input types (visible in `modx_list_tv_input_types` → `custom`). To learn
the `input_properties` a custom type needs, read its source (`modx_get_component_files` +
`modx_read_component_file`; see the `study_component` topic). For **MIGX** (repeating structured
rows) use `field_type:"migx"` and configure it per the `migx` help topic — that is its own art,
read it before building MIGX TVs.

## Verify

After create, `modx_get_element type=tv` shows the stored `type`, `input_properties`, `elements`
and attached `templates`. Add it to a template and check a resource's edit form (or
`modx_get_resource_tvs`).
