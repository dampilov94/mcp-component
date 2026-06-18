<?php
/**
 * One-off headless installer + verifier for the modxMCP transport package.
 * TEST/DEV ONLY — delete after use. Installs core/packages/<signature>.transport.zip
 * and reports namespace/settings/token/files. Enables the component for an endpoint test.
 */
set_time_limit(0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

$config = getenv('MODX_CONFIG_CORE');
if (!$config || !file_exists($config)) {
    $dir = dirname(__FILE__);
    for ($i = 0; $i < 12; $i++) {
        if (file_exists($dir . '/config.core.php')) { $config = $dir . '/config.core.php'; break; }
        $parent = dirname($dir); if ($parent === $dir) break; $dir = $parent;
    }
}
if (!$config) { die("config.core.php not found\n"); }
require_once $config;
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

$modx = new modX();
$modx->initialize('mgr');
$modx->getService('error', 'error.modError');
header('Content-Type: text/plain; charset=utf-8');

$signature = isset($_GET['sig']) ? preg_replace('/[^a-zA-Z0-9._-]/', '', $_GET['sig']) : 'modxmcp-1.0.0-pl';
$action = isset($_GET['action']) ? $_GET['action'] : 'install';
$modx->loadClass('transport.modTransportPackage');

if ($action === 'uninstall') {
    $pkg = $modx->getObject('transport.modTransportPackage', array('signature' => $signature));
    if (!$pkg) { echo "no package record for $signature\n"; exit; }
    $un = $pkg->uninstall();
    echo 'uninstall(): ' . ($un ? 'OK' : 'FAILED') . "\n";
    $pkg->remove();
    $modx->getCacheManager()->refresh();
    echo "package record removed\n";
    exit;
}

$package = $modx->getObject('transport.modTransportPackage', array('signature' => $signature));
if (!$package) {
    $package = $modx->newObject('transport.modTransportPackage');
    $package->set('signature', $signature);
    $package->set('state', 1);
    $package->set('created', date('Y-m-d H:i:s'));
    $package->set('workspace', 1);
    $sig = explode('-', $signature);
    $package->set('package_name', $sig[0]);
    $vparts = explode('.', isset($sig[1]) ? $sig[1] : '1.0.0');
    $package->set('version_major', isset($vparts[0]) ? $vparts[0] : 1);
    $package->set('version_minor', isset($vparts[1]) ? $vparts[1] : 0);
    $package->set('version_patch', isset($vparts[2]) ? $vparts[2] : 0);
    if (!empty($sig[2])) {
        $rel = preg_split('/([0-9]+)/', $sig[2], -1, PREG_SPLIT_DELIM_CAPTURE);
        $package->set('release', $rel[0]);
        $package->set('release_index', isset($rel[1]) ? $rel[1] : 0);
    }
    $package->save();
    echo "package record created\n";
} else {
    echo "package record exists\n";
}

$ok = $package->install();
echo 'install(): ' . ($ok ? 'OK' : 'FAILED') . "\n";

$modx->getCacheManager()->refresh();

$ns = $modx->getObject('modNamespace', array('name' => 'modxmcp'));
echo 'namespace modxmcp: ' . ($ns ? 'yes' : 'NO') . "\n";
echo 'modxmcp.* settings: ' . $modx->getCount('modSystemSetting', array('key:LIKE' => 'modxmcp.%')) . "\n";

$token = $modx->getObject('modSystemSetting', array('key' => 'modxmcp.api_token'));
$tv = $token ? (string) $token->get('value') : '';
echo 'api_token: ' . ($tv !== '' ? ('set, ' . strlen($tv) . ' chars') : 'EMPTY') . "\n";

$en = $modx->getObject('modSystemSetting', array('key' => 'modxmcp.enabled'));
echo 'enabled (default): ' . ($en ? var_export($en->get('value'), true) : '?') . "\n";

echo 'file assets/.../api.php: ' . (file_exists(MODX_ASSETS_PATH . 'components/modxmcp/api.php') ? 'yes' : 'NO') . "\n";
echo 'file core/.../modxmcp.class.php: ' . (file_exists(MODX_CORE_PATH . 'components/modxmcp/model/modxmcp.class.php') ? 'yes' : 'NO') . "\n";

/* enable for an endpoint smoke test (test site) */
if ($en) { $en->set('value', 1); $en->save(); $modx->getCacheManager()->refresh(); echo "enabled set to 1 for test\n"; }
echo 'TOKEN=' . $tv . "\n";
