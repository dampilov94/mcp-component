// Stand check: system_setting, context, context_setting, namespace. Run by the architect session only,
// after scripts/stand/deploy.py. Works only on objects whose key starts with mcp2test and undoes
// everything it did. The token is never printed.
//   node scripts/stand/check-settings.cjs
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
  const caps = await run("modx_capabilities");
  console.log("    kinds:", caps.kinds.join(", "), "| unavailable:", caps.unavailable_kinds.map((k) => `${k.name}(${k.reason})`).join(", "));
  for (const kind of ["system_setting", "context", "namespace"]) {
    if ((await run("modx_list", { kind, query: "mcp2test" })).total) throw new Error(`leftover mcp2test ${kind}; remove it first`);
  }
  const log0 = (await run("modx_read_log", { limit: 1 })).end_offset;

  // Reading the real configuration.
  const settings = await run("modx_list", { kind: "system_setting", limit: 5 });
  const siteName = await run("modx_get", { kind: "system_setting", key: "site_name" });
  assert.equal(siteName.key, "site_name");
  assert.equal((await run("modx_get", { kind: "system_setting", key: "site_name" })).revision, siteName.revision);
  const own = await run("modx_get", { kind: "system_setting", key: "modxmcp.debug" });
  const web = await run("modx_get", { kind: "context", key: "web" });
  const ctxSettings = await run("modx_list", { kind: "context_setting", where: { context_key: "web" } });
  const namespaces = await run("modx_list", { kind: "namespace", limit: 50 });
  step(`read: ${settings.total} system settings; site_name="${siteName.fields.value}"; web has ${web.relations.settings.length} settings; ${ctxSettings.total} web context settings; ${namespaces.total} namespaces`);

  // modxMCP's own settings are read-only, even for an admin token (sending the current value, so nothing could change).
  await fails("modx_update", { kind: "system_setting", key: "modxmcp.debug", fields: { value: own.fields.value } }, /changed in the MODX manager/);
  await fails("modx_delete", { kind: "system_setting", key: "modxmcp.debug", dry_run: true }, /changed in the MODX manager/);
  await fails("modx_create", { kind: "system_setting", fields: { key: "modxmcp.mcp2test", value: "x" } }, /changed in the MODX manager/);
  await fails("modx_create", { kind: "context_setting", fields: { context_key: "mgr", key: "modxmcp.allowed_ips", value: "" } }, /changed in the MODX manager/);
  assert.equal((await run("modx_get", { kind: "system_setting", key: "modxmcp.debug" })).revision, own.revision);
  step("modxMCP settings: update, delete, create and a mgr context override are all refused");

  const checkpoint = await run("modx_checkpoint", { label: "settings stand check" });
  const created = await run("modx_create", { kind: "system_setting", fields: { key: "mcp2test_setting", value: "Привет", area: "mcp2test" } });
  assert.equal(created.key, "mcp2test_setting");
  const got = await run("modx_get", { kind: "system_setting", key: "mcp2test_setting" });
  assert.equal(got.fields.value, "Привет");
  assert.equal(got.fields.namespace, "core");
  const upd = await run("modx_update", { kind: "system_setting", key: "mcp2test_setting", fields: { value: "Пока" }, expected_revision: got.revision });
  assert.deepEqual(upd.changed, ["value"]);
  await fails("modx_update", { kind: "system_setting", key: "mcp2test_setting", fields: { key: "mcp2test_other" } }, /cannot be changed after creation/);
  const secret = await run("modx_create", { kind: "system_setting", fields: { key: "mcp2test_api_key", value: "s3cr3t", area: "mcp2test" } });
  const secretGot = await run("modx_get", { kind: "system_setting", key: "mcp2test_api_key" });
  assert.equal(secretGot.secret, true);
  assert.equal(secretGot.fields.value, "s3cr3t", "an admin token sees the value");
  const secretUpd = await run("modx_update", { kind: "system_setting", key: "mcp2test_api_key", fields: { value: "n3w" } });
  const secretDiff = await run("modx_history_diff", { changeset_id: secretUpd.changeset_id });
  assert.deepEqual(secretDiff.objects[0].changed.value, { old: "[hidden]", new: "[hidden]" });
  step("system_setting: create, update with revision, key fixed, credential flagged and hidden in the diff");

  const ns = await run("modx_create", { kind: "namespace", fields: { key: "mcp2test_ns", path: "{core_path}components/mcp2test_ns/" } });
  assert.equal(ns.key, "mcp2test_ns");
  const ctx = await run("modx_create", { kind: "context", fields: { key: "mcp2test", name: "MCP test" } });
  const cs = await run("modx_create", { kind: "context_setting", fields: { context_key: "mcp2test", key: "site_url", value: "https://mcp2test.example/", namespace: "core" } });
  assert.equal(cs.key, "mcp2test:site_url");
  const ctxGot = await run("modx_get", { kind: "context", key: "mcp2test" });
  assert.ok(ctxGot.relations.settings.some((r) => r.key === "site_url"));
  const ctxRevision = ctxGot.revision;
  const csRevision = (await run("modx_get", { kind: "context_setting", key: "mcp2test:site_url" })).revision;
  const del = await run("modx_delete", { kind: "context", key: "mcp2test" });
  assert.deepEqual((await run("modx_history_diff", { changeset_id: del.changeset_id })).objects.map((o) => o.kind), ["context", "context_setting"]);
  await fails("modx_get", { kind: "context_setting", key: "mcp2test:site_url" }, /not_found/);
  await run("modx_undo", { changeset_id: del.changeset_id, confirm: true });
  assert.equal((await run("modx_get", { kind: "context", key: "mcp2test" })).revision, ctxRevision);
  assert.equal((await run("modx_get", { kind: "context_setting", key: "mcp2test:site_url" })).revision, csRevision);
  await fails("modx_delete", { kind: "context", key: "web", dry_run: true }, /validation_failed/);
  step("namespace, context and context setting created; context delete took its settings, undo restored both exactly; web is protected");

  const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.equal(back.performed, true);
  for (const kind of ["system_setting", "context", "namespace"]) {
    assert.equal((await run("modx_list", { kind, query: "mcp2test" })).total, 0, `${kind} leftovers`);
  }
  assert.equal((await run("modx_get", { kind: "system_setting", key: "site_name" })).revision, siteName.revision);
  const log1 = await run("modx_read_log", { after_offset: log0 });
  step(`undo to checkpoint (${back.changesets.length} changesets): nothing left; new error-log entries: ${log1.returned}`);
  for (const entry of log1.entries.slice(-4)) console.log("    log:", JSON.stringify(entry).slice(0, 240));
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
