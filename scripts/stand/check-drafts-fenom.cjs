// Stand check: resource drafts (task 31) and Fenom (task 32).
// Run by the architect session only, after scripts/stand/deploy.py. The token is never printed.
//   node scripts/stand/check-drafts-fenom.cjs
// Everything created is removed again by an undo to a checkpoint; the site's counts must match at the end.
const path = require("node:path");
const assert = require("node:assert/strict");
const repo = path.resolve(__dirname, "..", "..");
const { Client } = require(path.join(repo, "node_modules/@modelcontextprotocol/sdk/dist/cjs/client/index.js"));
const { StdioClientTransport } = require(path.join(repo, "node_modules/@modelcontextprotocol/sdk/dist/cjs/client/stdio.js"));
const cfg = require(path.join(repo, ".mcp.json")).mcpServers.modx.env;
(async () => {
  const transport = new StdioClientTransport({ command: process.execPath, args: [path.join(repo, "client", "index.js")],
    env: { ...process.env, MODX_MCP_SITE_URL: cfg.MODX_MCP_SITE_URL, MODX_MCP_TOKEN: cfg.MODX_MCP_TOKEN }, stderr: "ignore" });
  const client = new Client({ name: "stand-check", version: "0" }, { capabilities: {} });
  await client.connect(transport);
  const raw = (name, args = {}) => client.callTool({ name, arguments: args });
  const run = async (name, args = {}) => {
    const result = await raw(name, args);
    if (result.isError) throw new Error(`${name} ${JSON.stringify(args)} -> ${result.content[0].text}`);
    return result.structuredContent;
  };
  const fails = async (name, args, pattern) => {
    const result = await raw(name, args);
    assert.equal(result.isError, true, `${name} ${JSON.stringify(args)} should fail`);
    assert.match(result.content[0].text, pattern);
    return result.content[0].text;
  };
  const step = (label) => console.log("ok  " + label);
  const invoke = (op, args = {}) => run("modx_invoke", { kind: "resource", op, ...args });
  const before = (await run("modx_site_info")).counts;
  const checkpoint = await run("modx_checkpoint", { label: "drafts and fenom stand check" });
  try {
    await drafts();
    await fenom();
  } finally {
    const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true, force: true });
    assert.deepEqual((await run("modx_site_info")).counts, before);
    assert.equal((await invoke("list_drafts")).drafts.filter((d) => /mcp2test/.test(d.original_title)).length, 0);
    step(`undo to checkpoint (${back.changesets.length} changesets): counts as before, no test drafts left`);
  }
  await client.close();
  console.log("ALL OK");

  async function drafts() {
    const template = (await run("modx_list", { kind: "template", limit: 1 })).items[0].id;
    const page = await run("modx_create", { kind: "resource", fields: { pagetitle: "mcp2test_черновик", alias: "mcp2test-draft-page", template, published: true, hidemenu: true, content: "<p>Живая страница</p>\n<p>вторая строка</p>" } });
    const live = await run("modx_get", { kind: "resource", id: page.id });

    const made = await invoke("create_draft", { id: page.id });
    const draft = await run("modx_get", { kind: "resource", id: made.draft_id });
    assert.equal(draft.fields.published, false);
    assert.equal(draft.fields.hidemenu, true);
    assert.equal(draft.fields.alias, `mcp2test-draft-page-draft-${made.draft_id}`);
    assert.equal(draft.fields.content, live.fields.content);
    assert.equal(draft.draft_of, page.id);
    assert.equal((await run("modx_get", { kind: "resource", id: page.id })).draft_id, made.draft_id);
    assert.match(made.preview_url, new RegExp(`mcp2test-draft-page-draft-${made.draft_id}`));
    const anon = await fetch(made.preview_url, { redirect: "manual" });
    assert.notEqual(anon.status, 200, "an unpublished draft must not be public");
    step(`create_draft: draft ${made.draft_id} unpublished, hidden, alias ok; anonymous preview gets ${anon.status}; ${made.preview_url}`);

    await fails("modx_invoke", { kind: "resource", op: "create_draft", id: page.id }, /validation_failed/);
    await fails("modx_delete", { kind: "resource", id: page.id }, /draft/);
    await fails("modx_delete", { kind: "resource", id: made.draft_id }, /draft/);
    step("second draft, deleting the page and deleting the draft are refused");

    await run("modx_edit_code", { kind: "resource", id: made.draft_id, edits: [{ start_line: 1, replacement: "<p>Новая версия</p>", expect: "<p>Живая страница</p>" }] });
    await run("modx_update", { kind: "resource", id: made.draft_id, fields: { longtitle: "Длинный заголовок черновика" } });
    assert.equal((await run("modx_get", { kind: "resource", id: page.id })).revision, live.revision, "the live page is untouched");
    const plan = await invoke("apply_draft", { id: page.id });
    assert.equal(plan.performed, false);
    assert.match(plan.changes.content.diff, /-<p>Живая страница<\/p>\n\+<p>Новая версия<\/p>/);
    assert.equal(plan.changes.longtitle.new, "Длинный заголовок черновика");
    assert.equal(plan.original_changed, false);
    step(`apply_draft plan: ${Object.keys(plan.changes).join(", ")}; the live page is untouched`);

    const deletedBefore = (await run("modx_site_info")).counts.resources_deleted;
    const applied = await invoke("apply_draft", { id: page.id, args: { confirm: true } });
    const after = await run("modx_get", { kind: "resource", id: page.id });
    assert.equal(after.fields.content, "<p>Новая версия</p>\n<p>вторая строка</p>");
    assert.equal(after.fields.alias, "mcp2test-draft-page");
    assert.equal(after.fields.published, true);
    await fails("modx_get", { kind: "resource", id: made.draft_id }, /not_found/);
    assert.equal((await run("modx_site_info")).counts.resources_deleted, deletedBefore, "the draft does not go to the trash");
    const pageHtml = await (await fetch(cfg.MODX_MCP_SITE_URL.replace(/assets\/components\/modxmcp\/api\.php$/, "") + after.fields.uri)).text();
    assert.match(pageHtml, /Новая версия/);
    step(`apply_draft: the live page shows the new content (changeset ${applied.changeset_id}); draft removed, not in the trash`);

    await run("modx_undo", { changeset_id: applied.changeset_id, confirm: true });
    assert.equal((await run("modx_get", { kind: "resource", id: page.id })).fields.content, live.fields.content);
    const back = await run("modx_get", { kind: "resource", id: made.draft_id });
    assert.equal(back.draft_of, page.id);
    assert.ok((await invoke("list_drafts")).drafts.some((d) => d.draft_id === made.draft_id));
    step("undo of the apply: old page back, the draft back with its link");

    await run("modx_update", { kind: "resource", id: page.id, fields: { description: "правка оригинала" } });
    const conflict = await invoke("apply_draft", { id: made.draft_id });
    assert.equal(conflict.original_changed, true);
    await fails("modx_invoke", { kind: "resource", op: "apply_draft", id: page.id, args: { confirm: true } }, /revision_conflict/);
    step(`a page changed after the draft: plan warns (${conflict.original_changed_fields.join(", ")}), confirm refused without force`);

    const discarded = await invoke("discard_draft", { id: page.id, args: { confirm: true } });
    await fails("modx_get", { kind: "resource", id: made.draft_id }, /not_found/);
    assert.equal((await run("modx_get", { kind: "resource", id: page.id })).draft_id, undefined);
    step(`discard_draft (changeset ${discarded.changeset_id}): draft gone, page unchanged`);
  }

  async function fenom() {
    const ok = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_fenom_ok", content: "{if $title}<h1>{$title}</h1>{/if}\n{include 'tpl.base.footer'}" } });
    const text = await fails("modx_create", { kind: "chunk", fields: { name: "mcp2test_fenom_bad", content: "<div>\n{if $title}<h1>{$title}</h1>\n</div>" } }, /lint_failed/);
    step(`Fenom: a valid chunk is saved (#${ok.id}); a broken one is refused: ${text.slice(0, 160)}`);
    await fails("modx_edit_code", { kind: "chunk", id: ok.id, edits: [{ start_line: 1, replacement: "{foreach $rows as $row}" }] }, /lint_failed/);
    const js = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_fenom_js", content: "<script>\nfunction a() { return 1; }\nvar o = { a: 1 };\n</script>" } });
    step(`edit that breaks Fenom refused; a chunk with plain JavaScript is saved (#${js.id})`);

    const usages = await run("modx_find_usages", { kind: "chunk", name: "tpl.base.footer" });
    assert.ok(JSON.stringify(usages).includes("mcp2test_fenom_ok"), JSON.stringify(usages).slice(0, 400));
    step("find_usages sees the Fenom {include}");

    const audit = await run("modx_audit_site", { checks: ["unused_element", "missing_element", "fenom_syntax"], limit: 200 });
    const unused = audit.findings.filter((f) => f.check === "unused_element").map((f) => f.name);
    const missing = audit.findings.filter((f) => f.check === "missing_element").map((f) => `${f.name} -> ${f.details.missing}`);
    const broken = audit.findings.filter((f) => f.check === "fenom_syntax").map((f) => `${f.kind} ${f.name}: ${f.message}`);
    step(`audit: unused ${audit.summary.unused_element.count} (was 43 before Fenom), missing ${audit.summary.missing_element.count}, fenom_syntax ${audit.summary.fenom_syntax.count}`);
    console.log("    still unused:", unused.join(", "));
    console.log("    missing:", missing.join("; "));
    if (broken.length) console.log("    fenom_syntax:", broken.join("; "));
  }
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
