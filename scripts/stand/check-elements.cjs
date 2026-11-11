// Stand check: elements, journal and undo. Run by the architect session only, after scripts/stand/deploy.py.
// Talks to the development stand named in the ignored .mcp.json through the real bridge, works only on
// objects and paths named mcp2test_*, and undoes everything it did. The token is never printed.
//   node scripts/stand/check-elements.cjs
const path = require("node:path");
const assert = require("node:assert/strict");
const repo = path.resolve(__dirname, "..", "..");
const { Client } = require(path.join(repo, "node_modules/@modelcontextprotocol/sdk/dist/cjs/client/index.js"));
const { StdioClientTransport } = require(path.join(repo, "node_modules/@modelcontextprotocol/sdk/dist/cjs/client/stdio.js"));
const cfg = require(path.join(repo, ".mcp.json")).mcpServers.modx.env;

(async () => {
  const transport = new StdioClientTransport({
    command: process.execPath, args: [path.join(repo, "client", "index.js")],
    env: { ...process.env, MODX_MCP_SITE_URL: cfg.MODX_MCP_SITE_URL, MODX_MCP_TOKEN: cfg.MODX_MCP_TOKEN }, stderr: "inherit",
  });
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
    return result.content[0].text;
  };
  const step = (label) => console.log("ok  " + label);

  const { tools } = await client.listTools();
  console.log("tools:", tools.length);
  const before = (await run("modx_site_info")).counts;

  // Reading existing objects, including static ones, without touching them.
  const chunks = await run("modx_list", { kind: "chunk", limit: 5 });
  assert.ok(chunks.total >= chunks.items.length && chunks.items.length > 0);
  const first = await run("modx_get", { kind: "chunk", id: chunks.items[0].id });
  assert.match(first.revision, /^[a-f0-9]{64}$/);
  assert.equal((await run("modx_get", { kind: "chunk", id: chunks.items[0].id })).revision, first.revision);
  step(`list/get existing chunks (total ${chunks.total}), revision is stable`);
  for (const kind of ["chunk", "snippet", "template", "plugin"]) {
    const statics = await run("modx_list", { kind, where: { static: true }, limit: 1 });
    if (statics.total) {
      const item = await run("modx_get", { kind, id: statics.items[0].id });
      const view = await run("modx_view_code", { kind, id: statics.items[0].id, start_line: 1, end_line: 3 });
      assert.equal(view.static, true);
      assert.equal(view.revision, item.revision);
      step(`static ${kind} #${item.id}: file content read, ${view.total_lines} lines`);
    }
  }
  for (const kind of ["template", "tv", "plugin", "snippet", "category"]) {
    const list = await run("modx_list", { kind, limit: 2 });
    if (list.items.length) await run("modx_get", { kind, id: list.items[0].id });
  }
  step("list/get for every element kind");

  // Clean leftovers of an earlier interrupted run.
  for (const kind of ["chunk", "snippet", "plugin", "tv", "category"]) {
    for (const item of (await run("modx_list", { kind, query: "mcp2test_" })).items) {
      if (kind === "tv") await run("modx_update", { kind, id: item.id, relations: { templates: [] } });
      await run("modx_delete", { kind, id: item.id });
    }
  }

  const checkpoint = await run("modx_checkpoint", { label: "stage 2 stand check" });
  const chunk = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_chunk", description: "stand check", content: "<p>one</p>\n<p>two</p>" } });
  assert.ok(chunk.id > 0 && chunk.changeset_id > 0 && chunk.reversible);
  const got = await run("modx_get", { kind: "chunk", id: chunk.id });
  assert.equal(got.revision, chunk.revision);
  assert.equal(got.fields.content, "<p>one</p>\n<p>two</p>");
  step(`create chunk #${chunk.id}, changeset ${chunk.changeset_id}`);

  const updated = await run("modx_update", { kind: "chunk", id: chunk.id, fields: { description: "changed" }, expected_revision: chunk.revision });
  assert.deepEqual(updated.changed, ["description"]);
  await fails("modx_update", { kind: "chunk", id: chunk.id, fields: { description: "x" }, expected_revision: chunk.revision }, /revision_conflict/);
  const same = await run("modx_update", { kind: "chunk", id: chunk.id, fields: { description: "changed" } });
  assert.equal(same.changeset_id, null);
  assert.equal(same.revision, updated.revision);
  step("update: one field, stale revision refused, no-op records nothing");

  const view = await run("modx_view_code", { kind: "chunk", id: chunk.id });
  const edited = await run("modx_edit_code", { kind: "chunk", id: chunk.id, expected_revision: view.revision, edits: [{ start_line: 2, replacement: "<p>TWO</p>", expect: "<p>two</p>" }] });
  assert.deepEqual(edited.changed, ["content"]);
  const diff = await run("modx_history_diff", { changeset_id: edited.changeset_id });
  assert.equal(diff.objects[0].changed.content.diff, "@@ -2,1 +2,1 @@\n-<p>two</p>\n+<p>TWO</p>");
  step("edit_code + history_diff");

  await fails("modx_create", { kind: "snippet", fields: { name: "mcp2test_bad", content: "return (;" } }, /lint_failed.*line 1/);
  const snippet = await run("modx_create", { kind: "snippet", fields: { name: "mcp2test_snippet", content: "return date('Y');" } });
  await fails("modx_edit_code", { kind: "snippet", id: snippet.id, edits: [{ start_line: 1, replacement: "return date('Y';" }] }, /lint_failed/);
  assert.equal((await run("modx_get", { kind: "snippet", id: snippet.id })).revision, snippet.revision);
  step("PHP lint refuses broken code on create and on line edit");

  const plugin = await run("modx_create", { kind: "plugin", fields: { name: "mcp2test_plugin", content: "return;", disabled: true }, relations: { events: ["OnLoadWebDocument"] } });
  const pluginGot = await run("modx_get", { kind: "plugin", id: plugin.id });
  assert.deepEqual(pluginGot.relations.events, [{ event: "OnLoadWebDocument", priority: 0 }]);
  assert.equal(pluginGot.fields.disabled, true);
  const rel = await run("modx_update", { kind: "plugin", id: plugin.id, relations: { events: [{ event: "OnWebPageInit", priority: 3 }] } });
  assert.deepEqual(rel.changed, ["events"]);
  step("plugin with events relation");

  const template = (await run("modx_list", { kind: "template", limit: 1 })).items[0];
  const tv = await run("modx_create", { kind: "tv", fields: { name: "mcp2test_tv", caption: "Check", type: "text" }, relations: { templates: [template.id] } });
  const tvGot = await run("modx_get", { kind: "tv", id: tv.id });
  assert.deepEqual(tvGot.relations.templates, [template.id]);
  const category = await run("modx_create", { kind: "category", fields: { name: "mcp2test_category" } });
  step("tv with templates relation, category");

  const history = await run("modx_history", { this_session: true, limit: 50 });
  console.log("    session changesets:", history.changesets.map((c) => `${c.changeset_id}:${c.tool}`).join(" "));

  // Delete, then bring everything back with the same ids and identical state.
  const dry = await run("modx_delete", { kind: "tv", id: tv.id, dry_run: true });
  assert.equal(dry.dry_run, true);
  // MODX refuses to delete a TV that is still attached to a template.
  await fails("modx_delete", { kind: "tv", id: tv.id }, /validation_failed/);
  assert.equal((await run("modx_get", { kind: "tv", id: tv.id })).revision, tvGot.revision);
  await run("modx_update", { kind: "tv", id: tv.id, relations: { templates: [] } });
  const revisions = {};
  for (const [kind, id] of [["chunk", chunk.id], ["snippet", snippet.id], ["plugin", plugin.id], ["tv", tv.id], ["category", category.id]]) {
    revisions[`${kind}:${id}`] = (await run("modx_get", { kind, id })).revision;
  }
  const deletes = [];
  for (const key of Object.keys(revisions)) {
    const [kind, id] = key.split(":");
    deletes.push((await run("modx_delete", { kind, id: Number(id) })).changeset_id);
    await fails("modx_get", { kind, id: Number(id) }, /not_found/);
  }
  step("delete all five objects");
  const plan = await run("modx_undo", { last: 5 });
  assert.equal(plan.performed, false);
  assert.equal(plan.conflicts, 0);
  const undo = await run("modx_undo", { last: 5, confirm: true });
  assert.equal(undo.performed, true);
  for (const key of Object.keys(revisions)) {
    const [kind, id] = key.split(":");
    assert.equal((await run("modx_get", { kind, id: Number(id) })).revision, revisions[key], `${key} restored state differs`);
  }
  step("undo of five deletes: same ids, identical revisions (fields, events, templates)");

  // Undo everything back to the checkpoint: the test objects disappear again.
  const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.equal(back.performed, true);
  for (const kind of ["chunk", "snippet", "plugin", "tv", "category"]) {
    assert.equal((await run("modx_list", { kind, query: "mcp2test_" })).total, 0, `${kind} leftovers`);
  }
  const after = (await run("modx_site_info")).counts;
  assert.deepEqual(after, before);
  step(`undo to checkpoint (${back.changesets.length} changesets): site counts are back to the initial values`);
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
