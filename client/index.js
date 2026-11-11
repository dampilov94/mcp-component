#!/usr/bin/env node

// modx-mcp: a thin MCP bridge. Tools, their schemas and their effects are defined by the
// site (manifest action of modxMCP protocol 2); nothing about a tool lives in this client.

const { Server } = require("@modelcontextprotocol/sdk/server/index.js");
const { StdioServerTransport } = require("@modelcontextprotocol/sdk/server/stdio.js");
const {
  CallToolRequestSchema,
  ListToolsRequestSchema,
  ListResourcesRequestSchema,
  ReadResourceRequestSchema,
} = require("@modelcontextprotocol/sdk/types.js");
const { AjvJsonSchemaValidator } = require("@modelcontextprotocol/sdk/validation/ajv");
const { randomUUID } = require("node:crypto");
const fs = require("node:fs");
const path = require("node:path");
const { ModxApiClient, ModxApiError, PROTOCOL } = require("./api.js");

function loadLocalEnv() {
  const candidates = [path.resolve(process.cwd(), ".env"), path.resolve(__dirname, "..", ".env")];
  const loaded = new Set();
  for (const filePath of candidates) {
    if (loaded.has(filePath) || !fs.existsSync(filePath)) continue;
    loaded.add(filePath);
    for (const line of fs.readFileSync(filePath, "utf8").split(/\r?\n/)) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith("#")) continue;
      const separator = trimmed.indexOf("=");
      if (separator === -1) continue;
      const key = trimmed.slice(0, separator).trim();
      const value = trimmed.slice(separator + 1).trim();
      if (key && process.env[key] === undefined) process.env[key] = value.replace(/^["']|["']$/g, "");
    }
  }
}

loadLocalEnv();

const MODX_SITE_URL = process.env.MODX_MCP_SITE_URL;
const API_TOKEN = process.env.MODX_MCP_TOKEN;
if (!MODX_SITE_URL) {
  throw new Error("MODX_MCP_SITE_URL is required, e.g. https://your-site.com/assets/components/modxmcp/api.php");
}
if (!API_TOKEN) {
  throw new Error("MODX_MCP_TOKEN is required (create a token in the MODX manager: Components > modxMCP).");
}

let pkgInfo = { name: "modx-mcp", version: "0.0.0" };
try {
  pkgInfo = require(path.resolve(__dirname, "..", "package.json"));
} catch {
  // Keep the fallback identity if package.json can't be read.
}

const HELP_URI = "modx-help://";
const REQUEST_ID = {
  type: "string",
  pattern: "^[A-Za-z0-9_-]{16,128}$",
  description: "Optional request ID for retrying exactly the same call. Omit for a new operation; reuse the ID reported after a timeout. A new ID may execute the action again.",
};

let server = null;
/** Current manifest: { hash, instructions, tools: Map<name, tool> }, or null until loaded. */
let manifest = null;
const schemaProvider = new AjvJsonSchemaValidator();
let validators = new Map();

// The manifest hash rides on every API response. When it changes (a group was toggled, an
// add-on installed, the token's rights changed), tell the host the tool list changed.
function noteManifestHash(hash) {
  if (!manifest || manifest.hash === hash) return;
  manifest.stale = true;
  if (server) Promise.resolve(server.sendToolListChanged()).catch(() => {});
}

const api = new ModxApiClient(MODX_SITE_URL, API_TOKEN, { sessionId: randomUUID(), onManifestHash: noteManifestHash });

// Revisions of objects this session has read or written. If the model omits
// expected_revision, the remembered one is sent, so a write made on top of a stale read is
// refused by the site instead of silently overwriting someone else's change.
const seenRevisions = new Map();
const revisionKey = (kind, id) => `${kind}:${id}`;

function rememberRevisions(tool, result) {
  if (tool.action === "undo" && result.performed === true) {
    seenRevisions.clear();   // an undo moves any number of objects to older states
    return;
  }
  if (typeof result.kind !== "string" || !Number.isInteger(result.id)) return;
  if (result.deleted === true || typeof result.revision !== "string") seenRevisions.delete(revisionKey(result.kind, result.id));
  else if (result.dry_run !== true) seenRevisions.set(revisionKey(result.kind, result.id), result.revision);
}

function toMcpTool(tool) {
  const readOnly = tool.effect.read_only === true;
  const inputSchema = readOnly
    ? tool.inputSchema
    : { ...tool.inputSchema, properties: { ...tool.inputSchema.properties, _request_id: REQUEST_ID } };
  return {
    name: tool.name,
    description: tool.description,
    inputSchema,
    outputSchema: tool.outputSchema,
    annotations: {
      readOnlyHint: readOnly,
      destructiveHint: tool.effect.destructive === true,
      // A supplied _request_id protects one request; it does not make every call idempotent.
      idempotentHint: readOnly,
      openWorldHint: tool.effect.open_world === true,
    },
  };
}

function validManifestTool(tool) {
  return tool && typeof tool === "object"
    && typeof tool.name === "string" && /^[a-z][a-z0-9_]{0,63}$/.test(tool.name)
    && typeof tool.action === "string" && /^[a-z][a-z0-9_]{0,63}$/.test(tool.action)
    && typeof tool.description === "string"
    && tool.inputSchema && tool.inputSchema.type === "object"
    && tool.outputSchema && tool.outputSchema.type === "object"
    && tool.effect && typeof tool.effect === "object";
}

async function loadManifest(context = {}) {
  const { result } = await api.call("manifest", {}, context);
  if (result.protocol !== PROTOCOL) {
    throw new ModxApiError(`The site speaks modxMCP protocol ${result.protocol}; this client speaks ${PROTOCOL}. Update whichever is behind.`, undefined, "unsupported_protocol");
  }
  if (typeof result.hash !== "string" || !Array.isArray(result.tools) || !result.tools.every(validManifestTool)) {
    throw new ModxApiError("Endpoint returned an invalid manifest.", undefined, "invalid_response");
  }
  manifest = {
    hash: result.hash,
    instructions: typeof result.instructions === "string" ? result.instructions : undefined,
    tools: new Map(result.tools.map((tool) => [tool.name, { ...tool, mcp: toMcpTool(tool) }])),
    stale: false,
  };
  validators = new Map();
  return manifest;
}

async function currentManifest(context) {
  return manifest && !manifest.stale ? manifest : loadManifest(context);
}

function validator(kind, tool) {
  const key = `${kind}:${tool.name}`;
  let validate = validators.get(key);
  if (!validate) {
    validate = schemaProvider.getValidator(kind === "in" ? tool.mcp.inputSchema : tool.mcp.outputSchema);
    validators.set(key, validate);
  }
  return validate;
}

async function callTool(request, extra) {
  let requestId;
  try {
    const { name } = request.params;
    let tool = (await currentManifest({ signal: extra.signal })).tools.get(name);
    if (!tool) tool = (await loadManifest({ signal: extra.signal })).tools.get(name);
    if (!tool) throw new Error(`Tool ${name} is not available for this token. See modx_capabilities for the reason.`);

    const supplied = request.params.arguments ?? {};
    if (!supplied || typeof supplied !== "object" || Array.isArray(supplied)) throw new Error("Tool arguments must be an object.");
    const input = validator("in", tool)(supplied);
    if (!input.valid) throw new Error(`Invalid arguments: ${input.errorMessage}`);

    const args = { ...supplied };
    const write = tool.effect.read_only !== true;
    if (write) {
      requestId = args._request_id || randomUUID();
      delete args._request_id;
    }
    const tracked = write && tool.inputSchema.properties?.expected_revision && typeof args.kind === "string" && Number.isInteger(args.id)
      ? revisionKey(args.kind, args.id) : null;
    if (tracked && args.expected_revision === undefined && seenRevisions.has(tracked)) {
      args.expected_revision = seenRevisions.get(tracked);
    }
    let result;
    try {
      ({ result } = await api.call(tool.action, args, { requestId, signal: extra.signal }));
    } catch (error) {
      // After a conflict the remembered revision is known to be stale; the next read replaces it.
      if (tracked && error.errorCode === "revision_conflict") seenRevisions.delete(tracked);
      throw error;
    }
    rememberRevisions(tool, result);
    const output = validator("out", tool)(result);
    if (!output.valid) {
      const hint = requestId ? ` The action may have completed; check modx_request_status for request_id=${requestId}.` : "";
      throw new Error(`Unexpected tool result: ${output.errorMessage}.${hint}`);
    }
    return {
      content: [{ type: "text", text: JSON.stringify(result) }],
      structuredContent: result,
      ...(requestId ? { _meta: { request_id: requestId } } : {}),
    };
  } catch (error) {
    return {
      content: [{ type: "text", text: `Error: ${error.message || "Tool request failed."}` }],
      isError: true,
      ...(requestId ? { _meta: { request_id: requestId } } : {}),
    };
  }
}

// The built-in guide is also offered as MCP resources, for hosts that browse them.
async function listResources(extra) {
  const current = await currentManifest({ signal: extra.signal });
  if (!current.tools.has("modx_help")) return { resources: [] };
  const { result } = await api.call("help", {}, { signal: extra.signal });
  const topics = Array.isArray(result.topics) ? result.topics.filter((topic) => typeof topic === "string") : [];
  return {
    resources: topics.map((topic) => ({
      uri: HELP_URI + topic,
      name: `modxMCP guide: ${topic}`,
      mimeType: "text/markdown",
    })),
  };
}

async function readResource(request, extra) {
  const uri = String(request.params.uri);
  const topic = uri.startsWith(HELP_URI) ? uri.slice(HELP_URI.length) : "";
  if (!/^[a-z0-9_]{1,64}$/.test(topic)) throw new Error(`Unknown resource: ${uri}`);
  const { result } = await api.call("help", { topic }, { signal: extra.signal });
  return { contents: [{ uri, mimeType: "text/markdown", text: String(result.content ?? "") }] };
}

async function main() {
  // Load the manifest first so the host gets the site's own instructions. A site that is
  // down now must not prevent the bridge from starting; the next tools/list retries.
  try {
    await loadManifest();
  } catch (error) {
    console.error(`modx-mcp: cannot load the tool manifest yet: ${error.message}`);
  }
  server = new Server(
    { name: pkgInfo.name || "modx-mcp", version: pkgInfo.version || "0.0.0" },
    {
      capabilities: { tools: { listChanged: true }, resources: {} },
      ...(manifest?.instructions ? { instructions: manifest.instructions } : {}),
    },
  );
  server.setRequestHandler(ListToolsRequestSchema, async (_request, extra) => {
    const current = await loadManifest({ signal: extra.signal });
    return { tools: [...current.tools.values()].map((tool) => tool.mcp) };
  });
  server.setRequestHandler(CallToolRequestSchema, callTool);
  server.setRequestHandler(ListResourcesRequestSchema, (_request, extra) => listResources(extra));
  server.setRequestHandler(ReadResourceRequestSchema, readResource);
  await server.connect(new StdioServerTransport());
  console.error("modx-mcp started.");
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
