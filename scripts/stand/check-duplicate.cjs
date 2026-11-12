// Stand check: modx_duplicate. Run by the architect session only, after scripts/stand/deploy.py.
// Works only on objects named mcp2test_* and undoes everything it did. The token is never printed.
//   node scripts/stand/check-duplicate.cjs
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
  const run = async (name, args = {}) => {
    const result = await client.callTool({ name, arguments: args });
    if (result.isError) throw new Error(`${name} ${JSON.stringify(args)} -> ${result.content[0].text}`);
    return result.structuredContent;
  };
  const step = (label) => console.log("ok  " + label);
  const before = (await run("modx_site_info")).counts;
  for (const kind of ["chunk", "plugin", "resource"]) {
    if ((await run("modx_list", { kind, query: "mcp2test_" })).total) throw new Error(`leftover mcp2test_ ${kind}; remove it first`);
  }
  const checkpoint = await run("modx_checkpoint", { label: "duplicate stand check" });

  const chunk = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_dup", content: "<p>dup</p>", description: "d" } });
  const copy = await run("modx_duplicate", { kind: "chunk", id: chunk.id });
  const copyGot = await run("modx_get", { kind: "chunk", id: copy.id });
  assert.equal(copyGot.fields.name, "mcp2test_dup copy");
  assert.equal(copyGot.fields.content, "<p>dup</p>");
  assert.equal(copyGot.fields.description, "d");
  const copy2 = await run("modx_duplicate", { kind: "chunk", name: "mcp2test_dup" });
  assert.equal(copy2.name, "mcp2test_dup copy 2");
  step("chunk copied with automatic names");

  const staticChunk = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_dup_static", content: "static body", static: true, static_file: "[[++core_path]]elements/chunks/mcp2test_dup_static.tpl" } });
  const staticCopy = await run("modx_duplicate", { kind: "chunk", id: staticChunk.id, new_name: "mcp2test_dup_static_copy" });
  const staticGot = await run("modx_get", { kind: "chunk", id: staticCopy.id });
  assert.equal(staticGot.fields.static, false);
  assert.equal(staticGot.fields.static_file, "");
  assert.equal(staticGot.fields.content, "static body");
  step("copy of a static chunk is not static and has the file's content");

  const plugin = await run("modx_create", { kind: "plugin", fields: { name: "mcp2test_dup_plugin", content: "return;", disabled: true }, relations: { events: [{ event: "OnWebPageInit", priority: 4 }] } });
  const pluginCopy = await run("modx_duplicate", { kind: "plugin", id: plugin.id });
  const pluginGot = await run("modx_get", { kind: "plugin", id: pluginCopy.id });
  assert.deepEqual(pluginGot.relations.events, [{ event: "OnWebPageInit", priority: 4 }]);
  assert.equal(pluginGot.fields.disabled, true);
  step("plugin copied with its events");

  // A resource with a stored TV, a child and a schedule.
  let template = null, tvName = null;
  for (const item of (await run("modx_list", { kind: "resource", limit: 50 })).items) {
    const got = await run("modx_get", { kind: "resource", id: item.id, fields: ["template"] });
    const names = Object.keys(got.relations.tvs || {});
    if (names.length > 1) { template = got.fields.template; tvName = names[0]; break; }
  }
  assert.ok(template, "no template with two or more TVs");
  const page = await run("modx_create", { kind: "resource", fields: { pagetitle: "mcp2test_dup_page", template, content: "<p>page</p>", published: false, hidemenu: true, pub_date: "2031-01-02 03:04:05" }, relations: { tvs: { [tvName]: "stored" } } });
  await run("modx_create", { kind: "resource", fields: { pagetitle: "mcp2test_dup_child", parent: page.id, published: false, hidemenu: true } });
  const source = await run("modx_get", { kind: "resource", id: page.id });
  const pageCopy = await run("modx_duplicate", { kind: "resource", id: page.id });
  const pageGot = await run("modx_get", { kind: "resource", id: pageCopy.id });
  assert.equal(pageGot.fields.pagetitle, "mcp2test_dup_page copy");
  assert.equal(pageGot.fields.published, false);
  assert.equal(pageGot.fields.pub_date, "");
  assert.equal(pageGot.fields.content, "<p>page</p>");
  assert.equal(pageGot.fields.isfolder, false);
  assert.notEqual(pageGot.fields.alias, source.fields.alias);
  assert.ok(pageGot.fields.alias.length > 0 && pageGot.fields.uri.length > 0);
  assert.equal(pageGot.relations.tvs[tvName], "stored");
  assert.equal((await run("modx_list", { kind: "resource", where: { parent: pageCopy.id } })).total, 0);
  assert.equal((await run("modx_get", { kind: "resource", id: page.id })).revision, source.revision);
  const diff = await run("modx_history_diff", { changeset_id: pageCopy.changeset_id });
  const tvRows = diff.objects.find((o) => o.id === pageCopy.id).changed.tvs.new;
  assert.equal(tvRows.length, 1, "only the stored TV value is copied, not the defaults");
  step(`resource copied: unpublished, unscheduled, alias "${pageGot.fields.alias}", stored TV only, no children; source untouched`);

  const undo = await run("modx_undo", { changeset_id: copy.changeset_id, confirm: true });
  assert.equal(undo.objects[0].action, "remove");
  const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.equal(back.performed, true);
  assert.deepEqual((await run("modx_site_info")).counts, before);
  step(`undo to checkpoint (${back.changesets.length} changesets): counts as before`);
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
