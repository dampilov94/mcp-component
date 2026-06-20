<?php
/**
 * Resolver: on install/upgrade, log which popular MODX add-ons are present and which
 * are missing, so the operator knows what modxMCP can additionally manage. Purely
 * informational — it never installs anything.
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
    // Add-ons with dedicated modxMCP tooling. If present, those tools are usable here.
    $known = array(
        'minishop2'   => 'miniShop2 (ms2_*)',
        'migx'        => 'MIGX (migx_*)',
        'versionx'    => 'VersionX (versionx_*)',
        'virtualpage' => 'VirtualPage (virtualpage_*)',
    );
    $present = array();
    foreach ($known as $ns => $label) {
        if ($modx->getObject('modNamespace', array('name' => $ns))) {
            $present[] = $label;
        }
    }
    $modx->log(
        modX::LOG_LEVEL_INFO,
        '[modxMCP] Dedicated integrations detected: ' . (empty($present) ? 'none' : implode(', ', $present)) .
        '. Other add-ons (snippets/chunks) are handled via the generic element tools.'
    );
}

return $success;
