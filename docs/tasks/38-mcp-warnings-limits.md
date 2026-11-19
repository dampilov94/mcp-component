# 38 — MCP warnings and response limits

Branch `task/38-mcp-warnings-limits`, stacked on task 37 at `3385c5d`. Covers items 1 and 5
from the alpha2 review. Keep every tool's structured result and output schema compatible.

- Expose the kernel's warnings as a separate text block in both transports, including replayed
  results and failures carrying warnings. Keep the normal result or error in the first block.
- Enforce `modxmcp.max_response_bytes` on the actual MCP HTTP response, including legacy batches.
  An oversized result must explain how to check a write's status using its request ID. Reserve
  room for compact errors before executing a batch. An inadmissible batch must not start writes.
- Give each message in a legacy batch its own action context while keeping the session label.
  Test warnings, replay, isolated batch results, and oversized responses on the offline endpoint.

## Report

Based on: task/37-file-boundaries at `3385c5d`.

- The Node bridge and direct HTTP server append a separate, token-redacted text block for
  kernel warnings. The first text block and structured content keep their existing shapes.
  This also applies to errors carrying warnings and replayed envelopes.
- The kernel's byte limit applies to the serialized JSON-RPC response. A batch reserves room
  for a compact error per response before running any action. Each result that exceeds its
  available space becomes a `response_too_large` JSON-RPC error without removing the other
  results. A write's error identifies the request ID and explains how to check its status.
- The HTTP transport creates a fresh context per message with the same authenticated token and
  session label. The tests verify both warning delivery and the absence of warning or request ID
  carryover, and that a batch rejected up front never starts its first write.
- The HTTP integration checks use the actual kernel and SDK or raw loopback requests against
  fake MODX. `npm run check` and `npm test` passed in WSL on PHP 7.4.33 (5433 PHP checks and
  39 Node tests, no skips). On the stand, verify how a real client displays the warning and
  the compact error.
