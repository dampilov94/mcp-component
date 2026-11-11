// Stand check: resources. Run by the architect session only, after scripts/stand/deploy.py.
// Talks to the development stand named in the ignored .mcp.json through the real bridge, works only on
// objects and paths named mcp2test_*, and undoes everything it did. The token is never printed.
//   node scripts/stand/check-resources.cjs
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
  const warnings = [];
  const run = async (name, args = {}) => {
    const result = await raw(name, args);
    if (result.isError) throw new Error(`${name} ${JSON.stringify(args)} -> ${result.content[0].text}`);
    return result.structuredContent;
  };
  const step = (label) => console.log("ok  " + label);
  console.log("tools:", (await client.listTools()).tools.length);
  const before = (await run("modx_site_info")).counts;

  // Existing resources: read only.
  const all = await run("modx_list", { kind: "resource", limit: 200, fields: ["id", "pagetitle", "class_key", "template", "parent", "deleted"] });
  for (const item of all.items) {
    const a = await run("modx_get", { kind: "resource", id: item.id, fields: ["pagetitle", "uri", "pub_date", "editedon"] });
    const b = await run("modx_get", { kind: "resource", id: item.id, fields: ["pagetitle"] });
    assert.equal(a.revision, b.revision, `revision of resource ${item.id} is not stable`);
  }
  step(`read all ${all.total} existing resources, revisions stable; classes: ${[...new Set(all.items.map((i) => i.class_key))].join(", ")}`);
  const tree = await run("modx_resource_tree", { depth: 2 });
  step(`resource_tree: ${tree.returned} nodes, ${tree.nodes.length} at top level`);

  // A template that has TVs.
  let template = null, tvName = null;
  for (const item of all.items) {
    const got = await run("modx_get", { kind: "resource", id: item.id, fields: ["template"] });
    const names = Object.keys(got.relations.tvs || {});
    if (names.length) { template = got.fields.template; tvName = names[0]; break; }
  }
  assert.ok(template, "no template with TVs found");

  for (const item of (await run("modx_list", { kind: "resource", query: "mcp2test_" })).items) {
    throw new Error(`leftover test resource ${item.id}; remove it first`);
  }
  const checkpoint = await run("modx_checkpoint", { label: "stage 3 stand check" });
  const parent = await run("modx_create", { kind: "resource", fields: { pagetitle: "mcp2test_parent", template, content: "<p>one</p>\n<p>two</p>", published: false, hidemenu: true } });
  const child = await run("modx_create", { kind: "resource", fields: { pagetitle: "mcp2test_child", parent: parent.id, template, published: false, hidemenu: true }, relations: { tvs: { [tvName]: "stand value" } } });
  const parentGot = await run("modx_get", { kind: "resource", id: parent.id });
  assert.equal(parentGot.fields.isfolder, true);
  assert.equal(parentGot.fields.published, false);
  assert.equal(parentGot.fields.hidemenu, true);
  assert.ok(parentGot.fields.uri.length > 0);
  const childGot = await run("modx_get", { kind: "resource", id: child.id });
  assert.equal(childGot.relations.tvs[tvName], "stand value");
  assert.equal(childGot.fields.parent, parent.id);
  step(`create parent #${parent.id} and child #${child.id} with TV "${tvName}"; parent became a container`);

  const upd = await run("modx_update", { kind: "resource", id: parent.id, fields: { longtitle: "Long" }, expected_revision: parentGot.revision });
  assert.deepEqual(upd.changed, ["longtitle"]);
  const after = await run("modx_get", { kind: "resource", id: parent.id });
  for (const f of ["published", "hidemenu", "isfolder", "searchable", "cacheable", "richtext", "template", "alias", "uri", "content", "deleted", "pub_date"]) {
    assert.deepEqual(after.fields[f], parentGot.fields[f], `field ${f} changed unexpectedly`);
  }
  const noop = await run("modx_update", { kind: "resource", id: parent.id, fields: { longtitle: "Long" } });
  assert.equal(noop.changeset_id, null);
  step("update: only longtitle changed, flags and dates kept; a no-op records nothing");

  const future = "2031-05-06 07:08:09";
  await run("modx_update", { kind: "resource", id: child.id, fields: { pub_date: future } });
  await run("modx_update", { kind: "resource", id: child.id, fields: { menutitle: "Child" } });
  const sched = await run("modx_get", { kind: "resource", id: child.id });
  assert.equal(sched.fields.pub_date, future);
  assert.equal(sched.fields.published, false);
  step("scheduled publish date survives a later update and does not publish");

  const tv = await run("modx_update", { kind: "resource", id: child.id, relations: { tvs: { [tvName]: "second value" } } });
  assert.deepEqual(tv.changed, ["tvs"]);
  await run("modx_undo", { changeset_id: tv.changeset_id, confirm: true });
  assert.equal((await run("modx_get", { kind: "resource", id: child.id })).relations.tvs[tvName], "stand value");
  step("TV write and its undo");

  const edit = await run("modx_edit_code", { kind: "resource", id: parent.id, edits: [{ start_line: 2, replacement: "<p>TWO</p>", expect: "<p>two</p>" }] });
  assert.deepEqual(edit.changed, ["content"]);
  step("content line edit");

  const alias = await run("modx_update", { kind: "resource", id: parent.id, fields: { alias: "mcp2test-renamed" } });
  const childUri = (await run("modx_get", { kind: "resource", id: child.id, fields: ["uri"] })).fields.uri;
  assert.match(childUri, /mcp2test-renamed/);
  await run("modx_undo", { changeset_id: alias.changeset_id, confirm: true });
  const childUriBack = (await run("modx_get", { kind: "resource", id: child.id, fields: ["uri"] })).fields.uri;
  assert.doesNotMatch(childUriBack, /mcp2test-renamed/);
  step(`alias change moved the child URI (${childUri}) and undo moved it back (${childUriBack})`);

  const revs = { p: (await run("modx_get", { kind: "resource", id: parent.id })).revision, c: (await run("modx_get", { kind: "resource", id: child.id })).revision };
  const del = await run("modx_delete", { kind: "resource", id: parent.id });
  assert.equal((await run("modx_get", { kind: "resource", id: child.id, fields: ["deleted"] })).fields.deleted, true);
  const diff = await run("modx_history_diff", { changeset_id: del.changeset_id });
  assert.equal(diff.objects.length, 2);
  await run("modx_undo", { changeset_id: del.changeset_id, confirm: true });
  assert.equal((await run("modx_get", { kind: "resource", id: parent.id })).revision, revs.p);
  assert.equal((await run("modx_get", { kind: "resource", id: child.id })).revision, revs.c);
  step("delete to trash took the child along; undo restored both to identical state");

  const bulk = await run("modx_bulk_resources", { operation: "delete", ids: [child.id] });
  const back = await run("modx_bulk_resources", { operation: "undelete", ids: [child.id] });
  assert.ok(bulk.changeset_id && back.changeset_id);
  assert.equal((await run("modx_get", { kind: "resource", id: child.id })).revision, revs.c);
  const dry = await run("modx_bulk_resources", { operation: "move", ids: [child.id], parent: 0, dry_run: true });
  assert.equal(dry.dry_run, true);
  step("bulk delete / undelete / dry-run move");

  const all2 = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.equal(all2.performed, true);
  assert.equal((await run("modx_list", { kind: "resource", query: "mcp2test_" })).total, 0);
  assert.deepEqual((await run("modx_site_info")).counts, before);
  step(`undo to checkpoint (${all2.changesets.length} changesets): test resources are gone, counts as before`);
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
