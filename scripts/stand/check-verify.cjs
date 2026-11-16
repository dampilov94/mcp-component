// Stand check: modx_audit_site, modx_run and the open-in-the-manager guard (tasks 27-29).
// Run by the architect session only, after scripts/stand/deploy.py. The token is never printed.
//   node scripts/stand/check-verify.cjs [resource-id]
// - modx_run is checked only while modxmcp.allow_run is on (turn it on in the manager, off afterwards).
// - The guard is checked only when a resource id is given that a person has OPEN in the manager right now;
//   that resource is never changed (only dry runs and refused writes are sent to it).
// Everything created is removed again by an undo to a checkpoint.
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
  const raw = (name, args = {}) => client.callTool({ name, arguments: args });
  const run = async (name, args = {}) => {
    const result = await raw(name, args);
    if (result.isError) throw new Error(`${name} ${JSON.stringify(args)} -> ${result.content[0].text}`);
    return result.structuredContent;
  };
  const step = (label) => console.log("ok  " + label);
  const tools = (await client.listTools()).tools.map((t) => t.name);
  const before = (await run("modx_site_info")).counts;

  // Audit: read-only, on the real database.
  const audit = await run("modx_audit_site", { limit: 3 });
  for (const [check, s] of Object.entries(audit.summary)) assert.ok(s.scanned >= 0 && s.count >= 0, check);
  step("audit: " + Object.entries(audit.summary).map(([c, s]) => `${c} ${s.count}/${s.scanned}`).join(", "));
  const onlyPhp = await run("modx_audit_site", { checks: ["php_syntax"] });
  assert.deepEqual(Object.keys(onlyPhp.summary), ["php_syntax"]);
  step("audit: the checks filter works");

  const checkpoint = await run("modx_checkpoint", { label: "verify stand check" });
  try {
    if (tools.includes("modx_run")) await checkRun(); else console.log("--  run: modxmcp.allow_run is off, skipped");
    if (process.argv[2]) await checkOpen(Number(process.argv[2])); else console.log("--  open in the manager: no resource id given, skipped");
  } finally {
    const back = await run("modx_undo", { to_checkpoint: checkpoint.checkpoint_id, confirm: true });
    assert.deepEqual((await run("modx_site_info")).counts, before);
    step(`undo to checkpoint (${back.changesets.length} changesets): counts as before`);
  }
  await client.close();
  console.log("ALL OK" + (tools.includes("modx_run") ? " — now turn modxmcp.allow_run off again" : ""));

  async function checkRun() {
    await run("modx_create", { kind: "snippet", fields: { name: "mcp2test_run", content: "echo 'напечатано;';\nreturn 'Привет, ' . $modx->getOption('who', $scriptProperties, 'мир') . ' [[!mcp2test_run_inner]]';" } });
    await run("modx_create", { kind: "snippet", fields: { name: "mcp2test_run_inner", content: "return 'внутри';" } });
    await run("modx_create", { kind: "snippet", fields: { name: "mcp2test_run_throw", content: "throw new RuntimeException('намеренная ошибка');" } });
    await run("modx_create", { kind: "chunk", fields: { name: "mcp2test_run_chunk", content: "<b>[[+name]]</b> [[$mcp2test_run_missing]]" } });

    const plan = await run("modx_run", { snippet: "mcp2test_run", dry_run: true });
    assert.equal(plan.dry_run, true);
    const s = await run("modx_run", { snippet: "mcp2test_run", properties: { who: "стенд" } });
    assert.equal(s.ok, true);
    assert.match(s.output, /Привет, стенд/);
    assert.match(s.output, /внутри/, "the uncacheable tag in the snippet output is processed");
    assert.match(s.output + s.echoed, /напечатано;/);
    step(`run snippet: "${s.output}" (${s.duration_ms} ms), echo ${s.echoed ? "outside" : "inside"} the output`);

    const c = await run("modx_run", { chunk: "mcp2test_run_chunk", properties: { name: "Имя" } });
    assert.match(c.output, /<b>Имя<\/b>/);
    assert.ok(c.unparsed_tags.some((t) => t.tag.includes("mcp2test_run_missing")), JSON.stringify(c.unparsed_tags));
    step(`run chunk: placeholders filled, missing chunk listed: ${JSON.stringify(c.unparsed_tags)}`);

    const t = await run("modx_run", { text: "[[mcp2test_run? &who=`тег`]] / [[++site_name]]" });
    assert.match(t.output, /Привет, тег/);
    step(`run text: "${t.output}"`);

    const e = await run("modx_run", { snippet: "mcp2test_run_throw" });
    assert.equal(e.ok, false);
    assert.equal(e.error.message, "намеренная ошибка");
    assert.ok(!e.error.file.startsWith("/"), e.error.file);
    step(`run: an exception is a result: ${e.error.type} at ${e.error.file}:${e.error.line}; new log entries ${e.new_log_errors}`);

    const after = await run("modx_site_info");
    assert.ok(after.site_name, "the API still works after the runs (context restored)");
    const page = await run("modx_check_page", { url_path: "/" });
    assert.equal(page.status, 200);
    step("after the runs the site and the API work as before");
  }

  async function checkOpen(id) {
    const res = await run("modx_get", { kind: "resource", id, fields: ["pagetitle", "longtitle"] });
    const refused = await raw("modx_update", { kind: "resource", id, fields: { longtitle: res.fields.longtitle + " (stand)" } });
    assert.equal(refused.isError, true, "the write must be refused: is the resource open in the manager?");
    assert.match(refused.content[0].text, /locked.*open_in_manager/s);
    const who = refused.content[0].text.match(/"username":"([^"]*)"/);
    step(`open resource ${id} "${res.fields.pagetitle}": write refused, open by ${who ? who[1] : "?"}`);
    const dry = await run("modx_update", { kind: "resource", id, fields: { longtitle: "x" }, dry_run: true });
    assert.ok(dry.open_in_manager && dry.open_in_manager.username, JSON.stringify(dry));
    step("dry run reports open_in_manager");
    assert.equal((await run("modx_get", { kind: "resource", id, fields: ["longtitle"] })).fields.longtitle, res.fields.longtitle);
    const again = await raw("modx_update", { kind: "resource", id, fields: { longtitle: res.fields.longtitle + " (stand)" } });
    assert.equal(again.isError, true, "the lock must still be there after being read");
    step("the lock is only read: still in place, the page unchanged");
  }
})().catch((error) => { console.error("FAILED:", error.message); process.exit(1); });
