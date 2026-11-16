# 32 — Fenom (pdoTools): references in the graph and audit, syntax check before saving

Branch: `task/32-fenom` (create it from `task/31-drafts`, stacked). Spec: section 15 item 8
("проверка Fenom"). Read first: `src/Graph/GraphBuilder.php` (its tag parser, how references,
missing elements and orphans are found, `linkTargets()`), `src/Verify/SiteAudit.php`,
`src/Kind/Objects.php::lint()` and `src/Lint/PhpLint.php`, and the pdoTools source copied from
the stand into `.local/reference/pdotools-2.13.3/` (read-only): `model/pdotools/pdotools.class.php`
(`getFenom()`, `fenom()` — the exact rule deciding whether a text is Fenom at all, and how
`pdotools_fenom_*` settings change it), `model/pdotools/_fenom.php` / the `Fenom` classes, and
`model/pdotools/pdoparser.class.php`.

## Why

Sites built with pdoTools write chunks and templates in Fenom: `{include 'chunk'}`,
`{$_modx->runSnippet('pdoMenu', [...])}`, `{'chunk' | chunk}`. The graph only knows MODX tags,
so on the stand the audit reports chunks that are in fact used (`msProduct.content.fenom`,
`tpl.msEmail`, …) as unused, and a Fenom syntax error is only found when a visitor opens the page.

## Design (decided — do not change)

### References (graph, find_usages, audit)

Extend the graph's reference extraction (one place, used by every consumer) with Fenom. Only
texts that pdoTools would treat as Fenom are parsed this way — reuse pdoTools' own rule from its
source (reproduce it in a small pure function `Fenom::looksLike($text)` in `src/Code/Fenom.php`
with a comment pointing at the pdoTools method it mirrors); MODX `[[…]]` tags keep being parsed
as today in every text.

Recognise, with single or double quotes and optional whitespace and modifiers:

| Fenom | meaning for the graph |
|---|---|
| `{include 'name'}`, `{include 'chunk:name'}`, `{insert 'name'}`, `{extends 'name'}` | uses chunk `name` |
| `{include 'template:Name'}`, `{extends 'template:Name'}` | uses template `Name` (by name) |
| `{include 'file:…'}`, `@FILE …` | a file, not an element: ignored |
| `{'name' \| chunk}`, `{'name' \| chunk : [...]}` | uses chunk `name` |
| `{$_modx->getChunk('name' …)}`, `{$_modx->parseChunk('name' …)}` | uses chunk `name` |
| `{$_modx->runSnippet('name' …)}` (also `'!name'`), `{'name' \| snippet}` | calls snippet `name` |
| `'tpl' => 'name'`, `'tplOuter' => 'name'`, … inside those calls' arrays | uses chunk `name`, with the same key rule the graph already applies to `&tpl=` parameters (and `@INLINE …` is not a chunk) |
| `{$_modx->makeUrl(5)}`, `{5 \| url}` | a link to resource 5 (`linkTargets()`, so `broken_link` sees it) |

A name built at run time (a variable, concatenation) is not a reference; do not guess. Names are
matched exactly as the graph matches MODX tag names today, Unicode-safe. While there, make the
graph's existing tag-name pattern accept non-ASCII names (it is ASCII-only today, so Cyrillic
element names are never seen as referenced — noted in brief 28); keep the change minimal and
covered by tests.

`missing_element` must report a Fenom call of a chunk or snippet that does not exist, like a
MODX tag. The graph's `legend`/notes and the audit's notes mention that Fenom is understood.

### Syntax check before saving

When a chunk or template is saved (`create`, `update`, `edit_code`, `replace`, `duplicate` is a
copy and needs none) and the new code is Fenom by pdoTools' rule, compile it with the site's own
Fenom (`$modx->getService('pdoTools')` → `getFenom()` → compile without executing; read in the
source how pdoTools itself compiles a chunk and do the same, without rendering or caching it). A
compile error refuses the save with `lint_failed`, message and line as for PHP; nothing written.
`skip_lint` and the setting `modxmcp.lint_php` work the same way (rename nothing; add the setting
description in both lexicons that it covers Fenom too). When pdoTools is not installed or Fenom is
switched off for chunks (`pdotools_fenom_default`), there is no check and no error. Never let an
exception of Fenom itself (not a syntax error) break the save: log it with `[modxmcp]` and save.

The audit gets a check `fenom_syntax` (error): chunks and templates whose Fenom does not compile
(same compile, read-only), skipped with a note when pdoTools/Fenom is unavailable.

### Tests (required)

Pure tests of `Fenom::looksLike` and of the extraction on texts covering every row of the table,
quotes, modifiers, whitespace, runtime names (not references), `file:`/`@FILE`/`@INLINE`, Cyrillic
names, mixed MODX and Fenom tags in one text. On the fake site: graph and `find_usages` see Fenom
references; audit `unused_element` no longer reports a chunk used only through Fenom, and
`missing_element` reports a missing one; `broken_link` sees `makeUrl`/`url`. The compile check
with a fake `pdoTools` service whose `getFenom()` returns a test double that throws a real-looking
compile error for a marker string: refused with line; `skip_lint`; Fenom off; pdoTools absent;
the double throwing a non-syntax exception does not block the save. Update exact
check/tool lists where the audit's check list is asserted.

## Acceptance

`npm run check`, `npm test`. Report as usual, quoting the pdoTools rule you mirrored (file and
method) and the compile call you used; list what needs the stand (the real Fenom compiler, the
stand's chunks the audit should stop reporting).
