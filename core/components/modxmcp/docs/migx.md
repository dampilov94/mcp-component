# MIGX — repeating structured rows in a TV

MIGX stores a list of rows (e.g. a gallery, slides, feature blocks) in one TV. A row has fields;
a grid shows columns. **Recommended path: an INLINE config** — put the field/column JSON straight
into the TV's `input_properties`, no separate config object, works immediately after `clear_cache`.
(Named configs are reusable but need an extra registration step — see the end.)

## Simplest working example (copy, then adapt)

A gallery TV (each row = an image + a caption). `formtabs` and `columns` are **JSON strings**
inside `input_properties`; `configs` stays empty for inline mode:

```
{"action":"create_element","type":"tv","data":{
  "name":"gallery","caption":"Gallery","field_type":"migx","templates":[1],
  "input_properties":{
    "configs":"",
    "formtabs":"[{\"caption\":\"Item\",\"fields\":[{\"field\":\"image\",\"caption\":\"Image\",\"inputTVtype\":\"image\",\"pos\":1},{\"field\":\"title\",\"caption\":\"Title\",\"inputTVtype\":\"text\",\"pos\":2}]}]",
    "columns":"[{\"header\":\"Image\",\"dataIndex\":\"image\",\"width\":120},{\"header\":\"Title\",\"dataIndex\":\"title\",\"width\":200}]"
  }
}}
```

That's a complete, working MIGX TV. Attach it to the resource's template (`templates`), clear
cache, and the grid + add/edit form appear on those resources.

## Fields (`formtabs`)

`formtabs` = array of tabs; each tab has `caption` + `fields[]`. Each field:
- `field` — the stored key (use in columns/templates as `[[+field]]`).
- `caption` — label in the add/edit form.
- `inputTVtype` — the widget: `text`, `textarea`, `richtext`, `image`, `listbox`, `checkbox`,
  `colorpicker` (if installed), … (same family as TV input types).
- `inputOptionValues` — options for listbox/checkbox: `"Red==red||Blue==blue"`, or a DB binding
  `"@SELECT \`pagetitle\`,\`id\` FROM \`[[+PREFIX]]site_content\` WHERE \`deleted\`=0"`.
- `inputTV` — **reuse an existing TV as this field's input** (see below). Preferred for anything
  non-trivial.
- `default`, `pos` (order).

### Complex fields → `inputTV` (preferred), with the `ForMigx` naming convention

For anything beyond a plain text/number field, do NOT hand-craft `inputOptionValues`/`@SELECT`
inside the MIGX field. Instead create a normal TV, configure it fully there, and point the MIGX
field at it by name with `"inputTV":"<tvName>"`. MIGX then renders that TV's widget and uses its
`input_properties` inside the row form (it loads the TV by name and applies its config). Use this
for: a resource picker limited to a parent/template, a richtext/editor field, a media/image field
with a specific source, or a **nested MIGX** (a field whose `inputTV` is itself a `migx` TV).

**Convention (required here): name these helper TVs with a `ForMigx` suffix** —
`listNewsForMigx`, `textEditorForMigx`, `galleryForMigx` — so they're obviously MIGX-internal and
not meant to be attached to templates directly.

Example — a "news link" field that picks only children of the News folder (id 8):

```
// 1) the helper TV (configured once, NOT attached to a template):
{"action":"create_element","type":"tv","data":{
  "name":"listNewsForMigx","caption":"News item","field_type":"resourcelist",
  "input_properties":{"parents":"8"}
}}
// 2) reference it from a MIGX field:
//    formtabs field: {"field":"news","caption":"News","inputTV":"listNewsForMigx"}
```

This is cleaner and more reliable than embedding a raw `@SELECT`, and it's how you build nested
MIGX: make a `...ForMigx` TV of `field_type:"migx"` (with its own inline formtabs/columns) and use
it as `inputTV` on a field of the parent MIGX TV.

(`inputTVtype` is the quick alternative — just a widget type with no backing TV/config; use it
only for simple widgets like `text`/`image`. For real configuration, prefer `inputTV`.)

## Columns (`columns`)

`columns` = array of grid columns: `header`, `dataIndex` (which field to show), `width`. For a
plain field, `dataIndex` = the field key. To render a column with a chunk/snippet, see below.

## Gotchas (verified on MODX 2.8.8 + MIGX 3.0.x)

- **`renderChunk` columns render SERVER-side** (`migx.class.php` → `checkRenderOptions` →
  `renderChunk` → `parseChunk` + `processElementTags`), so snippets execute. Set on the column:
  `"renderer":"this.renderChunk"` and `"renderchunktpl":"<inline template>"` — the key is
  **`renderchunktpl`** (not `rendchunktpl`). The template gets row fields as `[[+field]]` and runs
  snippets, e.g. resolve a stored id to a title:
  `"renderchunktpl":"[[pdoField? &id=\`[[+resource]]\` &field=\`pagetitle\`]]"`. Wrong key or
  passing a chunk *name* makes the cell show `Array(...)`.
- **CRITICAL — a `renderChunk` column MUST use a VIRTUAL `dataIndex`, not a real field.**
  `checkRenderOptions` does `$row[dataIndex] = renderChunk(...)`, overwriting that field for
  display. If `dataIndex` is a stored field (e.g. `resource`), the rendered text is saved back
  into the TV on the next resource save and nests recursively → data corruption. Give the display
  column an invented `dataIndex` (e.g. `resourcetitle`) and reference the real field inside the
  template (`[[+resource]]`); keep a separate plain column `{"dataIndex":"resource"}` if you also
  want the raw value. (Real working example on this pattern: the demo `home_list` TV.)

## Inline vs named configs — inline is enough

Inline (`input_properties`) is essentially as capable as a named migxConfig for a TV-stored
MIGX. MIGX uses the TV's `input_properties` as the whole config when no named config is bound, so
besides `formtabs` + `columns` you may also add the same keys a config holds —
`contextmenus`, `actionbuttons`, `columnbuttons`, `filters`, `extended` — straight into
`input_properties`. **Prefer inline.** You only need a named config for: reuse of one config
across many TVs, or MIGXdb (database-backed grids that key off a registered config). For ordinary
"repeating rows stored in the TV", inline loses nothing.

Named configs (if you really need sharing): create with `modx_migx_create_config` (needs the
**migx** capability group), reference via `input_properties:{"configs":"MyConfig"}`. Caveat: an
xPDO-created config may show the default "Title" column with empty rows until opened+saved once in
**Components → MIGX** (that fills MIGX's relational form-tab tables) — another reason to prefer
inline. The `migx_*` config tools live in the **migx** group.
