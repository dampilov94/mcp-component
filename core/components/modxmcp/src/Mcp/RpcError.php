<?php
namespace ModxMCP\Mcp;

/** A JSON-RPC error of the MCP handler, with the HTTP status the transport gives it. */
final class RpcError extends \Exception {
    const PARSE_ERROR = -32700;
    const INVALID_REQUEST = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS = -32602;
    const INTERNAL_ERROR = -32603;
    /** Resource not found in the eras that have the initialize handshake (the 2026 era uses INVALID_PARAMS). */
    const RESOURCE_NOT_FOUND = -32002;
    const HEADER_MISMATCH = -32020;
    const UNSUPPORTED_PROTOCOL_VERSION = -32022;

    public $rpcCode;
    public $data;
    public $httpStatus;

    /** @param mixed $data */
    public function __construct($rpcCode, $message, $httpStatus = 200, $data = null) {
        parent::__construct($message);
        $this->rpcCode = $rpcCode;
        $this->httpStatus = $httpStatus;
        $this->data = $data;
    }

    /** The "error" member of a response. */
    public function toError() {
        $error = array('code' => $this->rpcCode, 'message' => $this->getMessage());
        if ($this->data !== null) { $error['data'] = $this->data; }
        return $error;
    }
}
