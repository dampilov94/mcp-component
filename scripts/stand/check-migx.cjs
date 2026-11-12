// Stand check: MIGX configs, a MIGX TV bound through input_properties, MIGX TV values on a page.
// Run by the architect session only, after scripts/stand/deploy.py. Real configs are only read;
// everything it creates is named mcp2test* and undone at the end.
//   node scripts/stand/check-migx.cjs
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
    assert.equal(result.isError, true, `${name} should fail`);
    assert.match(result.content[0].text, pattern);
  };
  const step = (label) => console.log("ok  " + label);
  const before = (await run("modx_site_info")).counts;
  const log0 = (await run("modx_read_log", { limit: 1 })).end_offset;
  for (const kind of ["migx_config", "tv"]) {
    if ((await run("modx_list", { kind, query: "mcp2test" })).total) throw new Error(`leftover mcp2test ${kind}; remove it first`);
  }

  const configs = await run("modx_list", { kind: "migx_config", limit: 20 });
  console.log(`    ${configs.total} configs: ${configs.items.map((c) => c.name).join(", ")}`);
  if (configs.total) {
    const real = await run("modx_get", { kind: "migx_config", id: configs.items[0].id });
    assert.equal((await run("modx_get", { kind: "migx_config", id: configs.items[0].id })).revision, real.revision);
    console.log(`    "${real.fields.name}": ${Array.isArray(real.fields.formtabs) ? real.fields.formtabs.length : typeof real.fields.formtabs} tab(s), ${Array.isArray(real.fields.columns) ? real.fields.columns.length : typeof real.fields.columns} column(s)`);
  }
  const migxTvs = await run("modx_list", { kind: "tv", where: { type: "migx" }, fields: ["id", "name"] });
  for (const tv of migxTvs.items) {
    const got = await run("modx_get", { kind: "tv", id: tv.id, fields: ["name", "input_properties"] });
    console.log(`    MIGX TV "${got.fields.name}": input_properties ${JSON.stringify(got.fields.input_properties).slice(0, 120)}`);
  }
  step("read the real MIGX configs and MIGX TVs (revision stable)");

  const checkpoint = await run("modx_checkpoint", { label: "migx stand check" });
  const config = await run("modx_create", { kind: "migx_config", fields: { name: "mcp2test_gallery",
    formtabs: [{ caption: "Фото", fields: [{ field: "title", caption: "Заголовок" }, { field: "image", caption: "Картинка", inputTVtype: "image" }] }],
    columns: [{ header: "Заголовок", dataIndex: "title" }, { header: "Картинка", dataIndex: "image" }] } });
  const cfgGot = await run("modx_get", { kind: "migx_config", id: config.id });
  assert.equal(cfgGot.fields.formtabs[0].caption, "Фото");
  await fails("modx_update", { kind: "migx_config", id: config.id, fields: { columns: [{ header: "x" }] }, dry_run: true }, /validation_failed/);
  const cols = await run("modx_update", { kind: "migx_config", id: config.id, fields: { columns: [{ header: "Заголовок", dataIndex: "title" }] } });
  assert.deepEqual(cols.changed, ["columns"]);
  step(`config #${config.id} created with Cyrillic tabs; a bad column shape refused; columns updated`);

  // A MIGX TV bound to the config, attached to the template of a test page.
  const someTemplate = (await run("modx_list", { kind: "template", limit: 1 })).items[0];
  const tv = await run("modx_create", { kind: "tv", fields: { name: "mcp2test_gallery_tv", caption: "Галерея", type: "migx", input_properties: { configs: "mcp2test_gallery" } }, relations: { templates: [someTemplate.id] } });
  const tvGot = await run("modx_get", { kind: "tv", id: tv.id });
  assert.equal(tvGot.fields.input_properties.configs, "mcp2test_gallery");
  const page = await run("modx_create", { kind: "resource", fields: { pagetitle: "mcp2test_migx_page", template: someTemplate.id, published: false, hidemenu: true } });
  const value = await run("modx_update", { kind: "resource", id: page.id, relations: { tvs: { mcp2test_gallery_tv: [{ title: "Первый", image: "a.jpg" }, { title: "Второй", image: "b.jpg" }] } } });
  const pageGot = await run("modx_get", { kind: "resource", id: page.id });
  const rows = pageGot.relations.tvs.mcp2test_gallery_tv;
  assert.ok(Array.isArray(rows) && rows.length === 2 && rows[0].MIGX_id && rows[1].MIGX_id);
  console.log("    stored MIGX value:", JSON.stringify(rows));
  await run("modx_undo", { changeset_id: value.changeset_id, confirm: true });
  step("MIGX TV bound through input_properties; a page value written as a list got MIGX_ids, read back as a list, undone");

  const del = await run("modx_delete", { kind: "migx_config", id: config.id });
  assert.equal((await run("modx_list", { kind: "migx_config", query: "mcp2test" })).total, 0);
  await run("modx_undo", { changeset_id: del.changeset_id, confirm: true });
  assert.equal((await run("modx_get", { kind: "migx_config", id: config.id })).fields.deleted, false);
  step("config delete (marked deleted, hidden from the list) and undo");

  const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.equal(back.performed, true, JSON.stringify(back).slice(0, 500));
  assert.deepEqual((await run("modx_site_info")).counts, before);
  const log1 = await run("modx_read_log", { after_offset: log0 });
  step(`undo to checkpoint (${back.changesets.length} changesets): counts as before; new error-log entries: ${log1.returned}`);
  for (const entry of log1.entries.slice(-5)) console.log("    log:", JSON.stringify(entry).slice(0, 260));
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
