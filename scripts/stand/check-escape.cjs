// Stand check: modx_run_processor and modx_query_db. Needs both settings turned on in the manager
// (modxmcp.allow_run_processor, modxmcp.allow_query_db); turn them off again afterwards.
// Run by the architect session only, after scripts/stand/deploy.py. Read-only on the site except
// for one harmless processor run (a cache refresh). The token is never printed.
//   node scripts/stand/check-escape.cjs
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
  const tools = (await client.listTools()).tools.map((t) => t.name);
  assert.ok(tools.includes("modx_run_processor") || tools.includes("modx_query_db"), "turn the settings on in the manager first");
  if (tools.includes("modx_query_db")) await checkQueryDb(); else console.log("--  query_db: setting off, skipped");
  if (tools.includes("modx_run_processor")) await checkRunProcessor(); else console.log("--  run_processor: setting off, skipped");
  await client.close();
  console.log("ALL OK — now turn modxmcp.allow_run_processor and modxmcp.allow_query_db off again");

  async function checkQueryDb() {
  // query_db on real MySQL.
  const q = await run("modx_query_db", { sql: "SELECT id, pagetitle FROM {prefix}site_content WHERE published = ? ORDER BY id", params: [1], limit: 3 });
  assert.deepEqual(q.columns, ["id", "pagetitle"]);
  assert.equal(q.returned, 3);
  assert.equal(q.truncated, true);
  const withQ = await run("modx_query_db", { sql: "WITH t AS (SELECT COUNT(*) AS n FROM {prefix}site_htmlsnippets) SELECT n FROM t" });
  assert.ok(Number(withQ.rows[0][0]) > 0);
  step(`query_db: SELECT with params (${q.time_ms} ms) and WITH work on MySQL; first page "${q.rows[0][1]}"`);
  for (const sql of [
    "DELETE FROM {prefix}site_content WHERE id = 0",
    "SELECT 1; DELETE FROM {prefix}site_content WHERE id = 0",
    "SELECT * FROM {prefix}site_content INTO OUTFILE '/tmp/x'",
    "SELECT password FROM {prefix}users",
    "SELECT hash FROM {prefix}modxmcp_tokens",
    "SELECT * FROM mysql.user",
    "SELECT SLEEP(5)",
  ]) await fails("modx_query_db", { sql }, /forbidden_scope|validation_failed/);
  const counts = (await run("modx_site_info")).counts;
  step("query_db: writes, second statements, files, password/token tables, system schemas and SLEEP are refused");
  // The read-only transaction must not leak into the connection: a write through the API still works afterwards.
  const checkpoint = await run("modx_checkpoint", { label: "escape stand check" });
  const chunk = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_after_query", content: "x" } });
  await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.deepEqual((await run("modx_site_info")).counts, counts);
  step(`a write in the same session after query_db works (chunk #${chunk.id}, undone)`);
  }

  async function checkRunProcessor() {
  // run_processor.
  await fails("modx_run_processor", { processor: "security/user/getlist", confirm: true }, /kind "user"/);
  await fails("modx_run_processor", { processor: "system/settings/update", confirm: true }, /system_setting/);
  await fails("modx_run_processor", { processor: "resource/emptyrecyclebin" }, /confirm/);
  const plan = await run("modx_run_processor", { processor: "element/chunk/getlist" });
  assert.equal(plan.performed, false);
  const list = await run("modx_run_processor", { processor: "element/chunk/getlist", properties: { limit: 2 }, confirm: true });
  assert.equal(list.performed, true);
  assert.equal(list.success, true);
  const bad = await run("modx_run_processor", { processor: "element/chunk/get", properties: { id: 999999 }, confirm: true });
  assert.equal(bad.success, false);
  step(`run_processor: refusals name the dedicated tool; plan without confirm; getlist ran (${JSON.stringify(list.object).slice(0, 80)}…); a processor error is success: false`);
  const hist = await run("modx_history", { limit: 3 });
  assert.ok(hist.changesets.some((c) => c.tool === "run_processor" && c.reversible === false));
  step("run_processor calls are in the history, marked irreversible");
  }
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
