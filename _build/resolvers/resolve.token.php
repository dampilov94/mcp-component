<?php
/**
 * Resolver: create the component tables (tokens, change journal) on install/upgrade and import the single 1.x token
 * (modxmcp.api_token) as an admin-scope token so existing clients keep working.
 * A fresh install has no tokens; the administrator creates one in Components > modxMCP.
 *
 * Works for both object and file vehicles: $modx is resolved defensively from
 * $transport / $object / globals (file-vehicle resolvers don't get an xPDOObject $object).
 *
 * @var mixed $transport
 * @var mixed $object
 * @var array $options
 */
$modx = null;
foreach (array('modx', 'transport', 'object') as $__v) {
    if (isset($$__v) && is_object($$__v)) {
        if ($$__v instanceof modX) { $modx = $$__v; break; }
        if (isset($$__v->xpdo) && $$__v->xpdo instanceof xPDO) { $modx = $$__v->xpdo; break; }
    }
}
if (!$modx && isset($GLOBALS['modx']) && $GLOBALS['modx'] instanceof modX) {
    $modx = $GLOBALS['modx'];
}
if (!$modx) {
    return true;
}

$action = isset($options[xPDOTransport::PACKAGE_ACTION]) ? $options[xPDOTransport::PACKAGE_ACTION] : '';
if ($action === xPDOTransport::ACTION_INSTALL || $action === xPDOTransport::ACTION_UPGRADE) {
    $autoload = $modx->getOption('core_path') . 'components/modxmcp/src/autoload.php';
    if (!file_exists($autoload)) {
        $modx->log(modX::LOG_LEVEL_ERROR, '[modxMCP] Component files are missing; the token table was not created.');
        return false;
    }
    require_once $autoload;
    try {
        $store = new \ModxMCP\Auth\TokenStore($modx);
        $store->install();
        (new \ModxMCP\Journal\Journal($modx))->install();
        if (!$store->all()) {
            $modx->log(modX::LOG_LEVEL_INFO, '[modxMCP] No API tokens yet. Create one in Components > modxMCP and put it into your MCP client.');
        }
    } catch (Exception $e) {
        $modx->log(modX::LOG_LEVEL_ERROR, '[modxMCP] Could not prepare the component tables: ' . $e->getMessage());
        return false;
    }
    if ($modx->getCacheManager()) {
        $modx->getCacheManager()->refresh();
    }
}

return true;
