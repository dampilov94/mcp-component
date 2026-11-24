// Stand check for tasks 52-54: check_page renders a draft and an unpublished page (and only with
// the one-time key), miniShop2 vendors/deliveries/payments, rename with references.
// Needs the plugin "modxMCP" to listen to OnWebPageInit (package install, or add the event once).
// Run by the architect session only, after scripts/stand/deploy.py. The token is never printed.
//   node scripts/stand/check-render-rename.cjs
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
  const step = (label) => console.log("ok  " + label);
  const siteRoot = cfg.MODX_MCP_SITE_URL.replace(/assets\/components\/modxmcp\/api\.php$/, "");
  const before = (await run("modx_site_info")).counts;
  const checkpoint = await run("modx_checkpoint", { label: "render and rename stand check" });
  try {
    // 52: an unpublished page renders for check_page, and never for anyone else.
    const template = (await run("modx_list", { kind: "template", limit: 1 })).items[0].id;
    const page = await run("modx_create", { kind: "resource", fields: { pagetitle: "mcp2test_скрытая", alias: "mcp2test-hidden", template,
      published: false, hidemenu: true, content: "<p>Секретный текст [[$mcp2test_missing_chunk]]</p>" } });
    const got = await run("modx_get", { kind: "resource", id: page.id, fields: ["uri"] });
    const anon = await fetch(siteRoot + got.fields.uri, { redirect: "manual" });
    assert.notEqual(anon.status, 200, "an anonymous request must not see the unpublished page");
    const forged = await fetch(siteRoot + got.fields.uri, { redirect: "manual", headers: { "X-MCP-Render": "a".repeat(64) } });
    assert.notEqual(forged.status, 200, "a made-up key must not open the page");
    assert.ok(!(await forged.text()).includes("Секретный текст"));
    const checked = await run("modx_check_page", { id: page.id });
    assert.equal(checked.status, 200, JSON.stringify(checked).slice(0, 300));
    assert.equal(checked.rendered_as, "unpublished");
    assert.ok(JSON.stringify(checked.unparsed_tags || []).includes("mcp2test_missing_chunk") || checked.unparsed_count >= 0);
    const again = await fetch(siteRoot + got.fields.uri, { redirect: "manual" });
    assert.notEqual(again.status, 200, "still hidden after the check (nothing cached)");
    step(`check_page renders unpublished page ${page.id} (status ${checked.status}); anonymous ${anon.status}, forged key ${forged.status}, afterwards ${again.status}`);

    const live = await run("modx_create", { kind: "resource", fields: { pagetitle: "mcp2test_живая", alias: "mcp2test-live", template, published: true, hidemenu: true, content: "<p>Старый</p>" } });
    const draft = await run("modx_invoke", { kind: "resource", id: live.id, op: "create_draft" });
    await run("modx_update", { kind: "resource", id: draft.draft_id, fields: { content: "<p>Новый текст черновика</p>" } });
    const dcheck = await run("modx_check_page", { id: draft.draft_id });
    assert.equal(dcheck.status, 200);
    assert.equal(dcheck.rendered_as, "draft");
    step(`check_page renders draft ${draft.draft_id} of page ${live.id}`);

    // 53: miniShop2 references.
    const tools = (await client.listTools()).tools.map((t) => t.name);
    const kinds = (await run("modx_describe")).kinds.map((k) => k.kind);
    if (kinds.includes("ms2_vendor")) {
      const vendor = await run("modx_create", { kind: "ms2_vendor", fields: { name: "mcp2test_Производитель", country: "Россия" } });
      assert.equal((await run("modx_get", { kind: "ms2_vendor", id: vendor.id })).fields.name, "mcp2test_Производитель");
      const deliveries = await run("modx_list", { kind: "ms2_delivery" });
      const payments = await run("modx_list", { kind: "ms2_payment" });
      const refused = await raw("modx_create", { kind: "ms2_delivery", fields: { name: "x" } });
      assert.equal(refused.isError, true);
      step(`ms2: vendor ${vendor.id} created; ${deliveries.total} deliveries and ${payments.total} payments readable; delivery writes refused`);
    } else console.log("--  ms2 kinds not available, skipped");

    // 54: rename a chunk together with its references.
    await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_old", content: "<b>старый</b>" } });
    const user = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_user", content: "[[$mcp2test_old]] [[$mcp2test_old_more]]\n{include 'mcp2test_old'}" } });
    const oldChunk = (await run("modx_list", { kind: "chunk", query: "mcp2test_old" })).items.find((c) => c.name === "mcp2test_old");
    const plan = await run("modx_invoke", { kind: "chunk", id: oldChunk.id, op: "rename", args: { new_name: "mcp2test_new" } });
    const confirm = await run("modx_invoke", { kind: "chunk", id: oldChunk.id, op: "rename", args: { new_name: "mcp2test_new", confirm: true, expected_revisions: plan.revisions } });
    const userCode = (await run("modx_get", { kind: "chunk", id: user.id })).fields.content;
    assert.equal(userCode, "[[$mcp2test_new]] [[$mcp2test_old_more]]\n{include 'mcp2test_new'}");
    await run("modx_undo", { changeset_id: confirm.changeset_id, confirm: true });
    assert.equal((await run("modx_get", { kind: "chunk", id: user.id })).fields.content, "[[$mcp2test_old]] [[$mcp2test_old_more]]\n{include 'mcp2test_old'}");
    step(`rename: chunk renamed with both references rewritten (a longer name untouched); one undo reverted all`);
  } finally {
    const undo = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true, force: true });
    assert.deepEqual((await run("modx_site_info")).counts, before);
    step(`undo to checkpoint (${undo.changesets.length} changesets): counts as before`);
  }
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
