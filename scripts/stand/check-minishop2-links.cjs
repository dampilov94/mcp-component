// Stand check: miniShop2 link types, product links, orders and order statuses. Run by the architect
// session only, after scripts/stand/deploy.py. Orders and statuses are only read; no order status is
// changed (that sends e-mails). Everything created is named mcp2test* and undone at the end.
//   node scripts/stand/check-minishop2-links.cjs
const path = require("node:path");
const assert = require("node:assert/strict");
const repo = path.resolve(__dirname, "..", "..");
const { Client } = require(path.join(repo, "node_modules/@modelcontextprotocol/sdk/dist/cjs/client/index.js"));
const { StdioClientTransport } = require(path.join(repo, "node_modules/@modelcontextprotocol/sdk/dist/cjs/client/stdio.js"));
const cfg = require(path.join(repo, ".mcp.json")).mcpServers.modx.env;
(async () => {
  const transport = new StdioClientTransport({ command: process.execPath, args: [path.join(repo, "client", "index.js")],
    env: { ...process.env, MODX_MCP_SITE_URL: cfg.MODX_MCP_SITE_URL, MODX_MCP_TOKEN: cfg.MODX_MCP_TOKEN }, stderr: "inherit" });
  const client = new Client({ name: "stand-check", version: "0" }, { capabilities: {} });
  await client.connect(transport);
  const raw = (name, args = {}) => client.callTool({ name, arguments: args });
  const run = async (name, args = {}) => {
    const result = await raw(name, args);
    if (result.isError) throw new Error(`${name} ${JSON.stringify(args)} -> ${result.content[0].text}`);
    return result.structuredContent;
  };
  const step = (label) => console.log("ok  " + label);
  const before = (await run("modx_site_info")).counts;
  const log0 = (await run("modx_read_log", { limit: 1 })).end_offset;
  for (const kind of ["resource", "ms2_link"]) {
    if ((await run("modx_list", { kind, query: "mcp2test", ...(kind === "resource" ? { where: { deleted: false } } : {}) })).total) throw new Error(`leftover mcp2test ${kind}; remove it first`);
  }

  const statuses = await run("modx_list", { kind: "ms2_order_status", limit: 50, fields: ["id", "name", "email_user", "email_manager"] });
  const orders = await run("modx_list", { kind: "ms2_order", limit: 5 });
  const links = await run("modx_list", { kind: "ms2_link", limit: 50 });
  console.log(`    ${statuses.total} statuses (${statuses.items.map((s) => s.name).join(", ")}), ${orders.total} orders, ${links.total} link types`);
  if (orders.total) {
    const order = await run("modx_get", { kind: "ms2_order", id: orders.items[0].id });
    const plan = await run("modx_invoke", { kind: "ms2_order", id: order.id, op: "change_status", args: { status: statuses.items[0].id } });
    assert.equal(plan.performed, false);
    console.log(`    order ${order.fields.num}: status plan ${JSON.stringify(plan).slice(0, 200)}`);
  }
  step("read: order statuses, orders, link types; no status was changed");

  const checkpoint = await run("modx_checkpoint", { label: "minishop2 links stand check" });
  const cat = await run("modx_create", { kind: "resource", fields: { pagetitle: "mcp2test_links_category", class_key: "msCategory", published: false, hidemenu: true } });
  const ids = [];
  for (const name of ["a", "b", "c"]) {
    ids.push((await run("modx_create", { kind: "resource", fields: { pagetitle: `mcp2test_product_${name}`, class_key: "msProduct", parent: cat.id, published: false, hidemenu: true } })).id);
  }
  const results = {};
  for (const type of ["many_to_many", "one_to_many", "many_to_one", "one_to_one"]) {
    const link = await run("modx_create", { kind: "ms2_link", fields: { name: `mcp2test_${type}`, type } });
    const created = await run("modx_create", { kind: "ms2_product_link", fields: { link: link.id, master: ids[0], slave: ids[1] } });
    const rows = (await run("modx_list", { kind: "ms2_product_link", where: { link: link.id } })).items.map((r) => r.key);
    const diff = await run("modx_history_diff", { changeset_id: created.changeset_id });
    assert.deepEqual(diff.objects.map((o) => o.key).sort(), [...rows].sort(), `${type}: journaled rows differ from stored rows`);
    const del = await run("modx_delete", { kind: "ms2_product_link", key: created.key });
    assert.equal((await run("modx_list", { kind: "ms2_product_link", where: { link: link.id } })).total, 0);
    await run("modx_undo", { changeset_id: del.changeset_id, confirm: true });
    assert.deepEqual((await run("modx_list", { kind: "ms2_product_link", where: { link: link.id } })).items.map((r) => r.key).sort(), [...rows].sort());
    results[type] = rows.join(" + ");
  }
  console.log("    stored rows per type:", JSON.stringify(results));
  step("product links of all four types: the journal holds exactly the rows MODX wrote; delete and undo restore them");

  const typeDelete = await run("modx_delete", { kind: "ms2_link", name: "mcp2test_many_to_many" });
  const typeDiff = await run("modx_history_diff", { changeset_id: typeDelete.changeset_id });
  await run("modx_undo", { changeset_id: typeDelete.changeset_id, confirm: true });
  step(`deleting a link type took ${typeDiff.objects.length - 1} product link(s) with it; undo brought them back`);

  const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.equal(back.performed, true, JSON.stringify(back).slice(0, 500));
  for (const kind of ["resource", "ms2_link"]) assert.equal((await run("modx_list", { kind, query: "mcp2test", ...(kind === "resource" ? { where: { deleted: false } } : {}) })).total, 0, `${kind} leftovers`);
  assert.deepEqual((await run("modx_site_info")).counts, before);
  const log1 = await run("modx_read_log", { after_offset: log0 });
  step(`undo to checkpoint (${back.changesets.length} changesets): nothing left; new error-log entries: ${log1.returned}`);
  for (const entry of log1.entries.slice(-5)) console.log("    log:", JSON.stringify(entry).slice(0, 260));
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
