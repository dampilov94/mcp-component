<?php
namespace ModxMCP\Mcp;

use ModxMCP\Kernel;

/**
 * The stateless era of MCP (revision 2026-07-28): no handshake, every request carries its protocol version and
 * client metadata in `_meta` and mirrors the method and name in HTTP headers. Everything specific to that revision
 * lives here, so it can follow the specification as it moves; HttpServer only asks "is this request modern?".
 *
 * Specification: basic/transports/streamable-http (request metadata, server validation), basic/versioning,
 * basic/index (_meta, error codes), server/discover, server/utilities/caching.
 */
final class ModernEra {
    const VERSION = '2026-07-28';
    const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

    /** Methods whose result carries caching hints (ttlMs, cacheScope). */
    private static $cacheable = array('server/discover', 'tools/list', 'prompts/list', 'resources/list', 'resources/templates/list', 'resources/read');

    /** Methods whose body value is mirrored in Mcp-Name, and where it sits in params. */
    private static $named = array('tools/call' => 'name', 'prompts/get' => 'name', 'resources/read' => 'uri');

    /** Is the header or the body of this request in this era's shape? */
    public static function claims($headerVersion, $message) {
        if ($headerVersion !== null) { return $headerVersion === self::VERSION; }
        return is_array($message) && isset($message['params']) && is_array($message['params'])
            && isset($message['params']['_meta']) && is_array($message['params']['_meta'])
            && array_key_exists(self::META_VERSION, $message['params']['_meta']);
    }

    /**
     * Check a request against the rules of the revision. Throws RpcError with HTTP 400 (or 404 for an unknown method
     * when $known says so).
     * @param array $server   $_SERVER
     * @param array $message  decoded request (an associative array)
     * @param string[] $methods methods this server implements
     */
    public static function validate(array $server, array $message, array $methods) {
        $id = array_key_exists('id', $message) ? $message['id'] : null;
        $header = HttpServer::header($server, 'MCP-Protocol-Version');
        if ($header === null || $header === '') {
            throw new RpcError(RpcError::HEADER_MISMATCH, 'Header mismatch: the MCP-Protocol-Version header is required.', 400);
        }
        $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : array();
        $meta = isset($params['_meta']) && is_array($params['_meta']) ? $params['_meta'] : null;
        if ($meta === null || !isset($meta[self::META_VERSION]) || !is_string($meta[self::META_VERSION])
            || !isset($meta[self::META_CAPABILITIES]) || !is_array($meta[self::META_CAPABILITIES])) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'Invalid params: _meta must carry ' . self::META_VERSION . ' and ' . self::META_CAPABILITIES . '.', 400);
        }
        if ($meta[self::META_VERSION] !== $header) {
            throw new RpcError(RpcError::HEADER_MISMATCH, 'Header mismatch: the MCP-Protocol-Version header does not match _meta.', 400);
        }
        $method = isset($message['method']) && is_string($message['method']) ? $message['method'] : '';
        $mcpMethod = HttpServer::header($server, 'Mcp-Method');
        if ($mcpMethod === null) {
            throw new RpcError(RpcError::HEADER_MISMATCH, 'Header mismatch: the Mcp-Method header is required.', 400);
        }
        if ($mcpMethod !== $method) {
            throw new RpcError(RpcError::HEADER_MISMATCH, 'Header mismatch: the Mcp-Method header does not match the method of the body.', 400);
        }
        if (!in_array($method, $methods, true)) {
            throw new RpcError(RpcError::METHOD_NOT_FOUND, 'Method not found: ' . $method, 404);
        }
        if (isset(self::$named[$method])) {
            $field = self::$named[$method];
            $name = HttpServer::header($server, 'Mcp-Name');
            if ($name === null) {
                throw new RpcError(RpcError::HEADER_MISMATCH, 'Header mismatch: the Mcp-Name header is required for ' . $method . '.', 400);
            }
            $decoded = self::decodeHeaderValue($name);
            if ($decoded === null || !isset($params[$field]) || !is_string($params[$field]) || $decoded !== $params[$field]) {
                throw new RpcError(RpcError::HEADER_MISMATCH, 'Header mismatch: the Mcp-Name header does not match params.' . $field . '.', 400);
            }
        }
    }

    /** A header value as the body would carry it: plain, or the =?base64?…?= sentinel form. Null when malformed. */
    public static function decodeHeaderValue($value) {
        if (strncmp($value, '=?base64?', 9) === 0 && substr($value, -2) === '?=' && strlen($value) >= 11) {
            $bytes = base64_decode(substr($value, 9, -2), true);
            return $bytes === false || !preg_match('//u', $bytes) ? null : $bytes;
        }
        return $value;
    }

    /** What server/discover returns. */
    public static function discover(array $capabilities, $instructions, array $supportedVersions) {
        return array(
            'supportedVersions' => array_values($supportedVersions),
            'capabilities'      => $capabilities,
            'instructions'      => $instructions,
        );
    }

    /**
     * Add what every result of the revision carries: resultType, the server's identity and, for lists and reads,
     * caching hints. The lists depend on the token, so they are private and always stale: a client re-reads them.
     * @param array $result associative result
     */
    public static function decorate($method, array $result) {
        $meta = isset($result['_meta']) && is_array($result['_meta']) ? $result['_meta'] : array();
        $meta[self::META_SERVER_INFO] = array('name' => 'modxMCP', 'version' => Kernel::VERSION);
        $result = array('resultType' => 'complete') + $result;
        if (in_array($method, self::$cacheable, true)) {
            $result['ttlMs'] = 0;
            $result['cacheScope'] = 'private';
        }
        $result['_meta'] = $meta;
        return $result;
    }
}
