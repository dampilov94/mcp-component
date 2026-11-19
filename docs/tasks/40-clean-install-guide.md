# 40 — Replace 1.x with a fresh 2.0 installation

Based on: task/39-properties-history-prompts at `a60bbc1`.

The owner plans to uninstall 1.x before installing 2.0. `README.md` now documents that path,
including backing up settings and custom files, removing the package, and configuring a new token.
Both packages retain the `modxmcp` namespace and install in sequence on the same site.

## Report

- Verified with real MODX 2.8.8, PHP 7.4 and MariaDB in an isolated WSL fixture. The native
  installer removed `1.10.0-pl` and its package record, then installed `2.0.0-alpha2`.
- Removal deleted the previous token, settings, audit log and a custom file placed inside the
  component. It preserved the MODX content fixture and a file in `core/elements/`.
- The new package registered the manager menus, used the default settings, accepted a newly
  created token and rejected the old one. A command through the real kernel recorded a change
  that could be undone.
- This checks the released `1.10.0-pl` archive and the previously built `alpha2` transport.
  It does not extend the result to other 1.x archives or to the pending changes in tasks 37–39.
  Runtime code is unchanged by this documentation task. The preceding branch passed
  `npm run check` and `npm test` (5447 PHP checks and 39 Node tests).
