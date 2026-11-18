// Stand check for tasks 35-36: static paths, property sets, lexicon entries, media source properties.
// Run by the architect session only, after scripts/stand/deploy.py. The token is never printed.
//   node scripts/stand/check-kinds2.cjs
// Everything created is removed again by an undo to a checkpoint; no static file is created.
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
  };
  const step = (label) => console.log("ok  " + label);
  const before = (await run("modx_site_info")).counts;
  const checkpoint = await run("modx_checkpoint", { label: "kinds2 stand check" });
  try {
    // Static paths: existing static elements still read; secrets refused.
    for (const kind of ["chunk", "snippet", "plugin"]) {
      const list = await run("modx_list", { kind, where: { static: true }, limit: 3 });
      for (const item of list.items) {
        const got = await raw("modx_get", { kind, id: item.id, fields: ["content"] });
        assert.equal(got.isError, false);
        assert.ok(!/outside modxmcp\.static_roots/.test(JSON.stringify(got)), `${kind} ${item.id} reads its file`);
      }
    }
    const audit = await run("modx_audit_site", { checks: ["static_outside_roots"] });
    assert.equal(audit.summary.static_outside_roots.count, 0);
    await fails("modx_create", { kind: "chunk", fields: { name: "mcp2test_secret", content: "x", static: true, static_file: "[[++core_path]]config/config.inc.php" } }, /static_roots/);
    await fails("modx_create", { kind: "chunk", fields: { name: "mcp2test_core", content: "x", static: true, static_file: "core/model/modx/modx.class.php", source: 1 } }, /static_roots/);
    step("static paths: existing static elements read their files, the audit finds none outside; config and MODX code refused");

    // Property sets and element properties.
    const chunk = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_props", content: "[[+tpl]]" } });
    const set = await run("modx_create", { kind: "property_set", fields: { name: "mcp2test_набор", description: "проверка",
      properties: { tpl: { type: "textfield", value: "Шаблон набора" } } } });
    await run("modx_invoke", { kind: "property_set", id: set.id, op: "assign", args: { kind: "chunk", id: chunk.id } });
    const linked = await run("modx_get", { kind: "chunk", id: chunk.id });
    assert.ok(JSON.stringify(linked.relations.property_sets).includes(String(set.id)), JSON.stringify(linked.relations));
    const setGot = await run("modx_get", { kind: "property_set", id: set.id });
    assert.equal(setGot.fields.properties.tpl.value, "Шаблон набора");
    await run("modx_update", { kind: "chunk", id: chunk.id, fields: { properties: { tpl: { type: "textfield", value: "свой" } } } });
    assert.equal((await run("modx_get", { kind: "chunk", id: chunk.id })).fields.properties.tpl.value, "свой");
    step(`property_set ${set.id}: created with properties, assigned to chunk ${chunk.id}; chunk default properties written and read`);

    // Lexicon: a file entry, an override, back to the file.
    const key = "core:default:ru:yes";
    const fileEntry = await run("modx_get", { kind: "lexicon_entry", key });
    const original = fileEntry.fields.value;
    await run("modx_update", { kind: "lexicon_entry", key, fields: { value: original + " (тест)" } });
    const overridden = await run("modx_get", { kind: "lexicon_entry", key });
    assert.equal(overridden.fields.value, original + " (тест)");
    assert.equal(overridden.fields.overridden, true);
    await run("modx_delete", { kind: "lexicon_entry", key });
    const back = await run("modx_get", { kind: "lexicon_entry", key });
    assert.equal(back.fields.value, original);
    assert.equal(back.fields.overridden, false);
    step(`lexicon_entry ${key}: "${original}" from the file, overridden in the database, override removed`);

    // Media source properties, and the protected-path rule.
    const source = await run("modx_create", { kind: "media_source", fields: { name: "mcp2test_источник", class_key: "sources.modFileMediaSource",
      properties: { basePath: "assets/mcp2test/", basePathRelative: true, baseUrl: "assets/mcp2test/", baseUrlRelative: true } } });
    const srcGot = await run("modx_get", { kind: "media_source", id: source.id });
    assert.equal(srcGot.fields.properties.basePath, "assets/mcp2test/");
    await fails("modx_update", { kind: "media_source", id: source.id, fields: { properties: { basePath: "core/config/" } } }, /validation_failed|allow_root_filesystem_read/);
    step(`media_source ${source.id}: properties stored; a basePath into core/config refused`);
  } finally {
    const undo = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true, force: true });
    assert.deepEqual((await run("modx_site_info")).counts, before);
    step(`undo to checkpoint (${undo.changesets.length} changesets): counts as before`);
  }
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
