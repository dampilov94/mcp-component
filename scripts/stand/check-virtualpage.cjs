// Stand check: VirtualPage events, handlers, routes, resolve, and a real request served by a route.
// Needs the virtualpage group enabled in Components > modxMCP. Run by the architect session only, after
// scripts/stand/deploy.py. Everything it creates is named mcp2test* and undone at the end.
//   node scripts/stand/check-virtualpage.cjs
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
  const step = (label) => console.log("ok  " + label);
  const caps = await run("modx_capabilities");
  assert.ok(caps.kinds.includes("vp_route"), "enable the virtualpage group in Components > modxMCP: " + JSON.stringify(caps.unavailable_kinds.filter((k) => k.name.startsWith("vp_"))));
  const before = (await run("modx_site_info")).counts;
  const log0 = (await run("modx_read_log", { limit: 1 })).end_offset;
  for (const kind of ["vp_handler", "chunk"]) {
    if ((await run("modx_list", { kind, query: "mcp2test" })).total) throw new Error(`leftover mcp2test ${kind}; remove it first`);
  }
  const events = await run("modx_list", { kind: "vp_event" });
  const routes = await run("modx_list", { kind: "vp_route" });
  console.log(`    ${events.total} events (${events.items.map((e) => e.name).join(", ")}), ${routes.total} routes`);
  const plugin = (await run("modx_list", { kind: "plugin", query: "vpEvent" })).items[0];
  console.log("    VirtualPage plugin:", plugin ? `${plugin.name} #${plugin.id}` : "none");

  const checkpoint = await run("modx_checkpoint", { label: "virtualpage stand check" });
  const chunk = await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_vp_page", content: "<!doctype html><title>MCP VP [[!+vp.id]]</title><p>Каталог: [[!+vp.id]]</p>", static: false } });
  let event = events.items.find((e) => e.name === "OnPageNotFound");
  if (!event) event = await run("modx_create", { kind: "vp_event", fields: { name: "OnPageNotFound" } });
  const handler = await run("modx_create", { kind: "vp_handler", fields: { name: "mcp2test_handler", type: "chunk", entry: chunk.id } });
  const route = await run("modx_create", { kind: "vp_route", fields: { route: "/mcp2test-catalog/{id:\\d+}", handler: handler.id, event: event.id } });
  const routeDiff = await run("modx_history_diff", { changeset_id: route.changeset_id });
  console.log("    route create journaled:", routeDiff.objects.map((o) => `${o.kind}#${o.id ?? o.key}${o.changed && o.changed.events ? " (events)" : ""}`).join(", "));
  const resolved = await run("modx_invoke", { kind: "vp_route", op: "resolve", args: { path: "/mcp2test-catalog/5" } });
  assert.ok(resolved.matches.length >= 1, JSON.stringify(resolved));
  console.log("    resolve:", JSON.stringify(resolved).slice(0, 260));
  const page = await run("modx_check_page", { url_path: "/mcp2test-catalog/5" });
  console.log(`    check_page: ${page.status}, title "${page.title}", new log entries ${page.log.new_entries}`);
  assert.equal(page.status, 200, "VirtualPage did not answer the route");
  assert.equal(page.title, "MCP VP 5");
  for (const entry of page.log.entries.slice(0, 3)) console.log("    log:", JSON.stringify(entry).slice(0, 240));
  step("event, handler on a chunk and a route created; resolve and a real request done");

  const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.equal(back.performed, true, JSON.stringify(back).slice(0, 500));
  assert.deepEqual((await run("modx_site_info")).counts, before);
  assert.equal((await run("modx_list", { kind: "vp_route" })).total, routes.total);
  const gone = await run("modx_check_page", { url_path: "/mcp2test-catalog/5" });
  assert.equal(gone.status, 404);
  const log1 = await run("modx_read_log", { after_offset: log0 });
  step(`undo to checkpoint (${back.changesets.length} changesets): route gone, the URL is 404 again; new error-log entries: ${log1.returned}`);
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
