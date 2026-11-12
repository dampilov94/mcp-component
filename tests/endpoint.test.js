// Runs the real PHP Kernel (stubbed MODX, SQLite tokens) behind PHP's built-in server and
// exercises the wire protocol directly, then through the Node bridge. No real site involved.
const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const net = require("node:net");
const { spawn, spawnSync } = require("node:child_process");
const { Client } = require("@modelcontextprotocol/sdk/client/index.js");
const { StdioClientTransport } = require("@modelcontextprotocol/sdk/client/stdio.js");

const root = path.resolve(__dirname, "..");
const php = process.env.PHP_BIN || "php";
const harness = path.join(root, "tests", "php", "endpoint.php");
const modules = spawnSync(php, ["-m"], { encoding: "utf8" });
const available = modules.status === 0 && /pdo_sqlite/i.test(modules.stdout);

function freePort() {
  return new Promise((resolve) => {
    const probe = net.createServer();
    probe.listen(0, "127.0.0.1", () => { const { port } = probe.address(); probe.close(() => resolve(port)); });
  });
}

async function startSite(env = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "modxmcp-endpoint-"));
  const setup = spawnSync(php, [harness, "setup", dir], { encoding: "utf8" });
  assert.equal(setup.status, 0, setup.stderr);
  const info = JSON.parse(setup.stdout);
  const port = await freePort();
  const child = spawn(php, ["-S", `127.0.0.1:${port}`, harness], { env: { ...process.env, MODXMCP_TEST_DIR: dir, ...env }, stdio: "ignore" });
  const url = `http://127.0.0.1:${port}/api.php`;
  for (let attempt = 0; ; attempt++) {
    try { await fetch(url); break; } catch (error) {
      if (attempt > 50) throw error;
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
  }
  const post = async (token, body, headers = {}) => {
    const response = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", ...(token ? { "X-MCP-Token": token } : {}), ...headers },
      body: typeof body === "string" ? body : JSON.stringify(body),
    });
    return { status: response.status, headers: response.headers, body: await response.json() };
  };
  const stop = () => { child.kill(); fs.rmSync(dir, { recursive: true, force: true }); };
  return { url, info, post, stop, dir };
}

test("kernel: wire protocol", { skip: available ? false : "PHP with pdo_sqlite is not available" }, async (t) => {
  const site = await startSite();
  t.after(site.stop);
  const { admin, read, revoked, expired } = site.info.tokens;
  const call = (token, action, args = {}, extra = {}) => site.post(token, { v: 2, action, args, ...extra });

  await t.test("tokens are stored only as hashes", () => {
    assert.equal(site.info.listed, 4);
    assert.equal(site.info.leaks, false);
    assert.equal(site.info.stored_plain, 0);
    assert.match(admin, /^mcp_[0-9a-f]{64}$/);
  });

  await t.test("GET is an unauthenticated health probe with no stray output", async () => {
    const response = await fetch(site.url);
    assert.equal(response.status, 200);
    assert.deepEqual(await response.json(), { component: "modxMCP", version: "2.0.0", protocol: 2, enabled: true });
  });

  await t.test("authentication", async () => {
    for (const token of [null, "mcp_" + "0".repeat(64), revoked, expired, "x"]) {
      const response = await call(token, "site_info");
      assert.equal(response.status, 401);
      assert.deepEqual(response.body, { ok: false, error: { code: "unauthorized", message: "Unauthorized" } });
    }
    const bearer = await site.post(null, { v: 2, action: "site_info", args: {} }, { Authorization: `Bearer ${read}` });
    assert.equal(bearer.status, 200);
  });

  await t.test("request validation", async () => {
    const cases = [
      [{ action: "site_info", args: {} }, 400, "unsupported_protocol"],
      [{ v: 1, action: "site_info", args: {} }, 400, "unsupported_protocol"],
      [{ v: 2, action: "site_info", args: {}, data: {} }, 400, "validation_failed"],
      [{ v: 2, action: "Bad Action", args: {} }, 400, "validation_failed"],
      [{ v: 2, action: "site_info", args: [] }, 400, "validation_failed"],
      [{ v: 2, action: "site_info", args: { extra: 1 } }, 400, "validation_failed"],
      [{ v: 2, action: "nope", args: {} }, 400, "unknown_action"],
      [{ v: 2, action: "site_info", args: {}, request_id: "short" }, 400, "validation_failed"],
      [{ v: 2, action: "request_status", args: {} }, 400, "validation_failed"],
      ["{not json", 400, "invalid_json"],
      ["[]", 400, "validation_failed"],
      [{ v: 2, action: "help", args: { topic: "x".repeat(5000) } }, 413, "request_too_large"],
    ];
    for (const [body, status, code] of cases) {
      const response = await site.post(admin, body);
      assert.equal(response.status, status, JSON.stringify(body).slice(0, 80));
      assert.equal(response.body.error.code, code, JSON.stringify(body).slice(0, 80));
    }
    const text = await site.post(admin, { v: 2, action: "site_info", args: {} }, { "Content-Type": "text/plain" });
    assert.equal(text.body.error.code, "unsupported_media_type");
    const put = await fetch(site.url, { method: "PUT", headers: { "X-MCP-Token": admin } });
    assert.equal(put.status, 405);
    assert.equal(put.headers.get("allow"), "GET, POST");
  });

  await t.test("manifest lists tools with contracts and a matching hash", async () => {
    const response = await call(read, "manifest");
    assert.equal(response.status, 200);
    const { result, meta } = response.body;
    assert.equal(result.protocol, 2);
    assert.deepEqual(result.tools.map((item) => item.name), [
      "modx_capabilities", "modx_site_info", "modx_describe", "modx_help", "modx_list", "modx_get",
      "modx_resource_tree", "modx_view_code", "modx_search", "modx_find_usages", "modx_list_files", "modx_read_file", "modx_history", "modx_history_diff",
      "modx_request_status",
    ]);
    const full = (await call(admin, "manifest")).body.result;
    assert.equal(full.tools.length, 27);
    assert.equal(full.tools[full.tools.findIndex((item) => item.name === "modx_delete") + 1].name, "modx_duplicate");
    assert.equal(full.tools.find((item) => item.name === "modx_duplicate").effect.read_only, false);
    assert.notEqual(full.hash, result.hash);
    assert.equal(full.tools.find((item) => item.name === "modx_delete").effect.destructive, true);
    assert.deepEqual(result.token, { label: "reader", scope: "read", plan_only: false });
    assert.equal(meta.manifest, result.hash);
    assert.deepEqual(result.tools[1].inputSchema, { type: "object", properties: {}, additionalProperties: false });
    assert.ok(result.error_codes.includes("revision_conflict"));
  });

  await t.test("actions", async () => {
    const info = await call(read, "site_info");
    assert.equal(info.body.result.site_name, "Test site");
    assert.equal(info.body.result.modx_version, "2.8.8-pl");
    assert.equal(info.body.result.counts.chunks, 0);

    const caps = await call(read, "capabilities");
    assert.equal(caps.body.result.token.scope, "read");
    assert.ok(caps.body.result.tools.includes("modx_describe"));
    assert.ok(caps.body.result.kinds.includes("chunk"));
    assert.equal(caps.body.result.integrations.length, 3);

    const kinds = await call(read, "describe");
    assert.equal(kinds.body.result.kinds.length, 8);
    const plugin = await call(read, "describe", { kind: "plugin" });
    assert.ok(plugin.body.result.fields.some((field) => field.name === "content" && field.code));
    const missing = await call(read, "describe", { kind: "nope" });
    assert.equal(missing.status, 404);
    assert.equal(missing.body.error.code, "not_found");
    assert.ok(missing.body.meta.manifest);

    const help = await call(read, "help");
    assert.equal(help.body.result.topic, "index");

    const status = await call(read, "request_status", { request_id: "never-used-request-1" });
    assert.deepEqual(status.body.result, { request_id: "never-used-request-1", state: "not_found" });
  });

  await t.test("writes: scope, journal and request-ID replay", async () => {
    const denied = await call(read, "create", { kind: "chunk", fields: { name: "x" } });
    assert.equal(denied.status, 403);
    assert.equal(denied.body.error.code, "forbidden_scope");

    const request = { request_id: "create-request-00001", session_id: "session-0000000001" };
    const created = await call(admin, "create", { kind: "chunk", fields: { name: "hero", content: "<b>hi</b>" } }, request);
    assert.equal(created.status, 200);
    assert.equal(created.body.result.id, 1);
    assert.equal(created.body.result.changeset_id, 1);

    // The same ID with the same arguments replays the stored response and creates nothing.
    const replay = await call(admin, "create", { kind: "chunk", fields: { name: "hero", content: "<b>hi</b>" } }, request);
    assert.equal(replay.headers.get("x-mcp-replayed"), "1");
    assert.deepEqual(replay.body, created.body);
    assert.equal((await call(admin, "list", { kind: "chunk" })).body.result.total, 1);
    const reused = await call(admin, "create", { kind: "chunk", fields: { name: "other" } }, request);
    assert.equal(reused.status, 409);
    assert.equal(reused.body.error.code, "request_state_error");
    const status = await call(admin, "request_status", { request_id: request.request_id, include_result: true });
    assert.equal(status.body.result.state, "completed");
    assert.equal(status.body.result.response.result.id, 1);

    const lint = await call(admin, "create", { kind: "snippet", fields: { name: "bad", content: "return (;" } });
    assert.equal(lint.status, 422);
    assert.equal(lint.body.error.code, "lint_failed");
    assert.equal(lint.body.error.details.line, 1);

    const stale = await call(admin, "update", { kind: "chunk", id: 1, fields: { description: "x" }, expected_revision: "0".repeat(64) });
    assert.equal(stale.status, 409);
    assert.equal(stale.body.error.code, "revision_conflict");
    assert.equal(stale.body.error.details.current_revision, created.body.result.revision);

    const history = await call(admin, "history", { this_session: true }, { session_id: "session-0000000001" });
    assert.deepEqual(history.body.result.changesets.map((item) => item.tool), ["create"]);
    const undone = await call(admin, "undo", { changeset_id: 1, confirm: true });
    assert.equal(undone.body.result.performed, true);
    assert.equal((await call(admin, "list", { kind: "chunk" })).body.result.total, 0);
  });

  await t.test("request and session IDs are accepted on reads and echoed", async () => {
    const response = await call(read, "site_info", {}, { request_id: "read-request-000001", session_id: "session-0000000001" });
    assert.equal(response.status, 200);
    assert.equal(response.body.meta.request_id, "read-request-000001");
    assert.equal(response.headers.get("x-mcp-replayed"), null);
  });
});

test("kernel: site switches", { skip: available ? false : "PHP with pdo_sqlite is not available" }, async (t) => {
  await t.test("IP allowlist rejects other peers before authentication", async () => {
    const site = await startSite({ MODXMCP_TEST_ALLOWED_IPS: "10.9.9.9" });
    try {
      const response = await site.post(site.info.tokens.admin, { v: 2, action: "site_info", args: {} });
      assert.equal(response.status, 403);
      assert.equal(response.body.error.code, "ip_not_allowed");
    } finally { site.stop(); }
  });
});

test("bridge + kernel end to end", { skip: available ? false : "PHP with pdo_sqlite is not available" }, async (t) => {
  const site = await startSite();
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: [path.join(root, "client", "index.js")],
    env: { ...process.env, MODX_MCP_SITE_URL: site.url, MODX_MCP_TOKEN: site.info.tokens.admin, MODX_MCP_TIMEOUT_MS: "5000" },
    stderr: "ignore",
  });
  const client = new Client({ name: "test", version: "0" }, { capabilities: {} });
  await client.connect(transport);
  t.after(async () => { await client.close(); site.stop(); });

  const { tools } = await client.listTools();
  assert.equal(tools.length, 27);
  const run = async (name, args = {}) => {
    const result = await client.callTool({ name, arguments: args });
    assert.equal(result.isError, undefined, `${name}: ${result.content[0].text}`);
    return result.structuredContent;
  };
  for (const name of ["modx_capabilities", "modx_site_info", "modx_describe", "modx_help", "modx_history"]) await run(name);
  await run("modx_request_status", { request_id: "never-used-request-1" });

  // A whole edit cycle through the bridge; every result is checked against its output schema.
  const created = await run("modx_create", { kind: "snippet", fields: { name: "year", content: "return date('Y');" } });
  const view = await run("modx_view_code", { kind: "snippet", id: created.id });
  assert.equal(view.numbered, "1\treturn date('Y');");
  const edited = await run("modx_edit_code", {
    kind: "snippet", id: created.id, expected_revision: view.revision,
    edits: [{ start_line: 1, replacement: "return date('Y-m');", expect: "return date('Y');" }],
  });
  assert.deepEqual(edited.changed, ["content"]);
  const got = await run("modx_get", { kind: "snippet", name: "year" });
  assert.equal(got.fields.content, "return date('Y-m');");
  assert.equal(got.revision, edited.revision);
  assert.equal((await run("modx_list", { kind: "snippet" })).total, 1);
  const diff = await run("modx_history_diff", { changeset_id: edited.changeset_id });
  assert.match(diff.objects[0].changed.content.diff, /^@@ -1,1 \+1,1 @@/);
  await run("modx_checkpoint", { label: "before delete" });
  const dry = await run("modx_delete", { kind: "snippet", id: created.id, dry_run: true });
  assert.equal(dry.dry_run, true);
  await run("modx_delete", { kind: "snippet", id: created.id });
  const undo = await run("modx_undo", { last: 1, confirm: true });
  assert.equal(undo.objects[0].action, "recreate");
  assert.equal((await run("modx_get", { kind: "snippet", id: created.id })).revision, edited.revision);
  const broken = await client.callTool({ name: "modx_update", arguments: { kind: "snippet", id: created.id, fields: { content: "return (;" } } });
  assert.match(broken.content[0].text, /MODX lint_failed \(HTTP 422\): PHP syntax error on line 1/);
  const failed = await client.callTool({ name: "modx_describe", arguments: { kind: "nope" } });
  assert.match(failed.content[0].text, /MODX not_found \(HTTP 404\)/);
  const { resources } = await client.listResources();
  assert.ok(resources.some((item) => item.uri === "modx-help://index"));
});
