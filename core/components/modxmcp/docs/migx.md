# MIGX — repeating structured rows in a TV

MIGX stores a list of rows (a gallery, slides, feature blocks) in one template variable (TV) of input type
`migx`. A row is an object; the TV value is the JSON list of rows. What a row has (form fields) and what the
grid shows (columns) is described by a **configuration**, which is either written inline in the TV's input
properties or kept as a named object of kind `migx_config` that many TVs share. The kind exists when MIGX is
installed and the `migx` group is on (`modx_capabilities` says why it is not).

## Rows: the values of a MIGX TV

A TV value is read and written through the `tvs` relation of a resource (the TV must be attached to the
resource's template):

```
modx_update {kind: "resource", id: 12, relations: {tvs: {gallery: [
  {image: "img/1.jpg", title: "Первый"},
  {image: "img/2.jpg", title: "Второй"}
]}}}
```

- A list of objects is encoded for you; every row without `MIGX_id` gets one, continuing from the highest
  `MIGX_id` in the list you send (as MIGX itself numbers a new row). Rows keep their order. To change one row,
  read the list, change it and send the whole list back: `relations.tvs.gallery` replaces the value.
- `modx_get {kind: "resource", id: 12}` shows a MIGX TV as the decoded list when the stored text is valid JSON,
  otherwise as the stored text (an empty value, an `@CHUNK` default, text an old tool wrote).
- A string is still accepted and stored as it is (nothing is checked). A row that is not an object, or an object
  where a list is expected, is refused. Only TVs of type `migx` take lists; every other TV stays a string.

## Named configurations: kind `migx_config`

```
modx_create {kind: "migx_config", fields: {
  name: "gallery_rows", category: "Сайт",
  formtabs: [{caption: "Слайд", fields: [
    {field: "image", caption: "Картинка", inputTVtype: "image"},
    {field: "title", caption: "Заголовок", inputTVtype: "text"}]}],
  columns: [{header: "Картинка", dataIndex: "image", width: 120}, {header: "Заголовок", dataIndex: "title", width: 200}]
}}
```

- `formtabs` is a list of `{caption, fields: [{field, caption, …}]}` and `columns` a list of
  `{header, dataIndex, …}`; a write with another shape is refused naming the first bad path. Extra keys are kept.
  `filters` is a list of objects; `extended`, `permissions`, `fieldpermissions` are objects.
- `contextmenus`, `actionbuttons`, `columnbuttons` are **strings** of names joined by `||` (as MIGX stores them),
  not JSON.
- The JSON fields are read and written as JSON values, not as text. They are not in `modx_list` unless you ask
  for them with `fields`, and cannot be used in `where`.
- MIGX mirrors the form tabs and their fields into two tables when it saves a configuration and writes the row
  ids back into `formtabs` as `MIGX_id` (and `pos`). Saving through the API does the same, so what you read back
  has those keys; send them back unchanged when you edit `formtabs`, otherwise the rows are replaced by new ones.
- Warnings (not errors): a `columns[].dataIndex` that is not a field of any form tab, and an `inputTV` that is
  not a TV on the site.
- `modx_delete` marks the configuration deleted, as the MIGX grid does (the row stays and MIGX ignores it);
  `modx_undo` brings it back. `modx_list {kind: "migx_config"}` hides deleted configurations; list them with
  `where: {deleted: true}`. A deleted configuration does not hold its name; undo is refused while a live one has it.
- `modx_duplicate {kind: "migx_config", id: …, new_name: "…"}` copies a configuration.

## Binding a TV to its configuration

A TV of type `migx` finds its configuration in its input properties: `configs` is the name of a `migx_config`
(several names joined by commas, `name:package` for a configuration kept by an add-on package). With no
configuration, MIGX reads `formtabs` and `columns` (JSON text) straight from the input properties, and may also
take `contextmenus`, `actionbuttons`, `columnbuttons`, `filters`, `extended` there. Inline is enough for rows
stored in one TV; use a named configuration to share one description between TVs.

`modx_create {kind: "tv", fields: {name: "gallery", caption: "Галерея", type: "migx", input_properties:
{configs: "gallery"}}, relations: {templates: [3]}}` creates the TV bound to the named configuration. For an
inline description put `formtabs` and `columns` (as JSON text) into `input_properties` instead of `configs`.
Writing `input_properties` replaces the whole map, so send every option the TV needs.

## Fields that need more than a widget: inputTV

For anything beyond a plain text or number field, do not write `inputOptionValues` or an `@SELECT` into the
MIGX field. Create a normal TV, configure it fully, and point the field at it with `"inputTV": "<tv name>"`: MIGX
renders that TV's widget with its input properties inside the row form. Use it for a resource picker limited to
a parent, an editor, an image with a given media source, or a nested MIGX (a field whose `inputTV` is itself a
`migx` TV). Name such helper TVs with a `ForMigx` suffix (`listNewsForMigx`) and do not attach them to templates.
`inputTVtype` (`text`, `textarea`, `richtext`, `image`, `listbox`, `checkbox`, …) is the quick alternative for
simple widgets; `inputOptionValues` takes `Red==red||Blue==blue`. `default` and `pos` are also known.

## Gotchas (MODX 2.8.8, MIGX 3.0.x)

- A `renderChunk` column renders on the server (snippets run). Set `"renderer": "this.renderChunk"` and
  `"renderchunktpl": "<template>"` (the key is `renderchunktpl`); the template gets the row fields as `[[+field]]`.
- A `renderChunk` column needs a **virtual** `dataIndex`, not a stored field: MIGX overwrites that field in the
  row for display, and the rendered text is saved back into the TV at the next resource save. Give the display
  column an invented `dataIndex` and use the real field inside the template; keep a plain column for the raw value.
  (The warning about a `dataIndex` without a field also fires for such a column; that is expected.)
- MIGXdb (grids over custom tables), MIGX import/export and package building are not covered by these tools.
