<?php
// Test harness: runs the real Kernel behind PHP's built-in server on the fake site
// (tests/php/FakeModx.php). Used by tests/endpoint.test.js; never deployed.
//
//   php tests/php/endpoint.php setup <dir>     prepare the site and print test tokens
//   MODXMCP_TEST_DIR=<dir> php -S 127.0.0.1:<port> tests/php/endpoint.php

require_once __DIR__ . '/FakeModx.php';
require_once dirname(dirname(__DIR__)) . '/core/components/modxmcp/src/autoload.php';

function testSite($dir) {
    return fakeSite($dir, array(
        'modxmcp.disabled_groups' => getenv('MODXMCP_TEST_DISABLED_GROUPS') ?: '',
        'modxmcp.allowed_ips' => getenv('MODXMCP_TEST_ALLOWED_IPS') ?: '',
        'modxmcp.max_payload_bytes' => 4096,
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
