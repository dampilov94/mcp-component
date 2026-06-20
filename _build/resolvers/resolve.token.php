<?php
/**
 * Resolver: generate a random modxmcp.api_token on install/upgrade if it's empty.
 * Leaves an existing token untouched (so upgrades don't rotate it).
 *
 * Works for both object and file vehicles: $modx is resolved defensively from
 * $transport / $object / globals (file-vehicle resolvers don't get an xPDOObject $object).
 *
 * @var mixed $transport
 * @var mixed $object
 * @var array $options
 */
$success = true;

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
    $setting = $modx->getObject('modSystemSetting', array('key' => 'modxmcp.api_token'));
    if ($setting && trim((string) $setting->get('value')) === '') {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $token = md5(uniqid('modxmcp', true)) . md5(uniqid('token', true));
        }
        $setting->set('value', $token);
        $setting->save();
        $modx->log(
            modX::LOG_LEVEL_INFO,
            '[modxMCP] Generated modxmcp.api_token. The component is enabled; copy the token from System Settings (modxmcp) or Components > modxMCP into your MCP client.'
        );
    }
    if ($modx->getCacheManager()) {
        $modx->getCacheManager()->refresh();
    }
}

return $success;
