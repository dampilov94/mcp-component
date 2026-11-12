<?php
// Test harness: runs the real Kernel behind PHP's built-in server on the fake site
// (tests/php/FakeModx.php). Used by tests/endpoint.test.js; never deployed.
//
//   php tests/php/endpoint.php setup <dir>     prepare the site and print test tokens
//   MODXMCP_TEST_DIR=<dir> php -S 127.0.0.1:<port> tests/php/endpoint.php

require_once __DIR__ . '/FakeModx.php';
require_once dirname(dirname(__DIR__)) . '/core/components/modxmcp/src/autoload.php';

/** A site with miniShop2 "installed": its namespace, and the files the integration probe and the processor lookup need. */
function installMiniShop2($modx, $dir) {
    $core = $dir . '/core/components/minishop2/';
    if (!is_dir($core . 'model/minishop2')) { mkdir($core . 'model/minishop2', 0700, true); }
    touch($core . 'model/minishop2/minishop2.class.php');
    foreach (array('mgr/settings/option/create', 'mgr/settings/option/update', 'mgr/settings/option/remove', 'mgr/settings/option/assign', 'mgr/category/option/delete',
        'mgr/settings/link/create', 'mgr/settings/link/update', 'mgr/settings/link/remove', 'mgr/product/productlink/create', 'mgr/product/productlink/remove') as $processor) {
        if (!is_dir(dirname($core . 'processors/' . $processor))) { mkdir(dirname($core . 'processors/' . $processor), 0700, true); }
        touch($core . 'processors/' . $processor . '.class.php');
    }
    $modx->store('modNamespace', array('name' => 'minishop2', 'path' => '{core_path}components/minishop2/', 'assets_path' => ''));
}

/** A site with MIGX "installed": its namespace and the file the integration probe looks for. */
function installMigx($modx, $dir) {
    $core = $dir . '/core/components/migx/';
    if (!is_dir($core . 'model/migx')) { mkdir($core . 'model/migx', 0700, true); }
    touch($core . 'model/migx/migxconfig.class.php');
    $modx->store('modNamespace', array('name' => 'migx', 'path' => '{core_path}components/migx/', 'assets_path' => ''));
}

function testSite($dir) {
    $modx = testSiteOptions($dir);
    if (getenv('MODXMCP_TEST_MS2')) { installMiniShop2($modx, $dir); }
    if (getenv('MODXMCP_TEST_MIGX')) { installMigx($modx, $dir); }
    return $modx;
}

function testSiteOptions($dir) {
    return fakeSite($dir, array(
        'modxmcp.disabled_groups' => getenv('MODXMCP_TEST_DISABLED_GROUPS') ?: '',
        'modxmcp.allowed_ips' => getenv('MODXMCP_TEST_ALLOWED_IPS') ?: '',
        'modxmcp.max_payload_bytes' => 4096,
        'modxmcp.allow_run_processor' => (bool) getenv('MODXMCP_TEST_ALLOW_ESCAPE'),
        'modxmcp.allow_query_db' => (bool) getenv('MODXMCP_TEST_ALLOW_ESCAPE'),
    ));
}

if (PHP_SAPI === 'cli') {
    if (!isset($argv[1], $argv[2]) || $argv[1] !== 'setup') { fwrite(STDERR, "usage: endpoint.php setup <dir>\n"); exit(2); }
    @mkdir($argv[2] . '/core', 0700, true);
    $modx = testSite($argv[2]);
    $store = new \ModxMCP\Auth\TokenStore($modx);
    $tokens = array(
        'admin'   => $store->create('admin', 'admin'),
        'read'    => $store->create('reader', 'read'),
        'revoked' => $store->create('revoked', 'admin'),
        'expired' => $store->create('expired', 'admin', false, array(), time() + 3600),
    );
    $store->revoke($tokens['revoked']['id']);
    $modx->pdo->exec('UPDATE modx_modxmcp_tokens SET expires_at = 1 WHERE id = ' . (int) $tokens['expired']['id']);
    $listed = $store->all();
    echo json_encode(array(
        'tokens' => array_map(function ($record) { return $record['token']; }, $tokens),
        'listed' => count($listed),
        'leaks'  => (bool) array_filter($listed, function ($record) { return isset($record['hash']) || isset($record['token']); }),
        'stored_plain' => (int) $modx->pdo->query("SELECT COUNT(*) FROM modx_modxmcp_tokens WHERE hash LIKE 'mcp_%'")->fetchColumn(),
    ));
    exit(0);
}

$bufferLevel = ob_get_level();
ob_start();
echo 'stray output that must never reach the client';
(new \ModxMCP\Kernel(testSite(getenv('MODXMCP_TEST_DIR')), $bufferLevel))->handle($_SERVER);
