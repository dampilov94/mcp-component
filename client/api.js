const axios = require("axios");

class ModxApiError extends Error {
  constructor(message, httpStatus, errorCode) {
    super(message);
    this.name = "ModxApiError";
    this.httpStatus = httpStatus;
    this.errorCode = errorCode;
  }
}

const PROTOCOL = 2;

function positiveSetting(name, fallback) {
  const value = process.env[name];
  if (value === undefined) return fallback;
  if (!/^\d+$/.test(value) || !Number.isSafeInteger(Number(value)) || Number(value) < 1) {
    throw new Error(`${name} must be a positive integer.`);
  }
  return Number(value);
}

function isObject(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

/** HTTP transport for modxMCP protocol 2. Knows the envelope, not the tools. */
class ModxApiClient {
  constructor(url, token, { sessionId, onManifestHash } = {}) {
    let endpoint;
    try { endpoint = new URL(url); } catch { throw new Error("MODX_MCP_SITE_URL must be an HTTP(S) URL."); }
    if (!["http:", "https:"].includes(endpoint.protocol) || endpoint.username || endpoint.password) {
      throw new Error("MODX_MCP_SITE_URL must be HTTP(S) without embedded credentials.");
    }
    this.url = endpoint.href;
    this.token = token;
    this.sessionId = sessionId;
    this.onManifestHash = onManifestHash;
    this.timeout = positiveSetting("MODX_MCP_TIMEOUT_MS", 60000);
    this.maxRequest = positiveSetting("MODX_MCP_MAX_REQUEST_BYTES", 1048576);
    this.maxResponse = positiveSetting("MODX_MCP_MAX_RESPONSE_BYTES", 4194304);
  }

  outcomeHint(requestId) {
    return requestId
      ? ` Outcome may be unknown. request_id=${requestId}. Check modx_request_status; reuse this ID as _request_id when retrying the same call.`
      : "";
  }

  redact(text) {
    return String(text).split(this.token).join("[redacted]");
  }

  decode(response) {
    const contentType = String(response.headers?.["content-type"] || "");
    if (!/^application\/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|\s*$)/i.test(contentType)) {
      throw new ModxApiError("Endpoint did not return application/json.", response.status, "invalid_response");
    }
    try {
      return JSON.parse(new TextDecoder("utf-8", { fatal: true }).decode(response.data));
    } catch {
      throw new ModxApiError("Endpoint returned invalid JSON or UTF-8.", response.status, "invalid_response");
    }
  }

  options(signal, timeout = this.timeout) {
    return {
      timeout,
      signal,
      maxRedirects: 0,
      maxBodyLength: this.maxRequest,
      maxContentLength: this.maxResponse,
      responseType: "arraybuffer",
      validateStatus: () => true,
    };
  }

  /**
   * Call one action. Resolves to { result, meta }; rejects with ModxApiError.
   * requestId is sent only for writes and makes the call safe to retry.
   */
  async call(action, args = {}, { requestId, signal } = {}) {
    if (requestId === this.token) throw new ModxApiError("A request ID must not be the API token; no request was sent.");
    const payload = { v: PROTOCOL, action, args };
    if (requestId) payload.request_id = requestId;
    if (this.sessionId) payload.session_id = this.sessionId;
    const body = JSON.stringify(payload);
    if (Buffer.byteLength(body, "utf8") > this.maxRequest) {
      throw new ModxApiError("Request exceeds MODX_MCP_MAX_REQUEST_BYTES; no request was sent.", undefined, "request_too_large");
    }
    try {
      const response = await axios.post(this.url, body, {
        ...this.options(signal),
        headers: { "X-MCP-Token": this.token, "Content-Type": "application/json; charset=utf-8" },
      });
      if (response.status >= 300 && response.status < 400) {
        throw new ModxApiError(`HTTP ${response.status}: redirects are disabled. Configure the final API URL.`, response.status, "redirect_refused");
      }
      const envelope = this.decode(response);
      if (!isObject(envelope) || typeof envelope.ok !== "boolean") {
        // A 1.x endpoint answers with {success: …}; say so instead of a generic envelope error.
        const legacy = isObject(envelope) && typeof envelope.success === "boolean";
        throw new ModxApiError(
          legacy
            ? "The site runs modxMCP 1.x, which this client does not support. Install modxMCP 2.x on the site."
            : "Endpoint returned an invalid response envelope.",
          response.status, legacy ? "unsupported_protocol" : "invalid_response",
        );
      }
      const meta = envelope.meta === undefined ? {} : envelope.meta;
      if (!isObject(meta)) throw new ModxApiError("Endpoint returned invalid meta.", response.status, "invalid_response");
      if (meta.request_id !== undefined && meta.request_id !== requestId) {
        throw new ModxApiError("Endpoint returned a different request ID.", response.status, "invalid_response");
      }
      if (meta.manifest !== undefined && typeof meta.manifest !== "string") {
        throw new ModxApiError("Endpoint returned invalid manifest metadata.", response.status, "invalid_response");
      }
      // A replayed response carries the manifest hash of the original call, not the current one.
      if (response.headers?.["x-mcp-replayed"] !== "1" && meta.manifest !== undefined) {
        this.onManifestHash?.(meta.manifest);
      }
      if (!envelope.ok) {
        const error = envelope.error;
        if (!isObject(error) || typeof error.message !== "string" || !error.message
          || typeof error.code !== "string" || !/^[a-z0-9_]{1,64}$/.test(error.code)
          || (error.error_id !== undefined && (typeof error.error_id !== "string" || !/^[A-Za-z0-9_.-]{1,128}$/.test(error.error_id)))) {
          throw new ModxApiError("Endpoint returned an invalid error envelope.", response.status, "invalid_response");
        }
        const details = error.details !== undefined ? ` details=${JSON.stringify(error.details).slice(0, 1500)}` : "";
        const errorId = error.error_id ? ` error_id=${error.error_id}` : "";
        const id = requestId ? ` request_id=${requestId}` : "";
        throw new ModxApiError(
          this.redact(`MODX ${error.code} (HTTP ${response.status}): ${error.message.slice(0, 1500)}${details}${errorId}${id}`),
          response.status, error.code,
        );
      }
      if (response.status < 200 || response.status >= 300 || !isObject(envelope.result)) {
        throw new ModxApiError("Endpoint returned inconsistent success status or a non-object result.", response.status, "invalid_response");
      }
      return { result: envelope.result, meta };
    } catch (error) {
      if (error instanceof ModxApiError) {
        if (["invalid_response", "redirect_refused"].includes(error.errorCode)) error.message += this.outcomeHint(requestId);
        throw error;
      }
      let message = "MODX network request failed.";
      if (axios.isCancel(error) || signal?.aborted) message = "MODX request cancelled; stopping the client wait does not cancel PHP execution.";
      else if (["ECONNABORTED", "ETIMEDOUT"].includes(error.code)) message = `MODX request timed out after ${this.timeout} ms.`;
      else if (error.code === "ERR_BAD_RESPONSE") message = "MODX response exceeded its size limit or the connection ended before completion.";
      else if (error.code) message = `MODX network request failed (${error.code}).`;
      throw new ModxApiError(message + this.outcomeHint(requestId), undefined, "network_error");
    }
  }
}

module.exports = { ModxApiClient, ModxApiError, PROTOCOL };
