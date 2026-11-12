// Stand check: miniShop2 product data, options, extra categories, kind ms2_option. Run by the architect
// session only, after scripts/stand/deploy.py. Real products are only read; everything it creates is
// named mcp2test* and undone at the end. The token is never printed.
//   node scripts/stand/check-minishop2.cjs
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
  const fails = async (name, args, pattern) => {
    const result = await raw(name, args);
    assert.equal(result.isError, true, `${name} ${JSON.stringify(args)} should fail`);
    assert.match(result.content[0].text, pattern);
  };
  const step = (label) => console.log("ok  " + label);
  const caps = await run("modx_capabilities");
  assert.ok(caps.kinds.includes("ms2_option"), "miniShop2 is not available: " + JSON.stringify(caps.unavailable_kinds));
  const before = (await run("modx_site_info")).counts;
  const log0 = (await run("modx_read_log", { limit: 1 })).end_offset;
  for (const kind of ["resource", "ms2_option"]) {
    if ((await run("modx_list", { kind, query: "mcp2test", ...(kind === "resource" ? { where: { deleted: false } } : {}) })).total) throw new Error(`leftover mcp2test ${kind}; remove it first`);
  }

  // Real catalogue: read only.
  const products = await run("modx_list", { kind: "resource", where: { class_key: "msProduct" }, limit: 5 });
  const categories = await run("modx_list", { kind: "resource", where: { class_key: "msCategory" }, limit: 5 });
  const options = await run("modx_list", { kind: "ms2_option", limit: 20 });
  console.log(`    ${products.total} products, ${categories.total} categories, ${options.total} options: ${options.items.map((o) => o.key).join(", ")}`);
  if (products.total) {
    const real = await run("modx_get", { kind: "resource", id: products.items[0].id });
    assert.equal((await run("modx_get", { kind: "resource", id: products.items[0].id })).revision, real.revision);
    console.log(`    real product #${real.id} "${real.fields.pagetitle}": price ${real.relations.ms2_data.price}, article "${real.relations.ms2_data.article}", options ${JSON.stringify(real.relations.ms2_options).slice(0, 120)}, categories ${JSON.stringify(real.relations.ms2_categories)}`);
  }
  const page = await run("modx_list", { kind: "resource", where: { class_key: "modDocument" }, limit: 1 });
  const pageGot = await run("modx_get", { kind: "resource", id: page.items[0].id });
  assert.equal(pageGot.relations.ms2_data, undefined);
  step("read: real products and options; a plain page has no product relations");

  const checkpoint = await run("modx_checkpoint", { label: "minishop2 stand check" });
  const cat = await run("modx_create", { kind: "resource", fields: { pagetitle: "mcp2test_category", class_key: "msCategory", published: false, hidemenu: true } });
  const cat2 = await run("modx_create", { kind: "resource", fields: { pagetitle: "mcp2test_category_2", class_key: "msCategory", published: false, hidemenu: true } });
  const option = await run("modx_create", { kind: "ms2_option", fields: { key: "mcp2test_material", caption: "Материал", type: "textfield" } });
  await run("modx_invoke", { kind: "ms2_option", id: option.id, op: "assign_to_category", args: { category: cat.id, active: true } });
  const product = await run("modx_create", { kind: "resource", fields: { pagetitle: "mcp2test_product", class_key: "msProduct", parent: cat.id, published: false, hidemenu: true },
    relations: { ms2_data: { price: 1500, article: "MCP-1", tags: ["новинка"] } } });
  let got = await run("modx_get", { kind: "resource", id: product.id });
  assert.equal(Number(got.relations.ms2_data.price), 1500);
  assert.deepEqual(got.relations.ms2_data.tags, ["новинка"]);
  const opt = await run("modx_update", { kind: "resource", id: product.id, relations: { ms2_options: { mcp2test_material: ["хлопок", "лён"] }, ms2_categories: [cat2.id] } });
  got = await run("modx_get", { kind: "resource", id: product.id });
  assert.deepEqual([...got.relations.ms2_options.mcp2test_material].sort(), ["лён", "хлопок"]);
  assert.deepEqual(got.relations.ms2_categories, [cat2.id]);
  step(`product #${product.id}: created with data row, price, tags; option values and an extra category set`);

  const stable = got.revision;
  const title = await run("modx_update", { kind: "resource", id: product.id, fields: { pagetitle: "mcp2test_product renamed" } });
  got = await run("modx_get", { kind: "resource", id: product.id });
  assert.equal(Number(got.relations.ms2_data.price), 1500, "a title change reset the price");
  assert.deepEqual([...got.relations.ms2_options.mcp2test_material].sort(), ["лён", "хлопок"], "a title change reset the options");
  assert.deepEqual(got.relations.ms2_categories, [cat2.id]);
  assert.deepEqual(title.changed, ["pagetitle"]);
  const price = await run("modx_update", { kind: "resource", id: product.id, relations: { ms2_data: { price: 990, old_price: 1500 } } });
  assert.equal(Number((await run("modx_get", { kind: "resource", id: product.id })).relations.ms2_data.price), 990);
  await run("modx_undo", { changeset_id: price.changeset_id, confirm: true });
  await run("modx_undo", { changeset_id: title.changeset_id, confirm: true });
  assert.equal((await run("modx_get", { kind: "resource", id: product.id })).revision, stable);
  step("a title change keeps price, options and categories; price change and title change undone exactly");

  const del = await run("modx_delete", { kind: "resource", id: product.id });
  await run("modx_undo", { changeset_id: del.changeset_id, confirm: true });
  assert.equal((await run("modx_get", { kind: "resource", id: product.id })).revision, stable);
  await fails("modx_update", { kind: "resource", id: page.items[0].id, relations: { ms2_data: { price: 1 } }, dry_run: true }, /validation_failed/);
  step("product to trash and back with identical data; product relations refused on a plain page");

  const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.equal(back.performed, true, JSON.stringify(back).slice(0, 500));
  for (const kind of ["resource", "ms2_option"]) assert.equal((await run("modx_list", { kind, query: "mcp2test", ...(kind === "resource" ? { where: { deleted: false } } : {}) })).total, 0, `${kind} leftovers`);
  assert.deepEqual((await run("modx_site_info")).counts, before);
  const log1 = await run("modx_read_log", { after_offset: log0 });
  step(`undo to checkpoint (${back.changesets.length} changesets): nothing left; new error-log entries: ${log1.returned}`);
  for (const entry of log1.entries.slice(-5)) console.log("    log:", JSON.stringify(entry).slice(0, 260));
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
