<?php
// Buffer third-party output so warnings/debug prints cannot corrupt the JSON response.
$modxmcpBufferLevel = ob_get_level();
ob_start();

function modxmcpFinishFrame(array $frame) {
    global $modxmcpBufferLevel;
    while (ob_get_level() > $modxmcpBufferLevel) {
        if (!@ob_end_clean()) { break; }
    }
    http_response_code($frame['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!empty($frame['replayed'])) { header('X-MCP-Replayed: 1'); }
    echo $frame['body'];
    exit;
}

function modxmcpReplyJson(array $body, $flags = JSON_UNESCAPED_UNICODE) {
    $status = http_response_code() ?: 200;
    modxmcpFinishFrame(array('status' => $status, 'body' => json_encode($body, $flags | JSON_THROW_ON_ERROR)));
}

function modxmcpLogHttpError($modx, $message) {
    try {
        if ($modx instanceof modX) { $modx->log(modX::LOG_LEVEL_ERROR, $message); }
        else { error_log($message); }
    } catch (Throwable $e) { error_log($message); }
}

function modxmcpBuildFrame($modx, $status, array $body, $maxResponse) {
    try {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > $maxResponse) { throw new LengthException('Response exceeds modxmcp.max_response_bytes.'); }
        return array('status' => $status, 'body' => $json);
    } catch (Throwable $e) {
        $errorId = uniqid('modxmcp_', true);
        modxmcpLogHttpError($modx, '[' . $errorId . '] Response failed after action processing: ' . $e->getMessage());
        $fallback = array('success' => false, 'error' => 'Response could not be returned; the action may already have completed. Check request status before retrying.',
            'error_code' => $e instanceof LengthException ? 'response_too_large' : 'response_encoding_failed', 'error_id' => $errorId);
        if (isset($body['request_id'])) { $fallback['request_id'] = $body['request_id']; }
        return array('status' => 500, 'body' => json_encode($fallback, JSON_THROW_ON_ERROR));
    }
}

function modxmcpCanonicalRequest($value) {
    if (is_array($value)) {
        if ($value !== array() && array_keys($value) !== range(0, count($value) - 1)) { ksort($value, SORT_STRING); }
        foreach ($value as $key => $item) { $value[$key] = modxmcpCanonicalRequest($item); }
    } elseif (is_float($value) && !is_finite($value)) {
        throw new ModxMCPClientException('JSON numbers must be finite.');
    }
    return $value;
}

$modx = null;
$requestId = null;
$maxResponse = 4194304;
$action = '';
$type = '';
try {
    require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.core.php';
    require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

    if (!class_exists('ModxMCPClientException')) {
        /** Expected/validation error whose message is safe to return to the client. */
        class ModxMCPClientException extends Exception {}
    }

    $modx = new modX();
    $modx->initialize('mgr');
    $modx->getService('error', 'error.modError');
    $modx->setLogLevel(modX::LOG_LEVEL_ERROR);

    header('Content-Type: application/json; charset=utf-8');

    // Lightweight unauthenticated health/version probe (GET) for client/server skew detection.
    // Returns only non-sensitive info: component name, server build version, enabled flag.
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $version = 'unknown';
        $corePath = $modx->getOption('modxmcp.core_path', null, $modx->getOption('core_path') . 'components/modxmcp/');
        $modelFile = $corePath . 'model/modxmcp.class.php';
        if (file_exists($modelFile)) {
            require_once $modelFile;
            if (defined('modxMCP::VERSION')) { $version = modxMCP::VERSION; }
        }
        modxmcpReplyJson([
            'component' => 'modxMCP',
            'version'   => $version,
            'enabled'   => (bool) $modx->getOption('modxmcp.enabled', null, false),
            'request_ids' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        modxmcpReplyJson(['success' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $isEnabled = $modx->getOption('modxmcp.enabled', null, false);
    if (!$isEnabled) {
        http_response_code(403);
        modxmcpReplyJson(['success' => false, 'error' => 'modxMCP is disabled.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $corePath = $modx->getOption('modxmcp.core_path', null, $modx->getOption('core_path') . 'components/modxmcp/');
    require_once $corePath . 'model/security.class.php';

    // Direct HTTPS must be reported by the web server. Forwarded protocol is trusted only
    // from a configured socket peer; a port number or an untrusted header proves nothing.
    if ((bool) $modx->getOption('modxmcp.require_https', null, false)) {
        $trustedProxies = (string) $modx->getOption('modxmcp.trusted_proxies', null, '');
        if (!ModxMCPSecurity::isHttps($_SERVER, $trustedProxies)) {
            http_response_code(403);
            modxmcpReplyJson(array('success' => false, 'error' => 'HTTPS required (modxmcp.require_https).', 'error_code' => 'https_required'));
        }
    }

    // Match REMOTE_ADDR only. X-Forwarded-For is not an authorization source.
    $allowedIps = trim((string) $modx->getOption('modxmcp.allowed_ips', null, ''));
    if ($allowedIps !== '') {
        $peer = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if (!ModxMCPSecurity::matchesIpList($peer, $allowedIps)) {
            http_response_code(403);
            modxmcpReplyJson(array('success' => false, 'error' => 'Forbidden: client IP is not allowed (modxmcp.allowed_ips).', 'error_code' => 'ip_not_allowed'));
        }
    }

    $expectedToken = (string) $modx->getOption('modxmcp.api_token', null, '');
    $headers =[];
    if (function_exists('getallheaders')) {
        $allHeaders = getallheaders();
        if (is_array($allHeaders)) { $headers = array_change_key_case($allHeaders, CASE_LOWER); }
    }

    $receivedToken = '';
    if (isset($headers['x-mcp-token'])) $receivedToken = trim($headers['x-mcp-token']);
    elseif (isset($_SERVER['HTTP_X_MCP_TOKEN'])) $receivedToken = trim($_SERVER['HTTP_X_MCP_TOKEN']);

    if (empty($expectedToken) || !hash_equals($expectedToken, $receivedToken)) {
        http_response_code(401);
        modxmcpReplyJson(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $contentType = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '';
    if (!preg_match('~^application/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|\s*$)~i', $contentType)) {
        http_response_code(415);
        modxmcpReplyJson(array('success' => false, 'error' => 'Content-Type must be application/json.', 'error_code' => 'unsupported_media_type'));
    }
    $maxPayloadBytes = (int) $modx->getOption('modxmcp.max_payload_bytes', null, 1048576);
    if ($maxPayloadBytes < 0) { $maxPayloadBytes = 1048576; }
    $declaredLength = isset($_SERVER['CONTENT_LENGTH']) ? (string) $_SERVER['CONTENT_LENGTH'] : '';
    if ($maxPayloadBytes > 0 && ctype_digit($declaredLength) && (float) $declaredLength > $maxPayloadBytes) {
        http_response_code(413);
        modxmcpReplyJson(array('success' => false, 'error' => 'Payload Too Large', 'error_code' => 'request_too_large'));
    }
    $rawInput = $maxPayloadBytes > 0
        ? file_get_contents('php://input', false, null, 0, $maxPayloadBytes + 1)
        : file_get_contents('php://input');
    if ($rawInput === false) { throw new Exception('Cannot read request body.'); }
    if ($maxPayloadBytes > 0 && strlen($rawInput) > $maxPayloadBytes) {
        http_response_code(413);
        modxmcpReplyJson(array('success' => false, 'error' => 'Payload Too Large', 'error_code' => 'request_too_large'));
    }
    try {
        $shape = json_decode($rawInput, false, 512, JSON_THROW_ON_ERROR);
        $input = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        http_response_code(400);
        modxmcpReplyJson(array('success' => false, 'error' => 'Invalid JSON or UTF-8.', 'error_code' => 'invalid_json'));
    }
    if (!($shape instanceof stdClass)) { throw new ModxMCPClientException('Request body must be a JSON object.'); }
    $allowedFields = array('action', 'type', 'data', 'name', 'content', 'id', 'request_id');
    if (array_diff(array_keys($input), $allowedFields)) {
        throw new ModxMCPClientException('Unknown top-level request fields. Put action arguments inside data.');
    }
    if (!isset($input['action']) || !is_string($input['action']) || !preg_match('/^[a-z][a-z0-9_]{0,127}$/D', $input['action'])) {
        throw new ModxMCPClientException('action must be a non-empty action name string.');
    }
    $action = $input['action'];
    if (array_key_exists('type', $input) && (!is_string($input['type']) || strlen($input['type']) > 64)) {
        throw new ModxMCPClientException('type must be an element-type string.');
    }
    $type = isset($input['type']) ? $input['type'] : '';
    if (property_exists($shape, 'data') && !($shape->data instanceof stdClass)) {
        throw new ModxMCPClientException('data must be a JSON object.');
    }
    $data = isset($input['data']) ? $input['data'] : array();
    foreach (array('name', 'content') as $field) {
        if (array_key_exists($field, $input)) {
            if (!is_string($input[$field])) { throw new ModxMCPClientException($field . ' must be a string.'); }
            $data[$field] = $input[$field];
        }
    }
    if (array_key_exists('id', $input)) {
        if (!is_int($input['id']) || $input['id'] < 1) { throw new ModxMCPClientException('id must be a positive integer.'); }
        $data['id'] = $input['id'];
    }
    $corePath = $modx->getOption('modxmcp.core_path', null, $modx->getOption('core_path') . 'components/modxmcp/');
    require_once $corePath . 'model/modxmcp.class.php';
    require_once $corePath . 'model/requeststore.class.php';
    if (array_key_exists('request_id', $input)) {
        if (!ModxMCPRequestStore::validId($input['request_id'])) { throw new ModxMCPClientException('request_id must contain 16-128 letters, digits, underscores or hyphens.'); }
        $requestId = $input['request_id'];
    }
    $maxResponse = max(1024, (int) $modx->getOption('modxmcp.max_response_bytes', null, 4194304));
    $normalized = modxmcpCanonicalRequest(array('action' => $action, 'type' => $type, 'data' => $data));
    $fingerprint = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $execute = function () use ($modx, $corePath, $action, $type, $data, $requestId, $maxResponse) {
        $status = 200;
        try {
            $mcp = new modxMCP($modx);
            $result = $mcp->processRequest($action, $type, $data);
            $body = array('success' => true, 'data' => $result);
        } catch (ModxMCPRequestStoreException $e) {
            $status = $e->httpStatus;
            $body = array('success' => false, 'error' => $e->getMessage(), 'error_code' => 'request_state_error');
        } catch (ModxMCPClientException $e) {
            $status = 400;
            $body = array('success' => false, 'error' => $e->getMessage(), 'error_code' => 'validation_failed');
        } catch (Throwable $e) {
            $status = 500;
            $errorId = uniqid('modxmcp_', true);
            modxmcpLogHttpError($modx, '[' . $errorId . '] action=' . $action . ' type=' . $type . ' error=' . $e->getMessage());
            $body = array('success' => false, 'error' => 'Internal Server Error', 'error_code' => 'internal_error', 'error_id' => $errorId);
            if ($modx->getOption('modxmcp.debug', null, false)) { $body['details'] = $e->getMessage(); }
        }
        $body['caps'] = (string) $modx->getOption('modxmcp.disabled_groups', null, '');
        if ($requestId !== null) { $body['request_id'] = $requestId; }
        return modxmcpBuildFrame($modx, $status, $body, $maxResponse);
    };
    if ($requestId !== null && $action !== 'get_request_status') {
        $directory = rtrim($modx->getOption('core_path'), '/\\') . '/modxmcp-data/requests';
        $store = new ModxMCPRequestStore($directory, $modx->getOption('modxmcp.request_retention_seconds', null, 86400), $maxResponse);
        ignore_user_abort(true);
        $frame = $store->execute($requestId, $fingerprint, $execute);
    } else {
        $frame = $execute();
    }
    modxmcpFinishFrame($frame);
} catch (Throwable $e) {
    if ($e instanceof ModxMCPClientException) {
        $status = 400;
        $body = array('success' => false, 'error' => $e->getMessage(), 'error_code' => 'validation_failed');
    } elseif ($e instanceof ModxMCPRequestStoreException) {
        $status = $e->httpStatus;
        $body = array('success' => false, 'error' => $e->getMessage(), 'error_code' => 'request_state_error');
    } else {
        $status = 500;
        $errorId = uniqid('modxmcp_', true);
        modxmcpLogHttpError($modx, '[' . $errorId . '] Endpoint error: ' . $e->getMessage());
        $body = array('success' => false, 'error' => 'Internal Server Error', 'error_code' => 'internal_error', 'error_id' => $errorId);
        if ($modx instanceof modX && $modx->getOption('modxmcp.debug', null, false)) { $body['details'] = $e->getMessage(); }
    }
    if ($modx instanceof modX) { $body['caps'] = (string) $modx->getOption('modxmcp.disabled_groups', null, ''); }
    if ($requestId !== null) { $body['request_id'] = $requestId; }
    modxmcpFinishFrame(modxmcpBuildFrame($modx, $status, $body, $maxResponse));
}
