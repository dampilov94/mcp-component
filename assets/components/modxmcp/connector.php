<?php
/**
 * modxMCP — manager connector. Routes manager-only AJAX (CMP buttons) to the
 * component's mgr processors. Requires a valid manager session (HTTP_MODAUTH),
 * which is separate from the public api.php token endpoint.
 */
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.core.php';
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CONNECTORS_PATH . 'index.php';

$corePath = $modx->getOption('modxmcp.core_path', null, $modx->getOption('core_path') . 'components/modxmcp/');

$modx->request->handleRequest(array(
    'processors_path' => $corePath . 'processors/',
    'location'        => '',
));
