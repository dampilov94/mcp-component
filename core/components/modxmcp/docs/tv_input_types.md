# Creating TV (template variable) fields

Create a TV with `modx_create_element` (`type: "tv"`). Useful fields in `data`:

- `name` (required), `caption`, `description`, `category` (id).
- `field_type` — the **input type** (widget). See the list below or call `modx_list_tv_input_types`.
- `default_text` — default value.
- `elements` — option string for select-like inputs (see below).
- `input_properties` — an object of input-type-specific settings (see below).
- `templates` — array of template ids to attach the TV to.
- `media_source` — media source id (for image/file types).

Attach to templates either at create time (`templates: [1,2]`) or later with `modx_update_element`.

## Core input types (`field_type`)
`text`, `textarea`, `textareamini`, `rawtext`, `rawtextarea`, `richtext`, `date`, `number`,
`email`, `url`, `hidden`, `checkbox`, `listbox` (single select), `listbox-multiple`, `radio`,
`image`, `file`, `resourcelist`, `tag`, `autotag`.

### Option-based types (listbox, listbox-multiple, radio, checkbox)
Put the choices in `elements` as `Label==value` pairs separated by `||`:

```
{"action":"create_element","type":"tv","data":{
  "name":"color","caption":"Color","field_type":"listbox",
  "elements":"Red==red||Green==green||Blue==blue","default_text":"red","templates":[1]
}}
```

You can also bind to a data source via `@`-bindings in `elements` (e.g. `@SELECT name, id FROM ...`).

### image / file
Set `field_type:"image"` (or `"file"`) and `media_source` to the media source id the picker should browse.

### Per-type settings (`input_properties`)
Some types accept extra settings via `input_properties` (an object), e.g. `allowBlank`, `listMax`,
date `format`, number `min/max`. Pass exactly the keys that input type expects.

## Custom / add-on input types (e.g. colorpicker, MIGX)
Add-ons register extra input types (e.g. a colorpicker, `migx`). Workflow:

1. `modx_list_tv_input_types` → find the new type key (e.g. `colorpicker`).
2. To learn what `input_properties` it needs, read the add-on's source — `modx_get_component_files`
   then `modx_read_component_file` on its `model/inputoptions`/`elements/tv/input` files and its
   plugin that hooks `OnTVInputRenderList`. (See the `study_component` help topic.)
3. Create the TV with that `field_type` and the discovered `input_properties`:

```
{"action":"create_element","type":"tv","data":{
  "name":"bg_color","caption":"Background","field_type":"colorpicker",
  "input_properties":{"format":"hex"},"templates":[1]
}}
```

For MIGX TVs use `field_type:"migx"` and set the MIGX config name in `input_properties`
(e.g. `{"configs":"MyConfig"}`); manage the config itself with the `migx_*` tools.
