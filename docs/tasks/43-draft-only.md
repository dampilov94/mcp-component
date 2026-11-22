# 43 — Draft-only token

Branch `task/43-draft-only`, based on local `main` after alpha3 (`d786d26` plus this brief).
Read section 10 of `docs/spec-2.0.md`, task 31, `Auth/Scope.php`, action and kind registries,
`InvokeAction`, `UpdateAction`, `ResourceKind`, `Drafts` and `DraftStore`.

## Workflow

An administrator creates a token with the scope `draft_only` in the Tokens screen. The client
finds a page, creates one unpublished draft through the existing operation and saves editorial
changes to that draft. The administrator can apply or discard it through the existing manager
screen. Preview continues to require a manager login. The four existing scopes and their
saved tokens work as before.

## API and manager

- Persist the new, immutable choice in the existing token `scope` column. It has the base rank
  of `content` for existing scope comparisons. The separate action and kind allowlists enforce
  its narrower rights. It can also be plan-only under the existing semantics. Add English and
  Russian labels and explain the workflow in the user guide and built-in help.
- Only `manifest`, `capabilities`, `site_info`, `help`, `describe`, `list`, `get`, `resource_tree`,
  `update` and `invoke` are available to this scope. The allowed kind is `resource`. Use the
  registries so capability reporting, tool discovery and direct dispatch agree. Generic resource
  reads retain their usual behavior. Site and token group switches still apply. This task does
  not define separate visibility for sections of a site or guarantee tenant isolation.
- For `invoke`, allow only `resource.create_draft` and `resource.list_drafts`. List only open
  drafts owned by the current token, using the `token_id` already recorded in the draft link.
  `describe` should show just these operations to this token. Keep the normal create constraints
  and transaction. Always refuse `apply_draft` and `discard_draft` for this scope, including dry
  runs and calls with `force`. Keep manager approval on its current path.
- For `update`, allow only `pagetitle`, `longtitle`, `description`, `introtext`, `content` and
  `menutitle` on a current draft owned by the token. Keep this first mode plain text. Reject
  markup or template delimiters (`<`, `>`, `[`, `]`, `{`, `}`, `&`) in provided field values.
  Existing content copied from the original is preserved until the corresponding field is
  changed. Refuse relations, `force`, and `skip_lint` when enabled. In particular, the token
  cannot set TVs, change a template or parent, schedule publication, or save code.
- Check ownership, class `modDocument`, and the draft's unpublished, not scheduled, not deleted
  state for both previews and real saves, including under the draft resource lock in the update
  transaction. Use the existing revision and open-page checks. Reject writes to original pages,
  ordinary unpublished pages, existing drafts owned by another token or by a manager, and drafts
  no longer in a safe state. Keep the restrictions server-side for both transports.

## Verification

Exercise issuance and authentication, tool and operation discovery, creation, editing, and
listing owned drafts, and approval through the existing `DraftService`. Confirm denied writes
leave the original and linked draft unchanged, including publish fields, generic create,
bulk edits, apply, undo, a draft owned by a second token, and editing an ordinary unpublished
page. Check both legacy v2 dispatch and direct MCP on the offline endpoint. Run
`npm run check` and `npm test` on PHP 7.4. Keep any native MODX check on the development fixtures
or dedicated stand.

Out of scope are public preview links, storefront domains, quotas, new page workflows, product
drafts, permissions by section, and the external cabinet or billing.

## Report

Based on `main` at `a3725b4`. Implementation commit `3a22cf5`.

- `Scope`, the action and kind registries, and `DraftPolicy` use the existing token column and
  enforce the restricted tool, kind, operation and field lists. Existing token records require
  no migration. `DescribeAction` presents the restricted contract and the prompt registry omits
  scenarios for this scope.
- `UpdateAction` checks the draft link and resource state before starting a write and again
  after acquiring the resource lock. `DraftStore` selects open drafts by token ID for the
  restricted listing. Manager review uses the existing service and workflow.
- The token screen labels and both guides describe the scope. Generic resource reads retain
  the usual visibility. The executor found no deviations from the brief.
- Offline checks passed on PHP 7.4.33 in WSL. `npm run check` validated 31 JavaScript and
  194 PHP files. `npm test` passed 5511 PHP checks and 41 Node tests, including agreement
  between legacy v2 and direct MCP HTTP. These suites use the MODX stand-in. The architect's
  separate disposable MODX check covered manager issuance, editing, publication refusal and
  approval, with the scope selector verified in Chrome.

## Review

- Verified with native MODX 2.8.8 and PHP 7.4 on an existing alpha3 token table. Manager issuance
  and authentication worked without a schema change. The draft owner could edit text and list
  its draft. A second token, a generic update of a live or ordinary unpublished resource, and
  attempts to publish, apply or add markup were refused. Manager approval applied the changes
  and removed the draft. Chrome confirmed the new choice in the token dialog.
- Deployed all 14 files to fordev and verified each checksum. The existing admin connection and
  draft approval operation remained available. The updated guide was served and the MODX cache
  was refreshed. Draft-only tokens were issued and exercised on the disposable MODX instance.
