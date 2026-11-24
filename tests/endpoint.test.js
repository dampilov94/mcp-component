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
  const setup = spawnSync(php, [harness, "setup", dir], { encoding: "utf8", env: { ...process.env, ...env } });
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
    assert.equal(site.info.listed, 5);
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
    const unauthenticated = await site.post(null, "x".repeat(5000), { "MCP-Protocol-Version": "2025-11-25" });
    assert.equal(unauthenticated.status, 401);
    assert.equal(unauthenticated.body.error.data.code, "unauthorized");
    const forbidden = await site.post(read, "{invalid", { "MCP-Protocol-Version": "2025-11-25", Origin: "https://other.example" });
    assert.equal(forbidden.status, 403);
    assert.equal(forbidden.body.error.data.code, "origin_not_allowed");
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
      "modx_invoke", "modx_resource_tree", "modx_view_code", "modx_search", "modx_find_usages", "modx_dependency_graph", "modx_list_files", "modx_read_file", "modx_search_files", "modx_history", "modx_history_diff",
      "modx_check_page", "modx_audit_site", "modx_request_status",
    ]);
    const full = (await call(admin, "manifest")).body.result;
    assert.equal(full.tools.length, 37);
    assert.equal(full.tools[full.tools.findIndex((item) => item.name === "modx_delete") + 1].name, "modx_duplicate");
    assert.equal(full.tools.find((item) => item.name === "modx_duplicate").effect.read_only, false);
    assert.notEqual(full.hash, result.hash);
    assert.equal(full.tools.find((item) => item.name === "modx_delete").effect.destructive, true);
    assert.deepEqual(result.token, { label: "reader", scope: "read", plan_only: false });
    assert.equal(meta.manifest, result.hash);
    assert.deepEqual(result.tools[1].inputSchema, { type: "object", properties: {}, additionalProperties: false });
    assert.ok(result.error_codes.includes("revision_conflict"));
  });

  await t.test("prompts: the manifest lists what the token may use and the prompt action renders them", async () => {
    const names = async (token) => (await call(token, "manifest")).body.result.prompts.map((item) => item.name);
    assert.deepEqual(await names(read), ["study_site", "audit"]);
    assert.deepEqual(await names(admin), ["study_site", "audit", "change_page", "new_page_type", "fix_errors"]);
    assert.notEqual((await call(read, "manifest")).body.result.hash, (await call(admin, "manifest")).body.result.hash);
    const ok = await call(read, "prompt", { name: "audit", arguments: { focus: "ссылки" } });
    assert.equal(ok.status, 200);
    assert.equal(ok.body.result.messages[0].role, "user");
    assert.match(ok.body.result.messages[0].content.text, /"ссылки"/);
    assert.match(ok.body.result.messages[0].content.text, /`modx_undo`/);
    assert.equal(ok.body.meta.manifest, (await call(read, "manifest")).body.result.hash);
    const denied = await call(read, "prompt", { name: "change_page", arguments: { page: "1", change: "x" } });
    assert.equal(denied.status, 403);
    assert.equal(denied.body.error.code, "unavailable");
    assert.equal(denied.body.error.details.reason, "forbidden_scope");
    assert.equal((await call(admin, "prompt", { name: "nope" })).body.error.code, "not_found");
    assert.equal((await call(admin, "prompt", { name: "change_page", arguments: { page: "1" } })).body.error.code, "validation_failed");
    assert.equal((await call(admin, "prompt", { name: "audit", arguments: { focus: "я".repeat(2001) } })).status, 400);
  });

  await t.test("run_processor and query_db are off by default", async () => {
    for (const [action, args] of [["run_processor", { processor: "resource/getlist", confirm: true }], ["query_db", { sql: "SELECT 1" }]]) {
      const response = await call(admin, action, args);
      assert.equal(response.status, 403, action);
      assert.equal(response.body.error.code, "unavailable", action);
      assert.equal(response.body.error.details.reason, "disabled_by_setting", action);
    }
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
    assert.equal(caps.body.result.integrations.length, 5);

    const kinds = await call(read, "describe");
    // The user and the four access kinds need the admin scope even to be read, so a read token sees 19 of the 24 kinds (the two package kinds are readable).
    assert.equal(kinds.body.result.kinds.length, 19);
    assert.ok(!kinds.body.result.kinds.some((kind) => kind.kind === "user"));
    const adminKinds = await call(admin, "describe");
    assert.equal(adminKinds.body.result.kinds.length, 24);
    for (const name of ["access_policy", "policy_template", "context_access", "resourcegroup_access"]) {
      assert.ok(adminKinds.body.result.kinds.some((kind) => kind.kind === name), name);
      assert.ok(!kinds.body.result.kinds.some((kind) => kind.kind === name), name);
    }
    assert.ok(adminKinds.body.result.kinds.some((kind) => kind.kind === "user"));
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

  await t.test("a resource open in the manager is not written over", async () => {
    const page = await call(admin, "create", { kind: "resource", fields: { pagetitle: "Страница", content: "текст" } });
    assert.equal(page.status, 200);
    const lock = spawnSync(php, [harness, "lock", site.dir, String(page.body.result.id), "77", "Редактор"], { encoding: "utf8" });
    assert.equal(lock.status, 0, lock.stderr);
    const refused = await call(admin, "update", { kind: "resource", id: page.body.result.id, fields: { longtitle: "x" } });
    assert.equal(refused.status, 409);
    assert.equal(refused.body.error.code, "locked");
    assert.equal(refused.body.error.details.reason, "open_in_manager");
    assert.equal(refused.body.error.details.user_id, 77);
    assert.equal(refused.body.error.details.username, "Редактор");
    const forced = await call(admin, "update", { kind: "resource", id: page.body.result.id, fields: { longtitle: "x" }, force: true });
    assert.equal(forced.status, 200);
    assert.equal(forced.body.meta.warnings.length, 1);
  });

  await t.test("request and session IDs are accepted on reads and echoed", async () => {
    const response = await call(read, "site_info", {}, { request_id: "read-request-000001", session_id: "session-0000000001" });
    assert.equal(response.status, 200);
    assert.equal(response.body.meta.request_id, "read-request-000001");
    assert.equal(response.headers.get("x-mcp-replayed"), null);
  });
});

test("draft-only tokens have the same policy on both HTTP transports", { skip: available ? false : "PHP with pdo_sqlite is not available" }, async (t) => {
  const site = await startSite();
  t.after(site.stop);
  const { admin, draft } = site.info.tokens;
  const call = (token, action, args) => site.post(token, { v: 2, action, args });
  const published = await call(admin, "create", { kind: "resource", fields: { pagetitle: "Draft-only fixture", content: "Old text", published: true } });
  assert.equal(published.body.ok, true, JSON.stringify(published.body));
  const originalId = published.body.result.id;
  const created = await call(draft, "invoke", { kind: "resource", op: "create_draft", id: originalId });
  assert.equal(created.body.ok, true, JSON.stringify(created.body));
  const draftId = created.body.result.draft_id;
  const refused = await call(draft, "update", { kind: "resource", id: originalId, fields: { content: "New text" } });
  assert.equal(refused.body.error.code, "forbidden_scope");
  const applied = await call(draft, "invoke", { kind: "resource", op: "apply_draft", id: draftId, args: { confirm: true } });
  assert.equal(applied.body.error.code, "forbidden_scope");

  const rpc = await httpClient(site, draft, "2025-11-25");
  t.after(() => rpc.client.close());
  const legacy = await call(draft, "capabilities", {});
  const discovered = await rpc.client.listTools();
  assert.deepEqual(discovered.tools.map((tool) => tool.name), legacy.body.result.tools);
  assert.deepEqual((await rpc.client.listPrompts()).prompts, []);
  const draftUpdate = { kind: "resource", id: draftId, fields: { content: "New text" } };
  const write = await rpc.client.callTool({ name: "modx_update", arguments: draftUpdate });
  assert.equal(write.isError, false, JSON.stringify(write));
  const publish = await rpc.client.callTool({ name: "modx_update", arguments: { ...draftUpdate, fields: { published: true } } });
  assert.equal(publish.isError, true);
  assert.match(publish.content[0].text, /forbidden_scope/);
  const read = async (id) => (await call(admin, "get", { kind: "resource", id })).body.result.fields;
  assert.equal((await read(originalId)).content, "Old text");
  assert.equal((await read(draftId)).content, "New text");
  assert.equal((await read(draftId)).published, false);
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
  assert.equal(tools.length, 37);
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

  const { prompts } = await client.listPrompts();
  assert.deepEqual(prompts.map((item) => item.name), ["study_site", "audit", "change_page", "new_page_type", "fix_errors"]);
  assert.deepEqual(prompts[2].arguments.map((item) => [item.name, item.required]), [["page", true], ["change", true]]);
  const page = await client.getPrompt({ name: "change_page", arguments: { page: "Контакты", change: "новый телефон" } });
  assert.match(page.messages[0].content.text, /"Контакты"/);
  await assert.rejects(client.getPrompt({ name: "change_page", arguments: { page: "Контакты" } }), /validation_failed/);
  await assert.rejects(client.getPrompt({ name: "nope" }), /not_found/);
});

test("bridge + kernel: file lines with revision tracking", { skip: available ? false : "PHP with pdo_sqlite is not available" }, async (t) => {
  const site = await startSite({ MODXMCP_TEST_FILES: "1" });
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: [path.join(root, "client", "index.js")],
    env: { ...process.env, MODX_MCP_SITE_URL: site.url, MODX_MCP_TOKEN: site.info.tokens.admin, MODX_MCP_TIMEOUT_MS: "5000" },
    stderr: "ignore",
  });
  const client = new Client({ name: "test", version: "0" }, { capabilities: {} });
  await client.connect(transport);
  t.after(async () => { await client.close(); site.stop(); });
  const file = path.join(site.dir, "media", "css", "site.css");
  const call = (name, args) => client.callTool({ name, arguments: args });
  const run = async (name, args) => {
    const result = await call(name, args);
    assert.equal(result.isError, undefined, `${name}: ${result.content[0].text}`);
    return result.structuredContent;
  };

  const found = await run("modx_search_files", { source: 1, query: "RED" });
  assert.deepEqual(found.results.map((item) => [item.path, item.matches[0].line]), [["css/site.css", 2]]);
  const window = await run("modx_read_file", { source: 1, path: "css/site.css", start_line: 2, end_line: 2 });
  assert.equal(window.numbered, "2\t  color: red;");
  assert.equal(window.total_lines, 3);
  assert.equal(window.root, "s1");

  // Somebody else changes the file: the revision remembered from the read is sent and the edit is refused.
  fs.writeFileSync(file, "body {\n  color: green;\n}\n");
  const stale = await call("modx_edit_file", { root: window.root, path: "css/site.css", edits: [{ start_line: 2, replacement: "  color: blue;" }] });
  assert.equal(stale.isError, true);
  assert.match(stale.content[0].text, /revision_conflict/);
  assert.equal(fs.readFileSync(file, "utf8"), "body {\n  color: green;\n}\n");

  const again = await run("modx_read_file", { source: 1, path: "css/site.css", numbered: true });
  const first = await run("modx_edit_file", { root: again.root, path: "css/site.css", edits: [{ start_line: 2, replacement: "  color: blue;", expect: "  color: green;" }] });
  assert.equal(first.edits_applied, 1);
  assert.equal(fs.readFileSync(file, "utf8"), "body {\n  color: blue;\n}\n");
  // The revision of the edit result is remembered too: a second edit needs no read.
  const second = await run("modx_edit_file", { source: 1, path: "css/site.css", edits: [{ start_line: 4, end_line: 3, replacement: "p {}" }] });
  assert.equal(second.total_lines_after, 4);
  assert.equal(fs.readFileSync(file, "utf8"), "body {\n  color: blue;\n}\np {}\n");
  await run("modx_undo", { changeset_id: second.changeset_id, confirm: true });
  assert.equal(fs.readFileSync(file, "utf8"), "body {\n  color: blue;\n}\n");
});

test("escape hatches: tools appear with the settings on", { skip: available ? false : "PHP with pdo_sqlite is not available" }, async (t) => {
  const site = await startSite({ MODXMCP_TEST_ALLOW_ESCAPE: "1" });
  t.after(site.stop);
  const { admin, read } = site.info.tokens;
  const call = (token, action, args = {}) => site.post(token, { v: 2, action, args });
  const names = async (token) => (await call(token, "manifest")).body.result.tools.map((item) => item.name);
  const adminTools = await names(admin);
  assert.equal(adminTools.length, 39);
  assert.deepEqual(adminTools.slice(adminTools.indexOf("modx_uninstall_package") + 1, adminTools.indexOf("modx_uninstall_package") + 4), ["modx_run_processor", "modx_query_db", "modx_request_status"]);
  const readTools = await names(read);
  assert.equal(readTools.length, 20);
  assert.ok(!readTools.includes("modx_query_db") && !readTools.includes("modx_run_processor"));
  assert.equal((await call(read, "query_db", { sql: "SELECT 1" })).body.error.code, "forbidden_scope");
  const query = (await call(admin, "query_db", { sql: "SELECT ? AS a, 'x;y' AS b", params: [7] })).body.result;
  assert.deepEqual(query.columns, ["a", "b"]);
  assert.equal(query.rows[0][1], "x;y");
  assert.equal(query.returned, 1);
  assert.equal((await call(admin, "query_db", { sql: "SELECT 1; SELECT 2" })).body.error.code, "validation_failed");
  assert.equal((await call(admin, "query_db", { sql: "SELECT hash FROM modx_modxmcp_tokens" })).body.error.code, "forbidden_scope");
  assert.equal((await call(admin, "run_processor", { processor: "security/user/update", confirm: true })).body.error.code, "validation_failed");
  assert.equal((await call(admin, "run_processor", { processor: "Resource/GetList" })).body.error.code, "validation_failed");
  assert.equal((await call(admin, "run_processor", { processor: "resource/getlist" })).body.error.code, "not_found");
});

test("modx_run: the tool appears only with its setting on", { skip: available ? false : "PHP with pdo_sqlite is not available" }, async (t) => {
  const off = await startSite();
  t.after(off.stop);
  const offNames = (await off.post(off.info.tokens.admin, { v: 2, action: "manifest", args: {} })).body.result.tools.map((item) => item.name);
  assert.ok(!offNames.includes("modx_run"));
  const refused = await off.post(off.info.tokens.admin, { v: 2, action: "run", args: { text: "x" } });
  assert.equal(refused.status, 403);
  assert.equal(refused.body.error.code, "unavailable");
  assert.equal(refused.body.error.details.reason, "disabled_by_setting");

  const on = await startSite({ MODXMCP_TEST_ALLOW_RUN: "1" });
  t.after(on.stop);
  const { admin, read } = on.info.tokens;
  const call = (token, action, args = {}) => on.post(token, { v: 2, action, args });
  const names = async (token) => (await call(token, "manifest")).body.result.tools.map((item) => item.name);
  const adminTools = await names(admin);
  assert.equal(adminTools.length, 38);
  assert.equal(adminTools[adminTools.indexOf("modx_audit_site") + 1], "modx_run");
  const run = (await call(admin, "manifest")).body.result.tools.find((item) => item.name === "modx_run");
  assert.deepEqual(run.effect, { read_only: false, destructive: true, reversible: false, open_world: true });
  assert.equal(run.scope, "develop");
  assert.ok(!(await names(read)).includes("modx_run"));
  assert.equal((await call(read, "run", { text: "x" })).body.error.code, "forbidden_scope");
  const result = await call(admin, "run", { text: "Привет [[+нет]]" });
  assert.equal(result.status, 200);
  assert.equal(result.body.result.ok, true);
  assert.equal(result.body.result.output, "Привет [[+нет]]");
  assert.equal(result.body.result.unparsed_count, 1);
  assert.equal(result.body.result.reversible, false);
  assert.ok(result.body.meta.warnings.includes("Changes made by the executed code are not journaled."));
  assert.equal((await call(admin, "run", { snippet: "a", chunk: "b" })).body.error.code, "validation_failed");
});

test("miniShop2: the option kind and the product relations appear when it is installed", { skip: available ? false : "PHP with pdo_sqlite is not available" }, async (t) => {
  const site = await startSite({ MODXMCP_TEST_MS2: "1" });
  t.after(site.stop);
  const { admin, read } = site.info.tokens;
  const call = (token, action, args = {}) => site.post(token, { v: 2, action, args });
  // 24 kinds without miniShop2 (see "kernel: wire protocol"), five more with it; a read token reads all of them but the orders.
  const adminKinds = (await call(admin, "describe")).body.result.kinds.map((kind) => kind.kind);
  assert.equal(adminKinds.length, 29);
  assert.deepEqual(adminKinds.slice(-5), ["ms2_option", "ms2_link", "ms2_product_link", "ms2_order", "ms2_order_status"]);
  const readKinds = (await call(read, "describe")).body.result.kinds.map((kind) => kind.kind);
  assert.equal(readKinds.length, 23);
  assert.deepEqual(readKinds.slice(-4), ["ms2_option", "ms2_link", "ms2_product_link", "ms2_order_status"]);
  assert.ok(!readKinds.includes("ms2_order"));
  assert.equal((await call(read, "get", { kind: "ms2_order", id: 1 })).body.error.code, "forbidden_scope");
  assert.equal((await call(read, "list", { kind: "ms2_order" })).body.error.code, "forbidden_scope");
  assert.equal((await call(admin, "list", { kind: "ms2_order" })).body.result.total, 0);
  const integration = (await call(read, "capabilities")).body.result.integrations.find((item) => item.key === "minishop2");
  assert.equal(integration.available, true);

  const option = (await call(admin, "create", { kind: "ms2_option", fields: { key: "brand", caption: "Бренд", properties: { values: ["Звезда", "Ёлка"] } } })).body;
  assert.equal(option.ok, true, JSON.stringify(option));
  const category = (await call(admin, "create", { kind: "resource", fields: { pagetitle: "Телефоны", class_key: "msCategory" } })).body.result;
  const product = (await call(admin, "create", {
    kind: "resource",
    fields: { pagetitle: "Смартфон «Звезда»", class_key: "msProduct", parent: category.id },
    relations: { ms2_data: { price: 1500, tags: ["новинка"] }, ms2_options: { brand: ["Ёлка"] } },
  })).body;
  assert.equal(product.ok, true, JSON.stringify(product));
  const shown = (await call(read, "get", { kind: "resource", id: product.result.id })).body.result.relations;
  assert.equal(shown.ms2_data.price, 1500);
  assert.deepEqual(shown.ms2_data.tags, ["новинка"]);
  assert.deepEqual(shown.ms2_options, { brand: ["Ёлка"] });
  assert.deepEqual(shown.ms2_categories, []);
  const plain = (await call(admin, "update", { kind: "resource", id: product.result.id, fields: { pagetitle: "Смартфон «Заря»" } })).body.result;
  assert.deepEqual(plain.changed, ["pagetitle"]);
  const after = (await call(read, "get", { kind: "resource", id: product.result.id })).body.result.relations;
  assert.deepEqual(after.ms2_data, shown.ms2_data);
  assert.deepEqual(after.ms2_options, shown.ms2_options);
  const refused = (await call(admin, "update", { kind: "resource", id: product.result.id, relations: { ms2_options: { nonexistent: ["x"] } } })).body;
  assert.equal(refused.error.code, "validation_failed");
  assert.equal(refused.error.message, "Unknown miniShop2 option 'nonexistent'.");
});

test("MIGX: the configuration kind and MIGX TV values appear when it is installed", { skip: available ? false : "PHP with pdo_sqlite is not available" }, async (t) => {
  const site = await startSite({ MODXMCP_TEST_MIGX: "1" });
  t.after(site.stop);
  const { admin, read } = site.info.tokens;
  const call = (token, action, args = {}) => site.post(token, { v: 2, action, args });
  // 24 kinds without MIGX (see "kernel: wire protocol"), one more with it; a read token reads it.
  const adminKinds = (await call(admin, "describe")).body.result.kinds.map((kind) => kind.kind);
  assert.equal(adminKinds.length, 25);
  assert.equal(adminKinds[adminKinds.length - 1], "migx_config");
  const readKinds = (await call(read, "describe")).body.result.kinds.map((kind) => kind.kind);
  assert.equal(readKinds.length, 20);
  assert.equal(readKinds[readKinds.length - 1], "migx_config");
  const integration = (await call(read, "capabilities")).body.result.integrations.find((item) => item.key === "migx");
  assert.equal(integration.available, true);

  const created = (await call(admin, "create", {
    kind: "migx_config",
    fields: {
      name: "галерея", category: "Сайт",
      formtabs: [{ caption: "Слайд", fields: [{ field: "title", caption: "Заголовок", inputTVtype: "text" }] }],
      columns: [{ header: "Заголовок", dataIndex: "title" }],
      extended: { formcaption: "Слайд" },
    },
  })).body;
  assert.equal(created.ok, true, JSON.stringify(created));
  const got = (await call(read, "get", { kind: "migx_config", id: created.result.id })).body.result;
  assert.equal(got.fields.name, "галерея");
  assert.equal(got.fields.formtabs[0].caption, "Слайд");
  assert.equal(got.fields.formtabs[0].fields[0].MIGX_id, "1");
  assert.deepEqual(got.fields.columns, [{ header: "Заголовок", dataIndex: "title" }]);
  assert.deepEqual(got.fields.extended, { formcaption: "Слайд" });
  assert.equal(got.fields.filters, null);
  const refused = (await call(admin, "update", { kind: "migx_config", id: created.result.id, fields: { columns: [{ header: "X" }] } })).body;
  assert.equal(refused.error.code, "validation_failed");
  assert.match(refused.error.message, /columns\[0\]\.dataIndex/);
  assert.equal((await call(read, "update", { kind: "migx_config", id: created.result.id, fields: { category: "x" } })).body.error.code, "forbidden_scope");

  const removed = (await call(admin, "delete", { kind: "migx_config", id: created.result.id })).body;
  assert.equal(removed.ok, true, JSON.stringify(removed));
  assert.equal((await call(read, "list", { kind: "migx_config" })).body.result.total, 0);
  assert.equal((await call(read, "list", { kind: "migx_config", where: { deleted: true } })).body.result.total, 1);
  assert.equal((await call(admin, "undo", { changeset_id: removed.result.changeset_id, confirm: true })).body.ok, true);
  assert.equal((await call(read, "list", { kind: "migx_config" })).body.result.items[0].name, "галерея");

  const template = (await call(admin, "create", { kind: "template", fields: { name: "Каталог" } })).body.result;
  const tv = (await call(admin, "create", { kind: "tv", fields: { name: "gallery", type: "migx" }, relations: { templates: [template.id] } })).body;
  assert.equal(tv.ok, true, JSON.stringify(tv));
  const page = (await call(admin, "create", { kind: "resource", fields: { pagetitle: "Галерея", template: template.id } })).body.result;
  const written = (await call(admin, "update", { kind: "resource", id: page.id, relations: { tvs: { gallery: [{ image: "a.jpg", title: "Первый" }, { image: "b.jpg", MIGX_id: 7 }, { image: "c.jpg" }] } } })).body;
  assert.equal(written.ok, true, JSON.stringify(written));
  const shown = (await call(read, "get", { kind: "resource", id: page.id })).body.result.relations.tvs.gallery;
  assert.deepEqual(shown, [{ image: "a.jpg", title: "Первый", MIGX_id: 8 }, { image: "b.jpg", MIGX_id: 7 }, { image: "c.jpg", MIGX_id: 9 }]);
  const bad = (await call(admin, "update", { kind: "resource", id: page.id, relations: { tvs: { gallery: ["текст"] } } })).body;
  assert.equal(bad.error.code, "validation_failed");
  assert.match(bad.error.message, /item 0 must be an object/);

  const help = (await call(read, "help", { topic: "migx" })).body.result;
  assert.match(help.content, /modx_create \{kind: "migx_config"/);
  assert.ok(!/modx_migx_|modx_update_resource_tvs|modx_get_resource_tvs|modx_create_element/.test(help.content));
});

test("VirtualPage: the three kinds, the route tester and the help appear when it is installed", { skip: available ? false : "PHP with pdo_sqlite is not available" }, async (t) => {
  const site = await startSite({ MODXMCP_TEST_VIRTUALPAGE: "1" });
  t.after(site.stop);
  const { admin, read } = site.info.tokens;
  const call = (token, action, args = {}) => site.post(token, { v: 2, action, args });
  // 24 kinds without VirtualPage (see "kernel: wire protocol"), three more with it; a read token reads them.
  const adminKinds = (await call(admin, "describe")).body.result.kinds.map((kind) => kind.kind);
  assert.equal(adminKinds.length, 27);
  assert.deepEqual(adminKinds.slice(-3), ["vp_event", "vp_handler", "vp_route"]);
  const readKinds = (await call(read, "describe")).body.result.kinds.map((kind) => kind.kind);
  assert.equal(readKinds.length, 22);
  assert.deepEqual(readKinds.slice(-3), ["vp_event", "vp_handler", "vp_route"]);
  const integration = (await call(read, "capabilities")).body.result.integrations.find((item) => item.key === "virtualpage");
  assert.equal(integration.available, true);

  const event = (await call(admin, "create", { kind: "vp_event", fields: { name: "OnPageNotFound" } })).body;
  assert.equal(event.ok, true, JSON.stringify(event));
  const chunk = (await call(admin, "create", { kind: "chunk", fields: { name: "товар", content: "<h1>[[+vp.id]]</h1>" } })).body.result;
  const handler = (await call(admin, "create", { kind: "vp_handler", fields: { name: "Товар", type: "chunk", entry: chunk.id } })).body;
  assert.equal(handler.ok, true, JSON.stringify(handler));
  const route = (await call(admin, "create", { kind: "vp_route", fields: { route: "/каталог/{id:\\d+}", handler: handler.result.id, event: event.result.id } })).body;
  assert.equal(route.ok, true, JSON.stringify(route));
  const resolved = (await call(read, "invoke", { kind: "vp_route", op: "resolve", args: { path: "/каталог/5" } })).body.result;
  assert.equal(resolved.matches.length, 1);
  assert.deepEqual(resolved.matches[0].parameters, { id: "5" });
  assert.equal(resolved.matches[0].handler.type, "chunk");
  assert.equal(resolved.answered_by, route.result.id);
  assert.deepEqual((await call(read, "invoke", { kind: "vp_route", op: "resolve", args: { path: "/нет" } })).body.result.matches, []);
  assert.equal((await call(read, "update", { kind: "vp_route", id: route.result.id, fields: { active: false } })).body.error.code, "forbidden_scope");
  const refused = (await call(admin, "create", { kind: "vp_route", fields: { route: "/каталог/{id:\\d+}", handler: handler.result.id, event: event.result.id } })).body;
  assert.equal(refused.error.code, "validation_failed");

  const removed = (await call(admin, "delete", { kind: "vp_handler", id: handler.result.id })).body;
  assert.equal(removed.ok, true, JSON.stringify(removed));
  assert.equal((await call(read, "list", { kind: "vp_route" })).body.result.total, 0);
  assert.equal((await call(admin, "undo", { changeset_id: removed.result.changeset_id, confirm: true })).body.ok, true);
  assert.equal((await call(read, "list", { kind: "vp_route" })).body.result.items[0].label, "GET /каталог/{id:\\d+} → Товар (OnPageNotFound)");

  const help = (await call(read, "help", { topic: "virtualpage" })).body.result;
  assert.match(help.content, /modx_create \{kind: "vp_route"/);
  assert.ok(!/modx_virtualpage_/.test(help.content));
});

// ---- MCP over HTTP: real MCP clients talk to the PHP kernel directly, without the bridge ----

const { StreamableHTTPClientTransport } = require("@modelcontextprotocol/sdk/client/streamableHttp.js");
const { toMcpTool, toMcpPrompt } = require(path.join(root, "client", "mapping.js"));

async function httpClient(site, token, protocolVersion) {
  // The ready snippets send X-MCP-Token (hosts with PHP under FastCGI drop Authorization); the older client below uses it.
  const headers = protocolVersion ? { "X-MCP-Token": token } : { Authorization: `Bearer ${token}` };
  const transport = new StreamableHTTPClientTransport(new URL(site.url), { requestInit: { headers } });
  const client = new Client({ name: "http-test", version: "0" }, { capabilities: {} });
  if (protocolVersion) {
    // Pretend to be an older client: ask for this version in initialize.
    const original = transport.send.bind(transport);
    transport.send = (message, options) => original(message.method === "initialize" ? { ...message, params: { ...message.params, protocolVersion } } : message, options);
  }
  await client.connect(transport);
  return { client, transport };
}

test("MCP over HTTP: the official SDK client talks to the kernel directly", { skip: available ? false : "PHP with pdo_sqlite is not available" }, async (t) => {
  const site = await startSite();
  const { client, transport } = await httpClient(site, site.info.tokens.admin);
  t.after(async () => { await client.close(); site.stop(); });

  assert.equal(client.getServerVersion().name, "modxMCP");
  assert.match(client.getInstructions(), /modx_site_info/);
  assert.match(transport.sessionId, /^[0-9a-f]{32}$/, "a session label is issued on initialize");

  // The same tools, prompts and help as through the bridge.
  const { tools } = await client.listTools();
  const manifest = (await site.post(site.info.tokens.admin, { v: 2, action: "manifest", args: {} })).body.result;
  assert.deepEqual(tools, manifest.tools.map(toMcpTool), "PHP Catalog and the bridge map the manifest the same way");
  const { prompts } = await client.listPrompts();
  assert.deepEqual(prompts, (manifest.prompts || []).map(toMcpPrompt));
  const { resources } = await client.listResources();
  assert.ok(resources.some((r) => r.uri === "modx-help://index"));
  const index = await client.readResource({ uri: "modx-help://index" });
  assert.match(index.contents[0].text, /modx_/);
  if (prompts.length) {
    const p = prompts[0];
    const args = Object.fromEntries((p.arguments || []).filter((a) => a.required).map((a) => [a.name, "тест"]));
    assert.ok((await client.getPrompt({ name: p.name, arguments: args })).messages.length >= 1);
  }

  // A read, a write, the journal under the session label, and an undo.
  const run = async (name, args = {}) => {
    const result = await client.callTool({ name, arguments: args });
    assert.equal(result.isError, false, `${name}: ${result.content[0].text}`);
    assert.deepEqual(JSON.parse(result.content[0].text), result.structuredContent);
    return result.structuredContent;
  };
  assert.equal((await run("modx_site_info")).site_name, "Test site");
  const created = await run("modx_create", { kind: "chunk", fields: { name: "через_http", content: "<b>привет</b>" } });
  assert.ok(created.changeset_id > 0);
  const history = await run("modx_history", { this_session: true });
  assert.deepEqual(history.changesets.map((c) => c.changeset_id), [created.changeset_id], "the write is journaled under the session label");
  await run("modx_undo", { changeset_id: created.changeset_id, confirm: true });
  assert.equal((await run("modx_list", { kind: "chunk", query: "через_http" })).total, 0);

  // A refusal of the site is a tool result with isError, not a protocol error; the token never shows.
  const refused = await client.callTool({ name: "modx_create", arguments: { kind: "snippet", fields: { name: "bad", content: "return (;" } } });
  assert.equal(refused.isError, true);
  assert.match(refused.content[0].text, /^MODX lint_failed: /);
  assert.ok(!refused.content[0].text.includes(site.info.tokens.admin));
  await assert.rejects(client.callTool({ name: "modx_nope", arguments: {} }), /Unknown tool/);
});

test("MCP over HTTP: older protocol versions, auth, methods and the bridge on the same URL", { skip: available ? false : "PHP with pdo_sqlite is not available" }, async (t) => {
  const site = await startSite();
  t.after(() => site.stop());
  const rpc = async (body, headers = {}, token = site.info.tokens.admin) => {
    const response = await fetch(site.url, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json, text/event-stream", ...(token ? { Authorization: `Bearer ${token}` } : {}), ...headers },
      body: typeof body === "string" ? body : JSON.stringify(body),
    });
    const text = await response.text();
    return { status: response.status, headers: response.headers, body: text ? JSON.parse(text) : null };
  };

  for (const version of ["2024-11-05", "2025-03-26", "2025-06-18", "2025-11-25"]) {
    const init = await rpc({ jsonrpc: "2.0", id: 1, method: "initialize", params: { protocolVersion: version, capabilities: {}, clientInfo: { name: "x", version: "0" } } });
    assert.equal(init.status, 200);
    assert.equal(init.body.result.protocolVersion, version);
    assert.equal(init.headers.get("content-type").split(";")[0], "application/json");
  }
  const future = await rpc({ jsonrpc: "2.0", id: 1, method: "initialize", params: { protocolVersion: "2099-01-01", capabilities: {}, clientInfo: { name: "x", version: "0" } } });
  assert.equal(future.body.result.protocolVersion, "2025-11-25", "an unknown version gets the newest handshake version");

  // An older client (2025-03-26) gets no outputSchema and no structuredContent.
  const old = await httpClient(site, site.info.tokens.read, "2025-03-26");
  const oldTools = (await old.client.listTools()).tools;
  assert.ok(oldTools.every((tool) => tool.outputSchema === undefined));
  const info = await old.client.callTool({ name: "modx_site_info", arguments: {} });
  assert.equal(info.structuredContent, undefined);
  assert.equal(JSON.parse(info.content[0].text).site_name, "Test site");
  assert.ok(!oldTools.some((tool) => tool.name === "modx_create"), "a read token sees no write tools over HTTP either");
  await old.client.close();

  // Notifications, batches, unknown methods, versions.
  const note = await rpc({ jsonrpc: "2.0", method: "notifications/initialized" });
  assert.equal(note.status, 202);
  assert.equal(note.body, null);
  const batch = await rpc([{ jsonrpc: "2.0", id: 1, method: "ping" }, { jsonrpc: "2.0", method: "notifications/initialized" }, { jsonrpc: "2.0", id: 2, method: "nope" }]);
  assert.equal(batch.body.length, 2);
  assert.deepEqual(batch.body[0], { jsonrpc: "2.0", id: 1, result: {} });
  assert.equal(batch.body[1].error.code, -32601);
  const badVersion = await rpc({ jsonrpc: "2.0", id: 1, method: "ping" }, { "MCP-Protocol-Version": "1999-01-01" });
  assert.equal(badVersion.status, 400);

  // Auth and transport rules.
  const anon = await rpc({ jsonrpc: "2.0", id: 1, method: "tools/list" }, {}, null);
  assert.equal(anon.status, 401);
  assert.match(anon.headers.get("www-authenticate"), /^Bearer/);
  assert.equal(anon.body.error.data.code, "unauthorized");
  const wrong = await rpc({ jsonrpc: "2.0", id: 1, method: "tools/list" }, {}, "mcp_" + "0".repeat(64));
  assert.equal(wrong.status, 401);
  const foreign = await rpc({ jsonrpc: "2.0", id: 1, method: "ping" }, { Origin: "https://evil.example" });
  assert.equal(foreign.status, 403);
  const stream = await fetch(site.url, { headers: { Accept: "text/event-stream" } });
  assert.equal(stream.status, 405);
  const probe = await fetch(site.url);
  assert.equal((await probe.json()).component, "modxMCP", "a plain GET is still the health probe");
  const del = await fetch(site.url, { method: "DELETE" });
  assert.equal(del.status, 405);

  // Revision 2026-07-28: no handshake; version, method and name in headers that must agree with the body.
  const meta = { "io.modelcontextprotocol/protocolVersion": "2026-07-28", "io.modelcontextprotocol/clientCapabilities": {} };
  const modern = (method, params, extra = {}) => rpc({ jsonrpc: "2.0", id: 7, method, params: { ...params, _meta: meta } },
    { "MCP-Protocol-Version": "2026-07-28", "Mcp-Method": method, ...extra });
  const discover = await modern("server/discover", {});
  assert.equal(discover.status, 200, JSON.stringify(discover.body));
  assert.ok(JSON.stringify(discover.body.result).includes("2026-07-28"));
  const listed = await modern("tools/list", {});
  assert.ok(listed.body.result.tools.some((tool) => tool.name === "modx_site_info"));
  const called = await modern("tools/call", { name: "modx_site_info", arguments: {} }, { "Mcp-Name": "modx_site_info" });
  assert.equal(called.body.result.isError, false);
  assert.equal(called.body.result.structuredContent.site_name, "Test site");
  const mismatch = await modern("tools/call", { name: "modx_site_info", arguments: {} }, { "Mcp-Name": "modx_help" });
  assert.equal(mismatch.status, 400);
  assert.equal(mismatch.body.error.code, -32020);
  const noName = await modern("tools/call", { name: "modx_site_info", arguments: {} });
  assert.equal(noName.status, 400);
  const wrongMethod = await modern("tools/list", {}, { "Mcp-Method": "prompts/list" });
  assert.equal(wrongMethod.body.error.code, -32020);
  const unknown = await modern("nope/never", {});
  assert.equal(unknown.status, 404);
  assert.equal(unknown.body.error.code, -32601);
  assert.equal(listed.headers.get("mcp-session-id"), null, "2026-07-28 has no sessions");

  // The protocol of the bridge keeps working on the same URL.
  const bridge = await site.post(site.info.tokens.read, { v: 2, action: "site_info", args: {} });
  assert.equal(bridge.status, 200);
  assert.equal(bridge.body.ok, true);
});

test("MCP over HTTP preserves warnings and bounds individual and batched responses", { skip: available ? false : "PHP with pdo_sqlite is not available" }, async (t) => {
  const limit = 2200;
  const site = await startSite({ MODXMCP_TEST_MAX_RESPONSE_BYTES: String(limit), MODXMCP_TEST_ALLOW_RUN: "1" });
  t.after(site.stop);
  const token = site.info.tokens.admin;
  const tool = (id, name, args = {}) => ({ jsonrpc: "2.0", id, method: "tools/call", params: { name: `modx_${name}`, arguments: args } });
  const rpc = async (body, version = "2025-03-26") => {
    const response = await fetch(site.url, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json, text/event-stream", "X-MCP-Token": token, "MCP-Protocol-Version": version },
      body: JSON.stringify(body),
    });
    const text = await response.text();
    assert.ok(Buffer.byteLength(text) <= limit, "actual JSON-RPC response stays within the byte cap");
    return { status: response.status, body: JSON.parse(text) };
  };

  const firstId = "warnings-replay-0001";
  const warned = tool(1, "run", { text: "Проверка", _request_id: firstId });
  const batch = await rpc([warned, tool(2, "site_info")]);
  assert.equal(batch.status, 200);
  assert.equal(batch.body[0].result._meta.request_id, firstId);
  assert.equal(JSON.parse(batch.body[0].result.content[0].text).output, "Проверка");
  assert.match(batch.body[0].result.content[1].text, /Warnings:.*\n- Changes made by the executed code are not journaled\./);
  assert.equal(batch.body[1].result.content.length, 1);
  assert.equal(batch.body[1].result._meta, undefined);
  const replay = await rpc({ ...warned, id: 3 }, "2025-11-25");
  assert.deepEqual(replay.body.result.content, batch.body[0].result.content);
  assert.deepEqual(replay.body.result.structuredContent, JSON.parse(replay.body.result.content[0].text));

  const created = await site.post(token, { v: 2, action: "create", args: { kind: "chunk", fields: { name: "large content", content: "x".repeat(1350), static: false } } });
  assert.equal(created.body.ok, true);
  const get = (id) => tool(id, "get", { kind: "chunk", id: created.body.result.id, fields: ["content"] });
  assert.equal((await rpc(get(4))).body.result.isError, false, "one text copy fits");
  const large = await rpc(get(5), "2025-11-25");
  assert.equal(large.status, 500);
  assert.equal(large.body.id, 5);
  assert.equal(large.body.error.data.code, "response_too_large");
  const combined = await rpc([get(6), get(7)]);
  assert.equal(combined.status, 200);
  assert.equal(combined.body[0].result.isError, false);
  assert.equal(combined.body[1].id, 7);
  assert.equal(combined.body[1].error.data.code, "response_too_large");

  const requestId = "oversized-write-0001";
  const write = await rpc(tool(8, "run", { text: "y".repeat(1300), _request_id: requestId }), "2025-11-25");
  assert.equal(write.status, 500);
  assert.equal(write.body.error.data.request_id, requestId);
  assert.match(write.body.error.message, /check modx_request_status/);
  const status = await rpc(tool(9, "request_status", { request_id: requestId }));
  const recorded = JSON.parse(status.body.result.content[0].text);
  assert.equal(recorded.state, "completed");
  assert.equal(recorded.success, true, "the saved result is independent of the transport cap");

  const rejectedId = "rejected-batch-0001";
  const pings = Array.from({ length: 24 }, (_, i) => ({ jsonrpc: "2.0", id: 11 + i, method: "ping" }));
  const rejected = await rpc([tool(10, "run", { text: "not started", _request_id: rejectedId }), ...pings]);
  assert.equal(rejected.status, 413);
  assert.equal(rejected.body.error.data.code, "batch_too_large");
  const notStarted = await rpc(tool(40, "request_status", { request_id: rejectedId }));
  assert.equal(JSON.parse(notStarted.body.result.content[0].text).state, "not_found");
});
