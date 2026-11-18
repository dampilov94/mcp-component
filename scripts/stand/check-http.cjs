// Stand check: MCP over HTTP straight to the site (task 34), with the official SDK client — no bridge.
// Run by the architect session only, after scripts/stand/deploy.py. The token is never printed.
//   node scripts/stand/check-http.cjs
// Creates one chunk and removes it again by an undo.
const path = require("node:path");
const assert = require("node:assert/strict");
const repo = path.resolve(__dirname, "..", "..");
const { Client } = require(path.join(repo, "node_modules/@modelcontextprotocol/sdk/dist/cjs/client/index.js"));
const { StreamableHTTPClientTransport } = require(path.join(repo, "node_modules/@modelcontextprotocol/sdk/dist/cjs/client/streamableHttp.js"));
const cfg = require(path.join(repo, ".mcp.json")).mcpServers.modx.env;
(async () => {
  const url = new URL(cfg.MODX_MCP_SITE_URL);
  const transport = new StreamableHTTPClientTransport(url, { requestInit: { headers: { "X-MCP-Token": cfg.MODX_MCP_TOKEN } } });
  const client = new Client({ name: "stand-http", version: "0" }, { capabilities: {} });
  const started = Date.now();
  await client.connect(transport);
  const step = (label) => console.log("ok  " + label);
  step(`connected over HTTP in ${Date.now() - started} ms: ${client.getServerVersion().name} ${client.getServerVersion().version}, session ${transport.sessionId ? "label issued" : "none"}`);
  const { tools } = await client.listTools();
  const { prompts } = await client.listPrompts();
  const { resources } = await client.listResources();
  step(`${tools.length} tools, ${prompts.length} prompts, ${resources.length} help resources`);
  const run = async (name, args = {}) => {
    const t = Date.now();
    const result = await client.callTool({ name, arguments: args });
    assert.ok(!result.isError, `${name}: ${result.content[0].text}`);
    return [result.structuredContent, Date.now() - t];
  };
  const [info, ms] = await run("modx_site_info");
  step(`modx_site_info: "${info.site_name}", MODX ${info.modx_version} (${ms} ms)`);
  const [created] = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_http", content: "<p>по HTTP</p>" } });
  const [history] = await run("modx_history", { this_session: true });
  assert.deepEqual(history.changesets.map((c) => c.changeset_id), [created.changeset_id]);
  const [undo] = await run("modx_undo", { changeset_id: created.changeset_id, confirm: true });
  assert.equal(undo.performed, true);
  step(`write, journal under the session label, undo (changesets ${created.changeset_id} → ${undo.changeset_id})`);
  const refused = await client.callTool({ name: "modx_create", arguments: { kind: "snippet", fields: { name: "mcp2test_bad", content: "return (;" } } });
  assert.equal(refused.isError, true);
  assert.ok(!refused.content[0].text.includes(cfg.MODX_MCP_TOKEN));
  step("a refusal comes back as a tool error: " + refused.content[0].text.slice(0, 90));
  const p = await client.getPrompt({ name: "audit", arguments: {} });
  assert.ok(p.messages[0].content.text.length > 200);
  step("prompt audit renders over HTTP");
  await client.close();

  const anon = await fetch(url, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json, text/event-stream" }, body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "ping" }) });
  assert.equal(anon.status, 401);
  const stream = await fetch(url, { headers: { Accept: "text/event-stream" } });
  assert.equal(stream.status, 405);
  step("no token → 401; GET event stream → 405 (on the real web server)");
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
