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
- `default`, `pos` (order).

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

## Named (reusable) configs

For a config shared across TVs: create it with `modx_migx_create_config` (needs the **migx**
capability group enabled), then reference it via `input_properties:{"configs":"MyConfig"}`.
Caveat on MODX/MIGX here: an xPDO-created config may render the default "Title" column with empty
rows until it's opened+saved once in **Components → MIGX** (that fills MIGX's relational
form-tab tables). The inline path above avoids this entirely — prefer it unless you truly need a
shared config. The `migx_*` tools (list/get/create/update/delete config) are in the **migx** group.
