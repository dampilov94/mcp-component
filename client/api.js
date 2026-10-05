const axios = require("axios");

class ModxApiError extends Error {
  constructor(message, httpStatus, errorCode) {
    super(message);
    this.name = "ModxApiError";
    this.httpStatus = httpStatus;
    this.errorCode = errorCode;
  }
}

function positiveSetting(name, fallback) {
  const value = process.env[name];
  if (value === undefined) return fallback;
  if (!/^\d+$/.test(value) || !Number.isSafeInteger(Number(value)) || Number(value) < 1) {
    throw new Error(`${name} must be a positive integer.`);
  }
  return Number(value);
}

class ModxApiClient {
  constructor(url, token, onCapabilities) {
    let endpoint;
    try { endpoint = new URL(url); } catch { throw new Error("MODX_MCP_SITE_URL must be an HTTP(S) URL."); }
    if (!["http:", "https:"].includes(endpoint.protocol) || endpoint.username || endpoint.password) {
      throw new Error("MODX_MCP_SITE_URL must be HTTP(S) without embedded credentials.");
    }
    this.url = endpoint.href;
    this.token = token;
    this.onCapabilities = onCapabilities;
    this.timeout = positiveSetting("MODX_MCP_TIMEOUT_MS", 60000);
    this.maxRequest = positiveSetting("MODX_MCP_MAX_REQUEST_BYTES", 1048576);
    this.maxResponse = positiveSetting("MODX_MCP_MAX_RESPONSE_BYTES", 4194304);
  }

  outcomeHint(requestId) {
    return requestId
      ? ` Outcome may be unknown. request_id=${requestId}. Check modx_get_request_status; reuse this ID as _request_id when retrying the same call.`
      : "";
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

  async post(payload, { signal } = {}) {
    const requestId = payload.request_id;
    if (requestId === this.token) throw new ModxApiError("A request ID must not be the API token; no request was sent.");
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
      if (!envelope || typeof envelope !== "object" || Array.isArray(envelope) || typeof envelope.success !== "boolean") {
        throw new ModxApiError("Endpoint returned an invalid response envelope.", response.status, "invalid_response");
      }
      if (envelope.error_id !== undefined && (typeof envelope.error_id !== "string" || !/^[A-Za-z0-9_.-]{1,128}$/.test(envelope.error_id))) {
        throw new ModxApiError("Endpoint returned invalid error ID metadata.", response.status, "invalid_response");
      }
      if (envelope.error_code !== undefined && (typeof envelope.error_code !== "string" || !/^[a-z0-9_]{1,64}$/.test(envelope.error_code))) {
        throw new ModxApiError("Endpoint returned invalid error-code metadata.", response.status, "invalid_response");
      }
      if (envelope.caps !== undefined && typeof envelope.caps !== "string") {
        throw new ModxApiError("Endpoint returned invalid capabilities metadata.", response.status, "invalid_response");
      }
      if (envelope.request_id !== undefined && envelope.request_id !== requestId) {
        throw new ModxApiError("Endpoint returned a different request ID.", response.status, "invalid_response");
      }
      if (response.headers?.["x-mcp-replayed"] !== "1" && envelope.caps !== undefined) {
        this.onCapabilities?.(envelope.caps);
      }
      if (!envelope.success) {
        if (typeof envelope.error !== "string" || !envelope.error) {
          throw new ModxApiError("Endpoint returned an invalid error envelope.", response.status, "invalid_response");
        }
        const errorId = typeof envelope.error_id === "string" ? ` error_id=${envelope.error_id}` : "";
        const id = requestId ? ` request_id=${requestId}` : "";
        const message = envelope.error.slice(0, 1500).split(this.token).join("[redacted]");
        throw new ModxApiError(`MODX HTTP ${response.status}: ${message}${errorId}${id}`.split(this.token).join("[redacted]"), response.status, envelope.error_code);
      }
      if (response.status < 200 || response.status >= 300 || !Object.prototype.hasOwnProperty.call(envelope, "data")) {
        throw new ModxApiError("Endpoint returned inconsistent success status or missing data.", response.status, "invalid_response");
      }
      return envelope;
    } catch (error) {
      if (error instanceof ModxApiError) {
        if (["invalid_response", "redirect_refused"].includes(error.errorCode)) error.message += this.outcomeHint(requestId);
        throw error;
      }
      let message = "MODX network request failed.";
      if (axios.isCancel(error) || signal?.aborted) message = "MODX request cancelled; stopping the client wait does not cancel PHP execution.";
      else if (["ECONNABORTED", "ETIMEDOUT"].includes(error.code)) message = `MODX request timed out after ${this.timeout} ms.`;
      else if (error.code === "ERR_BAD_RESPONSE") message = "MODX response exceeded its size limit or the connection ended before completion.";
      throw new ModxApiError(message + this.outcomeHint(requestId), undefined, "network_error");
    }
  }

  async health() {
    const response = await axios.get(this.url, this.options(undefined, Math.min(this.timeout, 5000)));
    if (response.status !== 200) throw new ModxApiError("Health probe failed.", response.status);
    const body = this.decode(response);
    if (!body || body.component !== "modxMCP" || typeof body.version !== "string") {
      throw new ModxApiError("Invalid health response.", response.status);
    }
    return body;
  }
}

module.exports = { ModxApiClient, ModxApiError };
