# MIGX

- **Configurations**: `modx_migx_list_configs`, `modx_migx_get_config`, `modx_migx_create_config`,
  `modx_migx_update_config`, `modx_migx_delete_config`. The grid/tab definitions (`formtabs`,
  `columns`, …) are JSON strings in MIGX's own format — copy the structure from an existing config
  (get one first) rather than guessing.
- **MIGX TVs**: create a TV with `field_type:"migx"`. Two ways to configure it via
  `input_properties`:
  - **Named config**: `{"configs":"MyConfig"}` — points at a migxConfig object (reusable across
    TVs, but an xPDO-created config needs the one-time native-editor save; see Gotchas).
  - **Inline (recommended for one-off TVs)**: leave `configs` empty and put the JSON straight into
    `{"formtabs":"<json>","columns":"<json>"}`. MIGX falls back to these inline properties when no
    config is bound (`migxinputrender` uses `$properties['columns']`/`['formtabs']`), so there is
    **no separate config object to register** — the grid and add/edit form work immediately after
    `clear_cache`. Verified stable across resource saves on MIGX 3.0.2.
  See the `tv_input_types` topic.

## Gotchas (verified on MODX 2.8.8 + MIGX 3.0.2)

- **Register a programmatically-created config once via the native MIGX editor.** A config made
  with `modx_migx_create_config` (direct xPDO) stores valid `formtabs`/`columns` JSON, but the
  TV's embedded grid renders the *default* "Title" column with empty rows until the config is
  opened and saved once in **Components → MIGX** (right-click the row → Редактировать → Сохранить).
  That native save populates MIGX's relational form-tab tables (`migx_formtabs`/`migx_formtab_fields`,
  adding `MIGX_id`s) and registers the config for TV rendering. This is a one-time step **per
  config**; later edits to `columns` via the API apply directly after `clear_cache` (no editor
  re-save needed).
- **`renderChunk` columns ARE rendered server-side** (`migx.class.php` → `checkRenderOptions` →
  `renderChunk($tpl, $row, false)` → `parseChunk` + `processElementTags`). Set the column property
  exactly `"renderer":"this.renderChunk"` and `"renderchunktpl":"<inline template>"` (note the key
  is `renderchunktpl`, not `rendchunktpl`). The inline template gets the row fields as placeholders
  and **executes snippets**, so you can resolve a stored id to its title, e.g.
  ``"renderchunktpl":"[[pdoField? &id=`[[+resource]]` &field=`pagetitle`]] (#[[+resource]])"``.
  The client-side `this.renderChunk` JS just returns the already-server-rendered value. Common
  mistakes that make the cell show `Array(...)`: wrong key (`rendchunktpl`) or passing a chunk
  *name* expecting client-side resolution.
- **CRITICAL: a `renderChunk` column must use a VIRTUAL `dataIndex`, not a real stored field.**
  `checkRenderOptions` does `$row[dataIndex] = renderChunk(...)`, overwriting that field for display.
  If `dataIndex` equals a stored form field (e.g. `resource`), the rendered text gets persisted back
  into the TV on the next resource save, and each save/render nests it again — data corrupts
  recursively (`resource: " (#Новости (#6))"` …). Instead give the display column an invented
  `dataIndex` (e.g. `resourcetitle`) and reference the real field inside the template
  (`[[+resource]]`); keep a separate plain column with `dataIndex:"resource"` (no renderer) if you
  also want the raw id. The real stored field then stays untouched and the frontend keeps getting
  clean values.

Enable the **migx** capability group if these tools are missing.
