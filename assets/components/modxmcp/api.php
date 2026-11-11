<?php
// Buffer third-party output so warnings/debug prints cannot corrupt the JSON response.
$modxmcpBufferLevel = ob_get_level();
ob_start();

try {
    require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.core.php';
    require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

    $modx = new modX();
    $modx->initialize('mgr');
    $modx->getService('error', 'error.modError');
    $modx->setLogLevel(modX::LOG_LEVEL_ERROR);

    $corePath = $modx->getOption('modxmcp.core_path', null, $modx->getOption('core_path') . 'components/modxmcp/');
    require_once rtrim($corePath, '/\\') . '/src/autoload.php';

    (new \ModxMCP\Kernel($modx, $modxmcpBufferLevel))->handle($_SERVER);
} catch (Throwable $e) {
    // Bootstrap failed before the kernel could answer; never leak the cause to the client.
    error_log('[modxmcp] Endpoint bootstrap failed: ' . $e->getMessage());
    while (ob_get_level() > $modxmcpBufferLevel) {
        if (!@ob_end_clean()) { break; }
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"ok":false,"error":{"code":"internal_error","message":"Internal Server Error"}}';
}
