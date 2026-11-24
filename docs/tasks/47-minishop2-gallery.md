# 47 — Read miniShop2 galleries and preserve them during product undo

Branch `task/47-minishop2-gallery`. Read tasks 21, 22 and 45 and the official miniShop2 3.0.7
package under `.local/upgrade-check/`. Inspect `msProductFile`, `msProductData::remove()` and
the vendor gallery list processor. Keep package metadata.

## Gallery reads

- Add `resource.list_gallery` via `modx_invoke`, on an existing `msProduct`. Respect the
  miniShop2 integration and token group and the draft-only tool restrictions. Declare the
  operation read-only. Use stable rank then ID order, optionally paginated by `limit` and
  `offset`, with a bounded default/max. Return `total` and `images`. Include root image rows
  only (`parent=0`, `type=image`), and return their numeric ID, name, description, rank,
  active status, stored URL and stored thumbnail URL when present. Exclude storage paths,
  credentials and raw media properties. Use xPDO reads or the matching native read model.
  Do not initialize a remote media backend or fetch any URLs.
- Document discovery and the response shape. Do not extend `draft_only` in this task.
  Gallery writes (captions, reordering, upload and removal) are separate follow-ups.

## Restore product rows safely

The existing resource restore for `ms2_data` removes the row and then recreates it. The native
`msProductData::remove()` removes files, thumbnail rows, product links and other relations. Use
the existing `msProductData` row in place when a snapshot restores a product with data. If the
row is missing, use the existing create path including its verified media-source restoration.
Preserve the existing row's `image` and `thumb`, which reflect the current gallery, when
restoring other data. Keep option and category restoration through their existing paths.

Before an undo path would actually remove a current product data row, product, or convert a
product to a different resource class, refuse if gallery rows exist or product data availability
cannot be determined. Include the case of a disabled integration on a product with current data.
Do not add gallery files to generic resource snapshots or make existing revisions depend on media
bytes. Do not use native gallery deletion to implement restoration.

## Verification

Test read-only discovery and paging, parent/type/product filtering, optional integration/token
access and draft-only refusal. Cover in-place undo with product files and gallery metadata left
intact, existing option/category restore, a changed cover after the old snapshot, and refusal
over a product with gallery records if undo would remove it. Run `npm run check` and `npm test`
on PHP 7.4. Architect will test with native miniShop2 3.0.7 and a harmless local fixture image.

## Report

Based on `main` at `3e557c3`.

- `resource.list_gallery` reads selected columns from `msProductFile`, using the optional
  integration and token group gates. It lists only root images and takes the first stored
  thumbnail URL in rank and ID order for each image. The response omits raw storage metadata,
  unsupported URL schemes and recognized credential-bearing URLs.
- `ResourceKind` restores existing `msProductData` in place and leaves its current cover URLs
  intact. Restoration still supports recreating a missing data row with the correct media
  source and restores options and categories through the existing relations.
- Removing product data or a product requires successful reads of the product and gallery
  tables. Physical removal verifies the resource subtree in bounded pages, covering galleries on
  descendants as well. Force cannot bypass either check. Generic product snapshots still
  exclude gallery rows and media bytes.
- The architect verified gallery paging, native product restore, cover preservation and
  physical file hashes on disposable MODX 2.8.8 with miniShop2 3.0.7. The native checks also
  verified refusal for an undo that would remove a product or a category with gallery files.
- `npm run check` and `npm test` passed in WSL on PHP 7.4.33. All 5726 fixture checks and
  41 Node tests passed. The fixture includes a gallery-free subtree with over a thousand
  descendants and a nested product gallery protected from category removal.
