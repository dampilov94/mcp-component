# TV fields — choose the right input type

A TV is a custom field on resources. Create one with `modx_create {kind: "tv", fields: …}`.
Use `modx_invoke {kind: "tv", op: "list_input_types"}` for core types, their uses and
requirements, plus custom types registered by installed add-ons.
`modx_invoke {kind: "tv", op: "suggest_type", args: {description: "…"}}` ranks input types
from an English or Russian description. Neither operation takes an object id or name.

The recommendation's `create_skeleton` preserves the historical suggestion format. Use
it as a hint: build the current API call with `fields.type` and `relations.templates`, as
below. Consult `modx_describe {kind: "tv"}` for fields this API currently exposes.

## Pick by task

| Editor input | `fields.type` | Setup |
|---|---|---|
| Short title, label, CSS class | `text` | — |
| Plain paragraphs | `textarea`, `textareamini` | — |
| Formatted content | `richtext` | WYSIWYG editor |
| Raw HTML/code | `rawtext`, `rawtextarea` | — |
| Numeric value | `number` | Optional min/max/step in manager |
| Date/time | `date` | Optional format in manager |
| Email or web link | `email`, `url` | — |
| On/off options | `checkbox` | `elements` for multiple options |
| One choice | `listbox`, `radio` | `elements` |
| Several choices | `listbox-multiple` | `elements` |
| Image or file | `image`, `file` | Configure picker media source in manager |
| Another resource id | `resourcelist` | Optional parent restriction in manager |
| Tags | `tag`, `autotag` | — |
| Repeating structured rows | `migx` | Installed add-on and its configuration |
| Color | `colorpicker` | Installed add-on and its configuration |
| Hidden value | `hidden` | — |

## Create and attach

Use `fields.name` (required), `caption`, `description`, `category`, `type`, `elements` and
`default_text`. Template links are a writable relation, not fields. Input properties and
TV media-source assignment are not exposed by the current TV kind; configure those in the
manager. The recommendation may mention them because it describes the widgets themselves.

```json
{"kind":"tv","fields":{"name":"badge","caption":"Badge","type":"listbox",
"elements":"None==||New==new||Sale==sale","default_text":""},"relations":{"templates":[1]}}
```

Pass this to `modx_create`. Option labels and values are separated by `==`, choices by `||`.
MODX `@` bindings in `elements` are supported, for example
`@SELECT pagetitle, id FROM modx_site_content WHERE parent=5`.

To attach an existing TV, call
`modx_update {kind: "tv", id: 12, relations: {templates: [1, 2]}}`.
This replaces the template links; a TV appears only on resources using an attached template.

For an image picker, create with `fields.type: "image"`. Discover available sources with
`modx_list {kind: "media_source"}` and choose the TV's source in the manager.
A `resourcelist` picker stores a resource id; configure its parent restriction in the manager.

## Custom types and verification

Custom types appear in the `custom` result of `list_input_types`. Inspect a component's
implementation with `modx_read_file {component: "…", path: "…"}` when its widget options
need explanation. Configure add-on-specific input properties in the manager.

After creation, `modx_get {kind: "tv", id: …}` shows fields and template links. Check the
resource edit form, or `modx_get {kind: "resource", id: …}` and its `relations.tvs` for values.
