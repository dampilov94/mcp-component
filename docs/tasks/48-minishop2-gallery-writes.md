# 48 — Upload and delete miniShop2 gallery images with byte-level undo

Branch `task/48-minishop2-gallery-writes`, from main after task 47. Review the official native
3.0.7 upload/remove processors, msProductFile and msProductData, and project FileRoot,
FileSubject, Subjects, Writer and Undo. Keep existing package metadata.

## API

- Add `resource.upload_gallery_image` via `modx_invoke` on a real product, `args` with
  `filename` (simple JPEG or PNG basename) and `content_base64`. No URL, caller-chosen path,
  source override or replacement of existing files. Check actual image type, reasonable
  dimensions and existing API/body limits. Upload uses a guarded private local staging file
  and miniShop2's native upload processor. Stage-and-clean in a finally block. The existing
  product media source chooses the actual stored filename and thumbnail configuration.
- Add `resource.delete_gallery_image` on a real product, `args` with `image_id` and optional
  `confirm`. The default is a dry plan and a confirmed call removes that root image and its
  thumbnail children. Verify ownership by product, root and type. Use miniShop2's native
  file removal behavior through a dedicated processor or other narrow native path. Avoid the
  stock final-image cleanup that recursively deletes a directory in the default media source.
- Honor optional integration and token group, plan-only, dry run, resource locks, existing
  write scope and the draft-only restrictions. Return the gallery image ID when relevant and
  a normal journal changeset ID. Keep `list_gallery` response compatible.

## Files and journal

- Limit writes to the exact native local filesystem source for the product and its canonical
  product directory under a writable `FileRoot` in assets. Check native source class and path
  rules before opening it. Check the stored row source and check every affected file is under
  `<source>/<product id>/`; reject ambiguous paths, symlinks, escapes, missing source or
  unsupported/custom thumbnail configuration. Bound image and thumbnail dimensions/types;
  native thumbnail settings must be incapable of addressing anything outside this directory.
- Introduce a stable product-gallery journal subject (`ms2_gallery` keyed by product ID),
  reconstructable by Subjects and with explicit access checks, alongside the regular resource
  touch when the native operation can change its cover. Its state should cover native
  `msProductFile` rows (all ranks may shift), product image/thumb and physical bytes/modes in
  the product folder. Reuse FileRoot/FileSubject helpers where appropriate. A bounded whole
  product-gallery snapshot is acceptable in this increment. Verify the source ID and canonical
  root identity when restoring. Refuse galleries that cannot fit the configured journal blob
  limit. Verify exact encoded before/after size before commit (never commit an irreversible
  gallery write). Keep a bounded recovery path even when an operation overflows the configured
  blob limit or partially fails. Native upload can report success even when a thumbnail fails:
  verify the new gallery rows and files and roll back if required thumbnails are missing.
- Restore metadata without `msProductFile::remove()` / `msProductData::remove()` or recreating
  unrelated product data. A restore-only lower-level helper is allowed, consistent with
  Snapshot; validate row identities against other products before applying. Use the same
  per-product lock for gallery writes and recovery. Remove only owned files from the snapshot
  delta and prune only empty directories. Support undo and undo-of-undo for both operations.
  Refuse ordinary product-removal undo with a gallery as in task 47. When the undo batch includes
  both the removal of gallery files and the product, sequence/check safe removal explicitly or
  refuse that batch so a product is never physically removed with gallery files still on disk.
- Keep returned histories compact and never include image bytes or private filesystem paths in
  diffs or logs. Update help with supported media sources, limits, and rollback workflow. Keep
  unrelated product fields intact.

## Verification

Run `npm run check` and `npm test` on PHP 7.4. Cover upload, delete, dry-run, rollback after
partial native failure, undo/undo-of-undo, resulting cover, permissions, wrong product and
missing files, blocked paths, incompatible sources, oversized media or snapshots. Architect
will exercise real miniShop2 3.0.7 and verify actual file hashes on disposable MODX 2.8.8.
