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
    $known = array(
        'minishop2'  => 'miniShop2 (ms2 option tools)',
        'migx'       => 'MIGX (MIGX TVs/configs)',
        'pdotools'   => 'pdoTools',
        'tickets'    => 'Tickets',
        'collections'=> 'Collections',
        'formit'     => 'FormIt',
        'msearch2'   => 'mSearch2',
        'mfilter2'   => 'mFilter2',
        'seosuite'   => 'SEO Suite',
        'versionx'   => 'VersionX (versionx_* tools)',
    );
    $present = array();
    $absent  = array();
    foreach ($known as $ns => $label) {
        if ($modx->getObject('modNamespace', array('name' => $ns))) {
            $present[] = $label;
        } else {
            $absent[] = $label;
        }
    }
    $modx->log(modX::LOG_LEVEL_INFO, '[modxMCP] Integrations present: ' . (empty($present) ? 'none' : implode(', ', $present)));
    if (!empty($absent)) {
        $modx->log(
            modX::LOG_LEVEL_INFO,
            '[modxMCP] Optional add-ons not installed: ' . implode(', ', $absent) .
            '. Install them via Package Management (or the install_package action, after enabling modxmcp.allow_package_install) if you want modxMCP to manage them.'
        );
    }
}

return $success;
