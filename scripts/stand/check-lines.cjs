// Stand check for tasks 50-51: read_file windows, edit_file, search_files, read_log dedupe,
// TV media sources per context and output properties. The token is never printed.
// Run by the architect session only, after scripts/stand/deploy.py.
//   node scripts/stand/check-lines.cjs
// It writes one test folder mcp2test_lines/ in the first writable media source and one TV, and
// removes both again through undo to a checkpoint (the folder is deleted with modx_delete_path).
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
  const before = (await run("modx_site_info")).counts;
  let source = null;
  for (const item of (await run("modx_list", { kind: "media_source" })).items) {
    const listing = await raw("modx_list_files", { source: item.id });
    if (!listing.isError) { source = item.id; break; }
  }
  assert.ok(source !== null, "no usable media source");
  const dir = "mcp2test_lines";
  const file = `${dir}/style.css`;
  const css = Array.from({ length: 300 }, (_, i) => `.c${i + 1} { color: #${String(i).padStart(3, "0")}; } /* строка ${i + 1} */`).join("\r\n") + "\r\n";
  const checkpoint = await run("modx_checkpoint", { label: "lines stand check" });
  try {
    await run("modx_write_file", { source, path: file, content: css, must_be_new: true });
    const win = await run("modx_read_file", { source, path: file, start_line: 150, end_line: 152 });
    assert.equal(win.total_lines, 300);
    assert.match(win.numbered, /^150\t\.c150 /);
    step(`read_file window: lines 150-152 of ${win.total_lines}, root ${win.root}`);

    const dry = await run("modx_edit_file", { root: win.root, path: file, expected_revision: win.revision, dry_run: true,
      edits: [{ start_line: 151, replacement: ".c151 { color: red; } /* изменено */", expect: win.numbered.split("\n")[1].split("\t")[1] }] });
    assert.equal(dry.dry_run, true);
    const edited = await run("modx_edit_file", { root: win.root, path: file, expected_revision: win.revision,
      edits: [{ start_line: 151, replacement: ".c151 { color: red; } /* изменено */", expect: win.numbered.split("\n")[1].split("\t")[1] }] });
    const after = await run("modx_read_file", { source, path: file });
    assert.ok(after.content.includes(".c151 { color: red; } /* изменено */\r\n"), "CRLF kept");
    assert.equal(after.content.length, css.length - ".c151 { color: #150; } /* строка 151 */".length + ".c151 { color: red; } /* изменено */".length);
    step(`edit_file: one line changed, CRLF kept (changeset ${edited.changeset_id})`);

    const found = await run("modx_search_files", { source, path: dir, query: "изменено" });
    assert.equal(found.files_matched, 1);
    assert.equal(found.results[0].matches[0].line, 151);
    step(`search_files: found on line 151 (${found.files_scanned} files scanned)`);

    await run("modx_undo", { changeset_id: edited.changeset_id, confirm: true });
    assert.equal((await run("modx_read_file", { source, path: file })).content, css);
    step("undo restored the exact bytes");

    const log = await run("modx_read_log", { limit: 20, dedupe: true });
    assert.ok(log.raw_entries >= log.entries.length);
    step(`read_log dedupe: ${log.raw_entries} raw entries folded into ${log.entries.length}` + (log.entries[0] ? `, top count ${Math.max(...log.entries.map((e) => e.count || 1))}` : ""));

    const tv = await run("modx_create", { kind: "tv", fields: { name: "mcp2test_картинка", caption: "Картинка", type: "image", output_properties: { alttext: "alt" } } });
    await run("modx_update", { kind: "tv", id: tv.id, relations: { media_sources: { web: source } } });
    const got = await run("modx_get", { kind: "tv", id: tv.id });
    assert.equal(got.relations.media_sources.web, source);
    assert.equal(got.fields.output_properties.alttext, "alt");
    step(`TV ${tv.id}: media source ${source} for web and output properties stored`);
  } finally {
    const undo = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true, force: true });
    const leftover = await raw("modx_list_files", { source, path: dir });
    if (!leftover.isError) await run("modx_delete_path", { source, path: dir, confirm: true }).catch(() => {});
    assert.deepEqual((await run("modx_site_info")).counts, before);
    step(`undo to checkpoint (${undo.changesets.length} changesets), test folder removed, counts as before`);
  }
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
