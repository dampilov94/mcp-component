<?php
// Offline tests for logic that does not need a MODX installation.
// Run: php tests/php/run.php

class xPDO {}
class modX extends xPDO {
    const LOG_LEVEL_ERROR = 1;
    public $options = array();
    public $user;
    public function getOption($key, $options = null, $default = null) {
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }
    public function log() {}
}

$component = dirname(dirname(__DIR__)) . '/core/components/modxmcp/';
require_once $component . 'src/autoload.php';

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Auth\Security;
use ModxMCP\Auth\TokenStore;
use ModxMCP\Context;
use ModxMCP\Kernel;
use ModxMCP\Manifest;
use ModxMCP\Request\RequestStore;
use ModxMCP\Request\RequestStoreException;
use ModxMCP\Schema;

$failures = 0;
$checks = 0;
function check($label, $condition) {
    global $failures, $checks;
    $checks++;
    if (!$condition) { $failures++; echo "FAIL {$label}\n"; }
}
function throws($label, $class, callable $fn) {
    try { $fn(); check($label, false); }
    catch (Throwable $e) { check($label . ' (' . get_class($e) . ')', $e instanceof $class); return $e; }
    return null;
}
$json = function ($text) { return json_decode($text, false); };

// --- Schema ---
$schema = Schema::obj(array(
    'kind'  => Schema::str(null, array('pattern' => '^[a-z][a-z0-9_]*$')),
    'limit' => Schema::int(null, array('minimum' => 1, 'maximum' => 200)),
    'ids'   => Schema::arr(Schema::int(), null, array('maxItems' => 2)),
    'mode'  => array('type' => 'string', 'enum' => array('a', 'b')),
    'flag'  => Schema::bool(),
), array('kind'));
check('schema: valid', Schema::validate($schema, $json('{"kind":"chunk","limit":5,"ids":[1,2],"mode":"a","flag":true}')) === null);
check('schema: required', Schema::validate($schema, $json('{}')) === 'args.kind is required.');
check('schema: unknown argument', Schema::validate($schema, $json('{"kind":"a","x":1}')) === 'args.x is not a known argument.');
check('schema: pattern', Schema::validate($schema, $json('{"kind":"Bad Kind"}')) !== null);
check('schema: integer type', Schema::validate($schema, $json('{"kind":"a","limit":"5"}')) === 'args.limit must be integer.');
check('schema: float is not integer', Schema::validate($schema, $json('{"kind":"a","limit":5.5}')) !== null);
check('schema: maximum', Schema::validate($schema, $json('{"kind":"a","limit":201}')) !== null);
check('schema: item type', Schema::validate($schema, $json('{"kind":"a","ids":[1,"2"]}')) === 'args.ids[1] must be integer.');
check('schema: maxItems', Schema::validate($schema, $json('{"kind":"a","ids":[1,2,3]}')) !== null);
check('schema: enum', Schema::validate($schema, $json('{"kind":"a","mode":"c"}')) !== null);
check('schema: array is not object', Schema::validate($schema, $json('[]')) === 'args must be object.');
check('schema: empty properties encode as object', json_encode(Schema::obj()) === '{"type":"object","properties":{},"additionalProperties":false}');
check('schema: empty object validates', Schema::validate(Schema::obj(), new stdClass()) === null);

// --- Scope ---
check('scope: admin allows read', Scope::allows('admin', 'read'));
check('scope: read denies content', !Scope::allows('read', 'content'));
check('scope: equal level', Scope::allows('develop', 'develop'));
check('scope: unknown scope denies', !Scope::allows('root', 'read') && !Scope::allows('admin', 'root'));

// --- Security ---
check('ip: exact v4', Security::matchesIpList('10.0.0.5', '10.0.0.5'));
check('ip: cidr v4', Security::matchesIpList('10.1.2.3', '192.168.0.0/16, 10.0.0.0/8'));
check('ip: outside cidr', !Security::matchesIpList('11.0.0.1', '10.0.0.0/8'));
check('ip: odd mask', Security::matchesIpList('10.0.0.130', '10.0.0.128/25') && !Security::matchesIpList('10.0.0.5', '10.0.0.128/25'));
check('ip: v6 cidr', Security::matchesIpList('2001:db8::1', '2001:db8::/32'));
check('ip: family mismatch', !Security::matchesIpList('::1', '127.0.0.1'));
check('ip: malformed mask ignored', !Security::matchesIpList('10.0.0.1', '10.0.0.0/33'));
check('ip: garbage address', !Security::matchesIpList('nope', '0.0.0.0/0'));
check('https: direct', Security::isHttps(array('HTTPS' => 'on', 'REMOTE_ADDR' => '1.2.3.4'), ''));
check('https: untrusted forwarded header', !Security::isHttps(array('HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '1.2.3.4'), ''));
check('https: trusted proxy', Security::isHttps(array('HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '10.0.0.1'), '10.0.0.0/8'));
check('https: trusted proxy chain rejected', !Security::isHttps(array('HTTP_X_FORWARDED_PROTO' => 'https, http', 'REMOTE_ADDR' => '10.0.0.1'), '10.0.0.0/8'));

// --- Tokens (pure parts) ---
check('token: hash is sha256', TokenStore::hashToken('abc') === hash('sha256', 'abc'));
check('token: new format accepted', TokenStore::looksLikeToken('mcp_' . str_repeat('a', 64)));
check('token: 1.x hex accepted', TokenStore::looksLikeToken(str_repeat('f', 64)));
check('token: short rejected', !TokenStore::looksLikeToken('short'));
check('token: odd characters rejected', !TokenStore::looksLikeToken(str_repeat('a', 40) . ' or 1=1'));

// --- Request store ---
$dir = sys_get_temp_dir() . '/modxmcp-test-' . bin2hex(random_bytes(6));
$store = new RequestStore($dir, 3600, 1048576);
$runs = 0;
$callback = function () use (&$runs) { $runs++; return array('status' => 200, 'body' => '{"ok":true,"result":{"n":1}}'); };
$id = 'request-id-000000001';
$first = $store->execute($id, str_repeat('a', 64), $callback);
$second = $store->execute($id, str_repeat('a', 64), $callback);
check('store: first run executes', $runs === 1 && $first['replayed'] === false);
check('store: retry replays without executing', $second['replayed'] === true && $second['body'] === $first['body']);
$e = throws('store: same id, different arguments', RequestStoreException::class, function () use ($store, $id, $callback) {
    $store->execute($id, str_repeat('b', 64), $callback);
});
check('store: mismatch is a conflict', $e && $e->httpStatus === 409);
$status = $store->status($id, true);
check('store: status completed', $status['state'] === 'completed' && $status['success'] === true && $status['response']['result']['n'] === 1);
check('store: unknown id', $store->status('request-id-unknown-01')['state'] === 'not_found');
throws('store: invalid id', RequestStoreException::class, function () use ($store) { $store->status('bad id'); });
foreach (glob($dir . '/*') as $file) { unlink($file); }
rmdir($dir);

// --- Actions, kinds and manifest with a stubbed site ---
$context = function ($scope, array $denied = array(), array $options = array()) use ($component) {
    $modx = new modX();
    $modx->options = $options;
    $token = array('id' => 1, 'label' => 'test', 'prefix' => 'mcp_test', 'scope' => $scope, 'plan_only' => false,
        'denied_groups' => $denied, 'expires_at' => null);
    return new Context($modx, $token, $component, function () { throw new RuntimeException('no store in tests'); });
};
$read = $context('read');
$tools = Manifest::tools($read);
$names = array_column($tools, 'name');
check('manifest: read token sees the core tools', $names === array('modx_capabilities', 'modx_site_info', 'modx_describe', 'modx_help', 'modx_request_status'));
check('manifest: protocol action is not a tool', !in_array('modx_manifest', $names, true));
check('manifest: hash is stable', Manifest::hash($tools) === Manifest::hash(Manifest::tools($context('read'))));
foreach ($tools as $tool) {
    check("manifest: {$tool['name']} is read-only", $tool['effect']['read_only'] === true && $tool['effect']['destructive'] === false);
    check("manifest: {$tool['name']} schemas are objects", $tool['inputSchema']['type'] === 'object' && $tool['outputSchema']['type'] === 'object');
    check("manifest: {$tool['name']} has a description", strlen($tool['description']) > 20);
}
check('manifest: encodes', is_string(json_encode($tools, JSON_THROW_ON_ERROR)));

$describe = $read->actions->get('describe');
$list = $describe->run(array(), $read);
check('describe: lists element kinds', array_column($list['kinds'], 'kind') === array('chunk', 'snippet', 'template', 'plugin', 'tv', 'category'));
$chunk = $describe->run(array('kind' => 'chunk'), $read);
$fields = array_column($chunk['fields'], null, 'name');
check('describe: chunk exposes content as code', !empty($fields['content']['code']) && $fields['name']['required'] === true && $fields['id']['read_only'] === true);
check('describe: column names stay internal', !isset($fields['content']['column']) && !isset($fields['snippet']));
check('describe: plugin has events relation', in_array('events', array_column($describe->run(array('kind' => 'plugin'), $read)['relations'], 'name'), true));
$e = throws('describe: unknown kind', ApiException::class, function () use ($describe, $read) { $describe->run(array('kind' => 'nope'), $read); });
check('describe: unknown kind is not_found', $e && $e->errorCode === 'not_found' && $e->httpStatus === 404);

$help = $read->actions->get('help');
$index = $help->run(array(), $read);
check('help: index topic', $index['topic'] === 'index' && in_array('index', $index['topics'], true) && $index['content'] !== '');
$e = throws('help: unknown topic', ApiException::class, function () use ($help, $read) { $help->run(array('topic' => 'missing_topic'), $read); });
check('help: unknown topic is not_found', $e && $e->errorCode === 'not_found');

$denied = $context('admin', array('minishop2'));
check('groups: token-denied group', $denied->capabilities->groupBlockReason('minishop2', $denied->token) === 'denied_for_token');
$disabled = $context('admin', array(), array('modxmcp.disabled_groups' => 'access, unknown_group'));
check('groups: site-disabled group', $disabled->capabilities->groupBlockReason('access', $disabled->token) === 'disabled_group');
check('groups: unknown names are ignored', $disabled->capabilities->disabledGroups() === array('access'));
check('groups: core is never blocked', $disabled->capabilities->groupBlockReason('core', $disabled->token) === null);

// --- Kernel helpers ---
check('canonical: key order does not matter', Kernel::canonical(array('b' => 1, 'a' => array('d' => 1, 'c' => 2))) === array('a' => array('c' => 2, 'd' => 1), 'b' => 1));
check('canonical: list order is kept', Kernel::canonical(array(3, 1, 2)) === array(3, 1, 2));
check('errors: every code has an HTTP status', min(ApiException::CODES) >= 400 && max(ApiException::CODES) <= 599);

echo $failures ? "{$failures} of {$checks} PHP checks failed.\n" : "PHP: {$checks} checks passed.\n";
exit($failures ? 1 : 0);
