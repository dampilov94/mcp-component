// Removes objects named mcp2test_* that an interrupted stand check left behind.
// Run by the architect session only. Resources go to the MODX trash (they are not purged).
//   node scripts/stand/cleanup.cjs
const path = require("node:path");
const repo = path.resolve(__dirname, "..", "..");
const { Client } = require(path.join(repo, "node_modules/@modelcontextprotocol/sdk/dist/cjs/client/index.js"));
const { StdioClientTransport } = require(path.join(repo, "node_modules/@modelcontextprotocol/sdk/dist/cjs/client/stdio.js"));
const cfg = require(path.join(repo, ".mcp.json")).mcpServers.modx.env;
(async () => {
  const transport = new StdioClientTransport({ command: process.execPath, args: [path.join(repo, "client", "index.js")],
    env: { ...process.env, MODX_MCP_SITE_URL: cfg.MODX_MCP_SITE_URL, MODX_MCP_TOKEN: cfg.MODX_MCP_TOKEN }, stderr: "ignore" });
  const client = new Client({ name: "stand-cleanup", version: "0" }, { capabilities: {} });
  await client.connect(transport);
  const call = async (name, args) => {
    const result = await client.callTool({ name, arguments: args });
    return result.isError ? { error: result.content[0].text } : result.structuredContent;
  };
  let removed = 0;
  for (const kind of ["context_access", "resourcegroup_access", "tv", "chunk", "snippet", "plugin", "template", "category", "resource_group", "user_group", "role", "user", "access_policy", "policy_template", "context_setting", "system_setting", "context", "namespace", "resource"]) {
    const list = await call("modx_list", { kind, query: "mcp2test", limit: 200 });
    for (const item of list.items || []) {
      if (kind === "resource" && item.deleted) continue;
      if (kind === "tv") await call("modx_update", { kind, id: item.id, relations: { templates: [] } });
      const ref = item.key !== undefined ? { key: item.key } : { id: item.id };
      const result = await call("modx_delete", { kind, ...ref });
      console.log(result.error ? `FAILED ${kind} ${item.id}: ${result.error.slice(0, 160)}` : `removed ${kind} ${item.key ?? item.id} (${item.name || item.pagetitle || item.username || item.label || ''})`);
      if (!result.error) removed++;
    }
  }
  console.log(`done, ${removed} removed`);
  await client.close();
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
