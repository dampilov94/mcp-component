# 33 — Ready-made scenarios (MCP prompts)

Branch: `task/33-prompts` (already created by the architect from `main`; work on it).
Spec: section 11 ("Сценарии (prompts)") and 4 (manifest). Read first: `client/index.js` (how the
manifest becomes tools, how the built-in guide becomes resources), `client/api.js`,
`src/Action/ManifestAction.php`, `src/Manifest.php`, `src/Action/HelpAction.php`,
`core/components/modxmcp/docs/getting_started.md`, and the MCP prompt types in
`node_modules/@modelcontextprotocol/sdk/dist/cjs/types.js` (`ListPromptsRequestSchema`,
`GetPromptRequestSchema`, `PromptSchema`, `GetPromptResultSchema`).

## Goal

The person picks a scenario from the client's menu (in Claude Code: `/` commands of the MCP
server) instead of explaining the workflow every time. Each scenario is a prepared instruction
for the model that uses the tools in the safe order this component was built for.

## Design (decided — do not change)

### Where prompts are defined

Like tools, prompts are defined on the site, never in the client. `src/Prompt/Prompt.php`
(abstract: `name()`, `title()`, `description()`, `arguments()` — list of
`{name, description, required}`, `requiredScope()` and optional `requiredTools()` (tool names;
the prompt is hidden when any is unavailable to the token), and `messages(array $args, Context
$context)` returning MCP messages `[{role: "user", content: {type: "text", text}}]`), one class per
prompt in `src/Prompt/`, listed in `src/Prompt/Registry.php`.

- The manifest (`ManifestAction`) gains `prompts`: name, title, description, arguments — only the
  prompts this token may use. The manifest hash covers them.
- A protocol action `prompt` (not exposed as a tool, like `manifest`): input `{name, arguments}`
  (arguments: object of strings), validates required arguments and the prompt's availability,
  returns `{description, messages}`. Unknown name → `not_found`; unavailable → `unavailable` with
  the reason.
- The client: `prompts: { listChanged: true }` capability; `ListPrompts` returns the manifest's
  prompts (names prefixed `modx_` like tools? — no: prompts get plain names, e.g. `study_site`,
  because the client shows them as `/mcp__modx__study_site`); `GetPrompt` calls the `prompt` action.
  A manifest hash change also sends `prompts/list_changed`.

### The prompts (texts in Russian; tool names in backticks; concise and imperative)

1. `study_site` — «Изучить сайт»: orient before any work: `modx_site_info`, `modx_capabilities`,
   `modx_help {topic: getting_started}`, `modx_resource_tree` (top levels), templates and their
   TVs, `modx_dependency_graph {format: summary}`, `modx_audit_site` summary; answer with a short
   map of the site (contexts, page types, key chunks/snippets, add-ons, problems found) and change
   nothing. No arguments. Scope read.
2. `audit` — «Провести аудит»: `modx_audit_site`; group findings by severity and by object;
   for each error explain the cause in one line, using `modx_find_usages` / `modx_view_code` where
   needed; propose fixes as a numbered list; do not change anything until the person picks items;
   then for each chosen fix: checkpoint, fix, `modx_check_page` of affected pages. Optional
   argument `focus` (free text, e.g. "только битые ссылки"). Scope read (fixing needs more; say so).
3. `change_page` — «Изменить страницу через черновик»: arguments `page` (id or title, required)
   and `change` (what to change, required). Find the resource; `create_draft`; make the change in
   the draft only (`modx_edit_code` for content, `modx_update` for fields/TVs); give the preview
   link and a summary of the plan (`apply_draft` without confirm); apply only after the person
   says so; if the page changed meanwhile, show the difference and ask. Scope content. Requires
   `modx_invoke`.
4. `new_page_type` — «Новый тип страницы»: argument `description` (required). Plan first
   (template, TVs with input types via the TV operations, chunks), list what will be created and
   ask; then `modx_checkpoint`, create with `dry_run` first where available, create, attach TVs,
   check with `modx_check_page` on a test page created unpublished, and tell how to undo
   (`to_checkpoint`). Scope develop.
5. `fix_errors` — «Разобрать ошибки сайта»: `modx_read_log` (recent errors), group by message
   and location, find the element or file causing each (`modx_search`, `modx_find_usages`,
   `modx_view_code`), explain, propose fixes; change only after confirmation, each fix in its own
   changeset; re-check the log afterwards. Optional argument `since` (e.g. "сегодня"). Scope read.

Every prompt text ends with the same short block of rules (shared constant): read before
writing and pass `expected_revision`; `dry_run` before a risky change; checkpoint before a series;
never `force` without the person's explicit consent; report every changeset id; everything is
undoable with `modx_undo`.

Argument values are inserted into the text as quoted data with a note that they are the
person's words, not instructions to follow blindly; cap each at 2000 characters
(`validation_failed` above).

### Help

`docs/index.md` and `getting_started.md`: a short list of the scenarios and how to call them in
Claude Code (`/mcp__modx__<name>`).

## Tests (required)

PHP (`tests/php/run.php`, block "Prompts"): registry; manifest lists exactly the prompts allowed
for read / content / develop tokens and when a required tool is unavailable (e.g. `modx_invoke`
hidden by a disabled group, or the token's denied group); the manifest hash changes when a prompt
becomes unavailable; `prompt` action: each prompt renders, contains the shared rules block, inserts
arguments quoted, refuses missing required / over-long arguments, unknown name, unavailable prompt.
Node (`tests/client.test.js` with the mock site and `tests/endpoint.test.js` through the real
kernel): `listPrompts` and `getPrompt` through the bridge, `prompts/list_changed` on a manifest
hash change, errors surface as MCP errors. Update exact manifest assertions where the manifest's
key set is asserted.

## Acceptance

`npm run check`, `npm test`. Report as usual, with the full Russian text of one prompt
(`change_page`) in the report so the architect can read it as the model will.
