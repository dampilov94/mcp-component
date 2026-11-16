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

## Report

Based on: task/31-drafts at a0041b6

Checks: `npm run check` and `npm test` pass. PHP checks 5012 before (the stack with brief 31), 5095 after (83 new, block "Fenom (pdoTools): references, syntax check" in `tests/php/run.php`); Node tests 32 before and after.

### What I read in pdoTools 2.13.3 (`.local/reference/pdotools-2.13.3/`)

- The rule that decides whether a text is Fenom: `pdoTools::fenom()` in `model/pdotools/pdotools.class.php`. The text is trimmed and Fenom runs only when `$this->config['useFenom']` (`pdotools_fenom_default`, default true, set in `setConfig()`) is on and `preg_match($this->config['fenomSyntax'], $content)` matches; `fenomSyntax` is the setting `pdotools_fenom_syntax` (empty means the default `#\{(\$|\/|\w+(\s|\(|\|)|\(|\')#`). Mirrored in `src/Code/Fenom.php`: `Fenom::looksLike($text, $pattern, $enabled)`, `Fenom::SYNTAX`, `Fenom::syntaxOf()`, with a comment naming the method. The rule is deliberately loose: `function a() {return 1;}` matches it (tested, mirrored on purpose).
- The compile call: `pdoTools::_compileChunk()` does `$fenom->getRawTemplate()->source($name, $content, true)`, but it catches the exception, logs it and returns an empty template, so it cannot be used to find an error. `Fenom\Error\CompileException` (extends `ErrorException`, `getLine()` is the template line) is what `source()` throws for a syntax error, an unknown tag or modifier. `src/Code/FenomCheck.php` makes the same `source()` call itself, on the Fenom from `$modx->getService('pdoTools')->getFenom()` (`FenomX`, or false). Nothing is rendered or cached.
- Also read: `pdoparser.class.php` (whole pages go through Fenom only with `pdotools_fenom_parser`), `_fenom.php` (the modifiers `chunk`, `snippet`, `url`, the providers `chunk:` / `template:` / `file:`), Fenom's `Template::source()` and `Compiler` (`force_include` includes a chunk at compile time).

### Implemented

- `src/Code/Fenom.php` (new, pure): `looksLike`, `syntaxOf`, `installed` (pdoTools' model file is under `core/components/pdotools/`, or `pdotools.core_path`), `active` (installed and `pdotools_fenom_default`), `references($text)`: every row of the brief's table, plus `{insert}` / `{extends}`, `parseChunk`, `'!name' | snippet`, comments and `{ignore}` skipped, a literal joined to something with `~` or `.` or a name with `$ { } \`` is a run-time name and not a reference, `file:` / `@FILE` / `@INLINE` ignored, unknown provider prefixes ignored.
- `src/Code/FenomCheck.php` (new): `available()`, `check($text, $name)` returning `{message, line}` for a `CompileException`; any other exception is logged (`[modxmcp] …`) and the text passes.
- `src/Graph/GraphBuilder.php`: one place (`withFenom()`) adds Fenom references for chunks and templates (and `linkTargets()` for the links, so `broken_link` sees `makeUrl` / `| url`); a Fenom call replaces the same call found as plain PHP (one edge); `missing_element` reports a missing chunk or snippet called through Fenom (`via` `fenom`), not the `'tpl' => 'x'` arrays (`property`, heuristic like `&tpl=`); legend and orphan note mention Fenom. The tag-name pattern is Unicode-aware (`\p{L}\p{N}\p{M}`, ASCII fallback on invalid UTF-8).
- `src/Code/Usages.php` (`modx_find_usages`): the Fenom patterns now cover `{include|insert|extends}` with an optional `chunk:`, `parseChunk`, `runSnippet('!name')`, `'tpl' => 'name'` and, for a template, `{include|extends 'template:Name'}`.
- `src/Kind/Objects.php::lint()`: also checks `lint() === 'fenom'` kinds (chunk, template; `ChunkKind` / `TemplateKind` declare it) with `FenomCheck`; `lint_failed` with line and message, `skip_lint` and `modxmcp.lint_php` work the same, no check when pdoTools or Fenom is unavailable. `DuplicateAction` passes `$fenom = false` (a copy needs none). The `skip_lint` argument descriptions say Fenom too.
- `src/Verify/SiteAudit.php`: check `fenom_syntax` (error), appended last in `CHECKS`; chunks and templates (effective content, static too), skipped with a note when pdoTools / Fenom is unavailable; notes of `missing_element` and `unused_element` mention Fenom. `scanElements()` reads Fenom links in chunks and templates only.
- Lexicons: `setting_modxmcp.lint_php_desc` (en, ru) and `modxmcp_cmp_danger_lint_php` (en, ru) say Fenom is covered. Docs: `getting_started.md`, `graph.md`.
- Tests: new `tests/php/FakeFenom.php` (a `Fenom\Error\CompileException` and a pdoTools / Fenom double: `{BROKEN}` throws a real-looking compile error with the line, `{EXPLODE}` a RuntimeException, it records what it compiled); `FakeModx::getService('pdoTools')` returns the double a test registers.

### Existing assertions changed (all in `tests/php/run.php`)

1. `graph: legacy Cyrillic tag-name limitation preserved` (expected 1 missing) is now `graph: a Cyrillic tag name is seen (brief 32 lifted the ASCII-only limitation)` expecting 2: the brief asks to lift exactly that limitation. The comment above it was adjusted.
2. `audit: summary counts every problem exactly once`: the counts list got an eighth entry `0` for the new check (`array(3, 1, 3, 1, 1, 2, 1, 0)`), the audit's exact check list.

### Deviations and decisions

- "pdoTools is installed" is decided by its model file in `core/components/pdotools/model/pdotools/pdotools.class.php` (or under `pdotools.core_path`), not by a system setting, because a setting may be missing while pdoTools defaults to Fenom on.
- Fenom references are read in chunks and templates only (plus Fenom links of resource content for `broken_link`); not in snippets, plugins or TV defaults: PHP code often contains `{$var}`, which pdoTools' rule accepts as Fenom. The setting `pdotools_fenom_parser` (whole pages through Fenom) is not considered: resource content and templates are read when they look like Fenom. Orphan verification against resource content still searches MODX tags only.
- Names with a space are accepted (`{include 'Мой чанк'}`); for `'tpl' => 'x'` the graph's `&tpl=` rule is kept (no space, not `@…`).
- The compile uses the text as stored, not trimmed, so reported lines are those of the stored code (pdoTools trims; the syntax is unaffected).
- `fenom_syntax` is appended at the end of `CHECKS` so that the positions of the other checks do not move.
- `Kind::lint()` now also returns `fenom`; the only other readers (`Kind::fileContent` / `codeFromFile`, `SiteAudit`) test for `php` and are unaffected.
- The graph legend edge kinds gained `fenom`.

### Verified only against the fake (to check on the stand)

- The real Fenom compiler through `getService('pdoTools')->getFenom()` (FenomX): that `getService('pdoTools')` returns the service on the stand (a fallback with the installed path is there), that `getRawTemplate()->source()` throws `Fenom\Error\CompileException` with a usable line for a bad tag and for an unknown modifier, and the message text. First `getFenom()` creates the compile directory under the pdoTools cache path (pdoTools does the same when it renders).
- `force_include` compiles an included chunk at compile time: a syntax error in an included chunk is then raised for the including one; and a chunk that includes a chunk that does not exist raises a non-syntax exception (logged, save goes on). I did not verify what the real provider throws.
- False refusals: pdoTools' own rule accepts text such as inline JavaScript with `{return …}`; Fenom would not compile it either (pdoTools renders such a chunk empty), but check that no existing stand chunk is refused or reported by `fenom_syntax` unjustly.
- The stand's chunks that the audit should stop reporting (`msProduct.content.fenom`, `tpl.msEmail`, …) and that the real `missing_element` result contains no false positive from Fenom texts.

### Open questions

- Should `modx_check_page` or the manager's chunk editor use the same compile (not in scope)?
- Resource content written in Fenom (with `pdotools_fenom_parser` on) does not count towards the orphan verification of chunks; say if it should.
