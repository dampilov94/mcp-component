# 46 — ClientConfig values

Branch `task/46-clientconfig` from local `main` after task 45. Read native ClientConfig 2.5.0
from the official transport in `.local/upgrade-check/clientconfig-2.5.0-pl.transport.zip`.
The transport is available from [MODX Extras](https://extras.modx.com/package/clientconfig).
Inspect `cgSetting`, `cgContextValue`, `ClientConfig::getSettings`, and the manager processors
`mgr/settings/update` and `mgr/settings/save`. Read the project setting kinds, `Writer`,
`Snapshot` and the optional integration registry. Keep the package version.

## Scope

- Register the optional `clientconfig` capability and a `clientconfig_setting` kind for
  existing settings. Use the native `cgSetting` ID and lookup by exact ClientConfig key.
  List and get show global value, metadata (key, label, xtype, group, required flag and static
  options) and the stored context overrides. Reads and writes require `admin`. Preserve the
  existing group switch and `draft_only` rules. `list` need not include all context overrides.
- Global updates run the native `mgr/settings/update` processor with existing metadata and
  checkbox flags. Support updates of existing text, textarea, number and checkbox values.
  Leave other xtypes readable and keep their writes in ClientConfig's own interface. Preserve
  required text behavior and normalize checkbox input following the native save path. Store
  native string values. Refuse setting and group creation, cloning, removal and metadata updates.
- Support changes to explicitly named context overrides through a writable `context_values`
  map on the setting using native `mgr/settings/save`. It reads `$_POST['values']` directly,
  so make its call within the writer with a locally prepared payload and restore the original
  superglobal in `finally`. Support a real existing non-manager context when context mode is
  enabled. An omitted key is left alone. Allow an explicit empty value for a non-required
  setting. No operation to remove the override is needed. Make the `cgContextValue` rows a
  snapshot relation, validate all targeted contexts and values before writing, and verify the
  expected value after each native save so failures roll back. Document conflicts and undo.
- Block writes to MCP keys, credentials and keys that collide with MODX system/context settings.
  Mask named or password-typed secret values in the result, including context values and history
  snapshots shown through the public API. Preserve other settings when updating or undoing.
  ClientConfig's native write processors invalidate its settings cache and emit its event.
  Undo and rollback must invalidate that cache as well and respect changed class/schema or
  unavailable integration and newly protected keys. Follow existing raw SQL restrictions for
  settings tables and avoid exporting sensitive data in tests or logs.
- Add en/ru capability labels and help. Preserve existing token scope contracts, APIs, package
  metadata and the draft-only workflow.

## Verification

Run `npm run check` and `npm test` on PHP 7.4. Test installed/missing integration, visibility,
native processor input, typed value changes, mixed global/context revisions, protected keys,
dry runs, unchanged definitions and other overrides, context mode off, invalid context/code,
and rollback and undo. Architect will exercise native ClientConfig 2.5.0 with MODX 2.8.8 on a
disposable local installation. fordev has no ClientConfig and verifies the optional absence path.

## Report

Based on `main` at `9b5ed27`. Implementation `8937458`.

- `ClientConfig` registers the optional model and calls the vendor processors. The new
  admin-only kind uses the native numeric ID and resolves `name` as the exact key. It
  preserves the definition and uses one snapshot for each global value and its context rows.
- `UpdateAction` prepares the entire change before writing and rechecks it under the writer
  lock. The kind scopes the native `$_POST` envelope to each context call and verifies each
  saved value. A stored empty string or `'0'` can fall back to the global value in ClientConfig
  2.5.0's effective settings, as documented in both guides.
- Protected keys, dynamic option expressions, credential values and their history are gated
  or masked. Restricted ClientConfig changesets are filtered before journal limits. Mixed
  changesets retain their visible entries. Undo checks the current definition and protection
  rules and invalidates the ClientConfig cache on restoration.
- The architect verified native ClientConfig 2.5.0 on disposable MODX 2.8.8, including typed
  global and context saves, exact lookup, context fallback, the reserved context key, a cache
  refresh and undo. The offline fixture exercises the native payload shapes, failure checks,
  rollback and permissions. Run the usual absence smoke on fordev during review.
- `npm run check` and `npm test` passed in WSL on PHP 7.4.33. All 5685 PHP checks and 41 Node
  tests passed with no failures or skips.

## Review

- Verified the committed runtime on MODX 2.8.8 with ClientConfig 2.5.0. The vendor service
  returned updated settings from its cache and the original values after undo. The test
  confirmed exact lookup, masked credentials, administrator-only history, preservation of
  metadata and other contexts, all-or-nothing validation and the native fallback for zero.
- Verified all 16 deployed files on fordev. Its existing resource tools worked, help included
  the new kind, and the ClientConfig kind reported unavailable because the add-on is absent.
