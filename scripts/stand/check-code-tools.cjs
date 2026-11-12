// Stand check: search, replace, find_usages, read_log, maintenance, access kinds.
// Run by the architect session only, after scripts/stand/deploy.py. Works only on objects named mcp2test_*
// and undoes everything it did. The token is never printed.
//   node scripts/stand/check-code-tools.cjs
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
    return result.content[0].text;
  };
  const step = (label) => console.log("ok  " + label);
  console.log("tools:", (await client.listTools()).tools.length);
  const before = (await run("modx_site_info")).counts;
  for (const kind of ["chunk", "snippet", "user_group", "role", "resource_group"]) {
    if ((await run("modx_list", { kind, query: "mcp2test_" })).total) throw new Error(`leftover mcp2test_ ${kind}; remove it first`);
  }
  const log0 = await run("modx_read_log", { limit: 3 });
  assert.ok(log0.size >= 0 && Array.isArray(log0.entries));
  step(`read_log: size ${log0.size}, last entry ${log0.entries.length ? JSON.stringify(log0.entries.at(-1)).slice(0, 110) : "(none)"}`);

  // Search existing code read-only: a tag that every MODX site has.
  const real = await run("modx_search", { query: "[[", limit: 5, max_matches: 1 });
  assert.ok(real.objects > 0);
  step(`search over existing elements: ${real.objects} objects${real.truncated ? " (truncated)" : ""}, first ${real.results[0].kind} "${real.results[0].name}"`);

  const checkpoint = await run("modx_checkpoint", { label: "code tools stand check" });
  const marker = "mcp2test_Маркер";
  const a = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_a", content: `<p>${marker}</p>\n[[$mcp2test_b]]\n[[$mcp2test_b_long]]` } });
  const b = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_b", content: `line\n${marker} twice ${marker}` } });
  const s = await run("modx_create", { kind: "snippet", fields: { name: "mcp2test_s", content: `return $modx->getChunk('mcp2test_b') . '${marker}';` } });

  const found = await run("modx_search", { query: marker.toUpperCase() });
  assert.deepEqual(found.results.map((r) => `${r.kind}:${r.name}:${r.match_count}`), ["chunk:mcp2test_a:1", "chunk:mcp2test_b:1", "snippet:mcp2test_s:1"]);
  assert.equal((await run("modx_search", { query: marker.toUpperCase(), case_sensitive: true })).objects, 0);
  assert.equal(found.results[1].matches[0].line, 2);
  step("search: Cyrillic, case-insensitive by default, line numbers");

  const usages = await run("modx_find_usages", { kind: "chunk", name: "mcp2test_b" });
  assert.deepEqual(usages.usages.map((u) => `${u.kind}:${u.name}:${u.matches.map((m) => m.via).join("+")}`), ["chunk:mcp2test_a:tag", "snippet:mcp2test_s:api"]);
  step("find_usages: tag and API call found, longer name not confused");

  const dry = await run("modx_replace", { find: marker, replacement: "mcp2test_Замена", dry_run: true });
  assert.equal(dry.total_occurrences, 4);
  assert.equal(Object.keys(dry.revisions).length, 3);
  await fails("modx_replace", { find: marker, replacement: "x" }, /validation_failed/);
  await run("modx_update", { kind: "chunk", id: b.id, fields: { description: "changed in between" } });
  await fails("modx_replace", { find: marker, replacement: "x", expected_revisions: dry.revisions }, /revision_conflict/);
  assert.match((await run("modx_get", { kind: "chunk", id: a.id })).fields.content, new RegExp(marker));
  const dry2 = await run("modx_replace", { find: marker, replacement: "mcp2test_Замена", dry_run: true });
  const done = await run("modx_replace", { find: marker, replacement: "mcp2test_Замена", expected_revisions: dry2.revisions });
  assert.equal(done.total_occurrences, 4);
  assert.equal((await run("modx_search", { query: marker })).objects, 0);
  assert.equal((await run("modx_search", { query: "mcp2test_Замена" })).objects, 3);
  await run("modx_undo", { changeset_id: done.changeset_id, confirm: true });
  assert.equal((await run("modx_search", { query: marker })).objects, 3);
  step("replace: dry run, stale revisions refused with nothing changed, applied as one changeset, undone");

  const badDry = await run("modx_replace", { find: "return", replacement: "return (", kinds: ["snippet"], dry_run: true });
  const mine = Object.fromEntries(Object.entries(badDry.revisions).filter(([key]) => key === `snippet:${s.id}`));
  await fails("modx_replace", { find: "return", replacement: "return (", kinds: ["snippet"], expected_revisions: mine }, /lint_failed/);
  step("replace: a result that breaks PHP is refused");

  // Access kinds.
  const group = await run("modx_create", { kind: "user_group", fields: { name: "mcp2test_group", description: "stand" } });
  const role = await run("modx_create", { kind: "role", fields: { name: "mcp2test_role", authority: 9000 } });
  const rgroup = await run("modx_create", { kind: "resource_group", fields: { name: "mcp2test_rgroup" } });
  const groupGot = await run("modx_get", { kind: "user_group", id: group.id });
  assert.equal(groupGot.fields.name, "mcp2test_group");
  assert.deepEqual(groupGot.relations.members, []);
  await run("modx_update", { kind: "role", id: role.id, fields: { description: "updated" } });
  assert.equal((await run("modx_get", { kind: "role", id: role.id })).fields.authority, 9000);
  await fails("modx_create", { kind: "user_group", fields: { name: "mcp2test_group" } }, /validation_failed/);
  const revs = {};
  for (const [kind, id] of [["user_group", group.id], ["role", role.id], ["resource_group", rgroup.id]]) revs[kind] = (await run("modx_get", { kind, id })).revision;
  for (const [kind, id] of [["user_group", group.id], ["role", role.id], ["resource_group", rgroup.id]]) await run("modx_delete", { kind, id });
  await run("modx_undo", { last: 3, confirm: true });
  for (const [kind, id] of [["user_group", group.id], ["role", role.id], ["resource_group", rgroup.id]]) {
    assert.equal((await run("modx_get", { kind, id })).revision, revs[kind], `${kind} restored state differs`);
  }
  const admins = await run("modx_get", { kind: "user_group", id: 1 });
  await fails("modx_delete", { kind: "user_group", id: 1 }, /validation_failed/);
  assert.equal((await run("modx_get", { kind: "user_group", id: 1 })).revision, admins.revision);
  step(`access kinds: create, update, duplicate-name refusal, delete + undo with identical state; group 1 ("${admins.fields.name}", ${admins.relations.members.length} members) is protected`);

  // Maintenance: everything except emptying the trash.
  for (const operation of ["clear_cache", "refresh_uris", "remove_locks", "flush_permissions"]) {
    assert.equal((await run("modx_maintenance", { operation })).performed, true, operation);
  }
  const trash = await run("modx_maintenance", { operation: "empty_trash" });
  assert.equal(trash.performed, false);
  step(`maintenance: four operations ran; empty_trash without confirm did nothing (would remove ${trash.would_remove})`);

  const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.equal(back.performed, true);
  assert.deepEqual((await run("modx_site_info")).counts, before);
  for (const kind of ["chunk", "snippet", "user_group", "role", "resource_group"]) {
    assert.equal((await run("modx_list", { kind, query: "mcp2test_" })).total, 0, `${kind} leftovers`);
  }
  const log1 = await run("modx_read_log", { after_offset: log0.end_offset });
  step(`undo to checkpoint (${back.changesets.length} changesets): nothing left; new error-log entries during the run: ${log1.returned}`);
  for (const entry of log1.entries.slice(-5)) console.log("    log:", JSON.stringify(entry).slice(0, 260));
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
