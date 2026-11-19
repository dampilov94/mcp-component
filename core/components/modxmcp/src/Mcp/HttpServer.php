<?php
namespace ModxMCP\Mcp;

use ModxMCP\ApiException;
use ModxMCP\Action\ManifestAction;
use ModxMCP\Context;
use ModxMCP\Kernel;
use ModxMCP\Manifest;
use ModxMCP\Request\RequestStore;

/**
 * MCP over Streamable HTTP, answered by the site itself. Stateless: every request is answered with one JSON
 * document (never SSE), nothing is kept between requests.
 *
 * Two eras share the URL. Clients up to 2025-11-25 open with `initialize`; the revision 2026-07-28 has no handshake
 * and is handled by ModernEra. Tools run through Kernel::dispatch(), the pipeline protocol 2 uses, so scope, group,
 * schema validation, the service user, replay protection and the journal behave the same.
 *
 * What the bridge does that a stateless endpoint cannot: it remembers the revisions a session has read and sends
 * them as expected_revision when the model omits it, and it tells the host at once that the tool list changed.
 */
final class HttpServer {
    /** Versions served through the initialize handshake, newest first. */
    const LEGACY_VERSIONS = array('2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05');
    /** What a request without MCP-Protocol-Version is taken for (2025-06-18 transports). */
    const DEFAULT_VERSION = '2025-03-26';
    private const REQUEST_ID_BYTES = 16;

    private static $legacyMethods = array('ping', 'tools/list', 'tools/call', 'prompts/list', 'prompts/get', 'resources/list', 'resources/read', 'resources/templates/list');

    private $kernel;
    private $modx;
    private $presented = '';
    private $sessionHeader = null;

    public function __construct(Kernel $kernel, \modX $modx) {
        $this->kernel = $kernel;
        $this->modx = $modx;
    }

    // ---- what the kernel asks before it has a server ----------------------------------------------------------

    /** An HTTP request header, or null when absent. */
    public static function header(array $server, $name) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($server[$key]) && is_string($server[$key]) ? trim($server[$key]) : null;
    }

    /** A GET that asks for an event stream (or comes from an MCP client): there is none, answer 405. */
    public static function wantsStream(array $server) {
        $accept = isset($server['HTTP_ACCEPT']) ? (string) $server['HTTP_ACCEPT'] : '';
        return stripos($accept, 'text/event-stream') !== false || self::header($server, 'MCP-Protocol-Version') !== null;
    }

    /** Does a POST body look like JSON-RPC (an object, or a non-empty list, whose first message says "jsonrpc": "2.0")? */
    public static function isJsonRpc($raw, $oversize = false) {
        if ($oversize) {
            $head = substr($raw, 0, 4096);
            return preg_match('/^\s*[\[{]/', $head) === 1 && preg_match('/"jsonrpc"\s*:\s*"2\.0"/', $head) === 1;
        }
        $trimmed = ltrim($raw);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) { return false; }
        $decoded = json_decode($raw, true, 512);
        if (!is_array($decoded) || $decoded === array()) { return false; }
        $first = isset($decoded['jsonrpc']) ? $decoded : (array_keys($decoded) === range(0, count($decoded) - 1) ? $decoded[0] : null);
        return is_array($first) && isset($first['jsonrpc']) && $first['jsonrpc'] === '2.0';
    }

    /** The site's own origin or a listed one; a request without Origin (command-line clients) passes. */
    public static function checkOrigin(array $server, \modX $modx) {
        if (!isset($server['HTTP_ORIGIN'])) { return; }
        $origin = self::normalizeOrigin($server['HTTP_ORIGIN']);
        $allowed = array(self::normalizeOrigin((string) $modx->getOption('site_url')));
        foreach (explode(',', (string) $modx->getOption('modxmcp.allowed_origins', null, '')) as $item) {
            $allowed[] = self::normalizeOrigin($item);
        }
        if ($origin === null || !in_array($origin, $allowed, true)) {
            throw new ApiException('origin_not_allowed', 'Forbidden: the Origin is not allowed (modxmcp.allowed_origins).', array(), 403);
        }
    }

    /** scheme://host[:port] in lower case without a default port, or null. */
    public static function normalizeOrigin($value) {
        $parts = @parse_url(trim((string) $value));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) { return null; }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') { return null; }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) { $port = null; }
        return $scheme . '://' . strtolower($parts['host']) . ($port !== null ? ':' . $port : '');
    }

    public static function methodNotAllowed() {
        return self::frame(405, self::errorDocument(null, new RpcError(RpcError::INVALID_REQUEST, 'Method Not Allowed', 405, array('code' => 'method_not_allowed'))), array('Allow: POST'));
    }

    /** A refusal before the MCP handler ran (disabled, HTTPS, IP, Origin, token): a JSON-RPC error with the HTTP status. */
    public static function refusal(ApiException $e) {
        $headers = $e->errorCode === 'unauthorized' ? array('WWW-Authenticate: Bearer realm="modxMCP"') : array();
        $rpc = new RpcError(RpcError::INVALID_REQUEST, $e->getMessage(), $e->httpStatus, array('code' => $e->errorCode));
        return self::frame($e->httpStatus, self::errorDocument(null, $rpc), $headers);
    }

    /** @param array $error the "error" member of a protocol-2 envelope (code, message, error_id, details) */
    public static function internalFrame(array $error, $limit) {
        $data = array('code' => isset($error['code']) ? $error['code'] : 'internal_error');
        if (isset($error['error_id'])) { $data['error_id'] = $error['error_id']; }
        if (isset($error['details'])) { $data['details'] = $error['details']; }
        $rpc = new RpcError(RpcError::INTERNAL_ERROR, isset($error['message']) ? $error['message'] : 'Internal Server Error', 500, $data);
        return self::frame(500, self::errorDocument(null, $rpc), array(), $limit);
    }

    // ---- the request -------------------------------------------------------------------------------------------

    /**
     * @param string $raw request body
     * @param array $token authenticated token record
     * @param string $presented the token value, kept only to scrub it from error texts
     * @return array frame (status, body, headers)
     */
    public function handle(array $server, $raw, $oversize, array $token, $presented) {
        $this->presented = (string) $presented;
        $this->sessionHeader = null;
        try {
            return $this->process($server, $raw, $oversize, $token);
        } catch (RpcError $e) {
            return self::frame($e->httpStatus, self::errorDocument(null, $e), array(), $this->kernel->responseLimit());
        }
    }

    private function process(array $server, $raw, $oversize, array $token) {
        $contentType = isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : '';
        if (!preg_match('~^application/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|\s*$)~i', $contentType)) {
            throw new RpcError(RpcError::INVALID_REQUEST, 'Content-Type must be application/json.', 415, array('code' => 'unsupported_media_type'));
        }
        if ($oversize) {
            throw new RpcError(RpcError::INVALID_REQUEST, 'Payload Too Large', 413, array('code' => 'request_too_large'));
        }
        try {
            $objects = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            $arrays = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RpcError(RpcError::PARSE_ERROR, 'Parse error: invalid JSON or UTF-8.', 400);
        }
        if (!is_array($arrays) || $arrays === array()) {
            throw new RpcError(RpcError::INVALID_REQUEST, 'Invalid request: the body must be a JSON-RPC message or a non-empty batch.', 400);
        }
        $batch = array_keys($arrays) === range(0, count($arrays) - 1);

        $header = self::header($server, 'MCP-Protocol-Version');
        if ($header !== null && !in_array($header, self::versions(), true)) {
            throw new RpcError(RpcError::UNSUPPORTED_PROTOCOL_VERSION, 'Unsupported protocol version', 400, array('supported' => self::versions(), 'requested' => $header));
        }
        if ($batch && $header === ModernEra::VERSION) {
            throw new RpcError(RpcError::INVALID_REQUEST, 'Batches are not supported by protocol version ' . ModernEra::VERSION . '.', 400);
        }
        $messages = $batch ? $arrays : array($arrays);
        $shapes = $batch ? $objects : array($objects);

        $sessionId = null;
        if (!($header === ModernEra::VERSION)) {
            $given = self::header($server, 'Mcp-Session-Id');
            if ($given !== null && RequestStore::validId($given)) { $sessionId = $given; }
        }
        $available = $this->kernel->responseLimit() - ($batch ? 2 : 0); // enclosing brackets
        $reserved = array();
        // Before running any action, make sure each message that can answer has room for a compact error.
        foreach ($messages as $index => $rawMessage) {
            $message = is_array($rawMessage) ? $rawMessage : array();
            if (($message['jsonrpc'] ?? null) === '2.0' && is_string($message['method'] ?? null) && !array_key_exists('id', $message)) {
                continue;
            }
            $possibleWrite = ($message['method'] ?? null) === 'tools/call';
            $supplied = $message['params']['arguments']['_request_id'] ?? null;
            $reservedId = null;
            if ($possibleWrite) {
                $reservedId = RequestStore::validId($supplied) ? $supplied : 'mcp_' . str_repeat('0', self::REQUEST_ID_BYTES * 2);
            }
            $reserved[$index] = strlen(self::encode(self::sizeError(self::responseId($message), $reservedId))) + ($batch ? 1 : 0);
            $available -= $reserved[$index];
            if ($available < 0) {
                throw new RpcError(RpcError::INVALID_REQUEST, 'The request needs too much space for a bounded response. Use smaller batches or shorter IDs.', 413,
                    array('code' => $batch ? 'batch_too_large' : 'request_id_too_large'));
            }
        }
        $responses = array();
        $status = 200;
        foreach ($reserved as $index => $reservation) {
            $message = is_array($messages[$index]) ? $messages[$index] : array();
            $context = $this->kernel->newContext($token, $sessionId, null);
            $outcome = $this->message($server, $header, $message, $shapes[$index], $context, !$batch);
            $available += $reservation;
            if ($outcome === null) { continue; }
            if (!$batch) { $status = $outcome[1]; }
            $json = self::encode($outcome[0]);
            $separator = $batch ? 1 : 0;
            if ($json === false || strlen($json) + $separator > $available) {
                $json = self::encode(self::sizeError(self::responseId($message), $context->requestId, $json === false));
                if (!$batch) { $status = 500; }
            }
            $responses[] = $json;
            $available -= strlen($json) + $separator;
        }
        $headers = $this->sessionHeader !== null ? array('Mcp-Session-Id: ' . $this->sessionHeader) : array();
        if (!$responses) { return array('status' => 202, 'body' => '', 'headers' => $headers); }
        return array('status' => $status, 'body' => $batch ? '[' . implode(',', $responses) . ']' : $responses[0], 'headers' => $headers);
    }

    private static function responseId(array $message) {
        $id = $message['id'] ?? null;
        return is_string($id) || is_int($id) ? $id : null;
    }

    /** @return array|null [response document, HTTP status], or null when the message needs no answer */
    private function message(array $server, $header, array $message, $shape, Context $context, $single) {
        $id = array_key_exists('id', $message) ? $message['id'] : null;
        $validId = is_string($id) || is_int($id);
        if (!isset($message['jsonrpc']) || $message['jsonrpc'] !== '2.0') {
            return array(self::errorDocument($validId ? $id : null, new RpcError(RpcError::INVALID_REQUEST, 'Invalid request: "jsonrpc" must be "2.0".')), 200);
        }
        if (!isset($message['method']) || !is_string($message['method'])) {
            // A response from the client carries nothing for a stateless server.
            if (array_key_exists('id', $message) && (array_key_exists('result', $message) || array_key_exists('error', $message))) { return null; }
            return array(self::errorDocument($validId ? $id : null, new RpcError(RpcError::INVALID_REQUEST, 'Invalid request: "method" is required.')), 200);
        }
        if (!array_key_exists('id', $message)) { return null; }   // a notification: accepted, nothing to answer
        if (!$validId) {
            return array(self::errorDocument(null, new RpcError(RpcError::INVALID_REQUEST, 'Invalid request: "id" must be a string or an integer.')), 200);
        }

        $method = $message['method'];
        $modern = $method !== 'initialize' && ModernEra::claims($header, $message);
        try {
            $version = $modern ? ModernEra::VERSION : ($header !== null ? $header : self::DEFAULT_VERSION);
            if ($modern) {
                ModernEra::validate($server, $message, array_merge(self::$legacyMethods, array('server/discover')));
            } elseif (!in_array($method, self::$legacyMethods, true) && $method !== 'initialize') {
                throw new RpcError(RpcError::METHOD_NOT_FOUND, 'Method not found: ' . $method);
            }
            if (isset($message['params']) && !is_array($message['params'])) {
                throw new RpcError(RpcError::INVALID_PARAMS, 'Invalid params: params must be an object.', $modern ? 400 : 200);
            }
            $params = isset($message['params']) ? $message['params'] : array();
            $paramsObject = is_object($shape) && isset($shape->params) && is_object($shape->params) ? $shape->params : new \stdClass();
            $result = $this->call($method, $params, $paramsObject, $context, $version, $modern);
            if ($modern) { $result = ModernEra::decorate($method, (array) $result); }
            return array(array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result), 200);
        } catch (RpcError $e) {
            return array(self::errorDocument($id, $e), $single && $modern ? $e->httpStatus : 200);
        } catch (\Throwable $e) {
            // Includes ApiException from the service user or the registries: an internal problem of a list call.
            list($httpStatus, $body) = $this->kernel->errorBody($e, 'mcp ' . $method);
            $error = $body['error'];
            $data = array('code' => $error['code']);
            if (isset($error['error_id'])) { $data['error_id'] = $error['error_id']; }
            if (isset($error['details'])) { $data['details'] = $error['details']; }
            $rpc = new RpcError(RpcError::INTERNAL_ERROR, $this->scrub($error['message']), 500, $data);
            return array(self::errorDocument($id, $rpc), $single && $modern ? 500 : 200);
        }
    }

    /** @return array|\stdClass */
    private function call($method, array $params, $paramsObject, Context $context, $version, $modern) {
        switch ($method) {
            case 'initialize':
                return $this->initialize($params);
            case 'ping':
                return new \stdClass();
            case 'server/discover':
                return ModernEra::discover(self::capabilities(), ManifestAction::INSTRUCTIONS, self::versions());
            case 'tools/list':
                $this->kernel->actAsServiceUser();
                return array('tools' => Catalog::tools($context, Catalog::structured($version)));
            case 'tools/call':
                return $this->callTool($params, $paramsObject, $context, $version, $modern);
            case 'prompts/list':
                $this->kernel->actAsServiceUser();
                return array('prompts' => Catalog::prompts($context));
            case 'prompts/get':
                return $this->getPrompt($params, $paramsObject, $context);
            case 'resources/list':
                return $this->listResources($context);
            case 'resources/templates/list':
                return array('resourceTemplates' => array());
            case 'resources/read':
                return $this->readResource($params, $context, $modern);
        }
        throw new RpcError(RpcError::METHOD_NOT_FOUND, 'Method not found: ' . $method);
    }

    // ---- methods -----------------------------------------------------------------------------------------------

    public static function versions() {
        return array_merge(array(ModernEra::VERSION), self::LEGACY_VERSIONS);
    }

    public static function capabilities() {
        // A stateless endpoint cannot push: lists refresh when the client reconnects.
        return array(
            'tools'     => array('listChanged' => false),
            'prompts'   => array('listChanged' => false),
            'resources' => array('subscribe' => false, 'listChanged' => false),
        );
    }

    private function initialize(array $params) {
        if (!isset($params['protocolVersion']) || !is_string($params['protocolVersion'])) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'Invalid params: protocolVersion is required.');
        }
        $version = in_array($params['protocolVersion'], self::LEGACY_VERSIONS, true) ? $params['protocolVersion'] : self::LEGACY_VERSIONS[0];
        // The session id is only a label for the journal; nothing is stored under it.
        $this->sessionHeader = bin2hex(random_bytes(16));
        return array(
            'protocolVersion' => $version,
            'capabilities'    => self::capabilities(),
            'serverInfo'      => array('name' => 'modxMCP', 'version' => Kernel::VERSION),
            'instructions'    => ManifestAction::INSTRUCTIONS,
        );
    }

    private function callTool(array $params, $paramsObject, Context $context, $version, $modern) {
        if (!isset($params['name']) || !is_string($params['name'])) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'Invalid params: name is required.', $modern ? 400 : 200);
        }
        $name = $params['name'];
        $status = $modern ? 400 : 200;
        $action = null;
        if (strncmp($name, Manifest::TOOL_PREFIX, strlen(Manifest::TOOL_PREFIX)) === 0) {
            $action = $context->actions->get(substr($name, strlen(Manifest::TOOL_PREFIX)));
        }
        if ($action === null || !$action->exposed() || $context->actions->blockReason($action, $context) !== null) {
            throw new RpcError(RpcError::INVALID_PARAMS, "Unknown tool: {$name}. It may be unavailable for this token; see modx_capabilities.", $status);
        }
        $shape = isset($paramsObject->arguments) ? $paramsObject->arguments : new \stdClass();
        if (!($shape instanceof \stdClass)) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'Invalid params: arguments must be an object.', $status);
        }
        $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : array();

        $requestId = null;
        if (!$action->readOnly()) {
            if (array_key_exists('_request_id', $args)) {
                $requestId = $args['_request_id'];
                if (!RequestStore::validId($requestId)) {
                    return $this->toolFailure('validation_failed', '_request_id must contain 16-128 letters, digits, underscores or hyphens.', null);
                }
                if ($this->scrub($requestId) !== $requestId) {
                    return $this->toolFailure('validation_failed', '_request_id cannot contain the API token.', null);
                }
                unset($args['_request_id'], $shape->_request_id);
            } else {
                // Every write is protected against a retry, as the bridge does.
                $requestId = 'mcp_' . bin2hex(random_bytes(self::REQUEST_ID_BYTES));
            }
        }
        $context->requestId = $requestId;
        try {
            $frame = $this->kernel->dispatch($context, $action->name(), $args, $shape, $requestId);
            $envelope = json_decode($frame['body']);
        } catch (\Throwable $e) {
            list($httpStatus, $body) = $this->kernel->errorBody($e, $name);
            $error = $body['error'];
            return $this->toolFailure($error['code'], $error['message'], $requestId, isset($error['details']) ? $error['details'] : null, isset($error['error_id']) ? $error['error_id'] : null);
        }
        if (!is_object($envelope) || !isset($envelope->ok)) {
            return $this->toolFailure('internal_error', 'The site returned an unreadable result.' . self::outcomeHint($requestId), $requestId);
        }
        if ($envelope->ok !== true) {
            $error = (array) $envelope->error;
            $failure = $this->toolFailure(
                isset($error['code']) ? $error['code'] : 'internal_error',
                isset($error['message']) ? $error['message'] : 'Tool request failed.',
                $requestId,
                isset($error['details']) ? $error['details'] : null,
                isset($error['error_id']) ? $error['error_id'] : null
            );
            return $this->withWarnings($failure, $envelope->meta->warnings ?? null);
        }
        $result = array('content' => array(array('type' => 'text', 'text' => json_encode($envelope->result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))));
        if (Catalog::structured($version)) { $result['structuredContent'] = $envelope->result; }
        $result['isError'] = false;
        if ($requestId !== null) { $result['_meta'] = array('request_id' => $requestId); }
        return $this->withWarnings($result, $envelope->meta->warnings ?? null);
    }

    private function withWarnings(array $result, $warnings) {
        if (!is_array($warnings)) { return $result; }
        $warnings = array_filter($warnings, 'is_string');
        if ($warnings) {
            $result['content'][] = array('type' => 'text', 'text' => $this->scrub("Warnings:\n- " . implode("\n- ", $warnings)));
        }
        return $result;
    }

    /** A failed action is a tool result, not a JSON-RPC error. The token is scrubbed from the text. */
    private function toolFailure($code, $message, $requestId, $details = null, $errorId = null) {
        $text = 'MODX ' . $code . ': ' . $this->clip((string) $message);
        if ($details !== null) { $text .= ' details=' . $this->clip((string) json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
        if ($errorId !== null) { $text .= ' error_id=' . $errorId; }
        if ($requestId !== null) { $text .= ' request_id=' . $requestId; }
        $result = array('content' => array(array('type' => 'text', 'text' => $this->scrub($text))), 'isError' => true);
        if ($requestId !== null) { $result['_meta'] = array('request_id' => $requestId); }
        return $result;
    }

    private static function outcomeHint($requestId) {
        return $requestId !== null ? " The action may have completed; check modx_request_status for request_id={$requestId}." : '';
    }

    private function clip($text) {
        return function_exists('mb_substr') ? mb_substr($text, 0, 1500, 'UTF-8') : substr($text, 0, 1500);
    }

    private function scrub($text) {
        return $this->presented !== '' ? str_replace($this->presented, '[redacted]', (string) $text) : (string) $text;
    }

    private function getPrompt(array $params, $paramsObject, Context $context) {
        if (!isset($params['name']) || !is_string($params['name'])) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'Invalid params: name is required.');
        }
        $shape = isset($paramsObject->arguments) ? $paramsObject->arguments : new \stdClass();
        if (!($shape instanceof \stdClass) || (isset($params['arguments']) && !is_array($params['arguments']))) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'Invalid params: arguments must be an object.');
        }
        $arguments = isset($params['arguments']) ? $params['arguments'] : array();
        $result = $this->internal($context, 'prompt', array('name' => $params['name'], 'arguments' => $arguments), (object) array('name' => $params['name'], 'arguments' => $shape));
        return array('description' => $result->description, 'messages' => $result->messages);
    }

    private function listResources(Context $context) {
        $this->kernel->actAsServiceUser();
        $available = false;
        foreach (Manifest::tools($context) as $tool) { if ($tool['name'] === 'modx_help') { $available = true; } }
        if (!$available) { return array('resources' => array()); }
        $result = $this->internal($context, 'help', array(), new \stdClass());
        return array('resources' => Catalog::resources(isset($result->topics) && is_array($result->topics) ? $result->topics : array()));
    }

    private function readResource(array $params, Context $context, $modern) {
        if (!isset($params['uri']) || !is_string($params['uri'])) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'Invalid params: uri is required.', $modern ? 400 : 200);
        }
        $uri = $params['uri'];
        $topic = Catalog::topicOf($uri);
        $missing = $modern ? RpcError::INVALID_PARAMS : RpcError::RESOURCE_NOT_FOUND;
        if ($topic === null) {
            throw new RpcError($missing, "Unknown resource: {$uri}", $modern ? 400 : 200);
        }
        try {
            $result = $this->internal($context, 'help', array('topic' => $topic), (object) array('topic' => $topic));
        } catch (RpcError $e) {
            if ($e->rpcCode !== RpcError::INVALID_PARAMS) { throw $e; }
            throw new RpcError($missing, "Unknown resource: {$uri}", $modern ? 400 : 200);
        }
        return array('contents' => array(array('uri' => $uri, 'mimeType' => 'text/markdown', 'text' => (string) (isset($result->content) ? $result->content : ''))));
    }

    /**
     * Run a protocol action for the MCP side itself (help, prompt). A refusal or failure is an RpcError with
     * "invalid params": the caller asked for something this token cannot have.
     * @return \stdClass the result
     */
    private function internal(Context $context, $actionName, array $args, $shape) {
        $context->requestId = null;
        try {
            $frame = $this->kernel->dispatch($context, $actionName, $args, $shape, null);
        } catch (ApiException $e) {
            throw new RpcError(RpcError::INVALID_PARAMS, $this->scrub($e->getMessage()));
        }
        $envelope = json_decode($frame['body']);
        if (!is_object($envelope) || !isset($envelope->ok)) {
            throw new RpcError(RpcError::INTERNAL_ERROR, 'The site returned an unreadable result.', 500);
        }
        if ($envelope->ok !== true) {
            $error = (array) $envelope->error;
            $code = isset($error['code']) ? $error['code'] : '';
            $message = isset($error['message']) ? (string) $error['message'] : 'Request failed.';
            if ($code === 'internal_error') { throw new RpcError(RpcError::INTERNAL_ERROR, $this->scrub($message), 500); }
            throw new RpcError(RpcError::INVALID_PARAMS, $this->scrub($message));
        }
        return $envelope->result;
    }

    // ---- frames --------------------------------------------------------------------------------------------------

    private static function errorDocument($id, RpcError $e) {
        return array('jsonrpc' => '2.0', 'id' => $id, 'error' => $e->toError());
    }

    private static function encode(array $document) {
        return json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sizeError($id, $requestId, $encodingFailed = false) {
        $message = $encodingFailed ? 'Response could not be encoded.' : 'Response exceeds modxmcp.max_response_bytes.';
        $data = array('code' => $encodingFailed ? 'response_encoding_failed' : 'response_too_large');
        if ($requestId !== null) { $data['request_id'] = $requestId; }
        return self::errorDocument($id, new RpcError(RpcError::INTERNAL_ERROR, $message . self::outcomeHint($requestId), 500, $data));
    }

    /** @param array $headers header lines ("Name: value") */
    private static function frame($status, array $document, array $headers = array(), $limit = 4194304) {
        $json = self::encode($document);
        if ($json === false || strlen($json) > $limit) {
            $json = self::encode(self::sizeError(null, null, $json === false));
            $status = 500;
        }
        return array('status' => $status, 'body' => $json, 'headers' => $headers);
    }
}
