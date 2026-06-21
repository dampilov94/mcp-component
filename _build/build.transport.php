<?php
/**
 * modxMCP — transport package builder.
 *
 * Run on a MODX 2.x install (CLI or web). It locates config.core.php by walking up
 * from this file, or use the MODX_CONFIG_CORE env var to point at it explicitly.
 *
 *   CLI:  php _build/build.transport.php
 *   web:  place the repo under the docroot and open _build/build.transport.php
 *
 * Produces _packages/modxmcp-<version>-<release>.transport.zip
 */
set_time_limit(0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

require_once dirname(__FILE__) . '/build.config.php';

$root = dirname(dirname(__FILE__)) . '/';          // mcp-component/
$buildDir = $root . '_build/';

/* ---- locate MODX ---- */
$config = getenv('MODX_CONFIG_CORE');
if (!$config || !file_exists($config)) {
    $dir = dirname(__FILE__);
    for ($i = 0; $i < 12; $i++) {
        if (file_exists($dir . '/config.core.php')) { $config = $dir . '/config.core.php'; break; }
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
}
if (!$config || !file_exists($config)) {
    die("modxMCP build: cannot find config.core.php. Set the MODX_CONFIG_CORE env var to its full path.\n");
}
require_once $config;
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

$modx = new modX();
$modx->initialize('mgr');

// When triggered over the web (workspace inside a docroot), require the site's modxMCP token
// as ?key=… so a stranger can't trigger builds. CLI runs are unrestricted.
$__isCli = (PHP_SAPI === 'cli') || (defined('XPDO_CLI_MODE') && XPDO_CLI_MODE);
if (!$__isCli) {
    $__expected = (string) $modx->getOption('modxmcp.api_token', null, '');
    $__provided = isset($_GET['key']) ? (string) $_GET['key'] : '';
    if ($__expected === '' || !hash_equals($__expected, $__provided)) {
        header('HTTP/1.1 403 Forbidden');
        die("Forbidden: web build requires ?key=<modxmcp.api_token>. Or run via CLI.\n");
    }
}

$modx->setLogLevel(modX::LOG_LEVEL_INFO);
$modx->setLogTarget((defined('XPDO_CLI_MODE') && XPDO_CLI_MODE) ? 'ECHO' : 'HTML');
echo ((defined('XPDO_CLI_MODE') && XPDO_CLI_MODE) ? '' : '<pre>');
$modx->log(modX::LOG_LEVEL_INFO, 'Building modxMCP ' . PKG_VERSION . '-' . PKG_RELEASE . ' ...');

$modx->loadClass('transport.modPackageBuilder', '', false, true);

$sources = array(
    'resolvers'     => $buildDir . 'resolvers/',
    'data'          => $buildDir . 'data/',
    'source_core'   => $root . 'core/components/' . PKG_NAMESPACE,
    'source_assets' => $root . 'assets/components/' . PKG_NAMESPACE,
    'docs'          => $root,
);

$builder = new modPackageBuilder($modx);
$builder->createPackage(PKG_NAMESPACE, PKG_VERSION, PKG_RELEASE);
$builder->registerNamespace(
    PKG_NAMESPACE,
    false,
    true,
    '{core_path}components/' . PKG_NAMESPACE . '/',
    '{assets_path}components/' . PKG_NAMESPACE . '/'
);

/* ---- system settings ---- */
$settings = include $sources['data'] . 'transport.settings.php';
if (is_array($settings) && !empty($settings)) {
    $attributes = array(
        xPDOTransport::UNIQUE_KEY    => 'key',
        xPDOTransport::PRESERVE_KEYS => true,
        xPDOTransport::UPDATE_OBJECT => false, // do not overwrite admin-edited settings on upgrade
    );
    foreach ($settings as $setting) {
        $vehicle = $builder->createVehicle($setting, $attributes);
        $builder->putVehicle($vehicle);
    }
    $modx->log(modX::LOG_LEVEL_INFO, 'Packaged ' . count($settings) . ' system settings.');
}

/* ---- manager menu (Components > modxMCP) ---- */
$menu = $modx->newObject('modMenu');
$menu->fromArray(array(
    'text'        => 'modxmcp',
    'parent'      => 'components',
    'description' => 'modxmcp_menu_desc',
    'icon'        => '',
    'menuindex'   => 0,
    'params'      => '',
    'handler'     => '',
    'action'      => 'index',
    'namespace'   => PKG_NAMESPACE,
), '', true, true);
$menuVehicle = $builder->createVehicle($menu, array(
    xPDOTransport::PRESERVE_KEYS => true,
    xPDOTransport::UPDATE_OBJECT => true,
    xPDOTransport::UNIQUE_KEY    => 'text',
    xPDOTransport::RELATED_OBJECTS => false,
));
$builder->putVehicle($menuVehicle);
$modx->log(modX::LOG_LEVEL_INFO, 'Packaged manager menu (Components > modxMCP).');

/* ---- core files ---- */
$coreVehicle = $builder->createVehicle(
    array(
        'source' => $sources['source_core'],
        'target' => "return MODX_CORE_PATH . 'components/';",
    ),
    array('vehicle_class' => 'xPDOFileVehicle')
);
$builder->putVehicle($coreVehicle);

/* ---- assets files (+ token resolver runs after files land) ---- */
$assetsVehicle = $builder->createVehicle(
    array(
        'source' => $sources['source_assets'],
        'target' => "return MODX_ASSETS_PATH . 'components/';",
    ),
    array('vehicle_class' => 'xPDOFileVehicle')
);
$assetsVehicle->resolve('php', array('source' => $sources['resolvers'] . 'resolve.token.php'));
$assetsVehicle->resolve('php', array('source' => $sources['resolvers'] . 'resolve.integrations.php'));
$builder->putVehicle($assetsVehicle);
$modx->log(modX::LOG_LEVEL_INFO, 'Packaged core + assets files and the api_token resolver.');

/* ---- package attributes ---- */
$builder->setPackageAttributes(array(
    'license'   => file_exists($sources['docs'] . 'LICENSE') ? file_get_contents($sources['docs'] . 'LICENSE') : 'MIT',
    'readme'    => file_exists($sources['docs'] . 'README.md') ? file_get_contents($sources['docs'] . 'README.md') : 'modxMCP — MCP endpoint for MODX.',
    'changelog' => file_exists($sources['docs'] . 'CHANGELOG.md') ? file_get_contents($sources['docs'] . 'CHANGELOG.md') : '',
));

/* ---- pack ---- */
$modx->log(modX::LOG_LEVEL_INFO, 'Packing ...');
$builder->pack();

$signature = $builder->getSignature();
$modx->log(modX::LOG_LEVEL_INFO, 'DONE. Package: core/packages/' . $signature . '.transport.zip');
echo ((defined('XPDO_CLI_MODE') && XPDO_CLI_MODE) ? '' : '</pre>');
