// Stand check: access_policy, policy_template, context_access, resourcegroup_access. Run by the
// architect session only, after scripts/stand/deploy.py. On real access rules it only tries
// refusals (dry runs); everything it creates is named mcp2test* and undone at the end.
//   node scripts/stand/check-access.cjs
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
  const log0 = (await run("modx_read_log", { limit: 1 })).end_offset;
  for (const kind of ["access_policy", "user_group", "context"]) {
    if ((await run("modx_list", { kind, query: "mcp2test" })).total) throw new Error(`leftover mcp2test ${kind}; remove it first`);
  }

  // Read the real configuration.
  const policies = await run("modx_list", { kind: "access_policy", limit: 100 });
  const templates = await run("modx_list", { kind: "policy_template", limit: 100 });
  const ctxRules = await run("modx_list", { kind: "context_access", limit: 100 });
  const rgRules = await run("modx_list", { kind: "resourcegroup_access", limit: 100 });
  console.log(`    ${policies.total} policies, ${templates.total} templates, ${ctxRules.total} context rules, ${rgRules.total} resource-group rules`);
  console.log("    context rules:", ctxRules.items.map((r) => r.label).join(" | ").slice(0, 400));
  const adminPolicy = policies.items.find((p) => p.name === "Administrator");
  const adminGot = await run("modx_get", { kind: "access_policy", id: adminPolicy.id });
  assert.ok(Object.keys(adminGot.fields.permissions).length > 50);
  const adminRule = ctxRules.items.find((r) => r.principal === 1);
  const adminRuleBefore = await run("modx_get", { kind: "context_access", id: adminRule.id });
  step(`read: Administrator policy has ${Object.keys(adminGot.fields.permissions).length} permissions`);

  // Safety rules on the real configuration: refusals only, as dry runs where the tool has one.
  await fails("modx_update", { kind: "access_policy", id: adminPolicy.id, fields: { description: adminGot.fields.description }, dry_run: true }, /validation_failed/);
  await fails("modx_delete", { kind: "access_policy", id: adminPolicy.id, dry_run: true }, /validation_failed/);
  await fails("modx_update", { kind: "context_access", id: adminRule.id, fields: { authority: adminRuleBefore.fields.authority }, dry_run: true }, /Administrator group/);
  await fails("modx_delete", { kind: "context_access", id: adminRule.id, dry_run: true }, /Administrator group/);
  await fails("modx_create", { kind: "context_access", fields: { target: "web", principal: 1, authority: 0, policy: adminPolicy.id }, dry_run: true }, /Administrator group/);
  const builtinTemplate = templates.items.find((t) => t.name === "ResourceTemplate");
  await fails("modx_delete", { kind: "policy_template", id: builtinTemplate.id, dry_run: true }, /validation_failed/);
  assert.equal((await run("modx_get", { kind: "access_policy", id: adminPolicy.id })).revision, adminGot.revision);
  assert.equal((await run("modx_get", { kind: "context_access", id: adminRule.id })).revision, adminRuleBefore.revision);
  step("built-in policy, template and the Administrator group's rules are all refused, unchanged");

  const checkpoint = await run("modx_checkpoint", { label: "access stand check" });
  const policy = await run("modx_create", { kind: "access_policy", fields: { name: "mcp2test_policy", template: builtinTemplate.id, permissions: { view: true, save: false } } });
  const policyGot = await run("modx_get", { kind: "access_policy", id: policy.id });
  assert.equal(policyGot.fields.permissions.view, true);
  assert.equal(policyGot.fields.permissions.save, false);
  const perm = await run("modx_update", { kind: "access_policy", id: policy.id, fields: { permissions: { view: true, save: true } } });
  assert.deepEqual(perm.changed, ["permissions"]);
  const group = await run("modx_create", { kind: "user_group", fields: { name: "mcp2test_group" } });
  const context = await run("modx_create", { kind: "context", fields: { key: "mcp2test", name: "MCP test" } });
  const rule = await run("modx_create", { kind: "context_access", fields: { target: "mcp2test", principal: group.id, authority: 9999, policy: policy.id } });
  const ruleGot = await run("modx_get", { kind: "context_access", id: rule.id });
  console.log("    new rule:", ruleGot.fields.label);
  const ruleDiff = await run("modx_history_diff", { changeset_id: rule.changeset_id });
  console.log("    rule create journaled:", ruleDiff.objects.map((o) => `${o.kind}#${o.id}`).join(", "));
  await fails("modx_delete", { kind: "access_policy", id: policy.id, dry_run: true }, /still used by 1 access rule/);
  const del = await run("modx_delete", { kind: "context_access", id: rule.id });
  await run("modx_undo", { changeset_id: del.changeset_id, confirm: true });
  assert.equal((await run("modx_get", { kind: "context_access", id: rule.id })).revision, ruleGot.revision);
  step("policy with permissions, context rule for a test group; a used policy cannot be deleted; rule delete + undo exact");

  const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.equal(back.performed, true, JSON.stringify(back).slice(0, 400));
  for (const kind of ["access_policy", "user_group", "context"]) {
    assert.equal((await run("modx_list", { kind, query: "mcp2test" })).total, 0, `${kind} leftovers`);
  }
  assert.equal((await run("modx_list", { kind: "context_access", limit: 100 })).total, ctxRules.total);
  assert.equal((await run("modx_get", { kind: "access_policy", id: adminPolicy.id })).revision, adminGot.revision);
  const log1 = await run("modx_read_log", { after_offset: log0 });
  step(`undo to checkpoint (${back.changesets.length} changesets): nothing left, rule count as before; new error-log entries: ${log1.returned}`);
  for (const entry of log1.entries.slice(-4)) console.log("    log:", JSON.stringify(entry).slice(0, 240));
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
