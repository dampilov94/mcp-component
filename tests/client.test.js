// End-to-end test of the Node bridge: a real MCP client talks to client/index.js over stdio,
// and the bridge talks to a mock modxMCP endpoint. No real site is contacted.
const test = require("node:test");
const assert = require("node:assert/strict");
const http = require("node:http");
const path = require("node:path");
const { Client } = require("@modelcontextprotocol/sdk/client/index.js");
const { StdioClientTransport } = require("@modelcontextprotocol/sdk/client/stdio.js");

const TOKEN = "mcp_" + "a".repeat(64);
const object = (properties = {}, required = []) => ({ type: "object", properties, required, additionalProperties: false });
const tool = (action, extra = {}) => ({
  name: `modx_${action}`, action, description: `Test tool ${action}.`,
  inputSchema: object(), outputSchema: { type: "object", properties: {}, additionalProperties: true },
  effect: { read_only: true, destructive: false, reversible: true, open_world: false },
  scope: "read", group: "core", ...extra,
});

function mockSite() {
  const state = { requests: [], hash: "h1", revision: "r1", tools: [
    tool("site_info"),
    tool("help", { inputSchema: object({ topic: { type: "string" } }) }),
    tool("describe", { inputSchema: object({ kind: { type: "string", pattern: "^[a-z]+$" } }) }),
    tool("get", { inputSchema: object({ kind: { type: "string" }, id: { type: "integer" } }) }),
    tool("undo", { inputSchema: object({ confirm: { type: "boolean" } }), effect: { read_only: false, destructive: true, reversible: true, open_world: false } }),
    tool("update", {
      inputSchema: object({ kind: { type: "string" }, id: { type: "integer" }, expected_revision: { type: "string" } }, ["kind"]),
      effect: { read_only: false, destructive: true, reversible: true, open_world: false },
    }),
  ] };
  const server = http.createServer((req, res) => {
    let raw = "";
    req.on("data", (chunk) => { raw += chunk; });
    req.on("end", () => {
      const reply = (status, body) => {
        res.writeHead(status, { "Content-Type": "application/json; charset=utf-8" });
        res.end(JSON.stringify(body));
      };
      if (req.headers["x-mcp-token"] !== TOKEN) return reply(401, { ok: false, error: { code: "unauthorized", message: "Unauthorized" } });
      const body = JSON.parse(raw);
      state.requests.push(body);
      const meta = { manifest: state.hash, ...(body.request_id ? { request_id: body.request_id } : {}) };
      const ok = (result) => reply(200, { ok: true, result, meta });
      switch (body.action) {
        case "manifest":
          return ok({ protocol: 2, server_version: "2.0.0", hash: state.hash, instructions: "Mock site.", tools: state.tools });
        case "site_info":
          return ok({ site_name: "Mock", counts: { chunks: 3 } });
        case "help":
          return body.args.topic
            ? ok({ topics: ["index", "acl"], topic: body.args.topic, content: `# ${body.args.topic}` })
            : ok({ topics: ["index", "acl"], topic: "index", content: "# index" });
        case "describe":
          return reply(404, { ok: false, error: { code: "not_found", message: `Unknown kind '${body.args.kind}'. Token ${TOKEN}`, details: { hint: "list kinds" } }, meta });
        case "get":
          return ok({ kind: body.args.kind, id: body.args.id, revision: state.revision });
        case "undo":
          return ok({ performed: true });
        case "update":
          if (body.args.id === 7 && body.args.expected_revision !== state.revision) {
            return reply(409, { ok: false, error: { code: "revision_conflict", message: "changed" }, meta });
          }
          if (body.args.id === 7) state.revision = "r" + (Number(state.revision.slice(1)) + 1);
          return ok({ kind: body.args.kind, id: body.args.id ?? 1, ...(body.args.id === 7 ? { revision: state.revision } : {}) });
        default:
          return reply(400, { ok: false, error: { code: "unknown_action", message: "Unknown action." }, meta });
      }
    });
  });
  return new Promise((resolve) => server.listen(0, "127.0.0.1", () => resolve({ server, state, url: `http://127.0.0.1:${server.address().port}/api.php` })));
}

async function connect(url, token = TOKEN) {
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: [path.resolve(__dirname, "..", "client", "index.js")],
    // Explicit values win over any local .env, so the test can never reach a real site.
    env: { ...process.env, MODX_MCP_SITE_URL: url, MODX_MCP_TOKEN: token, MODX_MCP_TIMEOUT_MS: "5000" },
    stderr: "ignore",
  });
  const client = new Client({ name: "test", version: "0" }, { capabilities: {} });
  await client.connect(transport);
  return client;
}

test("bridge serves the site's manifest and proxies calls", async (t) => {
  const site = await mockSite();
  const client = await connect(site.url);
  t.after(async () => { await client.close(); site.server.close(); });

  await t.test("instructions come from the site", () => {
    assert.equal(client.getInstructions(), "Mock site.");
  });

  await t.test("tools are built from the manifest", async () => {
    const { tools } = await client.listTools();
    assert.deepEqual(tools.map((item) => item.name), ["modx_site_info", "modx_help", "modx_describe", "modx_get", "modx_undo", "modx_update"]);
    const read = tools.find((item) => item.name === "modx_site_info");
    assert.equal(read.annotations.readOnlyHint, true);
    assert.equal(read.inputSchema.properties._request_id, undefined);
    const write = tools.find((item) => item.name === "modx_update");
    assert.equal(write.annotations.readOnlyHint, false);
    assert.equal(write.annotations.destructiveHint, true);
    assert.equal(write.inputSchema.properties._request_id.type, "string");
  });

  await t.test("a read call returns compact text and structured content", async () => {
    const result = await client.callTool({ name: "modx_site_info", arguments: {} });
    assert.equal(result.isError, undefined);
    assert.deepEqual(result.structuredContent, { site_name: "Mock", counts: { chunks: 3 } });
    assert.equal(result.content[0].text, '{"site_name":"Mock","counts":{"chunks":3}}');
    const sent = site.state.requests.at(-1);
    assert.equal(sent.v, 2);
    assert.equal(sent.request_id, undefined);
    assert.match(sent.session_id, /^[A-Za-z0-9_-]{16,128}$/);
  });

  await t.test("a write call gets a request ID and never forwards _request_id as an argument", async () => {
    const auto = await client.callTool({ name: "modx_update", arguments: { kind: "chunk" } });
    assert.match(auto._meta.request_id, /^[A-Za-z0-9_-]{16,128}$/);
    assert.equal(site.state.requests.at(-1).request_id, auto._meta.request_id);
    const given = await client.callTool({ name: "modx_update", arguments: { kind: "chunk", _request_id: "retry-id-0000000001" } });
    assert.equal(given._meta.request_id, "retry-id-0000000001");
    assert.deepEqual(site.state.requests.at(-1).args, { kind: "chunk" });
  });

  await t.test("the revision last seen is sent when the model omits expected_revision", async () => {
    const update = (args) => client.callTool({ name: "modx_update", arguments: { kind: "chunk", id: 7, ...args } });
    const sent = () => site.state.requests.at(-1).args.expected_revision;

    await update({});
    assert.equal(sent(), undefined, "nothing is known about an object that was never read");
    await client.callTool({ name: "modx_get", arguments: { kind: "chunk", id: 7 } });
    assert.equal((await update({})).isError, undefined);
    assert.equal(sent(), "r1", "revision from the read");
    assert.equal((await update({})).isError, undefined);
    assert.equal(sent(), "r2", "revision from the previous write");

    site.state.revision = "r9";   // somebody else changed the object
    const conflict = await update({});
    assert.equal(conflict.isError, true);
    assert.match(conflict.content[0].text, /revision_conflict/);
    await client.callTool({ name: "modx_get", arguments: { kind: "chunk", id: 7 } });
    assert.equal((await update({})).isError, undefined);
    assert.equal(sent(), "r9", "after re-reading, the write goes through");

    await update({ expected_revision: "r10" });
    assert.equal(sent(), "r10", "an explicit revision is never replaced");
    await client.callTool({ name: "modx_undo", arguments: { confirm: true } });
    await update({});
    assert.equal(sent(), undefined, "an undo forgets every remembered revision");
  });

  await t.test("invalid arguments are rejected before any request", async () => {
    const before = site.state.requests.length;
    const result = await client.callTool({ name: "modx_describe", arguments: { kind: "Not Valid" } });
    assert.equal(result.isError, true);
    assert.match(result.content[0].text, /Invalid arguments/);
    const unknown = await client.callTool({ name: "modx_site_info", arguments: { extra: 1 } });
    assert.equal(unknown.isError, true);
    assert.equal(site.state.requests.length, before);
  });

  await t.test("site errors carry the code and never the token", async () => {
    const result = await client.callTool({ name: "modx_describe", arguments: { kind: "nope" } });
    assert.equal(result.isError, true);
    assert.match(result.content[0].text, /MODX not_found \(HTTP 404\): Unknown kind 'nope'/);
    assert.match(result.content[0].text, /details=\{"hint":"list kinds"\}/);
    assert.ok(!result.content[0].text.includes(TOKEN));
    assert.match(result.content[0].text, /\[redacted\]/);
  });

  await t.test("an unknown tool is reported, not sent", async () => {
    const result = await client.callTool({ name: "modx_delete", arguments: {} });
    assert.equal(result.isError, true);
    assert.match(result.content[0].text, /not available for this token/);
  });

  await t.test("a changed manifest hash refreshes the tool list", async () => {
    const changed = new Promise((resolve) => {
      client.fallbackNotificationHandler = async (notification) => {
        if (notification.method === "notifications/tools/list_changed") resolve();
      };
    });
    site.state.hash = "h2";
    site.state.tools = site.state.tools.filter((item) => item.action !== "update");
    await client.callTool({ name: "modx_site_info", arguments: {} });
    await changed;
    const { tools } = await client.listTools();
    assert.ok(!tools.some((item) => item.name === "modx_update"));
    const gone = await client.callTool({ name: "modx_update", arguments: { kind: "chunk" } });
    assert.equal(gone.isError, true);
  });

  await t.test("the guide is offered as resources", async () => {
    const { resources } = await client.listResources();
    assert.deepEqual(resources.map((item) => item.uri), ["modx-help://index", "modx-help://acl"]);
    const { contents } = await client.readResource({ uri: "modx-help://acl" });
    assert.equal(contents[0].text, "# acl");
  });
});

test("a wrong token surfaces as a clear error and the bridge still starts", async (t) => {
  const site = await mockSite();
  const client = await connect(site.url, "mcp_" + "b".repeat(64));
  t.after(async () => { await client.close(); site.server.close(); });
  await assert.rejects(client.listTools(), /MODX unauthorized \(HTTP 401\)/);
});
