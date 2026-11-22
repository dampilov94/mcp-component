# 45 — miniShop2 product drafts

Branch `task/45-product-drafts`, from local `main` after task 44. Read tasks 21, 31, 43 and 44,
`Drafts`, `DraftPolicy`, the resource kind and the miniShop2 data writer. Use the existing
draft store, resource processors, revisions and manager approval. Keep the package version.

## Workflow and scope

- `resource.create_draft` works on `msProduct` when the miniShop2 group is available to the
  caller. The draft is an unpublished, unscheduled, hidden `modDocument` in the original
  context and parent, with template `0`, no TV copies, no children and no miniShop2 rows.
  Preserve text fields using `DraftPolicy::FIELDS`. Identify a product draft by the original
  class recorded in the draft link's base snapshot, never by a caller-supplied marker. The
  original and its product data stay unchanged while the draft is prepared. Do not return a
  normal storefront preview URL for a product draft. The shared preview from task 44 may
  show its edited text under the same existing permission and HTTPS rules.
- Add `resource.stage_product_prices` through `modx_invoke` on the shadow draft. Accept `price`
  and/or `old_price` as finite non-negative numbers acceptable to miniShop2. A draft-only token
  may stage prices only on its own active and safe draft. Other write scopes follow normal
  resource and miniShop2 group access. Store only explicitly staged prices in a private
  namespace of the shadow's native `properties` column, preserving unrelated properties. Use
  `Objects::save()` in the locked writer path; prove normal text edits keep properties and
  snapshots/undo cover them. Return staged values and expose them as draft metadata when
  reading the shadow. Existing `plan_only`, dry-run and manager locks still apply.
- The apply plan and manager display show staged price changes as old/new values. Apply only
  changed editorial fields (compared to the saved base) and explicitly staged price columns
  through the existing miniShop2 relation writer. Preserve the original's class, template, TVs,
  availability, link, article, categories, options and other product data. A price-only draft
  must apply and undo. Keep revision checks for the whole product, and refuse to apply if the
  original no longer has the right class/data, the draft does not have safe state, or the
  integration is unavailable. `force` may resolve a normal revision/manager lock conflict but
  must not bypass product invariants. Use the existing archive and undo behavior.
- Keep the draft out of the catalog. Ordinary draft edit routes must refuse direct publication,
  scheduling or conversion of the shadow through MCP, including bulk publication. The
  restricted token may never apply, discard, publish or change the live item. Do not extend the
  standalone shared preview to pricing as part of this brief. Leave ordinary page drafts' behavior
  intact. Use en/ru labels and update user help.

## Verification

Cover no extra products or product rows, unchanged live item before approval, staged price
metadata and revision, plan and partial apply, zero/invalid prices, disabled integration and
ownership, normal and forced conflicts, direct publication refusal, discard, and undo of
apply including restoration of staged prices. Run `npm run check` and `npm test` on PHP 7.4.
Native MODX/miniShop2 must confirm properties survive an ordinary text edit and that only text
and explicitly staged prices affect the original. Preserve existing scopes and package metadata.

## Report

Based on `main` at `d22a906`.

- `Drafts` and `ProductDraft` create a hidden `modDocument` from an eligible product. The
  saved base determines whether the draft is a product draft. `stage_product_prices`
  validates the native DECIMAL(12,2) range, checks access before taking the resource lock
  and rechecks inside the writer. The shadow's private properties hold the staged prices.
- The resource save and bulk paths protect the shadow's publication and structure. The
  normal update processor preserves its properties. Apply computes editorial changes from
  the base and sends only staged prices to the existing miniShop2 relation writer. The normal
  draft archive and journal handle discard and undo.
- Resource reads expose `staged_prices`; the manager plan displays current and proposed
  prices. The normal product draft has no storefront preview URL. The shared preview
  uses the same text-only rules as before. Updated help in the component and user guide.
- `npm run check` and `npm test` passed in WSL on PHP 7.4.33, with 5599 PHP fixture checks
  and 41 Node tests. The architect also verified MODX 2.8.8 with miniShop2 3.0.7 and pdoTools
  2.13.3, including property preservation, product apply and undo, and the manager price plan.
