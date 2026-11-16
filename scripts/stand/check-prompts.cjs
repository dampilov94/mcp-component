// Stand check: MCP prompts (task 33) through the real bridge. Read-only.
// Run by the architect session only, after scripts/stand/deploy.py. The token is never printed.
//   node scripts/stand/check-prompts.cjs
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
  const { prompts } = await client.listPrompts();
  assert.deepEqual(prompts.map((p) => p.name), ["study_site", "audit", "change_page", "new_page_type", "fix_errors"]);
  console.log("ok  prompts: " + prompts.map((p) => `${p.name} «${p.title || p.description}» (${(p.arguments || []).map((a) => a.name + (a.required ? "*" : "")).join(", ")})`).join("; "));
  const page = await client.getPrompt({ name: "change_page", arguments: { page: "О компании", change: "заменить \"телефон\"\nи адрес" } });
  const text = page.messages[0].content.text;
  assert.match(text, /create_draft/);
  assert.match(text, /"заменить \\"телефон\\"\\nи адрес"/);
  assert.match(text, /Никогда не используй `force`/);
  console.log("ok  change_page renders on the site; arguments are quoted, the rules block is there");
  await assert.rejects(client.getPrompt({ name: "change_page", arguments: { page: "x" } }), /validation_failed/);
  await assert.rejects(client.getPrompt({ name: "nope", arguments: {} }), /not_found/);
  console.log("ok  a missing argument and an unknown prompt are MCP errors");
  for (const p of prompts) {
    const args = {};
    for (const a of p.arguments || []) if (a.required) args[a.name] = "тест";
    const r = await client.getPrompt({ name: p.name, arguments: args });
    assert.ok(r.messages[0].content.text.length > 200, p.name);
  }
  console.log("ok  every prompt renders");
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
