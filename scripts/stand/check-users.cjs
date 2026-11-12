// Stand check: kind user and group membership. Run by the architect session only, after
// scripts/stand/deploy.py. Works only on a user and a group named mcp2test_* and undoes everything
// it did. The token and the test password are never printed.
//   node scripts/stand/check-users.cjs
const path = require("node:path");
const assert = require("node:assert/strict");
const crypto = require("node:crypto");
const repo = path.resolve(__dirname, "..", "..");
const { Client } = require(path.join(repo, "node_modules/@modelcontextprotocol/sdk/dist/cjs/client/index.js"));
const { StdioClientTransport } = require(path.join(repo, "node_modules/@modelcontextprotocol/sdk/dist/cjs/client/stdio.js"));
const cfg = require(path.join(repo, ".mcp.json")).mcpServers.modx.env;
(async () => {
  const transport = new StdioClientTransport({ command: process.execPath, args: [path.join(repo, "client", "index.js")],
    env: { ...process.env, MODX_MCP_SITE_URL: cfg.MODX_MCP_SITE_URL, MODX_MCP_TOKEN: cfg.MODX_MCP_TOKEN }, stderr: "inherit" });
  const client = new Client({ name: "stand-check", version: "0" }, { capabilities: {} });
  await client.connect(transport);
  const password = "Mcp2-" + crypto.randomBytes(9).toString("base64url");
  const transcript = [];
  const raw = async (name, args = {}) => {
    const result = await client.callTool({ name, arguments: args });
    transcript.push(JSON.stringify(result));
    return result;
  };
  const run = async (name, args = {}) => {
    const result = await raw(name, args);
    if (result.isError) throw new Error(`${name} -> ${result.content[0].text}`);
    return result.structuredContent;
  };
  const fails = async (name, args, pattern) => {
    const result = await raw(name, args);
    assert.equal(result.isError, true, `${name} should fail`);
    assert.match(result.content[0].text, pattern);
  };
  const step = (label) => console.log("ok  " + label);
  const before = (await run("modx_site_info")).counts;
  if ((await run("modx_list", { kind: "user", query: "mcp2test" })).total) throw new Error("leftover mcp2test user; remove it first");
  const log0 = (await run("modx_read_log", { limit: 1 })).end_offset;

  const users = await run("modx_list", { kind: "user" });
  console.log("    users:", users.items.map((u) => `${u.id}:${u.username}${u.sudo ? "(sudo)" : ""} ${u.email}`).join(" | "));
  // Only refusals are tried on real users, and only changes that must be refused in any case
  // (a sudo user's username), so a broken guard could not alter anything else.
  const sudoUser = users.items.find((u) => u.sudo);
  assert.ok(sudoUser, "no sudo user on the stand");
  const sudoBefore = await run("modx_get", { kind: "user", id: sudoUser.id });
  assert.equal(sudoBefore.fields.password, undefined);
  await fails("modx_update", { kind: "user", id: sudoUser.id, fields: { username: sudoUser.username } , dry_run: true }, /service user|sudo/);
  await fails("modx_delete", { kind: "user", id: sudoUser.id, dry_run: true }, /service user|sudo/);
  assert.equal((await run("modx_get", { kind: "user", id: sudoUser.id })).revision, sudoBefore.revision);
  step(`read the real users; sudo user ${sudoUser.username} is protected`);

  const checkpoint = await run("modx_checkpoint", { label: "users stand check" });
  const created = await run("modx_create", { kind: "user", fields: { username: "mcp2test_user", password, email: "mcp2test@example.invalid", fullname: "Тест Тестов", active: false } });
  const got = await run("modx_get", { kind: "user", id: created.id });
  assert.equal(got.fields.fullname, "Тест Тестов");
  assert.equal(got.fields.email, "mcp2test@example.invalid");
  assert.equal(got.fields.sudo, false);
  assert.equal(got.fields.active, false);
  const upd = await run("modx_update", { kind: "user", id: created.id, fields: { fullname: "Тест Изменён", comment: "stand" }, expected_revision: got.revision });
  assert.deepEqual(upd.changed.sort(), ["comment", "fullname"]);
  const pw = await run("modx_update", { kind: "user", id: created.id, fields: { password: password + "x" } });
  assert.deepEqual(pw.changed, ["password"]);
  const pwDiff = await run("modx_history_diff", { changeset_id: pw.changeset_id });
  assert.equal(JSON.stringify(pwDiff).includes(password), false);
  step(`user #${created.id}: create, profile update, password change (diff hidden)`);

  const group = await run("modx_create", { kind: "user_group", fields: { name: "mcp2test_group" } });
  const added = await run("modx_invoke", { kind: "user_group", id: group.id, op: "add_member", args: { user: created.id } });
  assert.deepEqual((await run("modx_get", { kind: "user", id: created.id })).relations.groups.map((g) => g.user_group), [group.id]);
  const addDiff = await run("modx_history_diff", { changeset_id: added.changeset_id });
  assert.deepEqual(addDiff.objects.map((o) => o.kind).sort(), ["user", "user_group"]);
  const revision = (await run("modx_get", { kind: "user", id: created.id })).revision;
  const del = await run("modx_delete", { kind: "user", id: created.id });
  await fails("modx_get", { kind: "user", id: created.id }, /not_found/);
  await run("modx_undo", { changeset_id: del.changeset_id, confirm: true });
  assert.equal((await run("modx_get", { kind: "user", id: created.id })).revision, revision);
  step("membership journaled on both sides; delete + undo restores the user, profile and membership exactly");

  const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
  assert.equal(back.performed, true);
  assert.equal((await run("modx_list", { kind: "user", query: "mcp2test" })).total, 0);
  assert.deepEqual((await run("modx_site_info")).counts, before);
  assert.equal(transcript.some((t) => t.includes(password)), false, "the password appeared in a tool result");
  const log1 = await run("modx_read_log", { after_offset: log0 });
  step(`undo to checkpoint (${back.changesets.length} changesets): nothing left; password never echoed; new error-log entries: ${log1.returned}`);
  for (const entry of log1.entries.slice(-4)) console.log("    log:", JSON.stringify(entry).slice(0, 240));
  await client.close();
  console.log("ALL OK");
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
