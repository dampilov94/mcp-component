# 39 — Property diffs, history keys and prompt fixes

Branch `task/39-properties-history-prompts`, stacked on task 38 at `ccfce94`. Covers the
property decoding and comparison, object-key history filter and two prompts from the alpha2 review.

- Decode JSON and serialized property arrays without instantiating PHP classes. Present element
  and property-set definitions in history, and recorded source property overrides as values.
  Mask secret property values in the diff. Keep snapshot bytes and revisions compatible.
- In update dry runs compare normalised element property maps. For a media source compare the
  effective properties after the same partial merge and defaults as a regular update.
- Support `modx_history {kind, key}` in addition to `{kind, id}`. Refuse an ambiguous selector.
- Offer `fix_errors` only when `modx_read_log` is available. In `new_page_type`, ask the user
  to preview the unpublished test page from the MODX manager.

## Report

Based on: task/38-mcp-warnings-limits at `ccfce94`.

- `Properties` decodes plain data in both storage formats and masks recognised credential
  names or password types for history. `ObjectSubject` uses the property representation for
  diffs. Media source diffs use stored overrides, since class defaults may have changed
  since the snapshot. Recorded snapshot bytes and their revision calculation are unchanged.
- The update preview compares element properties in their normalised API form. A media source
  preview shares the writer's merge logic and compares effective values with the class defaults.
- `HistoryAction` uses the existing journal key lookup for `kind` + `key`, alongside numeric
  IDs. The prompts check log access and use the manager preview for an unpublished page.
- The offline fixtures explicitly store PHP-serialized properties before updating, and verify
  the diff, empty update preview, credential masking and exact restoration of stored bytes.
  `npm run check` and `npm test` passed in WSL on PHP 7.4.33 (5447 PHP checks and 39 Node
  tests, no skips). On the stand, verify these with MODX's actual property storage and source
  defaults, including an update that resets a property to its default.
