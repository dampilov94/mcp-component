<?php
namespace ModxMCP;

use ModxMCP\Auth\Security;
use ModxMCP\Auth\TokenStore;
use ModxMCP\Request\RequestStore;
use ModxMCP\Request\RequestStoreException;

/**
 * HTTP entry point of protocol 2.
 *
 * Request:  POST JSON {"v":2,"action":"…","args":{…},"request_id":"…","session_id":"…"}
 *           with the token in X-MCP-Token (or Authorization: Bearer).
 * Response: {"ok":true,"result":{…},"meta":{…}} or {"ok":false,"error":{"code","message",…},"meta":{…}}
 */
final class Kernel {
    const VERSION = '2.0.0';
    const PROTOCOL = 2;

    private $modx;
    private $bufferLevel;
    private $maxResponse = 4194304;

    /**
     * @param int $bufferLevel Output-buffer level before the endpoint started buffering;
     *                         everything above it is discarded so stray output cannot corrupt JSON.
     */
    public function __construct(\modX $modx, $bufferLevel) {
        $this->modx = $modx;
        $this->bufferLevel = (int) $bufferLevel;
    }

    /** Handle the current HTTP request and terminate. */
    public function handle(array $server) {
        $requestId = null;
        try {
            $frame = $this->process($server, $requestId);
        } catch (\Throwable $e) {
            list($status, $body) = $this->errorBody($e, 'endpoint');
            if ($requestId !== null) { $body['meta'] = array('request_id' => $requestId); }
            $frame = $this->buildFrame($status, $body);
        }
        self::finish($frame, $this->bufferLevel);
    }

    /** Send a frame, dropping any output produced outside it. */
    public static function finish(array $frame, $bufferLevel) {
        while (ob_get_level() > $bufferLevel) {
            if (!@ob_end_clean()) { break; }
        }
        http_response_code($frame['status']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!empty($frame['headers'])) {
            foreach ($frame['headers'] as $header) { header($header); }
        }
        if (!empty($frame['replayed'])) { header('X-MCP-Replayed: 1'); }
        echo $frame['body'];
        exit;
    }

    private function process(array $server, &$requestId) {
        $modx = $this->modx;
        $method = isset($server['REQUEST_METHOD']) ? $server['REQUEST_METHOD'] : '';

        // Unauthenticated probe: only the component name, build version and enabled flag.
        if ($method === 'GET') {
            return $this->buildFrame(200, array(
                'component' => 'modxMCP',
                'version'   => self::VERSION,
                'protocol'  => self::PROTOCOL,
                'enabled'   => (bool) $modx->getOption('modxmcp.enabled', null, false),
            ));
        }
        if ($method !== 'POST') {
            $frame = $this->buildFrame(405, array('ok' => false, 'error' => array('code' => 'method_not_allowed', 'message' => 'Method Not Allowed')));
            $frame['headers'] = array('Allow: GET, POST');
            return $frame;
        }
        if (!$modx->getOption('modxmcp.enabled', null, false)) {
            throw new ApiException('disabled', 'modxMCP is disabled.');
        }

        // Direct HTTPS must be reported by the web server. Forwarded protocol is trusted only
        // from a configured socket peer; a port number or an untrusted header proves nothing.
        if ((bool) $modx->getOption('modxmcp.require_https', null, false)
            && !Security::isHttps($server, (string) $modx->getOption('modxmcp.trusted_proxies', null, ''))) {
            throw new ApiException('https_required', 'HTTPS required (modxmcp.require_https).');
        }
        // Match REMOTE_ADDR only. X-Forwarded-For is not an authorization source.
        $allowedIps = trim((string) $modx->getOption('modxmcp.allowed_ips', null, ''));
        if ($allowedIps !== '') {
            $peer = isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : '';
            if (!Security::matchesIpList($peer, $allowedIps)) {
                throw new ApiException('ip_not_allowed', 'Forbidden: client IP is not allowed (modxmcp.allowed_ips).');
            }
        }

        $token = (new TokenStore($modx))->authenticate(self::presentedToken($server));
        if ($token === null) {
            throw new ApiException('unauthorized', 'Unauthorized');
        }

        $input = $this->readBody($server);
        $shape = $input[0];
        $request = $input[1];

        if (array_diff(array_keys($request), array('v', 'action', 'args', 'request_id', 'session_id'))) {
            throw new ApiException('validation_failed', 'Unknown top-level request fields. Put action arguments inside args.');
        }
        if (!isset($request['v']) || $request['v'] !== self::PROTOCOL) {
            throw new ApiException('unsupported_protocol', 'This endpoint speaks modxMCP protocol ' . self::PROTOCOL . '. Update the modx-mcp client to 2.x.');
        }
        if (!isset($request['action']) || !is_string($request['action']) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $request['action'])) {
            throw new ApiException('validation_failed', 'action must be an action name string.');
        }
        $actionName = $request['action'];
        if (property_exists($shape, 'args') && !($shape->args instanceof \stdClass)) {
            throw new ApiException('validation_failed', 'args must be a JSON object.');
        }
        $shapeArgs = property_exists($shape, 'args') ? $shape->args : new \stdClass();
        $args = isset($request['args']) ? $request['args'] : array();
        if (array_key_exists('request_id', $request)) {
            if (!RequestStore::validId($request['request_id'])) {
                throw new ApiException('validation_failed', 'request_id must contain 16-128 letters, digits, underscores or hyphens.');
            }
            $requestId = $request['request_id'];
        }
        $sessionId = null;
        if (array_key_exists('session_id', $request)) {
            if (!RequestStore::validId($request['session_id'])) {
                throw new ApiException('validation_failed', 'session_id must contain 16-128 letters, digits, underscores or hyphens.');
            }
            $sessionId = $request['session_id'];
        }

        $this->maxResponse = max(1024, (int) $modx->getOption('modxmcp.max_response_bytes', null, 4194304));
        $maxResponse = $this->maxResponse;
        $storeFactory = function () use ($modx, $maxResponse) {
            return new RequestStore(
                rtrim($modx->getOption('core_path'), '/\\') . '/modxmcp-data/requests',
                $modx->getOption('modxmcp.request_retention_seconds', null, 86400),
                $maxResponse
            );
        };
        $corePath = $modx->getOption('modxmcp.core_path', null, $modx->getOption('core_path') . 'components/modxmcp/');
        $context = new Context($modx, $token, $corePath, $storeFactory);
        $context->sessionId = $sessionId;
        $context->requestId = $requestId;

        $action = $context->actions->get($actionName);
        if ($action === null) {
            throw new ApiException('unknown_action', "Unknown action '{$actionName}'.");
        }
        $reason = $context->actions->blockReason($action, $context);
        if ($reason === 'forbidden_scope') {
            throw new ApiException('forbidden_scope', "Action '{$actionName}' requires the '{$action->scope()}' scope; this token has '{$token['scope']}'.");
        }
        if ($reason !== null) {
            throw new ApiException('unavailable', "Action '{$actionName}' is unavailable: {$reason}.", array('reason' => $reason));
        }
        $invalid = Schema::validate($action->inputSchema(), $shapeArgs);
        if ($invalid !== null) {
            throw new ApiException('validation_failed', $invalid);
        }

        $this->actAsServiceUser();

        $execute = function () use ($action, $actionName, $args, $context, $requestId) {
            $status = 200;
            try {
                $result = $action->run($args, $context);
                $body = array('ok' => true, 'result' => $result === array() ? new \stdClass() : $result);
            } catch (\Throwable $e) {
                list($status, $body) = $this->errorBody($e, $actionName);
            }
            $body['meta'] = $this->meta($context, $requestId);
            return $this->buildFrame($status, $body);
        };

        if ($requestId !== null && !$action->readOnly()) {
            // The token is part of the fingerprint: an ID never replays another token's response.
            $fingerprint = hash('sha256', json_encode(
                self::canonical(array('action' => $actionName, 'args' => $args, 'token' => $token['id'])),
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
            ignore_user_abort(true);
            return $storeFactory()->execute($requestId, $fingerprint, $execute);
        }
        return $execute();
    }

    private static function presentedToken(array $server) {
        $headers = array();
        if (function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) { $headers = array_change_key_case($all, CASE_LOWER); }
        }
        if (isset($headers['x-mcp-token'])) { return trim((string) $headers['x-mcp-token']); }
        if (isset($server['HTTP_X_MCP_TOKEN'])) { return trim((string) $server['HTTP_X_MCP_TOKEN']); }
        // Some hosts strip Authorization before PHP sees it; X-MCP-Token is the reliable header.
        foreach (array(
            isset($headers['authorization']) ? $headers['authorization'] : null,
            isset($server['HTTP_AUTHORIZATION']) ? $server['HTTP_AUTHORIZATION'] : null,
            isset($server['REDIRECT_HTTP_AUTHORIZATION']) ? $server['REDIRECT_HTTP_AUTHORIZATION'] : null,
        ) as $value) {
            if (is_string($value) && preg_match('/^Bearer\s+(\S+)\s*$/i', $value, $match)) { return $match[1]; }
        }
        return '';
    }

    /** @return array [stdClass shape, associative request] */
    private function readBody(array $server) {
        $contentType = isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : '';
        if (!preg_match('~^application/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|\s*$)~i', $contentType)) {
            throw new ApiException('unsupported_media_type', 'Content-Type must be application/json.');
        }
        $maxPayload = (int) $this->modx->getOption('modxmcp.max_payload_bytes', null, 1048576);
        if ($maxPayload < 0) { $maxPayload = 1048576; }
        $declared = isset($server['CONTENT_LENGTH']) ? (string) $server['CONTENT_LENGTH'] : '';
        if ($maxPayload > 0 && ctype_digit($declared) && (float) $declared > $maxPayload) {
            throw new ApiException('request_too_large', 'Payload Too Large');
        }
        $raw = $maxPayload > 0
            ? file_get_contents('php://input', false, null, 0, $maxPayload + 1)
            : file_get_contents('php://input');
        if ($raw === false) { throw new \RuntimeException('Cannot read request body.'); }
        if ($maxPayload > 0 && strlen($raw) > $maxPayload) {
            throw new ApiException('request_too_large', 'Payload Too Large');
        }
        try {
            $shape = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            $request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException('invalid_json', 'Invalid JSON or UTF-8.');
        }
        if (!($shape instanceof \stdClass)) {
            throw new ApiException('validation_failed', 'Request body must be a JSON object.');
        }
        return array($shape, $request);
    }

    /** Actions run as the configured service user; what a token may do is decided by its scope. */
    private function actAsServiceUser() {
        $id = (int) $this->modx->getOption('modxmcp.service_user_id', null, 1);
        $user = $this->modx->getObject('modUser', array('id' => $id));
        if (!$user) { throw new ApiException('validation_failed', "Service user not found: {$id}."); }
        if (!$user->get('active')) { throw new ApiException('validation_failed', "Service user is inactive: {$id}."); }
        $this->modx->user = $user;
        $this->modx->user->set('sudo', 1);
    }

    private function meta(Context $context, $requestId) {
        $meta = array();
        if ($requestId !== null) { $meta['request_id'] = $requestId; }
        try {
            $meta['manifest'] = Manifest::hash(Manifest::tools($context));
        } catch (\Throwable $e) {
            // A notification failure must not turn a completed action into an unknown outcome.
            $this->log('Manifest hash failed: ' . $e->getMessage());
        }
        if ($context->warnings) { $meta['warnings'] = array_values($context->warnings); }
        return $meta ? $meta : new \stdClass();
    }

    /** @return array [http status, body] */
    private function errorBody(\Throwable $e, $where) {
        if ($e instanceof ApiException) {
            $error = array('code' => $e->errorCode, 'message' => $e->getMessage());
            if ($e->details) { $error['details'] = $e->details; }
            return array($e->httpStatus, array('ok' => false, 'error' => $error));
        }
        if ($e instanceof RequestStoreException) {
            return array($e->httpStatus, array('ok' => false, 'error' => array('code' => 'request_state_error', 'message' => $e->getMessage())));
        }
        $errorId = uniqid('modxmcp_', true);
        $this->log('[' . $errorId . '] ' . $where . ': ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        $error = array('code' => 'internal_error', 'message' => 'Internal Server Error', 'error_id' => $errorId);
        if ($this->modx->getOption('modxmcp.debug', null, false)) { $error['details'] = array('exception' => $e->getMessage()); }
        return array(500, array('ok' => false, 'error' => $error));
    }

    private function buildFrame($status, array $body) {
        try {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($json) > $this->maxResponse) { throw new \LengthException('Response exceeds modxmcp.max_response_bytes.'); }
            return array('status' => $status, 'body' => $json);
        } catch (\Throwable $e) {
            $errorId = uniqid('modxmcp_', true);
            $this->log('[' . $errorId . '] Response failed after action processing: ' . $e->getMessage());
            $fallback = array('ok' => false, 'error' => array(
                'code'     => $e instanceof \LengthException ? 'response_too_large' : 'response_encoding_failed',
                'message'  => 'Response could not be returned; the action may already have completed. Check request status before retrying.',
                'error_id' => $errorId,
            ));
            if (isset($body['meta']) && is_array($body['meta']) && isset($body['meta']['request_id'])) {
                $fallback['meta'] = array('request_id' => $body['meta']['request_id']);
            }
            return array('status' => 500, 'body' => json_encode($fallback, JSON_THROW_ON_ERROR));
        }
    }

    private function log($message) {
        try {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[modxmcp] ' . $message);
        } catch (\Throwable $e) {
            error_log('[modxmcp] ' . $message);
        }
    }

    /** Key-sorted form of a request so equal requests always hash equally. */
    public static function canonical($value) {
        if (is_array($value)) {
            if ($value !== array() && array_keys($value) !== range(0, count($value) - 1)) { ksort($value, SORT_STRING); }
            foreach ($value as $key => $item) { $value[$key] = self::canonical($item); }
        }
        return $value;
    }
}
