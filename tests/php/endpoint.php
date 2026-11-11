<?php
// Test harness: runs the real Kernel behind PHP's built-in server with stubbed MODX classes
// and a SQLite token table. Used by tests/endpoint.test.js; never deployed.
//
//   php tests/php/endpoint.php setup <db-file>     create the table and print test tokens
//   MODXMCP_TEST_DB=<db-file> php -S 127.0.0.1:<port> tests/php/endpoint.php

class TestStatement {
    public function execute() { return true; }
    public function fetch() { return false; }
    public function fetchColumn() { return false; }
}
class TestQuery {
    public $stmt;
    public function __construct() { $this->stmt = new TestStatement(); }
    public function __call($name, $arguments) { return $this; }
    public function prepare() { return true; }
}
class TestUser {
    public function get($key) { return 1; }
    public function set($key, $value) {}
}
class xPDO {
    public $pdo;
    public $options = array();
    public function getOption($key, $options = null, $default = null) {
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }
    public function prepare($sql) { return $this->pdo->prepare($sql); }
    public function exec($sql) { return $this->pdo->exec($sql); }
    public function lastInsertId() { return $this->pdo->lastInsertId(); }
}
class modX extends xPDO {
    const LOG_LEVEL_ERROR = 1;
    public $user;
    public function log($level, $message) { error_log($message); }
    public function getObject($class, $criteria = null) { return $class === 'modUser' ? new TestUser() : null; }
    public function getCollection($class) { return array(); }
    public function getCount($class, $criteria = null) { return 7; }
    public function newQuery($class) { return new TestQuery(); }
    public function getVersionData() { return array('full_version' => '2.8.8-pl'); }
}

$root = dirname(dirname(__DIR__));
require_once $root . '/core/components/modxmcp/src/autoload.php';

function testSite($dbFile) {
    global $root;
    $modx = new modX();
    $modx->pdo = new PDO('sqlite:' . $dbFile);
    $modx->options = array(
        'modxmcp.enabled' => true,
        'modxmcp.core_path' => $root . '/core/components/modxmcp/',
        'core_path' => dirname($dbFile) . '/',
        'table_prefix' => 'modx_',
        'site_name' => 'Test site',
        'modxmcp.disabled_groups' => getenv('MODXMCP_TEST_DISABLED_GROUPS') ?: '',
        'modxmcp.allowed_ips' => getenv('MODXMCP_TEST_ALLOWED_IPS') ?: '',
        'modxmcp.max_payload_bytes' => 4096,
    );
    return $modx;
}

if (PHP_SAPI === 'cli') {
    if (!isset($argv[1], $argv[2]) || $argv[1] !== 'setup') { fwrite(STDERR, "usage: endpoint.php setup <db-file>\n"); exit(2); }
    $modx = testSite($argv[2]);
    // Same columns as TokenStore::install(), in SQLite syntax.
    $modx->pdo->exec('CREATE TABLE modx_modxmcp_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT, prefix TEXT, hash TEXT UNIQUE,'
        . ' scope TEXT, plan_only INTEGER DEFAULT 0, denied_groups TEXT, expires_at INTEGER, last_used_at INTEGER,'
        . ' created_by INTEGER DEFAULT 0, created_at INTEGER, revoked_at INTEGER)');
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
(new \ModxMCP\Kernel(testSite(getenv('MODXMCP_TEST_DB')), $bufferLevel))->handle($_SERVER);
