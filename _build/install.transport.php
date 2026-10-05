<?php
/**
 * One-off headless installer + verifier for the modxMCP transport package.
 * TEST/DEV ONLY — delete after use. Installs core/packages/<signature>.transport.zip
 * CLI-only; reports namespace/settings/token presence/files. Use --help for options.
 */
// Reject web/CGI/php built-in server requests before loading MODX or reading secrets.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden: this development helper is CLI-only.\n");
}
$cliOptions = array('action' => 'install');
$seenOptions = array();
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        echo "Usage: php _build/install.transport.php [--sig=modxmcp-VERSION-RELEASE] [--action=install|uninstall]\n";
        exit(0);
    }
    if (!preg_match('/^--(sig|action)=(.+)$/D', $argument, $match) || isset($seenOptions[$match[1]])) {
        fwrite(STDERR, "Invalid or repeated option. Use --help.\n");
        exit(1);
    }
    $seenOptions[$match[1]] = true;
    $cliOptions[$match[1]] = $match[2];
}
require_once __DIR__ . '/build.config.php';
$signature = isset($cliOptions['sig']) ? $cliOptions['sig'] : PKG_NAMESPACE . '-' . PKG_VERSION . '-' . PKG_RELEASE;
$action = $cliOptions['action'];
if (!in_array($action, array('install', 'uninstall'), true)
    || !preg_match('/^' . preg_quote(PKG_NAMESPACE, '/') . '-[0-9]+\.[0-9]+\.[0-9]+-[A-Za-z][A-Za-z0-9]*$/D', $signature)) {
    fwrite(STDERR, "Invalid action or package signature. Use --help.\n");
    exit(1);
}

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
if (!$config || !file_exists($config)) { fwrite(STDERR, "config.core.php not found; set MODX_CONFIG_CORE.\n"); exit(1); }
require_once $config;
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

$modx = new modX();
$modx->initialize('mgr');
$modx->getService('error', 'error.modError');
if ($action === 'install' && !is_file(MODX_CORE_PATH . 'packages/' . $signature . '.transport.zip')) {
    fwrite(STDERR, "Package archive not found in core/packages. Copy the transport ZIP there first.\n");
    exit(1);
}
$modx->loadClass('transport.modTransportPackage');

if ($action === 'uninstall') {
    $pkg = $modx->getObject('transport.modTransportPackage', array('signature' => $signature));
    if (!$pkg) { echo "no package record for $signature\n"; exit; }
    $un = $pkg->uninstall();
    echo 'uninstall(): ' . ($un ? 'OK' : 'FAILED') . "\n";
    if (!$un) { exit(1); }
    if (!$pkg->remove()) { fwrite(STDERR, "Could not remove the package record.\n"); exit(1); }
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
    if (!$package->save()) { fwrite(STDERR, "Could not create the package record.\n"); exit(1); }
    echo "package record created\n";
} else {
    echo "package record exists\n";
}

$ok = $package->install();
echo 'install(): ' . ($ok ? 'OK' : 'FAILED') . "\n";
if (!$ok) { exit(1); }

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

echo "Copy the API token from Components > modxMCP in the manager.\n";
