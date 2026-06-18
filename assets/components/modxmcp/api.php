<?php
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isEnabled = $modx->getOption('modxmcp.enabled', null, false);
if (!$isEnabled) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'modxMCP is disabled.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$expectedToken = $modx->getOption('modxmcp.api_token', null, '');
$headers =[];
if (function_exists('getallheaders')) {
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
}

$receivedToken = '';
if (isset($headers['x-mcp-token'])) $receivedToken = trim($headers['x-mcp-token']);
elseif (isset($_SERVER['HTTP_X_MCP_TOKEN'])) $receivedToken = trim($_SERVER['HTTP_X_MCP_TOKEN']);

if (empty($expectedToken) || !hash_equals($expectedToken, $receivedToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$maxPayloadBytes = (int)$modx->getOption('modxmcp.max_payload_bytes', null, 1024 * 1024);
if ($maxPayloadBytes > 0 && strlen($rawInput) > $maxPayloadBytes) {
    http_response_code(413);
    echo json_encode([
        'success' => false,
        'error' => 'Payload Too Large',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid JSON encoding. Ensure your payload is strictly UTF-8 encoded.',
        'json_error' => json_last_error_msg()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$input || !isset($input['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Bad Request: Missing action.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $input['action'];
$type   = isset($input['type']) ? $input['type'] : '';
$data   = isset($input['data']) ? $input['data'] : [];

if (isset($input['name'])) $data['name'] = $input['name'];
if (isset($input['content'])) $data['content'] = $input['content'];
if (isset($input['id'])) $data['id'] = $input['id'];

try {
    $corePath = $modx->getOption('modxmcp.core_path', null, $modx->getOption('core_path') . 'components/modxmcp/');
    require_once $corePath . 'model/modxmcp.class.php';
    
    $mcp = new modxMCP($modx);
    $result = $mcp->processRequest($action, $type, $data);
    
    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
} catch (ModxMCPClientException $e) {
    // Expected, actionable error (validation, "not installed", "disabled", "not found").
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $errorId = uniqid('modxmcp_', true);
    $modx->log(
        modX::LOG_LEVEL_ERROR,
        sprintf(
            '[%s] MCP request failed. action=%s type=%s message=%s',
            $errorId,
            $action,
            $type,
            $e->getMessage()
        )
    );
    http_response_code(500);
    $debug = (bool)$modx->getOption('modxmcp.debug', null, false);
    $response = [
        'success' => false,
        'error' => 'Internal Server Error',
        'error_id' => $errorId,
    ];
    if ($debug) {
        $response['details'] = $e->getMessage();
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
