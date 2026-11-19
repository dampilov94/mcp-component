# 41 — Token labels and credential reads

Branch `task/41-token-and-credential-guards`, stacked on task 40 at `8b874ad`.

- Validate token labels consistently on create and update and preserve all 190 allowed UTF-8
  characters in storage. Keep the manager's existing required-label behaviour.
- Use the same property credential recognition for media source reads and history diffs.
  Hide recognised credentials from scopes below `admin`, as with settings. Keep the existing
  admin read and write access, and keep history diffs redacted for every scope.
- Refuse raw `user_attributes` queries for every token because this table can contain session
  identifiers. Keep user profiles accessible through the existing user kind and its masking.
- Add offline checks for long Unicode labels, source properties in get/list and admin SQL
  refusals. The existing table schema, tool schemas and journal snapshots stay compatible.

## Report

Based on: task/40-clean-install-guide at `8b874ad`.

- `TokenStore` validates UTF-8 and the character limit in both write paths. It stores the
  validated label untouched. `TokenService` still trims the label and requires a non-empty value.
- Media source `get` and explicitly selected list fields use the history credential mask for
  tokens below `admin`. Admin reads keep their previous scope, source writes retain stored
  values, and history stays masked regardless of scope. Masking recognises names and password
  types and does not interpret arbitrary application data.
- `QueryDbAction` puts `user_attributes` alongside the tables denied at all scopes, including
  a custom table prefix. The ordinary user tool and the SQL rules for other tables are retained.
- On a real MODX stand, verify that the manager can create a token with a long Cyrillic label
  and that an installed media source describes its credential fields as expected. Offline tests
  use fixture properties and SQLite storage, not credentials or tables from a live site.
  `npm run check` and `npm test` passed in WSL with PHP 7.4.33 (5459 PHP checks and 39 Node
  tests, no skips).
