// Stand check: files. Run by the architect session only, after scripts/stand/deploy.py.
// Talks to the development stand named in the ignored .mcp.json through the real bridge, works only on
// objects and paths named mcp2test_*, and undoes everything it did. The token is never printed.
//   node scripts/stand/check-files.cjs
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

  const sources = await run("modx_list", { kind: "media_source" });
  console.log("    media sources:", sources.items.map((s) => `${s.id}:${s.name} [${s.class_key}] ${s.base_path || "(site root)"}`).join(" | "));
  let source = null;
  for (const item of sources.items) {
    const listing = await raw("modx_list_files", { source: item.id });
    console.log(`    source ${item.id}: ${listing.isError ? listing.content[0].text.slice(0, 110) : listing.structuredContent.total + " entries, writable"}`);
    if (!listing.isError && source === null) source = item.id;
  }
  const component = await run("modx_read_file", { component: "modxmcp", path: "docs/index.md", max_bytes: 60 });
  assert.match(component.content, /^# modxMCP/);
  await fails("modx_write_file", { source: 999, path: "a.txt", content: "x" }, /not_found/);
  step("component code read; unknown source refused");
  if (source === null) { console.log("NO USABLE MEDIA SOURCE: write checks skipped"); await client.close(); return; }

  const dir = "mcp2test_files";
  const existing = await raw("modx_list_files", { source, path: dir });
  if (!existing.isError) throw new Error(`${dir} already exists in source ${source}; remove it first`);
  const checkpoint = await run("modx_checkpoint", { label: "stage 3 files stand check" });

  const created = await run("modx_write_file", { source, path: `${dir}/css/site.css`, content: "a { color: red; }\n", must_be_new: true });
  assert.equal(created.files[0].op, "create");
  const read = await run("modx_read_file", { source, path: `${dir}/css/site.css` });
  assert.equal(read.content, "a { color: red; }\n");
  assert.equal(read.revision, created.files[0].revision);
  step(`write_file created ${dir}/css/site.css in source ${source}; read back with the same revision`);

  const replaced = await run("modx_write_file", { source, path: `${dir}/css/site.css`, content: "a { color: blue; }\n", expected_revision: read.revision });
  await fails("modx_write_file", { source, path: `${dir}/css/site.css`, content: "x", expected_revision: read.revision }, /revision_conflict/);
  const diff = await run("modx_history_diff", { changeset_id: replaced.changeset_id });
  assert.equal(diff.objects[0].changed.content.diff, "@@ -1,1 +1,1 @@\n-a { color: red; }\n+a { color: blue; }");
  step("replace with revision; stale revision refused; journal diff");

  await run("modx_write_file", { source, path: `${dir}/img/dot.gif`, content: Buffer.from([71, 73, 70, 0, 255]).toString("base64"), encoding: "base64" });
  const bin = await run("modx_read_file", { source, path: `${dir}/img/dot.gif`, as_base64: true });
  assert.equal(bin.binary, true);
  assert.deepEqual([...Buffer.from(bin.content, "base64")], [71, 73, 70, 0, 255]);
  step("binary file round trip");

  await fails("modx_write_file", { source, path: `${dir}/bad.php`, content: "<?php echo (;" }, /lint_failed/);
  await fails("modx_read_file", { source, path: `${dir}/bad.php` }, /not_found/);
  await fails("modx_read_file", { source, path: "../outside.txt" }, /validation_failed/);
  step("broken PHP file refused and not written; traversal refused");

  const moved = await run("modx_move_path", { source, path: `${dir}/css`, to: `${dir}/styles` });
  assert.equal((await run("modx_read_file", { source, path: `${dir}/styles/site.css` })).content, "a { color: blue; }\n");
  await run("modx_undo", { changeset_id: moved.changeset_id, confirm: true });
  assert.equal((await run("modx_read_file", { source, path: `${dir}/css/site.css` })).content, "a { color: blue; }\n");
  await fails("modx_read_file", { source, path: `${dir}/styles/site.css` }, /not_found/);
  step("move a directory and undo the move");

  const dry = await run("modx_delete_path", { source, path: dir, dry_run: true });
  assert.equal(dry.count, 2);
  const deleted = await run("modx_delete_path", { source, path: dir });
  await fails("modx_list_files", { source, path: dir }, /not_found/);
  await run("modx_undo", { changeset_id: deleted.changeset_id, confirm: true });
  assert.equal((await run("modx_read_file", { source, path: `${dir}/css/site.css` })).content, "a { color: blue; }\n");
  assert.equal((await run("modx_read_file", { source, path: `${dir}/img/dot.gif` })).size, 5);
  step("delete the directory and restore it with undo, binary included");

  const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.equal(back.performed, true);
  await fails("modx_list_files", { source, path: dir }, /not_found/);
  step(`undo to checkpoint (${back.changesets.length} changesets): ${dir} is gone`);
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
