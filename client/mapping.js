// The manifest entries of the site as MCP tools and prompts. The PHP side makes the same mapping
// (src/Mcp/Catalog.php) for MCP over HTTP; tests compare the two, so change them together.

const REQUEST_ID = {
  type: "string",
  pattern: "^[A-Za-z0-9_-]{16,128}$",
  description: "Optional request ID for retrying exactly the same call. Omit for a new operation; reuse the ID reported after a timeout. A new ID may execute the action again.",
};

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

function toMcpPrompt(prompt) {
  return {
    name: prompt.name,
    ...(prompt.title ? { title: prompt.title } : {}),
    description: prompt.description,
    arguments: (prompt.arguments ?? []).map((argument) => ({
      name: argument.name,
      ...(typeof argument.description === "string" ? { description: argument.description } : {}),
      required: argument.required === true,
    })),
  };
}

module.exports = { REQUEST_ID, toMcpTool, toMcpPrompt };
