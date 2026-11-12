// Stand check: delete usages, modx_invoke (membership, TV operations, make_static), auto-static,
// check_page, dependency_graph. Run by the architect session only, after scripts/stand/deploy.py.
// Works only on objects named mcp2test_* and undoes everything it did. The token is never printed.
//   node scripts/stand/check-operations.cjs
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
  console.log("tools:", (await client.listTools()).tools.length);
  const before = (await run("modx_site_info")).counts;
  for (const kind of ["chunk", "snippet", "resource", "user_group", "resource_group"]) {
    if ((await run("modx_list", { kind, query: "mcp2test_" })).total) throw new Error(`leftover mcp2test_ ${kind}; remove it first`);
  }
  const log0 = (await run("modx_read_log", { limit: 1 })).end_offset;

  // Read-only: graph and TV operations.
  const graph = await run("modx_dependency_graph", { format: "summary" });
  step(`dependency_graph summary: ${JSON.stringify(graph.stats).slice(0, 200)}`);
  const full = await run("modx_dependency_graph", { max_nodes: 50 });
  assert.ok(full.nodes.length > 0 && Array.isArray(full.edges));
  step(`dependency_graph: ${full.nodes.length} nodes, ${full.edges.length} edges${full.truncated ? " (truncated)" : ""}`);
  const types = await run("modx_invoke", { kind: "tv", op: "list_input_types" });
  const suggestion = await run("modx_invoke", { kind: "tv", op: "suggest_type", args: { task: "image gallery" } }).catch((e) => ({ error: e.message }));
  step(`tv ops: list_input_types keys ${Object.keys(types).join(",")}; suggest_type -> ${JSON.stringify(suggestion).slice(0, 160)}`);

  const checkpoint = await run("modx_checkpoint", { label: "operations stand check" });

  // Auto-static on create, make_static on demand, managed file cleanup.
  const auto = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_auto", content: "<p>auto</p>" } });
  const autoGot = await run("modx_get", { kind: "chunk", id: auto.id });
  console.log(`    auto-static after create: static=${autoGot.fields.static} file=${autoGot.fields.static_file}`);
  const plain = await run("modx_create", { kind: "snippet", fields: { name: "mcp2test_plain", content: "return 'Привет';", static: false } });
  assert.equal((await run("modx_get", { kind: "snippet", id: plain.id })).fields.static, false);
  const made = await run("modx_invoke", { kind: "snippet", id: plain.id, op: "make_static" });
  assert.equal(made.status, "converted");
  const madeGot = await run("modx_get", { kind: "snippet", id: plain.id });
  assert.equal(madeGot.fields.static, true);
  assert.equal(madeGot.fields.content, "return 'Привет';");
  assert.match(madeGot.fields.static_file, /elements\/snippets\/mcp2test_plain-\d+\.php$/);
  assert.equal((await run("modx_invoke", { kind: "snippet", id: plain.id, op: "make_static" })).status, "already_static");
  const edited = await run("modx_edit_code", { kind: "snippet", id: plain.id, edits: [{ start_line: 1, replacement: "return 'Пока';" }] });
  assert.deepEqual(edited.changed, ["content"]);
  assert.equal((await run("modx_get", { kind: "snippet", id: plain.id })).fields.content, "return 'Пока';");
  const elements = await run("modx_list_files", { component: "modxmcp" }).catch(() => null);
  step(`make_static: converted to ${madeGot.fields.static_file}, code intact, edit goes to the file`);

  // Delete dry run shows usages.
  const user = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_user", content: "[[$mcp2test_auto]]" } });
  const dry = await run("modx_delete", { kind: "chunk", id: auto.id, dry_run: true });
  assert.equal(dry.usages.objects, 1);
  assert.equal(dry.usages.items[0].name, "mcp2test_user");
  assert.match(dry.warning, /Still used in 1 object/);
  step("delete dry run names the chunk that still uses it");

  // Membership through modx_invoke.
  const group = await run("modx_create", { kind: "user_group", fields: { name: "mcp2test_group" } });
  const added = await run("modx_invoke", { kind: "user_group", id: group.id, op: "add_member", args: { user: 1 } });
  assert.deepEqual(added.changed, ["members"]);
  assert.equal((await run("modx_invoke", { kind: "user_group", id: group.id, op: "member_count" })).members, 1);
  await fails("modx_invoke", { kind: "user_group", id: group.id, op: "add_member", args: { user: 1 } }, /validation_failed/);
  await run("modx_undo", { changeset_id: added.changeset_id, confirm: true });
  assert.equal((await run("modx_invoke", { kind: "user_group", id: group.id, op: "member_count" })).members, 0);
  const again = await run("modx_invoke", { kind: "user_group", id: group.id, op: "add_member", args: { user: 1 } });
  await run("modx_invoke", { kind: "user_group", id: group.id, op: "remove_member", args: { user: 1 } });
  assert.deepEqual((await run("modx_get", { kind: "user_group", id: group.id })).relations.members, []);
  step("user_group: add_member, duplicate refused, undo, remove_member");

  const page = await run("modx_create", { kind: "resource", fields: { pagetitle: "mcp2test_page", published: false, hidemenu: true, content: "<p>stand</p>" } });
  const rgroup = await run("modx_create", { kind: "resource_group", fields: { name: "mcp2test_rgroup" } });
  const joined = await run("modx_invoke", { kind: "resource_group", id: rgroup.id, op: "add_resource", args: { resource: page.id } });
  assert.deepEqual((await run("modx_get", { kind: "resource_group", id: rgroup.id })).relations.resources, [page.id]);
  assert.deepEqual((await run("modx_get", { kind: "resource", id: page.id })).relations.resource_groups, [rgroup.id]);
  await run("modx_undo", { changeset_id: joined.changeset_id, confirm: true });
  assert.deepEqual((await run("modx_get", { kind: "resource", id: page.id })).relations.resource_groups, []);
  step("resource_group: add_resource shows on both sides; undo reverts both");

  // check_page: the start page and the test page.
  const home = await run("modx_check_page", { url_path: "/" });
  assert.equal(home.status, 200);
  step(`check_page "/": ${home.status}, ${home.time_ms} ms, ${home.bytes} bytes, title "${home.title}", unparsed tags ${home.unparsed_count}, new log entries ${home.log.new_entries}`);
  await run("modx_update", { kind: "resource", id: page.id, fields: { published: true, content: "<p>stand</p>\n[[$mcp2test_missing_chunk]]\n<script>var a = [[1,2],[3,4]];</script>" } });
  const test = await run("modx_check_page", { id: page.id });
  step(`check_page test page: ${test.status}, title "${test.title}", unparsed ${JSON.stringify(test.unparsed_tags)}, new log entries ${test.log.new_entries}`);
  for (const entry of test.log.entries.slice(0, 3)) console.log("    log:", JSON.stringify(entry).slice(0, 220));
  await fails("modx_check_page", { url_path: "//example.com/" }, /validation_failed/);

  const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.equal(back.performed, true);
  assert.deepEqual((await run("modx_site_info")).counts, before);
  const log1 = await run("modx_read_log", { after_offset: log0 });
  step(`undo to checkpoint (${back.changesets.length} changesets): counts as before; new error-log entries during the run: ${log1.returned}`);
  for (const entry of log1.entries.slice(-4)) console.log("    log:", JSON.stringify(entry).slice(0, 240));
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
