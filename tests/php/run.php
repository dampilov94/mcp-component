<?php
// Offline tests for logic that does not need a MODX installation.
// Run: php tests/php/run.php

require_once __DIR__ . '/FakeModx.php';

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
check('manifest: read token sees the core tools', $names === array('modx_capabilities', 'modx_site_info', 'modx_describe', 'modx_help', 'modx_list', 'modx_get', 'modx_invoke', 'modx_resource_tree', 'modx_view_code', 'modx_search', 'modx_find_usages', 'modx_dependency_graph', 'modx_list_files', 'modx_read_file', 'modx_history', 'modx_history_diff', 'modx_check_page', 'modx_request_status'));
check('manifest: protocol action is not a tool', !in_array('modx_manifest', $names, true));
check('manifest: hash is stable', Manifest::hash($tools) === Manifest::hash(Manifest::tools($context('read'))));
foreach ($tools as $tool) {
    check("manifest: {$tool['name']} is read-only", $tool['name'] === 'modx_invoke' || ($tool['effect']['read_only'] === true && $tool['effect']['destructive'] === false));
}
$all = array_column(Manifest::tools($context('admin')), null, 'name');
check('manifest: full tool count', count($all) === 34);
check('manifest: duplicate is a reversible write after delete', $all['modx_duplicate']['effect']['read_only'] === false
    && $all['modx_duplicate']['effect']['reversible'] === true && $all['modx_duplicate']['effect']['destructive'] === false
    && array_search('modx_duplicate', array_keys($all), true) === array_search('modx_delete', array_keys($all), true) + 1);
check('manifest: writes are marked', $all['modx_update']['effect']['read_only'] === false && $all['modx_delete']['effect']['destructive'] === true && $all['modx_update']['effect']['destructive'] === false);
foreach ($all as $tool) {
    check("manifest: {$tool['name']} schemas are objects", $tool['inputSchema']['type'] === 'object' && $tool['outputSchema']['type'] === 'object');
    check("manifest: {$tool['name']} has a description", strlen($tool['description']) > 20);
}
check('manifest: encodes', is_string(json_encode($tools, JSON_THROW_ON_ERROR)));

$describe = $read->actions->get('describe');
$list = $describe->run(array(), $read);
check('describe: lists element kinds', array_column($list['kinds'], 'kind') === array('chunk', 'snippet', 'template', 'plugin', 'tv', 'category', 'resource', 'media_source', 'user_group', 'role', 'resource_group', 'system_setting', 'context', 'context_setting', 'namespace', 'package', 'provider'));
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

// --- Line edits, diff, lint (pure) ---
use ModxMCP\Code\Diff;
use ModxMCP\Code\LineEdit;
use ModxMCP\Journal\BlobStore;
use ModxMCP\Lint\PhpLint;

$code = "one\ntwo\nthree\nfour";
list($edited, $linesBefore, $linesAfter) = LineEdit::apply($code, array(
    array('start_line' => 2, 'replacement' => "TWO\n2b"),
    array('start_line' => 4, 'end_line' => 3, 'replacement' => 'inserted'),
));
check('edit: replace and insert against the original', $edited === "one\nTWO\n2b\nthree\ninserted\nfour" && $linesBefore === 4 && $linesAfter === 6);
check('edit: delete lines', LineEdit::apply($code, array(array('start_line' => 2, 'end_line' => 3)))[0] === "one\nfour");
check('edit: CRLF is kept', LineEdit::apply("a\r\nb\r\nc", array(array('start_line' => 2, 'replacement' => 'B')))[0] === "a\r\nB\r\nc");
check('edit: expect relocates a drifted range', LineEdit::apply($code, array(array('start_line' => 1, 'expect' => 'three', 'replacement' => '3')))[0] === "one\ntwo\n3\nfour");
$e = throws('edit: expect not found', ApiException::class, function () use ($code) { LineEdit::apply($code, array(array('start_line' => 2, 'expect' => 'zzz'))); });
check('edit: stale expect is a revision conflict', $e && $e->errorCode === 'revision_conflict');
throws('edit: ambiguous expect', ApiException::class, function () { LineEdit::apply("x\ny\nx", array(array('start_line' => 2, 'expect' => 'x'))); });
throws('edit: overlapping ranges', ApiException::class, function () use ($code) {
    LineEdit::apply($code, array(array('start_line' => 1, 'end_line' => 2), array('start_line' => 2, 'end_line' => 3)));
});
throws('edit: out of range', ApiException::class, function () use ($code) { LineEdit::apply($code, array(array('start_line' => 9))); });
$view = LineEdit::numbered($code, 2, 3);
check('view: numbered window', $view[0] === "2\ttwo\n3\tthree" && $view[1] === 4);

check('diff: equal texts', Diff::lines("a\nb", "a\nb") === '');
check('diff: change and insert', Diff::lines("a\nb\nc\nd\ne", "a\nB\nc\nd\nx\ne") === "@@ -2,1 +2,1 @@\n-b\n+B\n@@ -5,0 +5,1 @@\n+x");
check('diff: output is capped', substr_count(Diff::lines(implode("\n", range(1, 400)), implode("\n", range(1001, 1400)), 50), "\n") === 50);

check('lint: valid snippet code', PhpLint::check("\$a = 1;\nreturn \$a;") === null);
check('lint: opening tag accepted', PhpLint::check("<?php\nreturn 1;") === null);
$lint = PhpLint::check("\$a = 1;\nreturn \$a +;\n");
check('lint: error line refers to the stored source', $lint && $lint['line'] === 2 && strpos($lint['message'], 'syntax error') === 0);

// Pure bounded log reader: multiline entries, cursors and byte/character boundaries.
$logPath = sys_get_temp_dir() . '/modxmcp-log-' . bin2hex(random_bytes(6));
$logOne = "[2026-01-01 10:00:00] (ERROR @ /core/test.php : 7) first\ntrace one\ntrace two\n";
$logTwo = "[2026-01-01 10:01:00] (INFO @ /core/next.php) second\n";
$logThree = "[2026-01-01 10:02:00] odd header\ncontinued\n";
file_put_contents($logPath, "startup\n" . $logOne . $logTwo . $logThree);
$log = ModxMCP\Verify\LogReader::tail($logPath);
check('log: multiline entries, prologue and fallback headers', $log['returned'] === 4 && !$log['truncated'] && !$log['reset']
    && $log['entries'][0] === array('message' => 'startup')
    && $log['entries'][1] === array('time' => '2026-01-01 10:00:00', 'level' => 'ERROR', 'location' => '/core/test.php : 7', 'message' => "first\ntrace one\ntrace two")
    && $log['entries'][3] === array('time' => '2026-01-01 10:02:00', 'message' => "odd header\ncontinued"));
$limitedLog = ModxMCP\Verify\LogReader::tail($logPath, 1);
check('log: limit keeps newest matching entry', $limitedLog['truncated'] && $limitedLog['entries'] === array($log['entries'][3]));
$filteredLog = ModxMCP\Verify\LogReader::tail($logPath, 1, 262144, 'TRACE TWO');
check('log: filter covers whole entry before limit', !$filteredLog['truncated'] && $filteredLog['entries'] === array($log['entries'][1]));
check('log: cursor at end is empty', ModxMCP\Verify\LogReader::tail($logPath, 50, 262144, '', $log['end_offset'])['returned'] === 0);
file_put_contents($logPath, $logTwo, FILE_APPEND);
$newLog = ModxMCP\Verify\LogReader::tail($logPath, 50, 262144, '', $log['end_offset']);
check('log: appended entries and new end cursor', $newLog['entries'] === array($log['entries'][2]) && $newLog['end_offset'] === $log['size'] + strlen($logTwo));
$midLog = ModxMCP\Verify\LogReader::tail($logPath, 50, 262144, '', 40);
check('log: cursor inside an entry drops its tail', $midLog['returned'] === 3 && $midLog['entries'][0]['message'] === 'second');
file_put_contents($logPath, $logTwo);
$resetLog = ModxMCP\Verify\LogReader::tail($logPath, 50, 262144, '', $newLog['end_offset']);
check('log: rotation resets cursor', $resetLog['reset'] && $resetLog['returned'] === 1 && $resetLog['end_offset'] === strlen($logTwo));
file_put_contents($logPath, $logOne . str_repeat('old continuation x', 1000) . "\n" . $logTwo . $logThree);
$boundedLog = ModxMCP\Verify\LogReader::tail($logPath, 50, 12000);
check('log: reverse byte window drops partial entry', $boundedLog['truncated'] && $boundedLog['returned'] === 2
    && $boundedLog['entries'][0]['message'] === 'second' && $boundedLog['entries'][1]['message'] === "odd header\ncontinued");
$boundaryLog = ModxMCP\Verify\LogReader::tail($logPath, 50, strlen($logTwo . $logThree) + 1);
check('log: byte window on a line boundary keeps complete entry', $boundaryLog['returned'] === 2);
file_put_contents($logPath, str_replace("\n", "\r\n", $logOne . $logTwo));
check('log: CRLF normalized', ModxMCP\Verify\LogReader::tail($logPath)['entries'][0]['message'] === "first\ntrace one\ntrace two");
file_put_contents($logPath, "[2026-01-01 10:00:00] (ERROR @ place) bad\xff" . str_repeat('Ж', 2100) . 'needle');
$utfLog = ModxMCP\Verify\LogReader::tail($logPath, 50, 262144, 'needle');
check('log: invalid UTF-8 replaced and long message clipped after filtering', $utfLog['returned'] === 1
    && mb_strlen($utfLog['entries'][0]['message'], 'UTF-8') === 2001 && strpos($utfLog['entries'][0]['message'], "\xef\xbf\xbd") !== false
    && preg_match('//u', $utfLog['entries'][0]['message']) === 1);
unlink($logPath);
$emptyLog = ModxMCP\Verify\LogReader::tail($logPath);
check('log: missing file is empty', $emptyLog === array('size' => 0, 'end_offset' => 0, 'entries' => array(), 'returned' => 0, 'truncated' => false, 'reset' => false));

$blobDir = sys_get_temp_dir() . '/modxmcp-blob-' . bin2hex(random_bytes(6));
$blobs = new BlobStore($blobDir, 2048);
$hash = $blobs->put('hello');
check('blobs: content addressed', $hash === hash('sha256', 'hello') && $blobs->get($hash) === 'hello' && $blobs->put('hello') === $hash);
check('blobs: oversize is refused', $blobs->put(str_repeat('x', 4096)) === null);
check('blobs: absent blob', $blobs->get(hash('sha256', 'nope')) === null);
$blobPath = $blobDir . '/' . substr($hash, 0, 2) . '/' . $hash . '.php';
$blobTime = time() - 7200;
touch($blobPath, $blobTime);
file_put_contents(dirname($blobPath) . '/ignored.tmp', 'unfinished');
file_put_contents($blobDir . '/' . $hash . '.php', 'wrong level');
check('blobs: each reports hash, on-disk bytes and mtime, ignoring other files', iterator_to_array($blobs->each(), false)
    === array(array($hash, strlen(BlobStore::FILE_GUARD . 'hello'), $blobTime)));
$missingBlobs = new BlobStore($blobDir . '/not-created');
check('blobs: each on absent storage is empty', iterator_to_array($missingBlobs->each()) === array());
check('blobs: delete reports whether the blob existed', $blobs->delete($hash) === true && $blobs->delete($hash) === false
    && $blobs->get($hash) === null && file_exists(dirname($blobPath) . '/ignored.tmp'));
unlink(dirname($blobPath) . '/ignored.tmp');
$blobs->put('hello');
check('blobs: delete removes an empty shard', $blobs->delete($hash) && !is_dir(dirname($blobPath)));
throws('blobs: delete validates hashes', InvalidArgumentException::class, function () use ($blobs) { $blobs->delete('../bad'); });
$blobs->put('hello');
file_put_contents($blobDir . '/' . substr($hash, 0, 2) . '/' . $hash . '.php', BlobStore::FILE_GUARD . 'tampered');
throws('blobs: corruption is detected', RuntimeException::class, function () use ($blobs, $hash) { $blobs->get($hash); });

// --- Objects, journal and undo on a fake site ---
$siteDir = sys_get_temp_dir() . '/modxmcp-site-' . bin2hex(random_bytes(6));
mkdir($siteDir . '/core', 0700, true);
$site = fakeSite($siteDir);
// This older object/journal block specifically checks database-backed elements.
$site->options['modxmcp.auto_static'] = false;
$site->store('modEvent', array('name' => 'OnLoadWebDocument'));
$site->store('modEvent', array('name' => 'OnWebPageInit'));
$site->store('modTemplate', array('id' => 1, 'templatename' => 'Base', 'content' => '<html>', 'category' => 0, 'static' => 0, 'static_file' => '', 'source' => 0, 'locked' => 0, 'description' => '', 'icon' => ''));
$actor = function ($scope, array $token = array(), $session = 'session-aaaaaaaaaaaa') use ($site, $component) {
    $record = $token + array('id' => 1, 'label' => 'test', 'prefix' => 'mcp_test', 'scope' => $scope, 'plan_only' => false, 'denied_groups' => array(), 'expires_at' => null);
    $c = new Context($site, $record, $component, function () { throw new RuntimeException('no store in tests'); });
    $c->sessionId = $session;
    return $c;
};
// Validate arguments exactly as the kernel does, then run the action.
$call = function (Context $c, $name, array $args = array()) {
    $action = $c->actions->get($name);
    $reason = $c->actions->blockReason($action, $c);
    if ($reason !== null) { throw new ApiException($reason === 'forbidden_scope' ? 'forbidden_scope' : 'unavailable', $reason); }
    $invalid = Schema::validate($action->inputSchema(), json_decode(json_encode($args ? $args : new stdClass())));
    if ($invalid !== null) { throw new ApiException('validation_failed', $invalid); }
    $result = $action->run($args, $c);
    $bad = Schema::validate($action->outputSchema(), json_decode(json_encode($result)), 'result');
    if ($bad !== null) { throw new RuntimeException("Output of {$name} violates its schema: {$bad}"); }
    return $result;
};
$dev = $actor('develop');

$created = $call($dev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'header', 'content' => "<h1>\nTitle\n</h1>", 'description' => 'Top')));
check('create: returns id, revision and changeset', $created['id'] === 1 && preg_match('/^[a-f0-9]{64}$/', $created['revision']) && $created['changeset_id'] === 1 && $created['reversible']);
$got = $call($dev, 'get', array('kind' => 'chunk', 'name' => 'header'));
check('get: fields by api name', $got['fields']['content'] === "<h1>\nTitle\n</h1>" && $got['fields']['static'] === false && $got['revision'] === $created['revision']);
check('get: selected fields only', array_keys($call($dev, 'get', array('kind' => 'chunk', 'id' => 1, 'fields' => array('name')))['fields']) === array('name'));
$e = throws('get: missing object', ApiException::class, function () use ($call, $dev) { $call($dev, 'get', array('kind' => 'chunk', 'id' => 99)); });
check('get: missing object is not_found', $e && $e->errorCode === 'not_found');
throws('create: duplicate name is refused', ApiException::class, function () use ($call, $dev) { $call($dev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'header'))); });
throws('create: required field', ApiException::class, function () use ($call, $dev) { $call($dev, 'create', array('kind' => 'chunk', 'fields' => array('description' => 'x'))); });
throws('create: unknown field', ApiException::class, function () use ($call, $dev) { $call($dev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'a', 'snippet' => 'x'))); });
throws('create: wrong type', ApiException::class, function () use ($call, $dev) { $call($dev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'a', 'category' => '3'))); });
$e = throws('write: read token is refused', ApiException::class, function () use ($call, $actor) { $call($actor('read'), 'create', array('kind' => 'chunk', 'fields' => array('name' => 'a'))); });
check('write: refusal is forbidden_scope', $e && $e->errorCode === 'forbidden_scope');
check('read token: write tools are not in its manifest', !in_array('modx_update', array_column(Manifest::tools($actor('read')), 'name'), true)
    && in_array('modx_get', array_column(Manifest::tools($actor('read')), 'name'), true));

$updated = $call($dev, 'update', array('kind' => 'chunk', 'id' => 1, 'fields' => array('description' => 'Header block'), 'expected_revision' => $created['revision']));
check('update: only the given field changes', $updated['changed'] === array('description') && $updated['revision'] !== $created['revision'] && $updated['changeset_id'] === 2);
check('update: other fields are kept', $call($dev, 'get', array('kind' => 'chunk', 'id' => 1))['fields']['content'] === "<h1>\nTitle\n</h1>");
$e = throws('update: stale revision', ApiException::class, function () use ($call, $dev, $created) {
    $call($dev, 'update', array('kind' => 'chunk', 'id' => 1, 'fields' => array('description' => 'x'), 'expected_revision' => $created['revision']));
});
check('update: stale revision reports who changed it', $e && $e->errorCode === 'revision_conflict' && $e->details['current_revision'] === $updated['revision'] && $e->details['last_change']['changeset_id'] === 2);
$dry = $call($dev, 'update', array('kind' => 'chunk', 'id' => 1, 'fields' => array('description' => 'never', 'name' => 'header'), 'dry_run' => true));
check('dry run: reports and changes nothing', $dry['dry_run'] === true && $dry['would_change'] === array('description') && $call($dev, 'get', array('kind' => 'chunk', 'id' => 1))['revision'] === $updated['revision']);
$same = $call($dev, 'update', array('kind' => 'chunk', 'id' => 1, 'fields' => array('description' => 'Header block')));
check('update: no real change records no changeset', $same['changeset_id'] === null && $same['changed'] === array() && $same['revision'] === $updated['revision']);
$planner = $actor('develop', array('plan_only' => true));
$planned = $call($planner, 'update', array('kind' => 'chunk', 'id' => 1, 'fields' => array('description' => 'planned')));
check('plan-only token: every write is a dry run', $planned['dry_run'] === true && $planned['planned'] === true && $call($dev, 'get', array('kind' => 'chunk', 'id' => 1))['revision'] === $updated['revision']);

$view = $call($dev, 'view_code', array('kind' => 'chunk', 'id' => 1, 'start_line' => 2, 'end_line' => 2));
check('view_code: numbered range', $view['numbered'] === "2\tTitle" && $view['total_lines'] === 3 && $view['revision'] === $updated['revision']);
$edit = $call($dev, 'edit_code', array('kind' => 'chunk', 'id' => 1, 'expected_revision' => $view['revision'],
    'edits' => array(array('start_line' => 2, 'replacement' => 'New title', 'expect' => 'Title'))));
check('edit_code: applies', $edit['changed'] === array('content') && $edit['total_lines_after'] === 3 && $call($dev, 'get', array('kind' => 'chunk', 'id' => 1))['fields']['content'] === "<h1>\nNew title\n</h1>");
throws('edit_code: kind without code', ApiException::class, function () use ($call, $dev) { $call($dev, 'edit_code', array('kind' => 'tv', 'id' => 1, 'edits' => array(array('start_line' => 1)))); });

$snippet = $call($dev, 'create', array('kind' => 'snippet', 'fields' => array('name' => 'year', 'content' => "return date('Y');")));
$e = throws('lint: broken PHP is refused', ApiException::class, function () use ($call, $dev, $snippet) {
    $call($dev, 'update', array('kind' => 'snippet', 'id' => $snippet['id'], 'fields' => array('content' => "return date('Y';")));
});
check('lint: refusal names the line and saves nothing', $e && $e->errorCode === 'lint_failed' && $e->details['line'] === 1
    && $call($dev, 'get', array('kind' => 'snippet', 'id' => $snippet['id']))['revision'] === $snippet['revision']);
$e = throws('lint: line edit that breaks PHP', ApiException::class, function () use ($call, $dev, $snippet) {
    $call($dev, 'edit_code', array('kind' => 'snippet', 'id' => $snippet['id'], 'edits' => array(array('start_line' => 1, 'replacement' => 'return (;'))));
});
check('lint: line edit refused', $e && $e->errorCode === 'lint_failed');
throws('lint: create with broken PHP', ApiException::class, function () use ($call, $dev) { $call($dev, 'create', array('kind' => 'plugin', 'fields' => array('name' => 'bad', 'content' => 'if ('))); });
check('lint: nothing was created', $site->getCount('modPlugin') === 0);
$skipped = $call($dev, 'update', array('kind' => 'snippet', 'id' => $snippet['id'], 'fields' => array('content' => 'return (;'), 'skip_lint' => true));
check('lint: skip_lint saves and warns', $skipped['changed'] === array('content') && count($dev->warnings) === 1);
check('lint: chunks are not PHP', $call($dev, 'update', array('kind' => 'chunk', 'id' => 1, 'fields' => array('description' => 'if (')))['changeset_id'] !== null);

// Relations
$plugin = $call($dev, 'create', array('kind' => 'plugin', 'fields' => array('name' => 'tracker', 'content' => 'return;'), 'relations' => array('events' => array('OnLoadWebDocument'))));
$pluginGot = $call($dev, 'get', array('kind' => 'plugin', 'id' => $plugin['id']));
check('relations: created with the object', $pluginGot['relations']['events'] === array(array('event' => 'OnLoadWebDocument', 'priority' => 0)));
$rel = $call($dev, 'update', array('kind' => 'plugin', 'id' => $plugin['id'], 'relations' => array('events' => array(array('event' => 'OnWebPageInit', 'priority' => 5)))));
check('relations: replacing them is a journaled change', $rel['changed'] === array('events') && $rel['changeset_id'] !== null);
throws('relations: unknown event', ApiException::class, function () use ($call, $dev, $plugin) { $call($dev, 'update', array('kind' => 'plugin', 'id' => $plugin['id'], 'relations' => array('events' => array('OnNothing')))); });
check('relations: failed write leaves them as they were', $call($dev, 'get', array('kind' => 'plugin', 'id' => $plugin['id']))['revision'] === $rel['revision']);
throws('relations: not writable', ApiException::class, function () use ($call, $dev, $plugin) { $call($dev, 'update', array('kind' => 'plugin', 'id' => $plugin['id'], 'relations' => array('property_sets' => array(1)))); });
$tv = $call($dev, 'create', array('kind' => 'tv', 'fields' => array('name' => 'hero', 'type' => 'image'), 'relations' => array('templates' => array(1))));
check('relations: tv templates', $call($dev, 'get', array('kind' => 'tv', 'id' => $tv['id']))['relations']['templates'] === array(1));

// List
$call($dev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'footer', 'category' => 3)));
$call($dev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'head_meta')));
$list = $call($dev, 'list', array('kind' => 'chunk'));
check('list: sorted by name with total', $list['total'] === 3 && array_column($list['items'], 'name') === array('footer', 'head_meta', 'header') && $list['next_start'] === null);
check('list: default fields exclude code', !isset($list['items'][0]['content']) && isset($list['items'][0]['static']));
$page = $call($dev, 'list', array('kind' => 'chunk', 'limit' => 2));
check('list: paging', count($page['items']) === 2 && $page['next_start'] === 2);
check('list: name query', array_column($call($dev, 'list', array('kind' => 'chunk', 'query' => 'head'))['items'], 'name') === array('head_meta', 'header'));
check('list: exact filter', array_column($call($dev, 'list', array('kind' => 'chunk', 'where' => array('category' => 3)))['items'], 'name') === array('footer'));
throws('list: code cannot be listed', ApiException::class, function () use ($call, $dev) { $call($dev, 'list', array('kind' => 'chunk', 'fields' => array('content'))); });

// Search: temporary fixtures do not disturb the journal/history checks below.
$searchFile = $siteDir . '/search.tpl';
file_put_contents($searchFile, "  Needle one  \nno\nNeedle two Needle\nneedle lower");
$searchRows = array(
    array('modChunk', array('id' => 1001, 'name' => 'Z search', 'snippet' => 'stale', 'static' => 1, 'static_file' => $searchFile)),
    array('modChunk', array('id' => 1002, 'name' => 'A search', 'snippet' => 'Needle chunk')),
    array('modSnippet', array('id' => 1001, 'name' => 'S search', 'snippet' => 'return "needle";')),
    array('modResource', array('id' => 1001, 'pagetitle' => 'Page search', 'content' => 'needle %_\\ literal')),
);
foreach ($searchRows as $row) { $site->store($row[0], $row[1]); }
$searchBefore = $site->row('modChunk', 1001);
$found = $call($actor('read'), 'search', array('query' => 'needle', 'kinds' => array('snippet', 'chunk'), 'max_matches' => 1));
check('search: exact counts, requested kind/name order', $found['objects'] === 3 && !$found['truncated']
    && array_column($found['results'], 'name') === array('S search', 'A search', 'Z search')
    && $found['results'][2]['match_count'] === 3 && $found['results'][2]['matches'] === array(array('line' => 1, 'text' => 'Needle one')));
$exact = $call($dev, 'search', array('query' => 'Needle', 'case_sensitive' => true));
check('search: case sensitivity and line numbers', $exact['objects'] === 2 && $exact['results'][1]['matches'] === array(
    array('line' => 1, 'text' => 'Needle one'), array('line' => 3, 'text' => 'Needle two Needle')));
check('search: static file and database are untouched', $site->row('modChunk', 1001) === $searchBefore
    && file_get_contents($searchFile) === "  Needle one  \nno\nNeedle two Needle\nneedle lower");
$limited = $call($dev, 'search', array('query' => 'needle', 'limit' => 1));
check('search: limit reports truncation', $limited['objects'] === 1 && $limited['truncated']);
$nameOnly = $call($dev, 'search', array('query' => 'A search'))['results'][0];
check('search: name-only hit', $nameOnly['name_match'] && $nameOnly['match_count'] === 0 && $nameOnly['matches'] === array());
check('search: resources are opt-in', $call($dev, 'search', array('query' => 'needle'))['objects'] === 3
    && $call($dev, 'search', array('query' => 'needle', 'kinds' => array('resource')))['objects'] === 1);
check('search: SQL wildcard escaping stays literal', $call($dev, 'search', array('query' => '%_\\', 'kinds' => array('resource')))['objects'] === 1
    && $call($dev, 'search', array('query' => '%x', 'kinds' => array('resource')))['objects'] === 0);
foreach (array('tv' => 'validation_failed', 'unknown' => 'not_found') as $badKind => $code) {
    $e = throws('search: invalid kind', ApiException::class, function () use ($call, $dev, $badKind) { $call($dev, 'search', array('query' => 'needle', 'kinds' => array($badKind))); });
    check('search: kind error code', $e && $e->errorCode === $code);
}
file_put_contents($searchFile, str_repeat('Ж', 400) . 'needle' . str_repeat('я', 400));
$window = $call($dev, 'search', array('query' => 'needle'))['results'][1]['matches'][0]['text'];
check('search: long UTF-8 window keeps match visible', preg_match('//u', $window) === 1 && mb_strlen($window, 'UTF-8') <= 301 && strpos($window, 'needle') !== false);
unlink($searchFile);
$missing = $call($dev, 'search', array('query' => 'Z search'))['results'][0];
check('search: missing static file permits name-only hit', $missing['name_match'] && $missing['match_count'] === 0);
foreach ($searchRows as $row) { $site->removeCollection($row[0], array('id' => $row[1]['id'])); }

// Replace uses its own fake site so the history assertions below remain independent.
$replaceDir = $siteDir . '/replace';
mkdir($replaceDir . '/core', 0700, true);
$replaceSite = fakeSite($replaceDir);
$replaceDev = new Context($replaceSite, $dev->token, $dev->corePath, function () { throw new RuntimeException('no store'); });
$replaceDev->sessionId = 'replace-session-0001';
$replaceFile = $replaceDir . '/static.tpl';
file_put_contents($replaceFile, 'replA replA');
$replaceSite->store('modChunk', array('id' => 1, 'name' => 'replace a', 'snippet' => "replA\nreplA repla"));
$replaceSite->store('modChunk', array('id' => 2, 'name' => 'replace static', 'snippet' => 'stale', 'static' => 1, 'static_file' => $replaceFile));
$replaceSite->store('modSnippet', array('id' => 1, 'name' => 'replace php', 'snippet' => "return 'replA';"));
foreach (array('chunk', 'snippet') as $kindName) {
    $kind = $replaceDev->kinds->get($kindName);
    $defaults = array();
    foreach ($kind->fields() as $field => $definition) { $defaults[$kind->column($field)] = $definition['type'] === 'string' ? '' : 0; }
    foreach ($replaceSite->getCollection($kind->modelClass()) as $object) { $replaceSite->store($kind->modelClass(), $object->toArray() + $defaults); }
}
$replaceArgs = array('find' => 'replA', 'replacement' => 'done');
$plan = $call($replaceDev, 'replace', $replaceArgs + array('dry_run' => true));
check('replace: dry run counts occurrences and supplies revisions', count($plan['objects']) === 3 && $plan['total_occurrences'] === 5
    && !$plan['truncated'] && array_keys($plan['revisions']) === array('chunk:1', 'chunk:2', 'snippet:1')
    && $plan['objects'][0]['matches'] === array(array('line' => 1, 'text' => 'replA'), array('line' => 2, 'text' => 'replA repla'))
    && $replaceDev->journal()->lastChangesetId() === 0 && file_get_contents($replaceFile) === 'replA replA');
check('replace: case-insensitive preview counts all casings', $call($replaceDev, 'replace', $replaceArgs + array('dry_run' => true, 'case_sensitive' => false))['total_occurrences'] === 6);
check('replace: bounded preview', $call($replaceDev, 'replace', $replaceArgs + array('dry_run' => true, 'limit' => 1))['truncated']);
$e = throws('replace: real run requires a reviewed map', ApiException::class, function () use ($call, $replaceDev, $replaceArgs) { $call($replaceDev, 'replace', $replaceArgs); });
check('replace: missing map error', $e && $e->errorCode === 'validation_failed');
$applied = $call($replaceDev, 'replace', $replaceArgs + array('expected_revisions' => $plan['revisions']));
check('replace: one changeset replaces all reviewed objects', $applied['total_occurrences'] === 5 && $applied['changeset_id'] === 1
    && count($replaceDev->journal()->changes(array(1))) === 3 && file_get_contents($replaceFile) === 'done done'
    && $replaceSite->row('modChunk', 1)['snippet'] === "done\ndone repla");
$call($replaceDev, 'undo', array('changeset_id' => 1, 'confirm' => true));
check('replace: undo restores all objects including static file', file_get_contents($replaceFile) === 'replA replA'
    && $call($replaceDev, 'get', array('kind' => 'chunk', 'id' => 1))['revision'] === $plan['revisions']['chunk:1']
    && $call($replaceDev, 'get', array('kind' => 'snippet', 'id' => 1))['revision'] === $plan['revisions']['snippet:1']);
$e = throws('replace: lint preflights every object', ApiException::class, function () use ($call, $replaceDev, $plan) {
    $call($replaceDev, 'replace', array('find' => 'replA', 'replacement' => "'", 'expected_revisions' => $plan['revisions']));
});
check('replace: lint identifies object and leaves chunks untouched', $e && $e->errorCode === 'lint_failed' && $e->details['object'] === 'snippet:1'
    && file_get_contents($replaceFile) === 'replA replA' && $replaceSite->row('modChunk', 1)['snippet'] === "replA\nreplA repla");
$call($replaceDev, 'update', array('kind' => 'snippet', 'id' => 1, 'fields' => array('description' => 'changed since preview')));
$e = throws('replace: stale revision refuses the whole batch', ApiException::class, function () use ($call, $replaceDev, $replaceArgs, $plan) {
    $call($replaceDev, 'replace', $replaceArgs + array('expected_revisions' => $plan['revisions']));
});
check('replace: conflict names changed object', $e && $e->errorCode === 'revision_conflict' && $e->details['changed'] === array('snippet:1')
    && file_get_contents($replaceFile) === 'replA replA');
foreach (array('bad' => 'validation_failed', 'resource:1' => 'validation_failed', 'chunk:999' => 'not_found') as $key => $error) {
    $e = throws('replace: invalid selection', ApiException::class, function () use ($call, $replaceDev, $replaceArgs, $key) {
        $call($replaceDev, 'replace', $replaceArgs + array('expected_revisions' => array($key => str_repeat('0', 64))));
    });
    check('replace: selection error code', $e && $e->errorCode === $error);
}
$replacePlanOnly = new Context($replaceSite, array('plan_only' => true) + $dev->token, $dev->corePath, function () {});
check('replace: plan-only forces preview', $call($replacePlanOnly, 'replace', $replaceArgs)['planned']);
$e = throws('replace: content cannot write chunks', ApiException::class, function () use ($call, $actor, $replaceArgs) { $call($actor('content'), 'replace', $replaceArgs + array('dry_run' => true)); });
check('replace: scope enforced during preview', $e && $e->errorCode === 'forbidden_scope');
$insensitive = $call($replaceDev, 'replace', $replaceArgs + array('dry_run' => true, 'case_sensitive' => false));
$done = $call($replaceDev, 'replace', $replaceArgs + array('expected_revisions' => $insensitive['revisions'], 'case_sensitive' => false));
check('replace: insensitive real run', $done['total_occurrences'] === 6 && $replaceSite->row('modChunk', 1)['snippet'] === "done\ndone done");
$zeroPlan = $call($replaceDev, 'replace', array('find' => 'done', 'replacement' => 'x', 'dry_run' => true));
$zero = $call($replaceDev, 'replace', $replaceArgs + array('expected_revisions' => $zeroPlan['revisions']));
check('replace: zero occurrences are returned without a changeset', $zero['total_occurrences'] === 0 && $zero['changeset_id'] === null && count($zero['objects']) === 3);
unlink($replaceFile);
$missingRevision = $call($replaceDev, 'get', array('kind' => 'chunk', 'id' => 2))['revision'];
$e = throws('replace: missing static file is refused', ApiException::class, function () use ($call, $replaceDev, $replaceArgs, $missingRevision) {
    $call($replaceDev, 'replace', $replaceArgs + array('expected_revisions' => array('chunk:2' => $missingRevision)));
});
check('replace: missing file identifies object', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'chunk:2') !== false);

// Find usages: textual references and structural extras on an independent site.
$usageDir = $siteDir . '/usages';
mkdir($usageDir . '/core', 0700, true);
$usageSite = fakeSite($usageDir);
$usageRead = new Context($usageSite, array('scope' => 'read') + $dev->token, $dev->corePath, function () {});
$usageSite->store('modChunk', array('id' => 12, 'name' => 'header', 'snippet' => '[[$header]]', 'category' => 3));
$usageSite->store('modSnippet', array('id' => 13, 'name' => 'worker', 'snippet' => '[[worker]]', 'category' => 3));
$usageSite->store('modTemplateVar', array('id' => 14, 'name' => 'image', 'category' => 3));
$usageSite->store('modResource', array('id' => 12, 'pagetitle' => 'Target', 'content' => '[[~12]]', 'template' => 0, 'deleted' => 0, 'parent' => 0));
$usageSite->store('modTemplate', array('id' => 2, 'templatename' => 'Base usages', 'content' => '', 'category' => 3));
$usageSite->store('modCategory', array('id' => 3, 'category' => 'Parent', 'parent' => 0));
$usageSite->store('modCategory', array('id' => 4, 'category' => 'Child', 'parent' => 3));
$usageCode = <<<'CODE'
[[$header]]
[[!$header]]
getChunk('header')
&tpl=`header`
{include 'header'}
'header' | chunk
[[$header_top]] getChunk('header_top') `header_top` {include 'header_top'}
[[worker]]
[[!worker]]
runSnippet("worker")
&snippet=`worker`
'worker' | snippet
[[worker.extra]] runSnippet('worker-extra') `worker_more`
[[*image]]
[[!*image]]
getTVValue('image')
resource.image
&includeTVs=`other,image`
&processTVs=`image,other`
&tvFilters=`image==yes`
[[*image_more]] resource.image_more &includeTVs=`image_more`
[[~12]]
[[!~12]]
makeUrl(12)
12 | url
[[~123]] makeUrl(123) 123 | url 112 | url
CODE;
$usageSite->store('modChunk', array('id' => 100, 'name' => 'A calls', 'snippet' => $usageCode, 'category' => 3));
$usageFile = $usageDir . '/uses.tpl';
file_put_contents($usageFile, "[[\$header]]\n[[worker]]\n[[*image]]\n[[~12]]");
$usageSite->store('modChunk', array('id' => 101, 'name' => 'Z static', 'snippet' => 'no references', 'static' => 1, 'static_file' => $usageFile, 'category' => 0));
$usageSite->store('modPlugin', array('id' => 100, 'name' => 'Event', 'plugincode' => '', 'category' => 3));
$usageSite->store('modResource', array('id' => 13, 'pagetitle' => 'Page calls', 'content' => "[[\$header]] [[worker]] [[*image]] [[~12]]", 'template' => 2, 'deleted' => 0, 'parent' => 12));
for ($i = 20; $i <= 41; $i++) { $usageSite->store('modResource', array('id' => $i, 'pagetitle' => 'Page ' . $i, 'content' => '', 'template' => 2, 'deleted' => 0, 'parent' => 0)); }
$usageSite->store('modResource', array('id' => 19, 'pagetitle' => 'Trashed', 'content' => '', 'template' => 2, 'deleted' => 1, 'parent' => 0));
$usageSite->store('modTemplateVarTemplate', array('templateid' => 2, 'tmplvarid' => 14));
$usageSite->store('modTemplateVarResource', array('contentid' => 13, 'tmplvarid' => 14, 'value' => 'one'));
$usageSite->store('modTemplateVarResource', array('contentid' => 20, 'tmplvarid' => 14, 'value' => 'two'));
foreach (array('chunk' => array(12, 6, 1), 'snippet' => array(13, 5, 8), 'tv' => array(14, 7, 14), 'resource' => array(12, 4, 22)) as $kindName => $expected) {
    $usage = $call($usageRead, 'find_usages', array('kind' => $kindName, 'id' => $expected[0]));
    check('usages: exact count and ordering for ' . $kindName, $usage['objects'] === 3 && !$usage['truncated']
        && array_column($usage['usages'], 'name') === array('A calls', 'Z static', 'Page calls')
        && $usage['usages'][0]['match_count'] === $expected[1] && $usage['usages'][0]['matches'][0]['line'] === $expected[2]);
    check('usages: tags, API and Fenom classified for ' . $kindName,
        array_column($usage['usages'][0]['matches'], 'via') === ($kindName === 'resource' ? array('tag', 'tag', 'api', 'fenom')
        : ($kindName === 'tv' ? array('tag', 'tag', 'api', 'fenom', 'property') : array('tag', 'tag', 'api', 'property', 'fenom'))));
    check('usages: static content read for ' . $kindName, $usage['usages'][1]['static'] && $usage['usages'][1]['match_count'] === 1);
    $capped = $call($usageRead, 'find_usages', array('kind' => $kindName, 'id' => $expected[0], 'limit' => 1));
    check('usages: object limit for ' . $kindName, $capped['objects'] === 1 && $capped['truncated']);
}
check('usages: template extras count live pages and cap ids', $call($usageRead, 'find_usages', array('kind' => 'template', 'id' => 2))['extra']
    === array('resources' => 23, 'resource_ids' => array_merge(array(13), range(20, 38)), 'tvs' => array(14)));
check('usages: TV extras', $call($usageRead, 'find_usages', array('kind' => 'tv', 'name' => 'image'))['extra'] === array('templates' => array(2), 'resources_with_value' => 2));
check('usages: category extras', $call($usageRead, 'find_usages', array('kind' => 'category', 'id' => 3))['extra']
    === array('chunks' => 2, 'snippets' => 1, 'templates' => 1, 'plugins' => 1, 'tvs' => 1, 'subcategories' => 1));
check('usages: resource children and empty chunk extra', $call($usageRead, 'find_usages', array('kind' => 'resource', 'id' => 12))['extra'] === array('children' => 1)
    && $call($usageRead, 'find_usages', array('kind' => 'chunk', 'id' => 12))['extra'] instanceof stdClass);
$e = throws('usages: unsupported target', ApiException::class, function () use ($call, $usageRead) { $call($usageRead, 'find_usages', array('kind' => 'plugin', 'id' => 100)); });
check('usages: unsupported lists supported targets', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'category') !== false);

// Delete previews reuse the exact usage lookup without deleting or changing it.
$usageAdmin = new Context($usageSite, array('scope' => 'admin') + $dev->token, $dev->corePath, function () {});
$beforeUsages = json_encode($call($usageRead, 'find_usages', array('kind' => 'chunk', 'id' => 12)));
$deleteUsed = $call($usageAdmin, 'delete', array('kind' => 'chunk', 'id' => 12, 'dry_run' => true));
check('delete usages: users and compact warning', $deleteUsed['usages']['objects'] === 3 && !isset($deleteUsed['usages']['items'][0]['matches'])
    && $deleteUsed['warning'] === 'Still used in 3 object(s); deleting it will break them.' && $usageSite->row('modChunk', 12) !== null);
check('delete usages: standalone lookup unchanged', json_encode($call($usageRead, 'find_usages', array('kind' => 'chunk', 'id' => 12))) === $beforeUsages);
$usageSite->store('modChunk', array('id' => 200, 'name' => 'Свободный чанк', 'snippet' => ''));
$unusedDelete = $call($usageAdmin, 'delete', array('kind' => 'chunk', 'id' => 200, 'dry_run' => true));
check('delete usages: unused object has no warning', $unusedDelete['usages']['objects'] === 0 && !isset($unusedDelete['warning']));
foreach (array('template' => array(2, '23 resource(s) use this template.'), 'tv' => array(14, '2 resource(s) have a value for this TV.'),
    'category' => array(3, 'The category is not empty.'), 'resource' => array(12, '1 child resource(s) go to the trash with it.')) as $kindName => $expect) {
    $dryUsage = $call($usageAdmin, 'delete', array('kind' => $kindName, 'id' => $expect[0], 'dry_run' => true));
    check('delete usages: structural warning ' . $kindName, strpos($dryUsage['warning'], $expect[1]) !== false);
}
$usageSite->store('modUserGroup', array('id' => 5, 'name' => 'Group'));
foreach (array('plugin' => 100, 'user_group' => 5) as $kindName => $id) {
    $dryUsage = $call($usageAdmin, 'delete', array('kind' => $kindName, 'id' => $id, 'dry_run' => true));
    check('delete usages: unsupported kind omitted', !isset($dryUsage['usages']) && !isset($dryUsage['warning']));
}
$lookupDenied = new Context($usageSite, array('scope' => 'admin', 'denied_groups' => array('resources')) + $dev->token, $dev->corePath, function () {});
$lookupFailure = $call($lookupDenied, 'delete', array('kind' => 'chunk', 'id' => 12, 'dry_run' => true));
check('delete usages: lookup failure preserves dry run', $lookupFailure['dry_run'] && !isset($lookupFailure['usages']) && count($lookupDenied->warnings) === 1);

// Read log is developer-only and reads the configured MODX cache path.
mkdir($site->getCachePath() . 'logs', 0700, true);
file_put_contents($site->getCachePath() . 'logs/error.log', "[2026-01-01 10:00:00] (ERROR @ fake) action log\n");
check('read_log: develop token can read', $call($dev, 'read_log')['entries'][0]['message'] === 'action log');
foreach (array('read', 'content') as $scope) {
    $e = throws('read_log: lesser scope refused', ApiException::class, function () use ($call, $actor, $scope) { $call($actor($scope), 'read_log'); });
    check('read_log: forbidden and absent from manifest', $e && $e->errorCode === 'forbidden_scope'
        && !in_array('modx_read_log', array_column(Manifest::tools($actor($scope)), 'name'), true));
}

// History and diff
$history = $call($dev, 'history', array('kind' => 'chunk', 'id' => 1));
check('history: newest first, for one object', array_column($history['changesets'], 'tool') === array('update', 'edit_code', 'update', 'create') && $history['changesets'][0]['this_session'] === true);
$editChangeset = $history['changesets'][1]['changeset_id'];
$diff = $call($dev, 'history_diff', array('changeset_id' => $editChangeset));
check('history_diff: code as a line diff', $diff['objects'][0]['changed']['content']['diff'] === "@@ -2,1 +2,1 @@\n-Title\n+New title");
$relDiff = $call($dev, 'history_diff', array('changeset_id' => $rel['changeset_id']));
check('history_diff: relation rows', $relDiff['objects'][0]['changed']['events']['new'][0]['event'] === 'OnWebPageInit');
check('history: other session filter', $call($actor('develop', array(), 'session-bbbbbbbbbbbb'), 'history', array('this_session' => true))['changesets'] === array());

// Undo
$beforeUndo = $call($dev, 'get', array('kind' => 'chunk', 'id' => 1));
$planOnly = $call($dev, 'undo', array('changeset_id' => $editChangeset));
check('undo: without confirm only plans', $planOnly['performed'] === false && $call($dev, 'get', array('kind' => 'chunk', 'id' => 1))['revision'] === $beforeUndo['revision']);
check('undo: a later change is a conflict', $planOnly['conflicts'] === 1 && strpos($planOnly['objects'][0]['conflict'], 'changed later by changeset') === 0);
$e = throws('undo: conflict stops a confirmed undo', ApiException::class, function () use ($call, $dev, $editChangeset) { $call($dev, 'undo', array('changeset_id' => $editChangeset, 'confirm' => true)); });
check('undo: conflict error', $e && $e->errorCode === 'undo_conflict' && $call($dev, 'get', array('kind' => 'chunk', 'id' => 1))['revision'] === $beforeUndo['revision']);

$lastUndo = $call($dev, 'undo', array('last' => 1, 'confirm' => true));   // the "create head_meta" changeset
check('undo: create is undone by removing the object', $lastUndo['performed'] === true && $lastUndo['objects'][0]['action'] === 'remove' && $site->getCount('modChunk', array('name' => 'head_meta')) === 0);
$redo = $call($dev, 'undo', array('changeset_id' => $lastUndo['changeset_id'], 'confirm' => true));
$restored = $site->getObject('modChunk', array('name' => 'head_meta'));
check('undo of undo: the object is back with the same id', $redo['performed'] === true && $restored && (int) $restored->get('id') === 3);
check('undo of undo: the original changeset is live again', $call($dev, 'history', array('kind' => 'chunk', 'id' => 3))['changesets'][2]['status'] === 'committed');
throws('undo: an undone changeset cannot be undone twice', ApiException::class, function () use ($call, $dev, $lastUndo) { $call($dev, 'undo', array('changeset_id' => $lastUndo['changeset_id'], 'confirm' => true)); });

$pluginBefore = $call($dev, 'get', array('kind' => 'plugin', 'id' => $plugin['id']));
$site->store('modTemplateVarResource', array('id' => 1, 'tmplvarid' => $tv['id'], 'contentid' => 7, 'value' => 'hero.jpg'));
$tvBefore = $call($dev, 'get', array('kind' => 'tv', 'id' => $tv['id']));
$dryDelete = $call($dev, 'delete', array('kind' => 'tv', 'id' => $tv['id'], 'dry_run' => true));
check('delete: dry run counts related rows', $dryDelete['would_delete']['related_rows'] == array('templates' => 1, 'values' => 1) && $site->getCount('modTemplateVar') === 1);
$checkpoint = $call($dev, 'checkpoint', array('label' => 'before cleanup'));
$deletedTv = $call($dev, 'delete', array('kind' => 'tv', 'id' => $tv['id']));
$deletedPlugin = $call($dev, 'delete', array('kind' => 'plugin', 'name' => 'tracker'));
$call($dev, 'update', array('kind' => 'chunk', 'id' => 2, 'fields' => array('description' => 'after checkpoint')));
check('delete: object and its rows are gone', $deletedTv['deleted'] === true && $site->getCount('modTemplateVar') === 0 && $site->getCount('modTemplateVarTemplate') === 0
    && $site->getCount('modTemplateVarResource') === 0 && $site->getCount('modPluginEvent') === 0);
$site->events = array();
$back = $call($dev, 'undo', array('to_checkpoint' => $checkpoint['checkpoint_id'], 'confirm' => true));
check('undo to checkpoint: everything after it is reverted', $back['performed'] === true && count($back['changesets']) === 3 && $back['conflicts'] === 0);
check('undo of delete: same id, same state', $call($dev, 'get', array('kind' => 'tv', 'id' => $tv['id']))['revision'] === $tvBefore['revision']
    && $call($dev, 'get', array('kind' => 'plugin', 'id' => $plugin['id']))['revision'] === $pluginBefore['revision']);
check('undo of delete: rows owned by other objects return too', $site->getCount('modTemplateVarResource', array('value' => 'hero.jpg')) === 1);
check('undo: MODX save events are announced', in_array('OnTVFormSave', $site->events, true) && in_array('OnPluginFormSave', $site->events, true) && in_array('OnChunkFormSave', $site->events, true));
check('undo: nothing left after the checkpoint', $call($dev, 'undo', array('to_checkpoint' => $checkpoint['checkpoint_id']))['changesets'] === array());

// A change made outside the journal is detected, and force undoes over it.
$chunk2 = $call($dev, 'update', array('kind' => 'chunk', 'id' => 2, 'fields' => array('description' => 'by mcp')));
$row = $site->row('modChunk', 2);
$site->store('modChunk', array('description' => 'by a person in the manager') + $row);
$outside = $call($dev, 'undo', array('changeset_id' => $chunk2['changeset_id']));
check('undo: change outside the journal is a conflict', $outside['conflicts'] === 1 && $outside['objects'][0]['conflict'] === 'changed outside the journal since this change');
$forced = $call($dev, 'undo', array('changeset_id' => $chunk2['changeset_id'], 'confirm' => true, 'force' => true));
check('undo: force goes back and journals the overwritten state', $forced['performed'] === true && $site->row('modChunk', 2)['description'] === ''
    && $call($dev, 'history_diff', array('changeset_id' => $forced['changeset_id']))['objects'][0]['changed']['description']['old'] === 'by a person in the manager');
$outsideLog = $call($dev, 'history', array('kind' => 'chunk', 'id' => 2, 'source' => 'external'));
check('outside change: recorded as its own changeset when next touched', count($outsideLog['changesets']) === 1 && $outsideLog['changesets'][0]['objects'][0]['op'] === 'update');
$outsideDiff = $call($dev, 'history_diff', array('changeset_id' => $outsideLog['changesets'][0]['changeset_id']));
check('outside change: its diff shows what the person did', $outsideDiff['objects'][0]['changed']['description'] === array('old' => 'by mcp', 'new' => 'by a person in the manager'));
throws('undo: exactly one selector', ApiException::class, function () use ($call, $dev) { $call($dev, 'undo', array('last' => 1, 'this_session' => true)); });

// Static elements: the file is the content.
$file = $siteDir . '/menu.tpl';
file_put_contents($file, "file v1");
$site->store('modChunk', array('id' => 10, 'name' => 'menu', 'snippet' => 'stale database copy', 'static' => 1, 'static_file' => $file, 'source' => 0, 'category' => 0, 'description' => '', 'locked' => 0, 'properties' => null));
$menu = $call($dev, 'get', array('kind' => 'chunk', 'id' => 10));
check('static: reads the file, not the stale column', $menu['fields']['content'] === 'file v1' && $menu['fields']['static'] === true);
$menuEdit = $call($dev, 'update', array('kind' => 'chunk', 'id' => 10, 'fields' => array('content' => 'file v2'), 'expected_revision' => $menu['revision']));
check('static: update writes the file', file_get_contents($file) === 'file v2' && $menuEdit['changed'] === array('content'));
$site->store('modChunk', array('snippet' => 'file v3') + $site->row('modChunk', 10));   // column already equals the new content
$call($dev, 'update', array('kind' => 'chunk', 'id' => 10, 'fields' => array('content' => 'file v3')));
check('static: file is written even when the column already matched', file_get_contents($file) === 'file v3');
file_put_contents($file, 'edited over FTP');
$e = throws('static: file edited outside is a revision conflict', ApiException::class, function () use ($call, $dev, $menuEdit) {
    $call($dev, 'update', array('kind' => 'chunk', 'id' => 10, 'fields' => array('content' => 'x'), 'expected_revision' => $menuEdit['revision']));
});
check('static: conflict leaves the file alone', $e && $e->errorCode === 'revision_conflict' && file_get_contents($file) === 'edited over FTP');
$ftp = $call($dev, 'update', array('kind' => 'chunk', 'id' => 10, 'fields' => array('content' => 'file v4')));
$call($dev, 'undo', array('changeset_id' => $ftp['changeset_id'], 'confirm' => true));
check('static: undo restores the file content', file_get_contents($file) === 'edited over FTP');

// A failure after the processor saved: the object is put back and nothing is journaled.
$stable = $call($dev, 'get', array('kind' => 'chunk', 'id' => 1));
$journalSize = count($call($dev, 'history', array('limit' => 100))['changesets']);
$site->failProcessor = 'element/chunk/update';
throws('rollback: failure after save', RuntimeException::class, function () use ($call, $dev) { $call($dev, 'update', array('kind' => 'chunk', 'id' => 1, 'fields' => array('description' => 'half done'))); });
$site->failProcessor = 'element/chunk/create';
throws('rollback: failure after create', RuntimeException::class, function () use ($call, $dev) { $call($dev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'orphan'))); });
$site->failProcessor = null;
check('rollback: update is reverted', $call($dev, 'get', array('kind' => 'chunk', 'id' => 1))['revision'] === $stable['revision']);
check('rollback: nothing is journaled', count($call($dev, 'history', array('limit' => 100))['changesets']) === $journalSize);

// Oversized snapshots are saved but marked irreversible.
$small = $actor('develop');
$site->options['modxmcp.history_max_blob_bytes'] = 1024;
$big = $call($small, 'update', array('kind' => 'chunk', 'id' => 1, 'fields' => array('content' => str_repeat('x', 5000))));
$site->options['modxmcp.history_max_blob_bytes'] = 5242880;
check('oversize: saved, flagged, with a warning', $big['reversible'] === false && $big['changeset_id'] !== null && count($small->warnings) === 1);
$e = throws('oversize: undo is refused', ApiException::class, function () use ($call, $dev, $big) { $call($dev, 'undo', array('changeset_id' => $big['changeset_id'], 'confirm' => true)); });
check('oversize: refusal is not_reversible', $e && $e->errorCode === 'not_reversible');

$site->options['modxmcp.auto_static'] = true;

// --- Resources ---
$editor = $actor('content');
check('content token: sees write tools', in_array('modx_update', array_column(Manifest::tools($editor), 'name'), true));
$e = throws('content token: elements stay out of reach', ApiException::class, function () use ($call, $editor) { $call($editor, 'update', array('kind' => 'chunk', 'id' => 1, 'fields' => array('description' => 'x'))); });
check('content token: element write is forbidden_scope', $e && $e->errorCode === 'forbidden_scope');

$site->store('modTemplateVar', array('id' => 20, 'name' => 'subtitle', 'default_text' => 'default sub', 'caption' => '', 'type' => 'text', 'category' => 0));
$site->store('modTemplateVar', array('id' => 21, 'name' => 'other', 'default_text' => '', 'caption' => '', 'type' => 'text', 'category' => 0));
$site->store('modTemplateVarTemplate', array('tmplvarid' => 20, 'templateid' => 2, 'rank' => 0));
$home = $call($editor, 'create', array('kind' => 'resource', 'fields' => array('pagetitle' => 'Home', 'template' => 2, 'content' => "<h1>Home</h1>\n<p>text</p>", 'published' => true)));
$about = $call($editor, 'create', array('kind' => 'resource', 'fields' => array('pagetitle' => 'About us', 'parent' => $home['id'], 'template' => 2), 'relations' => array('tvs' => array('subtitle' => 'Who we are'))));
$team = $call($editor, 'create', array('kind' => 'resource', 'fields' => array('pagetitle' => 'Team', 'parent' => $about['id'])));
check('resource: created by a content token', $home['id'] === 1 && $about['changeset_id'] !== null);
$homeGot = $call($editor, 'get', array('kind' => 'resource', 'id' => $home['id']));
check('resource: parent became a container, journaled with the child', $homeGot['fields']['isfolder'] === true
    && count($call($editor, 'history_diff', array('changeset_id' => $about['changeset_id']))['objects']) === 2);
check('resource: tv values read with defaults', $homeGot['relations']['tvs'] == array('subtitle' => 'default sub')
    && $call($editor, 'get', array('kind' => 'resource', 'id' => $about['id']))['relations']['tvs'] == array('subtitle' => 'Who we are'));
check('resource: found by alias', $call($editor, 'get', array('kind' => 'resource', 'name' => 'about-us'))['id'] === $about['id']);
$e = throws('resource: tv not on the template', ApiException::class, function () use ($call, $editor, $home) { $call($editor, 'update', array('kind' => 'resource', 'id' => $home['id'], 'relations' => array('tvs' => array('other' => 'x')))); });
check('resource: unknown tv names the valid ones', $e && strpos($e->getMessage(), 'subtitle') !== false);
throws('resource: tvs must be an object', ApiException::class, function () use ($call, $editor, $home) { $call($editor, 'update', array('kind' => 'resource', 'id' => $home['id'], 'relations' => array('tvs' => array('a', 'b')))); });

$title = $call($editor, 'update', array('kind' => 'resource', 'id' => $home['id'], 'fields' => array('longtitle' => 'Welcome'), 'expected_revision' => $homeGot['revision']));
$afterTitle = $site->row('modResource', $home['id']);
check('resource: update changes one field and keeps the checkboxes', $title['changed'] === array('longtitle') && $afterTitle['published'] === '1' && $afterTitle['isfolder'] === '1' && $afterTitle['searchable'] === '1');
check('resource: the cache was refreshed', $site->getCacheManager()->refreshes > 0);
$noop = $call($editor, 'update', array('kind' => 'resource', 'id' => $home['id'], 'fields' => array('longtitle' => 'Welcome')));
check('resource: editedon alone is not a change', $noop['changeset_id'] === null && $noop['revision'] === $title['revision']);
$tvSet = $call($editor, 'update', array('kind' => 'resource', 'id' => $home['id'], 'relations' => array('tvs' => array('subtitle' => 'Start here'))));
check('resource: tv write is journaled', $tvSet['changed'] === array('tvs') && $call($editor, 'get', array('kind' => 'resource', 'id' => $home['id']))['relations']['tvs'] == array('subtitle' => 'Start here'));
$call($editor, 'undo', array('changeset_id' => $tvSet['changeset_id'], 'confirm' => true));
check('resource: undo of a tv write', $call($editor, 'get', array('kind' => 'resource', 'id' => $home['id']))['relations']['tvs'] == array('subtitle' => 'default sub'));

$future = date('Y-m-d H:i:s', time() + 86400 * 30);
$scheduled = $call($editor, 'update', array('kind' => 'resource', 'id' => $team['id'], 'fields' => array('pub_date' => $future)));
check('resource: publish date is written and read as a date string', $call($editor, 'get', array('kind' => 'resource', 'id' => $team['id']))['fields']['pub_date'] === $future);
$call($editor, 'update', array('kind' => 'resource', 'id' => $team['id'], 'fields' => array('menutitle' => 'Our team')));
$teamRow = $site->row('modResource', $team['id']);
check('resource: a later update keeps the schedule and does not publish', (int) $teamRow['pub_date'] === strtotime($future) && $teamRow['published'] === '0');

$edited = $call($editor, 'edit_code', array('kind' => 'resource', 'id' => $home['id'], 'edits' => array(array('start_line' => 2, 'replacement' => '<p>new text</p>', 'expect' => '<p>text</p>'))));
check('resource: content edits by line', $edited['changed'] === array('content') && $site->row('modResource', $home['id'])['content'] === "<h1>Home</h1>\n<p>new text</p>");

$resources = $call($editor, 'list', array('kind' => 'resource'));
check('resource: list in tree order', array_column($resources['items'], 'pagetitle') === array('Home', 'About us', 'Team') && $resources['items'][0]['published'] === true);
check('resource: list searches title and alias', array_column($call($editor, 'list', array('kind' => 'resource', 'query' => 'about-'))['items'], 'id') === array($about['id']));
check('resource: list filter by parent', array_column($call($editor, 'list', array('kind' => 'resource', 'where' => array('parent' => $home['id'])))['items'], 'id') === array($about['id']));
$tree = $call($editor, 'resource_tree', array('depth' => 3));
check('tree: nested with child counts', $tree['returned'] === 3 && $tree['nodes'][0]['children'] === 1 && $tree['nodes'][0]['nodes'][0]['nodes'][0]['pagetitle'] === 'Team');
check('tree: depth limit leaves the count', !isset($call($editor, 'resource_tree', array('depth' => 1))['nodes'][0]['nodes']));

// Trash with children, and undo
$aboutBefore = $call($editor, 'get', array('kind' => 'resource', 'id' => $about['id']))['revision'];
$teamBefore = $call($editor, 'get', array('kind' => 'resource', 'id' => $team['id']))['revision'];
$trashed = $call($editor, 'delete', array('kind' => 'resource', 'id' => $about['id']));
check('resource delete: to trash with children', $site->row('modResource', $about['id'])['deleted'] === '1' && $site->row('modResource', $team['id'])['deleted'] === '1');
check('resource delete: children are in the changeset', count($call($editor, 'history_diff', array('changeset_id' => $trashed['changeset_id']))['objects']) === 2);
$call($editor, 'undo', array('changeset_id' => $trashed['changeset_id'], 'confirm' => true));
check('resource delete: undo restores the branch exactly', $call($editor, 'get', array('kind' => 'resource', 'id' => $about['id']))['revision'] === $aboutBefore
    && $call($editor, 'get', array('kind' => 'resource', 'id' => $team['id']))['revision'] === $teamBefore);

// Bulk
$news = $call($editor, 'create', array('kind' => 'resource', 'fields' => array('pagetitle' => 'News')));
$dryBulk = $call($editor, 'bulk_resources', array('operation' => 'publish', 'ids' => array($home['id'], $about['id'], $news['id']), 'dry_run' => true));
check('bulk: dry run lists only real changes', array_column($dryBulk['objects'], 'would_change') === array(array(), array('published'), array('published')) && $site->row('modResource', $news['id'])['published'] === '0');
$published = $call($editor, 'bulk_resources', array('operation' => 'publish', 'ids' => array($home['id'], $about['id'], $news['id'])));
check('bulk: one changeset for all', $published['count'] === 2 && $published['changeset_id'] !== null && $site->row('modResource', $about['id'])['published'] === '1');
$moved = $call($editor, 'bulk_resources', array('operation' => 'move', 'ids' => array($team['id']), 'parent' => $news['id']));
check('bulk move: both parents follow', $site->row('modResource', $news['id'])['isfolder'] === '1' && $site->row('modResource', $about['id'])['isfolder'] === '0' && $moved['count'] === 3);
$call($editor, 'undo', array('changeset_id' => $moved['changeset_id'], 'confirm' => true));
check('bulk move: undo puts the tree back', $site->row('modResource', $team['id'])['parent'] === (string) $about['id'] && $site->row('modResource', $about['id'])['isfolder'] === '1' && $site->row('modResource', $news['id'])['isfolder'] === '0');
$call($editor, 'bulk_resources', array('operation' => 'reorder', 'ids' => array($news['id'], $home['id'])));
check('bulk reorder: menu order follows ids', $site->row('modResource', $news['id'])['menuindex'] === '0' && $site->row('modResource', $home['id'])['menuindex'] === '1');
$e = throws('bulk: a missing id stops everything', ApiException::class, function () use ($call, $editor, $news) { $call($editor, 'bulk_resources', array('operation' => 'unpublish', 'ids' => array($news['id'], 999))); });
check('bulk: nothing changed after the failure', $e && $e->errorCode === 'not_found' && $site->row('modResource', $news['id'])['published'] === '1');
$binned = $call($editor, 'bulk_resources', array('operation' => 'delete', 'ids' => array($news['id'], $about['id'])));
$call($editor, 'bulk_resources', array('operation' => 'undelete', 'ids' => array($about['id'])));
check('bulk delete/undelete', $site->row('modResource', $news['id'])['deleted'] === '1' && $site->row('modResource', $team['id'])['deleted'] === '0' && $binned['count'] === 3);
throws('bulk: set_template needs a template', ApiException::class, function () use ($call, $editor, $news) { $call($editor, 'bulk_resources', array('operation' => 'set_template', 'ids' => array($news['id']))); });

// Duplicate: one object through the normal create path, with writable relations only.
$original = $call($dev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'duplicate source', 'content' => '<p>Copy me</p>', 'description' => 'Original description')));
$copy = $call($dev, 'duplicate', array('kind' => 'chunk', 'id' => $original['id']));
$copyGot = $call($dev, 'get', array('kind' => 'chunk', 'id' => $copy['id']));
check('duplicate: chunk fields, identity and journal', $copy['id'] !== $original['id'] && $copy['source_id'] === $original['id']
    && $copy['name'] === 'duplicate source copy' && $copyGot['fields']['name'] === $copy['name']
    && $copyGot['fields']['content'] === '<p>Copy me</p>' && $copyGot['fields']['description'] === 'Original description'
    && $copy['changeset_id'] !== null && $copy['revision'] === $copyGot['revision']);
$secondCopy = $call($dev, 'duplicate', array('kind' => 'chunk', 'name' => 'duplicate source'));
check('duplicate: source by name and automatic numbering', $secondCopy['name'] === 'duplicate source copy 2' && $secondCopy['source_id'] === $original['id']);
$namedCopy = $call($dev, 'duplicate', array('kind' => 'chunk', 'id' => $original['id'], 'new_name' => 'Named copy'));
check('duplicate: explicit copy name', $namedCopy['name'] === 'Named copy');
$e = throws('duplicate: explicit name collision', ApiException::class, function () use ($call, $dev, $original) {
    $call($dev, 'duplicate', array('kind' => 'chunk', 'id' => $original['id'], 'new_name' => 'Named copy'));
});
check('duplicate: explicit collision is validation_failed', $e && $e->errorCode === 'validation_failed');
$overridden = $call($dev, 'duplicate', array('kind' => 'chunk', 'id' => $original['id'], 'new_name' => 'Before override',
    'fields' => array('name' => 'After override', 'description' => 'Overridden', 'content' => 'new content')));
$overriddenGot = $call($dev, 'get', array('kind' => 'chunk', 'id' => $overridden['id']));
check('duplicate: fields win including the returned name', $overridden['name'] === 'After override'
    && $overriddenGot['fields']['name'] === 'After override' && $overriddenGot['fields']['description'] === 'Overridden'
    && $overriddenGot['fields']['content'] === 'new content');
foreach (array(array('id' => 5), array('category' => 'bad'), array('unknown' => 'x')) as $invalidFields) {
    $e = throws('duplicate: invalid field override', ApiException::class, function () use ($call, $dev, $original, $invalidFields) {
        $call($dev, 'duplicate', array('kind' => 'chunk', 'id' => $original['id'], 'fields' => $invalidFields));
    });
    check('duplicate: invalid fields are validation_failed', $e && $e->errorCode === 'validation_failed');
}
for ($number = 3; $number <= 20; $number++) {
    $call($dev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'duplicate source copy ' . $number)));
}
$chunkCount = $site->getCount('modChunk');
$e = throws('duplicate: automatic names stop at 20', ApiException::class, function () use ($call, $dev, $original) {
    $call($dev, 'duplicate', array('kind' => 'chunk', 'id' => $original['id']));
});
check('duplicate: exhausted names create nothing', $e && $e->errorCode === 'validation_failed' && $site->getCount('modChunk') === $chunkCount);

$staticBefore = $call($dev, 'get', array('kind' => 'chunk', 'id' => 10));
$fileBefore = file_get_contents($file);
$staticCopy = $call($dev, 'duplicate', array('kind' => 'chunk', 'id' => 10));
$staticGot = $call($dev, 'get', array('kind' => 'chunk', 'id' => $staticCopy['id']));
check('duplicate: static copy uses effective content without sharing the file', $staticGot['fields']['static'] === false
    && $staticGot['fields']['static_file'] === '' && $staticGot['fields']['content'] === $fileBefore
    && file_get_contents($file) === $fileBefore && $call($dev, 'get', array('kind' => 'chunk', 'id' => 10))['revision'] === $staticBefore['revision']);

$site->store('modElementPropertySet', array('element' => $plugin['id'], 'element_class' => 'modPlugin', 'property_set' => 7));
$duplicateActor = $actor('develop');
$pluginCopy = $call($duplicateActor, 'duplicate', array('kind' => 'plugin', 'id' => $plugin['id']));
$pluginCopyGot = $call($dev, 'get', array('kind' => 'plugin', 'id' => $pluginCopy['id']));
check('duplicate: plugin events are copied', $pluginCopyGot['relations']['events'] === $call($dev, 'get', array('kind' => 'plugin', 'id' => $plugin['id']))['relations']['events']);
check('duplicate: non-writable relations are omitted with one warning', $pluginCopyGot['relations']['property_sets'] === array()
    && $duplicateActor->warnings === array('Not copied: property_sets'));
$site->store('modSnippet', array('id' => $site->nextId('modSnippet'), 'name' => 'Broken duplicate source', 'snippet' => 'return (;'));
$snippetCount = $site->getCount('modSnippet');
$e = throws('duplicate: broken PHP fails lint', ApiException::class, function () use ($call, $dev) {
    $call($dev, 'duplicate', array('kind' => 'snippet', 'name' => 'Broken duplicate source'));
});
check('duplicate: lint failure creates nothing', $e && $e->errorCode === 'lint_failed' && $site->getCount('modSnippet') === $snippetCount);

// One attached TV is stored, one has only a default, and one stored TV is no longer attached.
$site->store('modTemplateVar', array('id' => 22, 'name' => 'default_only', 'default_text' => 'inherited'));
$site->store('modTemplateVarTemplate', array('tmplvarid' => 22, 'templateid' => 2, 'rank' => 0));
$site->store('modTemplateVarResource', array('tmplvarid' => 21, 'contentid' => $about['id'], 'value' => 'stale TV'));
$call($editor, 'update', array('kind' => 'resource', 'id' => $about['id'], 'fields' => array('uri_override' => true, 'uri' => 'custom/about')));
$resourceBefore = $call($editor, 'get', array('kind' => 'resource', 'id' => $about['id']));
$childBefore = $call($editor, 'get', array('kind' => 'resource', 'id' => $team['id']))['revision'];
$resourceCopy = $call($editor, 'duplicate', array('kind' => 'resource', 'id' => $about['id']));
$resourceGot = $call($editor, 'get', array('kind' => 'resource', 'id' => $resourceCopy['id']));
check('duplicate: resource copy is unpublished with a generated URL and same parent', $resourceGot['fields']['published'] === false
    && $resourceGot['fields']['alias'] === 'about-us-copy' && $resourceGot['fields']['alias'] !== $resourceBefore['fields']['alias']
    && $resourceGot['fields']['uri_override'] === false && $resourceGot['fields']['uri'] !== 'custom/about'
    && $resourceGot['fields']['parent'] === $resourceBefore['fields']['parent']);
check('duplicate: only stored TVs on the template are copied', $resourceGot['relations']['tvs'] == array('default_only' => 'inherited', 'subtitle' => 'Who we are')
    && $site->getCount('modTemplateVarResource', array('contentid' => $resourceCopy['id'])) === 1
    && $site->getCount('modTemplateVarResource', array('contentid' => $resourceCopy['id'], 'tmplvarid' => 20, 'value' => 'Who we are')) === 1);
check('duplicate: resource has no children and source is unchanged', $site->getCount('modResource', array('parent' => $resourceCopy['id'])) === 0
    && $call($editor, 'get', array('kind' => 'resource', 'id' => $about['id']))['revision'] === $resourceBefore['revision']
    && $call($editor, 'get', array('kind' => 'resource', 'id' => $team['id']))['revision'] === $childBefore);
$sameTitle = $call($editor, 'duplicate', array('kind' => 'resource', 'id' => $home['id'], 'new_name' => 'Home'));
check('duplicate: resource names need not be unique and empty TV maps stay empty', $sameTitle['name'] === 'Home'
    && $site->getCount('modTemplateVarResource', array('contentid' => $sameTitle['id'])) === 0);

$resourceCount = $site->getCount('modResource');
$journalBefore = $site->pdo->query('SELECT COUNT(*) FROM modx_modxmcp_changesets')->fetchColumn();
$dryCopy = $call($editor, 'duplicate', array('kind' => 'resource', 'id' => $about['id'], 'dry_run' => true));
$planCopy = $call($actor('content', array('plan_only' => true)), 'duplicate', array('kind' => 'resource', 'id' => $about['id']));
check('duplicate: dry run and plan-only describe the copy without writes', $dryCopy['dry_run'] === true
    && $dryCopy['kind'] === 'resource' && $dryCopy['source_id'] === $about['id'] && $dryCopy['would_create'] === array('name' => 'About us copy')
    && $dryCopy['relations'] === array('tvs') && $planCopy['planned'] === true && $planCopy['dry_run'] === true
    && $site->getCount('modResource') === $resourceCount && $site->pdo->query('SELECT COUNT(*) FROM modx_modxmcp_changesets')->fetchColumn() === $journalBefore);
$site->store('modResource', array('pub_date' => (string) (time() + 86400), 'unpub_date' => (string) (time() + 172800)) + $site->row('modResource', $team['id']));
$scheduledCopy = $call($editor, 'duplicate', array('kind' => 'resource', 'id' => $team['id']));
$scheduledRow = $site->row('modResource', $scheduledCopy['id']);
check('duplicate: the copy does not inherit a publish schedule', $scheduledRow['pub_date'] === '0' && $scheduledRow['unpub_date'] === '0' && $scheduledRow['published'] === '0');
$byName = $call($dev, 'duplicate', array('kind' => 'chunk', 'name' => 'duplicate source', 'new_name' => 'Copy found by name'));
check('duplicate: source by name, copy by new_name', $byName['name'] === 'Copy found by name' && $byName['source_id'] === $original['id']);
foreach (array($actor('read'), $editor) as $deniedActor) {
    $e = throws('duplicate: token cannot copy a chunk', ApiException::class, function () use ($call, $deniedActor, $original) {
        $call($deniedActor, 'duplicate', array('kind' => 'chunk', 'id' => $original['id']));
    });
    check('duplicate: scope refusal is forbidden_scope', $e && $e->errorCode === 'forbidden_scope');
}
$call($dev, 'undo', array('changeset_id' => $copy['changeset_id'], 'confirm' => true));
check('duplicate: undo removes the copy and keeps the source', $site->row('modChunk', $copy['id']) === null && $site->row('modChunk', $original['id']) !== null);
$call($editor, 'undo', array('changeset_id' => $resourceCopy['changeset_id'], 'confirm' => true));
check('duplicate: resource undo removes the copy and its stored TVs', $site->row('modResource', $resourceCopy['id']) === null
    && $site->getCount('modTemplateVarResource', array('contentid' => $resourceCopy['id'])) === 0);

$site->store('modResource', array('class_key' => 'msProduct') + $site->row('modResource', $news['id']));
$warned = $actor('content');
$call($warned, 'update', array('kind' => 'resource', 'id' => $news['id'], 'fields' => array('longtitle' => 'Product')));
check('resource: extended classes get a journaling warning', count($warned->warnings) === 1 && strpos($warned->warnings[0], 'msProduct') !== false);

// Maintenance operations use a separate site; irreversible cleanup must not affect other tests.
$maintenanceDir = $siteDir . '/maintenance';
mkdir($maintenanceDir . '/core', 0700, true);
$maintenanceSite = fakeSite($maintenanceDir);
$maintenanceAdmin = new Context($maintenanceSite, array('scope' => 'admin') + $dev->token, $dev->corePath, function () {});
$maintenancePlan = new Context($maintenanceSite, array('scope' => 'admin', 'plan_only' => true) + $dev->token, $dev->corePath, function () {});
$trashParent = $call($maintenanceAdmin, 'create', array('kind' => 'resource', 'fields' => array('pagetitle' => 'Parent')));
$trashChild = $call($maintenanceAdmin, 'create', array('kind' => 'resource', 'fields' => array('pagetitle' => 'Child', 'parent' => $trashParent['id'])));
$trashOther = $call($maintenanceAdmin, 'create', array('kind' => 'resource', 'fields' => array('pagetitle' => 'Other')));
$keepPage = $call($maintenanceAdmin, 'create', array('kind' => 'resource', 'fields' => array('pagetitle' => 'Keep')));
$maintenanceSite->store('modTemplateVarResource', array('contentid' => $trashChild['id'], 'tmplvarid' => 20, 'value' => 'trash'));
$maintenanceSite->store('modResourceGroupResource', array('document' => $trashChild['id'], 'document_group' => 2));
$call($maintenanceAdmin, 'delete', array('kind' => 'resource', 'id' => $trashParent['id']));
$call($maintenanceAdmin, 'delete', array('kind' => 'resource', 'id' => $trashOther['id']));
$maintenanceHistory = $maintenanceAdmin->journal()->lastChangesetId();
$trashPlan = $call($maintenanceAdmin, 'maintenance', array('operation' => 'empty_trash'));
check('maintenance: unconfirmed trash previews descendants too', !$trashPlan['performed'] && $trashPlan['would_remove'] === 3
    && array_column($trashPlan['resources'], 'id') === array($trashParent['id'], $trashChild['id'], $trashOther['id'])
    && $maintenanceSite->getCount('modResource') === 4);
$refreshes = $maintenanceSite->getCacheManager()->refreshes;
foreach (array('clear_cache', 'refresh_uris', 'remove_locks', 'flush_permissions', 'empty_trash') as $operation) {
    check('maintenance: plan-only never performs ' . $operation, !$call($maintenancePlan, 'maintenance', array('operation' => $operation, 'confirm' => true))['performed']);
}
check('maintenance: plans cause no cache/processor work', $maintenanceSite->getCacheManager()->refreshes === $refreshes && $maintenanceSite->maintenanceProcessors === array());
check('maintenance: clear cache calls refresh', $call($maintenanceAdmin, 'maintenance', array('operation' => 'clear_cache'))['performed']
    && $maintenanceSite->getCacheManager()->refreshes === $refreshes + 1);
foreach (array('refresh_uris' => 'system/refreshuris', 'remove_locks' => 'system/remove_locks', 'flush_permissions' => 'security/access/flush') as $operation => $path) {
    check('maintenance: correct processor for ' . $operation, $call($maintenanceAdmin, 'maintenance', array('operation' => $operation))['performed']
        && end($maintenanceSite->maintenanceProcessors) === $path);
}
$maintenanceSite->failProcessor = 'system/refreshuris';
$e = throws('maintenance: processor refusal', ApiException::class, function () use ($call, $maintenanceAdmin) { $call($maintenanceAdmin, 'maintenance', array('operation' => 'refresh_uris')); });
check('maintenance: processor message passes through', $e && $e->errorCode === 'validation_failed' && $e->getMessage() === 'Maintenance processor refused.');
$maintenanceSite->failProcessor = null;
foreach (array('read', 'content', 'develop') as $scope) {
    $e = throws('maintenance: non-admin refused', ApiException::class, function () use ($call, $actor, $scope) { $call($actor($scope), 'maintenance', array('operation' => 'clear_cache')); });
    check('maintenance: forbidden scope and hidden tool', $e && $e->errorCode === 'forbidden_scope'
        && !in_array('modx_maintenance', array_column(Manifest::tools($actor($scope)), 'name'), true));
}
$maintenanceTool = array_column(Manifest::tools($maintenanceAdmin), null, 'name')['modx_maintenance'];
check('maintenance: admin manifest marks irreversible destructive write', !$maintenanceTool['effect']['read_only'] && !$maintenanceTool['effect']['reversible'] && $maintenanceTool['effect']['destructive']);
$emptied = $call($maintenanceAdmin, 'maintenance', array('operation' => 'empty_trash', 'confirm' => true));
check('maintenance: confirmed purge removes exactly trashed pages and relations', $emptied['performed'] && $emptied['removed'] === 3
    && $maintenanceSite->getCount('modResource') === 1 && $maintenanceSite->row('modResource', $keepPage['id']) !== null
    && $maintenanceSite->getCount('modTemplateVarResource') === 0 && $maintenanceSite->getCount('modResourceGroupResource') === 0
    && end($maintenanceSite->maintenanceProcessors) === 'resource/emptyrecyclebin');
check('maintenance: purge warns and never journals', $maintenanceAdmin->warnings === array('Resources removed from the trash cannot be restored by modx_undo.')
    && $maintenanceAdmin->journal()->lastChangesetId() === $maintenanceHistory);

// Access kinds: only admin writes; ordinary read scopes and group gates still apply.
$accessDir = $siteDir . '/access';
mkdir($accessDir . '/core', 0700, true);
$accessSite = fakeSite($accessDir);
$accessContext = function ($scope = 'admin', array $extra = array()) use ($accessSite, $dev) {
    $context = new Context($accessSite, $extra + array('scope' => $scope) + $dev->token, $dev->corePath, function () {});
    $context->sessionId = 'access-session-0001';
    return $context;
};
$accessAdmin = $accessContext();
$accessSite->store('modUserGroup', array('id' => 1, 'name' => 'Renamed core group', 'description' => '', 'parent' => 0, 'rank' => 0, 'dashboard' => 1));
$accessSite->store('modUserGroupRole', array('id' => 1, 'name' => 'Super User', 'description' => '', 'authority' => 0));
$accessSite->store('modUserGroupRole', array('id' => 2, 'name' => 'Member', 'description' => '', 'authority' => 9999));
$accessCreated = array();
foreach (array('user_group', 'role', 'resource_group') as $kindName) {
    $fields = array('name' => 'Test ' . $kindName);
    if ($kindName === 'user_group') { $fields += array('description' => 'group description', 'parent' => 0, 'rank' => 7, 'dashboard' => 2); }
    if ($kindName === 'role') { $fields += array('description' => 'role description', 'authority' => 500); }
    $made = $call($accessAdmin, 'create', array('kind' => $kindName, 'fields' => $fields));
    $accessCreated[$kindName] = $made;
    $gotAccess = $call($accessAdmin, 'get', array('kind' => $kindName, 'id' => $made['id']));
    check('access: create/get ' . $kindName, $gotAccess['fields']['name'] === $fields['name'] && $gotAccess['revision'] === $made['revision']
        && $made['changeset_id'] !== null);
    foreach ($fields as $field => $value) { check('access: field ' . $kindName . '.' . $field, $gotAccess['fields'][$field] === $value); }
    $changedAccess = $call($accessAdmin, 'update', array('kind' => $kindName, 'id' => $made['id'], 'fields' => array('name' => 'Updated ' . $kindName)));
    check('access: update/list ' . $kindName, $changedAccess['changeset_id'] !== null
        && $call($accessAdmin, 'list', array('kind' => $kindName, 'query' => 'Updated'))['items'][0]['name'] === 'Updated ' . $kindName);
    $duplicatedAccess = $call($accessAdmin, 'duplicate', array('kind' => $kindName, 'id' => $made['id']));
    check('access: duplicate ' . $kindName, $duplicatedAccess['name'] === 'Updated ' . $kindName . ' copy' && $duplicatedAccess['id'] !== $made['id']);
    $call($accessAdmin, 'delete', array('kind' => $kindName, 'id' => $duplicatedAccess['id']));
    check('access: delete ' . $kindName, $accessSite->row($accessAdmin->kinds->get($kindName)->modelClass(), $duplicatedAccess['id']) === null);
    $beforeError = $accessAdmin->journal()->lastChangesetId();
    $e = throws('access: duplicate names rejected', ApiException::class, function () use ($call, $accessAdmin, $kindName) {
        $call($accessAdmin, 'create', array('kind' => $kindName, 'fields' => array('name' => 'Updated ' . $kindName)));
    });
    check('access: duplicate error journals nothing', $e && $e->errorCode === 'validation_failed' && $accessAdmin->journal()->lastChangesetId() === $beforeError);
    foreach (array('read', 'content', 'develop') as $scope) {
        $reader = $accessContext($scope);
        check('access: lower scope reads ' . $kindName, $call($reader, 'list', array('kind' => $kindName))['total'] > 0
            && $call($reader, 'get', array('kind' => $kindName, 'id' => $made['id']))['id'] === $made['id']);
        foreach (array('create', 'update', 'delete', 'duplicate') as $action) {
            $e = throws('access: lower scope write refused', ApiException::class, function () use ($call, $reader, $kindName, $made, $action) {
                $args = array('kind' => $kindName, 'id' => $made['id']);
                if ($action === 'create') { $args = array('kind' => $kindName, 'fields' => array('name' => 'Forbidden')); }
                if ($action === 'update') { $args['fields'] = array('name' => 'Forbidden'); }
                $call($reader, $action, $args);
            });
            check('access: write scope is forbidden_scope', $e && $e->errorCode === 'forbidden_scope');
        }
    }
}
foreach (array('disabled_group', 'denied_for_token') as $reason) {
    $accessSite->options['modxmcp.disabled_groups'] = $reason === 'disabled_group' ? 'access' : '';
    $blockedAccess = $accessContext('admin', array('denied_groups' => $reason === 'denied_for_token' ? array('access') : array()));
    $visible = array_column($call($blockedAccess, 'describe')['kinds'], 'kind');
    $capsAccess = $call($blockedAccess, 'capabilities');
    $unavailableAccess = array_column($capsAccess['unavailable_kinds'], 'reason', 'name');
    foreach ($accessCreated as $kindName => $made) {
        check('access: hidden kind and reason ' . $reason, !in_array($kindName, $visible, true)
            && !in_array($kindName, $capsAccess['kinds'], true) && $unavailableAccess[$kindName] === $reason);
        foreach (array('describe', 'list', 'get', 'create', 'update', 'delete', 'duplicate', 'view_code', 'edit_code', 'find_usages', 'search', 'replace') as $action) {
            $args = array('kind' => $kindName, 'id' => $made['id']);
            if ($action === 'describe' || $action === 'list') { unset($args['id']); }
            if ($action === 'create') { $args = array('kind' => $kindName, 'fields' => array('name' => 'Blocked')); }
            if ($action === 'update') { $args['fields'] = array('name' => 'Blocked'); }
            if ($action === 'edit_code') { $args['edits'] = array(array('start_line' => 1, 'replacement' => 'Blocked')); }
            if ($action === 'search') { $args = array('query' => 'Blocked', 'kinds' => array($kindName)); }
            if ($action === 'replace') { $args = array('find' => 'Blocked', 'replacement' => '', 'kinds' => array($kindName), 'dry_run' => true); }
            $e = throws('access: blocked kind tool ' . $action, ApiException::class, function () use ($call, $blockedAccess, $action, $args) { $call($blockedAccess, $action, $args); });
            check('access: blocked kind is unavailable', $e && $e->errorCode === 'unavailable');
        }
    }
}
$accessSite->options['modxmcp.disabled_groups'] = '';
$groupId = $accessCreated['user_group']['id'];
$roleId = $accessCreated['role']['id'];
$resourceGroupId = $accessCreated['resource_group']['id'];
$memberRows = array(array('id' => 11, 'user_group' => $groupId, 'member' => 101, 'role' => $roleId, 'rank' => 2),
    array('id' => 12, 'user_group' => $groupId, 'member' => 102, 'role' => 2, 'rank' => 3));
$resourceRows = array(array('id' => 21, 'document_group' => $resourceGroupId, 'document' => 201), array('id' => 22, 'document_group' => $resourceGroupId, 'document' => 202));
foreach ($memberRows as $row) { $accessSite->store('modUserGroupMember', $row); }
foreach ($resourceRows as $row) { $accessSite->store('modResourceGroupResource', $row); }
foreach (array('user_group' => array('members', $groupId, 'modUserGroupMember'), 'resource_group' => array('resources', $resourceGroupId, 'modResourceGroupResource')) as $kindName => $info) {
    list($relationName, $objectId, $relationClass) = $info;
    $withRelations = $call($accessAdmin, 'get', array('kind' => $kindName, 'id' => $objectId));
    check('access: related rows exposed ' . $kindName, $withRelations['relations'][$relationName] === ($kindName === 'user_group'
        ? array(array('member' => 101, 'role' => $roleId), array('member' => 102, 'role' => 2)) : array(201, 202)));
    $e = throws('access: relations remain read-only', ApiException::class, function () use ($call, $accessAdmin, $kindName, $objectId, $relationName) {
        $call($accessAdmin, 'update', array('kind' => $kindName, 'id' => $objectId, 'relations' => array($relationName => array())));
    });
    check('access: relation write refused', $e && $e->errorCode === 'validation_failed');
    $removedAccess = $call($accessAdmin, 'delete', array('kind' => $kindName, 'id' => $objectId));
    check('access: delete cascades links', $accessSite->getCount($relationClass) === 0);
    $call($accessAdmin, 'undo', array('changeset_id' => $removedAccess['changeset_id'], 'confirm' => true));
    check('access: undo restores id and complete relation state', $call($accessAdmin, 'get', array('kind' => $kindName, 'id' => $objectId))['revision'] === $withRelations['revision']
        && $accessSite->getCount($relationClass) === 2);
}
$namedAdmin = $call($accessAdmin, 'create', array('kind' => 'user_group', 'fields' => array('name' => 'Administrator')));
foreach (array(array('user_group', 1), array('user_group', $namedAdmin['id']), array('role', 1), array('role', 2), array('role', $roleId)) as $protectedAccess) {
    $lastAccess = $accessAdmin->journal()->lastChangesetId();
    $e = throws('access: MODX protected/assigned object refusal', ApiException::class, function () use ($call, $accessAdmin, $protectedAccess) {
        $call($accessAdmin, 'delete', array('kind' => $protectedAccess[0], 'id' => $protectedAccess[1]));
    });
    check('access: refusal is validation_failed with no changeset', $e && $e->errorCode === 'validation_failed' && $accessAdmin->journal()->lastChangesetId() === $lastAccess);
}
$call($accessAdmin, 'update', array('kind' => 'role', 'id' => 1, 'fields' => array('name' => 'Renamed ordinary role')));
$call($accessAdmin, 'delete', array('kind' => 'role', 'id' => 1));
check('access: role protection follows names, not id, as in MODX', $accessSite->row('modUserGroupRole', 1) === null);

// Invoke: membership operations through the existing access fixture.
$accessSite->store('modUser', array('id' => 501, 'username' => 'участник', 'primary_group' => 0));
$opSpecs = array_column($call($accessAdmin, 'describe', array('kind' => 'user_group'))['operations'], null, 'op');
check('invoke: describe includes schemas and needs_object', $opSpecs['add_member']['needs_object'] && !$opSpecs['add_member']['read_only']
    && $opSpecs['add_member']['input']['required'] === array('user') && $opSpecs['member_count']['read_only']);
$opBase = array('kind' => 'user_group', 'id' => $groupId);
$beforeMemberCount = $call($accessContext('read'), 'invoke', $opBase + array('op' => 'member_count'))['members'];
$invokeDry = $call($accessAdmin, 'invoke', $opBase + array('op' => 'add_member', 'args' => array('user' => 501), 'dry_run' => true));
check('invoke: validated dry run does not call handler', $invokeDry['validated'] && $invokeDry['dry_run'] && !$accessSite->getCount('modUserGroupMember', array('member' => 501)));
check('invoke: plan-only forces dry run', $call($accessContext('admin', array('plan_only' => true)), 'invoke', $opBase + array('op' => 'add_member', 'args' => array('user' => 501)))['planned']);
$addedMember = $call($accessAdmin, 'invoke', $opBase + array('op' => 'add_member', 'args' => array('user' => 501)));
check('invoke: member addition is journaled with default role zero', $addedMember['changed'] === array('members') && $addedMember['changeset_id'] !== null
    && count($accessAdmin->journal()->changes(array($addedMember['changeset_id']))) === 2   // the group and, since task 17, the member
    && $accessSite->getObject('modUserGroupMember', array('member' => 501))->get('role') === '0');
check('invoke: read member_count works and tool is visible', $call($accessContext('read'), 'invoke', $opBase + array('op' => 'member_count'))['members'] === $beforeMemberCount + 1
    && in_array('modx_invoke', array_column(Manifest::tools($accessContext('read')), 'name'), true));
$lastInvoke = $accessAdmin->journal()->lastChangesetId();
$e = throws('invoke: duplicate member', ApiException::class, function () use ($call, $accessAdmin, $opBase) { $call($accessAdmin, 'invoke', $opBase + array('op' => 'add_member', 'args' => array('user' => 501))); });
check('invoke: duplicate add passes error without journal', $e && $e->errorCode === 'validation_failed' && $accessAdmin->journal()->lastChangesetId() === $lastInvoke);
$removedMember = $call($accessAdmin, 'invoke', $opBase + array('op' => 'remove_member', 'args' => array('user' => 501)));
check('invoke: member removal journaled', $removedMember['changed'] === array('members') && !$accessSite->getCount('modUserGroupMember', array('member' => 501)));
$e = throws('invoke: removing non-member', ApiException::class, function () use ($call, $accessAdmin, $opBase) { $call($accessAdmin, 'invoke', $opBase + array('op' => 'remove_member', 'args' => array('user' => 501))); });
check('invoke: remove non-member error and no journal', $e && $e->errorCode === 'validation_failed' && $accessAdmin->journal()->lastChangesetId() === $removedMember['changeset_id']);
$call($accessAdmin, 'undo', array('changeset_id' => $removedMember['changeset_id'], 'confirm' => true));
check('invoke: undo member removal', $accessSite->getCount('modUserGroupMember', array('member' => 501)) === 1);
$call($accessAdmin, 'undo', array('changeset_id' => $addedMember['changeset_id'], 'confirm' => true));
check('invoke: undo member addition', !$accessSite->getCount('modUserGroupMember', array('member' => 501)));
$opPage = $call($accessAdmin, 'create', array('kind' => 'resource', 'fields' => array('pagetitle' => 'Страница группы')));
$resourceOp = array('kind' => 'resource_group', 'id' => $resourceGroupId, 'args' => array('resource' => $opPage['id']));
$addedResource = $call($accessAdmin, 'invoke', $resourceOp + array('op' => 'add_resource'));
check('invoke: resource and group share one changeset', $addedResource['changed'] === array('resources')
    && count($accessAdmin->journal()->changes(array($addedResource['changeset_id']))) === 2 && end($accessSite->events) === 'OnResourceAddToResourceGroup');
$e = throws('invoke: duplicate resource membership', ApiException::class, function () use ($call, $accessAdmin, $resourceOp) { $call($accessAdmin, 'invoke', $resourceOp + array('op' => 'add_resource')); });
check('invoke: duplicate resource does not journal', $e && $e->errorCode === 'validation_failed' && $accessAdmin->journal()->lastChangesetId() === $addedResource['changeset_id']);
$removedResource = $call($accessAdmin, 'invoke', $resourceOp + array('op' => 'remove_resource'));
check('invoke: resource removal fires event and journals both', count($accessAdmin->journal()->changes(array($removedResource['changeset_id']))) === 2 && end($accessSite->events) === 'OnResourceRemoveFromResourceGroup');
$e = throws('invoke: removing non-member resource', ApiException::class, function () use ($call, $accessAdmin, $resourceOp) { $call($accessAdmin, 'invoke', $resourceOp + array('op' => 'remove_resource')); });
check('invoke: resource refusal', $e && $e->errorCode === 'validation_failed' && $accessAdmin->journal()->lastChangesetId() === $removedResource['changeset_id']);
$call($accessAdmin, 'undo', array('changeset_id' => $removedResource['changeset_id'], 'confirm' => true));
check('invoke: undo restores resource relation', in_array($resourceGroupId, $call($accessAdmin, 'get', array('kind' => 'resource', 'id' => $opPage['id']))['relations']['resource_groups'], true));
$call($accessAdmin, 'undo', array('changeset_id' => $addedResource['changeset_id'], 'confirm' => true));
check('invoke: undo removes resource relation', !in_array($resourceGroupId, $call($accessAdmin, 'get', array('kind' => 'resource', 'id' => $opPage['id']))['relations']['resource_groups'], true));
foreach (array(array(), array('user' => '501'), array('user' => 501, 'extra' => true)) as $invalidOp) {
    $e = throws('invoke: schema validation', ApiException::class, function () use ($call, $accessAdmin, $opBase, $invalidOp) { $call($accessAdmin, 'invoke', $opBase + array('op' => 'add_member', 'args' => $invalidOp)); });
    check('invoke: schema error path', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'args.') !== false);
}
foreach (array(array('kind' => 'user_group', 'op' => 'unknown', 'id' => $groupId), array('kind' => 'role', 'op' => 'member_count', 'id' => $roleId)) as $unknownOp) {
    $e = throws('invoke: unknown operation', ApiException::class, function () use ($call, $accessAdmin, $unknownOp) { $call($accessAdmin, 'invoke', $unknownOp); });
    check('invoke: unknown op points to describe', $e && $e->errorCode === 'not_found' && strpos($e->getMessage(), 'modx_describe') !== false);
}
$e = throws('invoke: object required', ApiException::class, function () use ($call, $accessAdmin) { $call($accessAdmin, 'invoke', array('kind' => 'user_group', 'op' => 'member_count')); });
check('invoke: missing object reference', $e && $e->errorCode === 'validation_failed');
$e = throws('invoke: write scope', ApiException::class, function () use ($call, $accessContext, $opBase) { $call($accessContext('develop'), 'invoke', $opBase + array('op' => 'add_member', 'args' => array('user' => 501))); });
check('invoke: write denied to develop', $e && $e->errorCode === 'forbidden_scope');
$accessSite->options['modxmcp.disabled_groups'] = 'access';
$e = throws('invoke: disabled group', ApiException::class, function () use ($call, $accessAdmin, $opBase) { $call($accessAdmin, 'invoke', $opBase + array('op' => 'member_count')); });
check('invoke: group disabled is unavailable', $e && $e->errorCode === 'unavailable');
$accessSite->options['modxmcp.disabled_groups'] = '';

// Exercise the generic object-less contract before real object-less ops land in task 11.
$invokeFixtureContext = $accessContext();
$fixtureKind = new class extends ModxMCP\Kind\Kind {
    public function name() { return 'invoke_fixture'; }
    public function label() { return 'Invoke fixture'; }
    public function modelClass() { return 'modUserGroup'; }
    public function processorPath() { return 'security/group/'; }
    public function fields() { return (new ModxMCP\Kind\UserGroupKind())->fields(); }
    public function operations() {
        return array('read' => array('description' => 'Fixture read.', 'read_only' => true, 'needs_object' => false, 'input' => Schema::obj(), 'handler' => 'read'),
            'noop' => array('description' => 'Fixture no-op.', 'read_only' => false, 'input' => Schema::obj(), 'handler' => 'noop'));
    }
    public function read(Context $context, $object, array $args): array { return array('object_is_null' => $object === null); }
    public function noop(Context $context, ModxMCP\Write\Transaction $tx, $object, array $args): array { return array(); }
};
$fixtureKinds = $invokeFixtureContext->kinds->all();
$fixtureKinds['invoke_fixture'] = $fixtureKind;
$fixtureProperty = new ReflectionProperty(ModxMCP\Kind\KindRegistry::class, 'kinds');
$fixtureProperty->setAccessible(true);
$fixtureProperty->setValue($invokeFixtureContext->kinds, $fixtureKinds);
check('invoke: object-less read receives null', $call($invokeFixtureContext, 'invoke', array('kind' => 'invoke_fixture', 'op' => 'read'))['object_is_null']);
foreach (array(array('id' => $groupId), array('name' => 'any')) as $badReference) {
    $e = throws('invoke: object-less reference forbidden', ApiException::class, function () use ($call, $invokeFixtureContext, $badReference) {
        $call($invokeFixtureContext, 'invoke', array('kind' => 'invoke_fixture', 'op' => 'read') + $badReference);
    });
    check('invoke: object-less validation code', $e && $e->errorCode === 'validation_failed');
}
$noOpState = ModxMCP\Journal\Snapshot::capture($accessSite, $fixtureKind, $groupId);
$invokeNoop = $call($invokeFixtureContext, 'invoke', array('kind' => 'invoke_fixture', 'op' => 'noop', 'id' => $groupId));
check('invoke: no-op returns current revision', $invokeNoop['changeset_id'] === null && $invokeNoop['changed'] === array()
    && $invokeNoop['revision'] === ModxMCP\Journal\Snapshot::revision($noOpState));

// TV input operations preserve the legacy recommendation data, including Cyrillic matching.
$tvRuntime = new class extends modX {
    public $inputEvent = array();
    public function invokeEvent($name, array $params = array()) {
        if ($name === 'OnTVInputRenderList') {
            if ($this->inputEvent instanceof Throwable) { throw $this->inputEvent; }
            return $this->inputEvent;
        }
        return parent::invokeEvent($name, $params);
    }
};
$tvRead = new Context($tvRuntime, array('scope' => 'read') + $dev->token, $dev->corePath, function () {});
$types = $call($tvRead, 'invoke', array('kind' => 'tv', 'op' => 'list_input_types'));
check('tv inputs: core and empty custom match legacy values', count($types['core']) === 20 && $types['custom'] === array()
    && $types['core']['image'] === array('label' => 'Image', 'use' => 'pick/upload an image', 'requires' => array('media_source (id)')));
$imageSuggestion = $call($tvRead, 'invoke', array('kind' => 'tv', 'op' => 'suggest_type', 'args' => array('description' => 'IMAGE PHOTO')));
check('tv inputs: ranked image suggestion exact values', $imageSuggestion['description'] === 'IMAGE PHOTO' && $imageSuggestion['recommended'] === 'image'
    && $imageSuggestion['candidates'] === array(array('type' => 'image', 'why' => 'a single image', 'score' => 2, 'matched' => array('image', 'photo'), 'order' => 1))
    && $imageSuggestion['create_skeleton'] === array('action' => 'create_element', 'type' => 'tv', 'data' => array('name' => '<tv_name>', 'caption' => '<Caption>',
        'field_type' => 'image', 'templates' => array('<template id>'), 'media_source' => '<media source id — see modx_list_media_sources>')) && $imageSuggestion['note'] === null);
$colorSuggestion = $call($tvRead, 'invoke', array('kind' => 'tv', 'op' => 'suggest_type', 'args' => array('description' => 'ЦВЕТ')));
check('tv inputs: Cyrillic and unavailable color fallback', $colorSuggestion['recommended'] === 'text' && $colorSuggestion['candidates'][0]['matched'] === array('цвет')
    && $colorSuggestion['note'] === 'No colorpicker input type is installed; suggesting text. Install a colorpicker add-on for a real picker.');
$unknownSuggestion = $call($tvRead, 'invoke', array('kind' => 'tv', 'op' => 'suggest_type', 'args' => array('description' => 'xyz')));
check('tv inputs: no-keyword fallback', $unknownSuggestion['candidates'] === array(array('type' => 'text', 'why' => 'no strong signal — short single-line text is the safe default', 'score' => 0, 'matched' => array())));
$tvRuntime->inputEvent = array("fancy==Выбор\n/path/to/render\nplain", array('other==Другой'));
$customTypes = $call($tvRead, 'invoke', array('kind' => 'tv', 'op' => 'list_input_types'));
check('tv inputs: custom parsing skips paths', $customTypes['custom'] === array('fancy' => 'Выбор', 'plain' => 'plain', 'other' => 'Другой'));
$tvRuntime->store('modNamespace', array('name' => 'migx'));
$tvRuntime->store('modNamespace', array('name' => 'colorpicker'));
$tvRuntime->inputEvent = new RuntimeException('Headless plugin failure');
$customTypes = $call($tvRead, 'invoke', array('kind' => 'tv', 'op' => 'list_input_types'));
check('tv inputs: plugin failure still allows installed fallbacks', $customTypes['custom'] === array('migx' => 'MIGX', 'migxdb' => 'MIGXdb', 'colorpicker' => 'ColorPicker'));
check('tv inputs: installed colorpicker recommended', $call($tvRead, 'invoke', array('kind' => 'tv', 'op' => 'suggest_type', 'args' => array('description' => 'ЦВЕТ')))['recommended'] === 'colorpicker');
foreach (array(array(), array('description' => 7), array('description' => ' '), array('description' => 'text', 'extra' => true)) as $badSuggestion) {
    $e = throws('tv inputs: argument validation', ApiException::class, function () use ($call, $tvRead, $badSuggestion) { $call($tvRead, 'invoke', array('kind' => 'tv', 'op' => 'suggest_type', 'args' => $badSuggestion)); });
    check('tv inputs: validation code', $e && $e->errorCode === 'validation_failed');
}
$e = throws('tv inputs: object reference forbidden', ApiException::class, function () use ($call, $tvRead) { $call($tvRead, 'invoke', array('kind' => 'tv', 'id' => 1, 'op' => 'list_input_types')); });
check('tv inputs: object-less validation', $e && $e->errorCode === 'validation_failed');
$tvHelp = $call($dev, 'help', array('topic' => 'tv_input_types'))['content'];
check('tv inputs: help is rewritten', strpos($tvHelp, 'modx_invoke') !== false);
foreach (array('modx_suggest_tv_type', 'modx_list_tv_input_types', 'modx_get_element', 'modx_update_element', 'modx_create_element', 'modx_get_resource_tvs') as $legacyName) {
    check('tv inputs: no legacy help tool ' . $legacyName, strpos($tvHelp, $legacyName) === false);
}

// Managed static files on fresh sites, with auto-static enabled by default.
$staticFixture = function ($suffix) use ($siteDir, $dev) {
    $dir = $siteDir . '/static-' . $suffix;
    mkdir($dir . '/core', 0700, true);
    $modx = fakeSite($dir);
    $context = new Context($modx, $dev->token, $dev->corePath, function () {});
    $context->sessionId = 'static-session-0001';
    return array($modx, $context, $dir);
};
list($staticSite, $staticDev, $staticDir) = $staticFixture('convert');
foreach (array('chunk' => array('chunks', 'tpl'), 'snippet' => array('snippets', 'php'), 'template' => array('templates', 'tpl'), 'plugin' => array('plugins', 'php')) as $kindName => $pathInfo) {
    $code = in_array($kindName, array('snippet', 'plugin'), true) ? "return 'Привет';" : '<p>Привет</p>';
    $dbElement = $call($staticDev, 'create', array('kind' => $kindName, 'fields' => array('name' => 'Test Мир ' . $kindName, 'content' => $code, 'static' => false)));
    $converted = $call($staticDev, 'invoke', array('kind' => $kindName, 'id' => $dbElement['id'], 'op' => 'make_static'));
    $stored = '[[++core_path]]elements/' . $pathInfo[0] . '/Test-' . $kindName . '-' . $dbElement['id'] . '.' . $pathInfo[1];
    $absolute = str_replace('[[++core_path]]', $staticSite->getOption('core_path'), $stored);
    $gotStatic = $call($staticDev, 'get', array('kind' => $kindName, 'id' => $dbElement['id']));
    check('make_static: conversion ' . $kindName, $converted['status'] === 'converted' && $converted['static_file'] === $stored
        && file_get_contents($absolute) === (in_array($kindName, array('snippet', 'plugin'), true) ? "<?php\n" : '') . $code
        && $gotStatic['fields']['static'] && $gotStatic['fields']['source'] === 0 && $gotStatic['fields']['content'] === $code
        && $converted['revision'] !== $dbElement['revision'] && count($staticDev->journal()->changes(array($converted['changeset_id']))) === 1);
    $already = $call($staticDev, 'invoke', array('kind' => $kindName, 'id' => $dbElement['id'], 'op' => 'make_static'));
    check('make_static: already static is no-op', $already['status'] === 'already_static' && $already['changeset_id'] === null && $already['revision'] === $converted['revision']);
    $call($staticDev, 'undo', array('changeset_id' => $converted['changeset_id'], 'confirm' => true));
    check('make_static: undo retains file and restores database content', is_file($absolute)
        && !$call($staticDev, 'get', array('kind' => $kindName, 'id' => $dbElement['id']))['fields']['static']
        && $call($staticDev, 'get', array('kind' => $kindName, 'id' => $dbElement['id']))['fields']['content'] === $code);
}
foreach (array('!!!', 'ЖЖЖ') as $name) {
    $slugElement = $call($staticDev, 'create', array('kind' => 'chunk', 'fields' => array('name' => $name, 'content' => 'slug', 'static' => false)));
    $baseFile = $staticSite->getOption('core_path') . 'elements/chunks/chunk-' . $slugElement['id'] . '.tpl';
    file_put_contents($baseFile, 'occupied');
    $slugged = $call($staticDev, 'invoke', array('kind' => 'chunk', 'id' => $slugElement['id'], 'op' => 'make_static'));
    check('make_static: fallback slug and collision suffix', $slugged['static_file'] === '[[++core_path]]elements/chunks/chunk-' . $slugElement['id'] . '-1.tpl'
        && file_get_contents($baseFile) === 'occupied');
}
$failedStatic = $call($staticDev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'fail static', 'content' => 'stable', 'static' => false)));
$staticSite->failProcessor = 'element/chunk/update';
throws('make_static: processor failure', RuntimeException::class, function () use ($call, $staticDev, $failedStatic) { $call($staticDev, 'invoke', array('kind' => 'chunk', 'id' => $failedStatic['id'], 'op' => 'make_static')); });
$staticSite->failProcessor = null;
check('make_static: failure removes created file and restores object', !file_exists($staticSite->getOption('core_path') . 'elements/chunks/fail-static-' . $failedStatic['id'] . '.tpl')
    && $call($staticDev, 'get', array('kind' => 'chunk', 'id' => $failedStatic['id']))['revision'] === $failedStatic['revision']);
list($autoSite, $autoDev, $autoDir) = $staticFixture('auto');
$auto = $call($autoDev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'Auto', 'content' => 'auto code')));
$autoGot = $call($autoDev, 'get', array('kind' => 'chunk', 'id' => $auto['id']));
$autoPath = str_replace('[[++core_path]]', $autoSite->getOption('core_path'), $autoGot['fields']['static_file']);
check('auto_static: default create produces a managed file', $autoGot['fields']['static'] && file_get_contents($autoPath) === 'auto code'
    && in_array('static', $auto['changed'], true) && in_array('static_file', $auto['changed'], true));
$autoCopy = $call($autoDev, 'duplicate', array('kind' => 'chunk', 'id' => $auto['id']));
check('auto_static: duplicate remains in database', !$call($autoDev, 'get', array('kind' => 'chunk', 'id' => $autoCopy['id']))['fields']['static']);
$call($autoDev, 'undo', array('changeset_id' => $auto['changeset_id'], 'confirm' => true));
check('auto_static: undo create removes file and empty shard but keeps root', $autoSite->row('modChunk', $auto['id']) === null && !file_exists($autoPath)
    && !is_dir(dirname($autoPath)) && is_dir($autoSite->getOption('core_path') . 'elements'));
$explicitDb = $call($autoDev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'Explicit DB', 'static' => false)));
check('auto_static: explicit false wins', !$call($autoDev, 'get', array('kind' => 'chunk', 'id' => $explicitDb['id']))['fields']['static']);
$autoSite->options['modxmcp.auto_static'] = false;
$optionDb = $call($autoDev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'Option DB')));
check('auto_static: disabled setting keeps database storage', !$call($autoDev, 'get', array('kind' => 'chunk', 'id' => $optionDb['id']))['fields']['static']);
$autoSite->options['modxmcp.auto_static'] = true;
foreach (array('tv', 'category') as $kindName) {
    $notStatic = $call($autoDev, 'create', array('kind' => $kindName, 'fields' => array('name' => 'Not static ' . $kindName)));
    check('auto_static: non-code kinds unaffected', !isset($call($autoDev, 'get', array('kind' => $kindName, 'id' => $notStatic['id']))['fields']['static']));
}
$deleteStatic = $call($autoDev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'Delete static', 'content' => 'kept code')));
$deletePath = $autoSite->getObject('modChunk', $deleteStatic['id'])->getSourceFile();
$staticDeleted = $call($autoDev, 'delete', array('kind' => 'chunk', 'id' => $deleteStatic['id']));
check('auto_static: delete cleans managed file', !file_exists($deletePath));
$call($autoDev, 'undo', array('changeset_id' => $staticDeleted['changeset_id'], 'confirm' => true));
check('auto_static: undo delete restores static element and file', file_get_contents($deletePath) === 'kept code'
    && $call($autoDev, 'get', array('kind' => 'chunk', 'id' => $deleteStatic['id']))['revision'] === $deleteStatic['revision']);
$externalPath = $autoDir . '/external.tpl';
file_put_contents($externalPath, 'external');
$externalElement = $call($autoDev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'External', 'static' => true, 'static_file' => $externalPath, 'content' => 'external')));
$externalDeleted = $call($autoDev, 'delete', array('kind' => 'chunk', 'id' => $externalElement['id']));
check('auto_static: delete leaves external file', file_get_contents($externalPath) === 'external');
$call($autoDev, 'undo', array('changeset_id' => $externalDeleted['changeset_id'], 'confirm' => true));
$call($autoDev, 'undo', array('changeset_id' => $externalElement['changeset_id'], 'confirm' => true));
check('auto_static: undo create leaves external file', file_get_contents($externalPath) === 'external');
$autoSite->failProcessor = 'element/chunk/update';
$autoCount = $autoSite->getCount('modChunk');
throws('auto_static: conversion failure aborts create', RuntimeException::class, function () use ($call, $autoDev) {
    $call($autoDev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'Failure automatic', 'content' => 'x')));
});
$autoSite->failProcessor = null;
check('auto_static: failed create leaves no object or file', $autoSite->getCount('modChunk') === $autoCount
    && glob($autoSite->getOption('core_path') . 'elements/chunks/Failure-automatic-*.tpl') === array());

// Page checks never use the network: the injected fetcher controls responses and log writes.
$pageDir = $siteDir . '/page-check';
mkdir($pageDir . '/core/cache/logs', 0700, true);
$pageSite = fakeSite($pageDir, array('site_url' => 'https://site.example/base/'));
$pageSite->store('modContext', array('key' => 'web', 'name' => 'Web'));
$pageSite->store('modContext', array('key' => 'other', 'name' => 'Other'));
$pageSite->store('modContextSetting', array('context_key' => 'other', 'key' => 'site_url', 'value' => 'https://Other.Example:8443/sub/'));
$pageSite->store('modContextSetting', array('context_key' => 'other', 'key' => 'http_host', 'value' => 'alias.example:8080'));
$pageSite->store('modResource', array('id' => 1, 'pagetitle' => 'Страница', 'context_key' => 'web', 'uri' => 'page.html'));
$pageRead = new Context($pageSite, array('scope' => 'read') + $dev->token, $dev->corePath, function () {});
$fetcher = new class implements ModxMCP\Verify\PageFetcher {
    public $response;
    public $onFetch;
    public $calls = array();
    public function fetch($url, $timeoutSeconds, $maxBytes): array {
        $this->calls[] = array($url, $timeoutSeconds, $maxBytes);
        if ($this->onFetch) { call_user_func($this->onFetch); }
        if ($this->response instanceof RuntimeException) { throw $this->response; }
        return $this->response;
    }
};
$pageRead->setPageFetcher($fetcher);
$fetcher->response = array('status' => 200, 'headers' => array('content-type' => 'text/html; charset=UTF-8'),
    'body' => "<title> Привет &amp; мир </title>\n[[\$missing]]\n<script>[[ignored]]</script>\n<style>[[ignored]]</style>\n[[!Сниппет]]", 'time_ms' => 17, 'truncated' => false);
$pageLog = $pageSite->getCachePath() . 'logs/error.log';
file_put_contents($pageLog, "[2026-01-01 10:00:00] (INFO @ old) old error\n");
$fetcher->onFetch = function () use ($pageLog) { file_put_contents($pageLog, "[2026-01-01 10:01:00] (ERROR in Page::render @ fake) Новая ошибка\n", FILE_APPEND); };
$checkedPage = $call($pageRead, 'check_page', array('id' => 1));
check('check_page: id URL, HTML and precise tags', $checkedPage['url'] === 'https://site.example/base/page.html' && $checkedPage['status'] === 200
    && $checkedPage['title'] === 'Привет & мир' && $checkedPage['time_ms'] === 17 && $checkedPage['bytes'] === strlen($fetcher->response['body'])
    && $checkedPage['unparsed_count'] === 2 && $checkedPage['unparsed_tags'] === array(array('tag' => '[[$missing]]', 'line' => 2), array('tag' => '[[!Сниппет]]', 'line' => 5)));
check('check_page: only new log entries', $checkedPage['log']['new_entries'] === 1 && $checkedPage['log']['entries'][0]['message'] === 'Новая ошибка');
$fetcher->onFetch = null;
$pathPage = $call($pageRead, 'check_page', array('url_path' => '/target?q=1', 'context' => 'other', 'max_bytes' => 1234));
check('check_page: context origin discards base path and passes limit', $pathPage['url'] === 'https://Other.Example:8443/target?q=1'
    && end($fetcher->calls) === array('https://Other.Example:8443/target?q=1', 15, 1234) && $pathPage['log']['new_entries'] === 0);
foreach (array('//evil.example/x', '/\\evil', 'https://evil.example', '/../secret', '/a/../b', "/bad\npath", '/%2e%2e/secret', '/%5cevil', '/%2fevil') as $badPath) {
    $e = throws('check_page: unsafe path', ApiException::class, function () use ($call, $pageRead, $badPath) { $call($pageRead, 'check_page', array('url_path' => $badPath)); });
    check('check_page: path validation', $e && $e->errorCode === 'validation_failed');
}
foreach (array('https://evil.example/x', 'ftp://site.example/x', 'https://user:pass@site.example/x', '') as $badUrl) {
    $pageSite->options['fake_urls'] = array(1 => $badUrl);
    $e = throws('check_page: final URL guard', ApiException::class, function () use ($call, $pageRead) { $call($pageRead, 'check_page', array('id' => 1)); });
    check('check_page: URL refused', $e && $e->errorCode === 'validation_failed');
}
$pageSite->options['fake_urls'] = array(1 => 'https://alias.example/ok');
check('check_page: context http_host is allowed', $call($pageRead, 'check_page', array('id' => 1))['url'] === 'https://alias.example/ok');
unset($pageSite->options['fake_urls']);
$fetcher->response['body'] = '<title>' . str_repeat('Я', 350) . '</title>' . str_repeat("\n[[tag]]", 25);
$longPage = $call($pageRead, 'check_page', array('id' => 1));
check('check_page: long title and tag list caps', mb_strlen($longPage['title'], 'UTF-8') === 300 && $longPage['unparsed_count'] === 25 && count($longPage['unparsed_tags']) === 20);
foreach (array(404, 500) as $status) {
    $fetcher->response['status'] = $status;
    check('check_page: HTTP error is a result', $call($pageRead, 'check_page', array('id' => 1))['status'] === $status);
}
$fetcher->response['status'] = 302;
foreach (array('/local' => true, 'next' => true, 'https://other.example/page' => true, '//evil.example/page' => false, 'https://evil.example/page' => false, 'javascript:alert(1)' => false) as $location => $same) {
    $fetcher->response['headers']['location'] = $location;
    $redirect = $call($pageRead, 'check_page', array('id' => 1));
    check('check_page: redirect reported without following', $redirect['location'] === $location && $redirect['redirect_same_site'] === $same);
}
$fetcher->response = array('status' => 200, 'headers' => array('content-type' => 'application/octet-stream'), 'body' => '<title>not HTML</title>[[tag]]', 'time_ms' => 1, 'truncated' => true);
$binaryPage = $call($pageRead, 'check_page', array('id' => 1));
check('check_page: binary content not scanned and truncation retained', !isset($binaryPage['title']) && $binaryPage['unparsed_count'] === 0 && $binaryPage['truncated']);
$fetcher->response = new RuntimeException('TLS transport failure');
$failedPage = $call($pageRead, 'check_page', array('id' => 1));
check('check_page: transport failure remains a result', $failedPage['status'] === 0 && $failedPage['error'] === 'TLS transport failure' && $failedPage['log']['new_entries'] === 0);
foreach (array(array(), array('id' => 1, 'url_path' => '/')) as $badSelector) {
    $e = throws('check_page: exactly one selector', ApiException::class, function () use ($call, $pageRead, $badSelector) { $call($pageRead, 'check_page', $badSelector); });
    check('check_page: selector error', $e && $e->errorCode === 'validation_failed');
}
$e = throws('check_page: unknown resource', ApiException::class, function () use ($call, $pageRead) { $call($pageRead, 'check_page', array('id' => 999)); });
check('check_page: not_found', $e && $e->errorCode === 'not_found');

// Graph expectations are hand-derived from legacy insertion order and reference scanners.
$graphDir = $siteDir . '/graph';
mkdir($graphDir . '/core', 0700, true);
$graphSite = fakeSite($graphDir);
$graphRead = new Context($graphSite, array('scope' => 'read') + $dev->token, $dev->corePath, function () {});
$graphFile = $graphDir . '/static.tpl';
file_put_contents($graphFile, '[[$header]]');
$graphSite->store('modNamespace', array('name' => 'app'));
$graphSite->store('modCategory', array('id' => 1, 'category' => 'App', 'parent' => 0));
$graphSite->store('modCategory', array('id' => 2, 'category' => 'Child category', 'parent' => 1));
$graphSite->store('modTemplate', array('id' => 1, 'templatename' => 'Главный', 'content' => '[[$header]][[!$row]][[!Worker? &tpl=`row`]][[Worker]][[~10]][[$missing]][[*helperTV]]'));
$graphSite->store('modChunk', array('id' => 1, 'name' => 'header', 'snippet' => '[[+placeholder]]', 'category' => 2));
$graphSite->store('modChunk', array('id' => 2, 'name' => 'row', 'snippet' => 'row'));
$graphSite->store('modChunk', array('id' => 3, 'name' => 'unused', 'snippet' => 'unused'));
$graphSite->store('modChunk', array('id' => 4, 'name' => 'Статический', 'snippet' => '[[$staleMissing]]', 'static' => 1, 'static_file' => $graphFile));
$graphSite->store('modSnippet', array('id' => 1, 'name' => 'Worker', 'snippet' => '$modx->getChunk("row"); $modx->runSnippet("Helper"); $modx->getChunk("Статический");', 'properties' => array('tpl' => 'header')));
$graphSite->store('modSnippet', array('id' => 2, 'name' => 'Helper', 'snippet' => 'return "ok";'));
$graphSite->store('modTemplateVar', array('id' => 1, 'name' => 'gallery', 'type' => 'migx', 'default_text' => '@CHUNK header', 'input_properties' => array('formtabs' => '[{"inputTV":"helperTV"}]', 'columns' => '[{"renderchunktpl":"row"}]')));
$graphSite->store('modTemplateVar', array('id' => 2, 'name' => 'helperTV', 'type' => 'text', 'default_text' => ''));
$graphSite->store('modPlugin', array('id' => 1, 'name' => 'Listener', 'plugincode' => '$modx->runSnippet("Worker");', 'disabled' => 0));
$graphSite->store('modTemplateVarTemplate', array('templateid' => 1, 'tmplvarid' => 1));
$graphSite->store('modPluginEvent', array('pluginid' => 1, 'event' => 'OnLoadWebDocument'));
$graphSite->store('modPropertySet', array('id' => 1, 'name' => 'Overrides', 'properties' => array(array('name' => 'tpl', 'value' => 'row'))));
$graphSite->store('modElementPropertySet', array('element_class' => 'modSnippet', 'element' => 1, 'property_set' => 1));
$graphSite->store('modSystemSetting', array('key' => 'site.row_tpl', 'value' => 'row'));
$graphSite->store('modResource', array('id' => 10, 'pagetitle' => 'Страница 1', 'template' => 1, 'deleted' => 0, 'published' => 1, 'content' => ''));
$graphSite->store('modResource', array('id' => 11, 'pagetitle' => 'Страница 2', 'template' => 1, 'deleted' => 0, 'published' => 0, 'content' => ''));
$graph = $call($graphRead, 'dependency_graph');
$expectedNodes = array(
    array('i' => 0, 'type' => 'template', 'id' => 1, 'name' => 'Главный', 'category' => null, 'static' => false, 'resources' => 2, 'in' => 0, 'out' => 6),
    array('i' => 1, 'type' => 'chunk', 'id' => 1, 'name' => 'header', 'category' => 'Child category', 'static' => false, 'vendor' => true, 'in' => 4, 'out' => 0),
    array('i' => 2, 'type' => 'chunk', 'id' => 2, 'name' => 'row', 'category' => null, 'static' => false, 'used_by' => 'system setting site.row_tpl', 'in' => 5, 'out' => 0),
    array('i' => 3, 'type' => 'chunk', 'id' => 3, 'name' => 'unused', 'category' => null, 'static' => false, 'in' => 0, 'out' => 0),
    array('i' => 4, 'type' => 'chunk', 'id' => 4, 'name' => 'Статический', 'category' => null, 'static' => true, 'in' => 1, 'out' => 1),
    array('i' => 5, 'type' => 'snippet', 'id' => 1, 'name' => 'Worker', 'category' => null, 'static' => false, 'in' => 2, 'out' => 5),
    array('i' => 6, 'type' => 'snippet', 'id' => 2, 'name' => 'Helper', 'category' => null, 'static' => false, 'in' => 1, 'out' => 0),
    array('i' => 7, 'type' => 'tv', 'id' => 1, 'name' => 'gallery', 'category' => null, 'static' => false, 'tv_type' => 'migx', 'in' => 1, 'out' => 3),
    array('i' => 8, 'type' => 'tv', 'id' => 2, 'name' => 'helperTV', 'category' => null, 'static' => false, 'tv_type' => 'text', 'in' => 2, 'out' => 0),
    array('i' => 9, 'type' => 'plugin', 'id' => 1, 'name' => 'Listener', 'category' => null, 'static' => false, 'disabled' => false, 'events' => array('OnLoadWebDocument'), 'in' => 0, 'out' => 1),
);
$expectedEdges = array(array(0,1,'tag'), array(0,2,'tag'), array(0,5,'tag'), array(0,2,'property'), array(0,8,'tag'),
    array(4,1,'tag'), array(5,2,'php'), array(5,4,'php'), array(5,6,'php'), array(5,1,'property'),
    array(7,8,'migx'), array(7,2,'migx'), array(7,1,'binding'), array(9,5,'php'), array(0,7,'attached'), array(5,2,'property'));
check('graph: exact legacy nodes including degrees/vendor/settings', $graph['nodes'] === $expectedNodes);
check('graph: exact deduplicated legacy edges in insertion order', $graph['edges'] === $expectedEdges);
check('graph: exact missing and unused markers', $graph['missing'] === array(array('from' => 'template:Главный', 'from_id' => 1, 'kind' => 'chunk', 'name' => 'missing'))
    && $graph['orphans'] === array(array('type' => 'chunk', 'id' => 3, 'name' => 'unused', 'reason' => 'never referenced')));
check('graph: exact stats and read-only static file', $graph['stats'] === array('nodes' => 10, 'edges' => 16,
    'by_type' => array('template' => 1, 'chunk' => 4, 'snippet' => 2, 'tv' => 2, 'plugin' => 1), 'missing' => 1, 'orphans' => 1, 'vendor' => 1, 'orphans_verified' => true)
    && file_get_contents($graphFile) === '[[$header]]' && $graphSite->row('modChunk', 4)['snippet'] === '[[$staleMissing]]');
$summaryGraph = $call($graphRead, 'dependency_graph', array('format' => 'summary'));
check('graph: summary keeps global diagnostics without graph', $summaryGraph['stats'] === $graph['stats'] && $summaryGraph['missing'] === $graph['missing'] && !isset($summaryGraph['nodes']));
$resourceGraph = $call($graphRead, 'dependency_graph', array('include_resources' => true));
check('graph: only linked resource becomes a node', $resourceGraph['stats']['nodes'] === 11 && $resourceGraph['stats']['edges'] === 18
    && $resourceGraph['nodes'][10] === array('i' => 10, 'type' => 'resource', 'id' => 10, 'name' => 'Страница 1', 'category' => null, 'static' => false, 'published' => true, 'in' => 1, 'out' => 1)
    && array_slice($resourceGraph['edges'], -2) === array(array(0,10,'link'), array(10,0,'template')));
$typeGraph = $call($graphRead, 'dependency_graph', array('types' => array('chunk')));
check('graph: type filtering remaps endpoints and keeps global stats', array_column($typeGraph['nodes'], 'name') === array('header','row','unused','Статический')
    && $typeGraph['edges'] === array(array(3,0,'tag')) && $typeGraph['stats'] === $graph['stats']);
$outGraph = $call($graphRead, 'dependency_graph', array('focus' => 'snippet:Worker', 'direction' => 'out', 'depth' => 1));
check('graph: outgoing focus', array_column($outGraph['nodes'], 'name') === array('header','row','Статический','Worker','Helper')
    && $outGraph['focus'] === array('node' => 'snippet:Worker', 'depth' => 1, 'direction' => 'out') && !isset($outGraph['orphans']));
$inGraph = $call($graphRead, 'dependency_graph', array('focus' => 'Worker', 'direction' => 'in', 'depth' => 1));
check('graph: incoming focus by bare name', array_column($inGraph['nodes'], 'name') === array('Главный','Worker','Listener'));
$bothGraph = $call($graphRead, 'dependency_graph', array('focus' => 'Worker', 'direction' => 'both', 'depth' => 5));
check('graph: bidirectional depth expands reachability', count($bothGraph['nodes']) === 9 && !in_array('unused', array_column($bothGraph['nodes'], 'name'), true));
$clampedGraph = $call($graphRead, 'dependency_graph', array('focus' => 'Worker', 'direction' => 'invalid', 'depth' => 99));
check('graph: legacy direction fallback and depth clamp', $clampedGraph === $bothGraph);
$notFoundGraph = $call($graphRead, 'dependency_graph', array('focus' => 'absent'));
check('graph: missing focus stays a result', $notFoundGraph['focus'] === null && $notFoundGraph['nodes'] === array() && isset($notFoundGraph['error']));
$graphSite->store('modResource', array('content' => '[[$unused]]') + $graphSite->row('modResource', 11));
check('graph: verify_orphans option controls resource cross-check', $call($graphRead, 'dependency_graph')['orphans'] === array()
    && count($call($graphRead, 'dependency_graph', array('verify_orphans' => false))['orphans']) === 1);
$graphSite->store('modResource', array('content' => '') + $graphSite->row('modResource', 11));
mkdir($graphDir . '/shop/model', 0700, true);
$graphSite->options['minishop2.core_path'] = $graphDir . '/shop/';
$graphSite->store('msOrderStatus', array('id' => 1, 'name' => 'Новый', 'body_user' => 3, 'body_manager' => 0));
$shopGraph = $call($graphRead, 'dependency_graph');
check('graph: miniShop2 helper protects status chunks', $shopGraph['nodes'][3]['used_by'] === 'miniShop2 order status "Новый" (body_user)' && $shopGraph['orphans'] === array());
unset($graphSite->options['minishop2.core_path']);
$graphSite->store('modChunk', array('id' => 5, 'name' => 'extra1', 'snippet' => ''));
$graphSite->store('modChunk', array('id' => 6, 'name' => 'extra2', 'snippet' => ''));
$cappedGraph = $call($graphRead, 'dependency_graph', array('max_nodes' => 1));
check('graph: node cap uses legacy minimum 10', $cappedGraph['truncated'] && $cappedGraph['returned']['nodes'] === 10 && $cappedGraph['stats']['nodes'] === 12);
$graphSite->removeCollection('modChunk', array('id' => 5));
$graphSite->removeCollection('modChunk', array('id' => 6));
// Preserve the old empty-file fallback and ASCII-only tag-name heuristic, not silently fix them.
file_put_contents($graphFile, '');
check('graph: legacy empty static file falls back to database', count($call($graphRead, 'dependency_graph')['missing']) === 2);
file_put_contents($graphFile, '[[$header]]');
$graphSite->store('modChunk', array('id' => 5, 'name' => 'Юникод', 'snippet' => '[[$несуществующий]]'));
check('graph: legacy Cyrillic tag-name limitation preserved', count($call($graphRead, 'dependency_graph')['missing']) === 1);
$graphSite->removeCollection('modChunk', array('id' => 5));
// Instantiate the real manager processor against a minimal processor base and fake MODX.
if (!class_exists('modProcessor')) {
    class modProcessor {
        public $modx; private $properties;
        public function __construct($modx, array $properties = array()) { $this->modx = $modx; $this->properties = $properties; }
        public function getProperty($key) { return isset($this->properties[$key]) ? $this->properties[$key] : null; }
        public function success($message, $object) { return array('success' => true, 'object' => $object); }
        public function failure($message) { return array('success' => false, 'message' => $message); }
    }
}
require_once $dev->corePath . 'processors/mgr/graph.class.php';
foreach (array('true' => true, 'false' => false) as $flag => $boolean) {
    $managerGraph = new ModxmcpGraphProcessor($graphSite, array('include_resources' => $flag));
    $managerResult = $managerGraph->process();
    check('graph: manager and API use identical builder results', $managerGraph->checkPermissions() && $managerResult['success']
        && $managerResult['object'] === $call($graphRead, 'dependency_graph', array('include_resources' => $boolean, 'max_nodes' => 2500)));
}
$graphSite->options['fake_permissions'] = array('settings' => false);
check('graph: manager settings permission unchanged', !(new ModxmcpGraphProcessor($graphSite))->checkPermissions());
$graphHelp = $call($dev, 'help', array('topic' => 'graph'))['content'];
foreach (array('modx_project_overview', 'modx_search_code', 'modx_delete_element', 'modx_replace_across') as $oldGraphTool) {
    check('graph: rewritten help tool names', strpos($graphHelp, $oldGraphTool) === false);
}

// --- Files ---
use ModxMCP\Files\FileRoot;

check('paths: cleaned', FileRoot::normalize('a//b/./c.txt') === 'a/b/c.txt' && FileRoot::normalize('') === '' && FileRoot::normalize('a\\b') === 'a/b');
foreach (array('../x', 'a/../../x', '/etc/passwd', 'C:\\x', 'php://input', "a\0b") as $bad) {
    throws('paths: rejected ' . json_encode($bad), ApiException::class, function () use ($bad) { FileRoot::normalize($bad); });
}
check('paths: executable names', FileRoot::isExecutable('a/b.php') && FileRoot::isExecutable('.htaccess') && FileRoot::isExecutable('x.PHTML') && !FileRoot::isExecutable('a/b.css') && !FileRoot::isExecutable('php.txt'));

$media = $siteDir . '/media';
mkdir($media . '/img', 0755, true);
mkdir($siteDir . '/core/config', 0755, true);
mkdir($siteDir . '/core/components/demo/model', 0755, true);
file_put_contents($media . '/style.css', "body { color: red; }\n");
file_put_contents($media . '/img/logo.png', "\x89PNG\x00\x01binary");
file_put_contents($siteDir . '/core/config/config.inc.php', '<?php $database_password = "secret";');
file_put_contents($siteDir . '/core/components/demo/model/demo.class.php', '<?php class Demo {}');
file_put_contents($siteDir . '/outside.txt', 'outside the source');
symlink($siteDir . '/outside.txt', $media . '/escape.txt');
$site->options['base_path'] = $siteDir . '/';
$site->options['assets_path'] = $siteDir . '/assets/';
$site->store('sources.modMediaSource', array('id' => 1, 'name' => 'Media', 'description' => '', 'class_key' => 'sources.modFileMediaSource', 'basePath' => $media . '/'));
$site->store('sources.modMediaSource', array('id' => 2, 'name' => 'Whole site', 'description' => '', 'class_key' => 'sources.modFileMediaSource', 'basePath' => $siteDir . '/'));
$site->store('sources.modMediaSource', array('id' => 3, 'name' => 'Cloud', 'description' => '', 'class_key' => 'sources.modS3MediaSource', 'basePath' => ''));
$site->store('modNamespace', array('name' => 'demo'));

$sources = $call($actor('read'), 'list', array('kind' => 'media_source'));
check('media sources: listed with their directory', $sources['total'] === 3 && $sources['items'][1]['base_path'] === $media . '/' && $sources['items'][0]['base_path'] === '');
$dirList = $call($actor('read'), 'list_files', array('source' => 1));
check('files: directory listing, directories first', array_column($dirList['entries'], 'name') === array('img', 'escape.txt', 'style.css') && $dirList['entries'][0]['type'] === 'dir' && $dirList['entries'][1]['type'] === 'link' && $dirList['writable'] === true);
$css = $call($actor('read'), 'read_file', array('source' => 1, 'path' => 'style.css'));
check('files: read text with revision', $css['content'] === "body { color: red; }\n" && $css['binary'] === false && $css['truncated'] === false && strlen($css['revision']) === 64);
$part = $call($actor('read'), 'read_file', array('source' => 1, 'path' => 'style.css', 'max_bytes' => 4));
check('files: partial read', $part['content'] === 'body' && $part['truncated'] === true && $part['next_offset'] === 4);
$png = $call($actor('read'), 'read_file', array('source' => 1, 'path' => 'img/logo.png'));
check('files: binary reports size only', $png['binary'] === true && !isset($png['content']) && $png['size'] === 12
    && base64_decode($call($actor('read'), 'read_file', array('source' => 1, 'path' => 'img/logo.png', 'as_base64' => true))['content']) === "\x89PNG\x00\x01binary");
throws('files: symlink out of the root', ApiException::class, function () use ($call, $dev) { $call($dev, 'read_file', array('source' => 1, 'path' => 'escape.txt')); });
throws('files: traversal', ApiException::class, function () use ($call, $dev) { $call($dev, 'read_file', array('source' => 1, 'path' => '../outside.txt')); });
$e = throws('files: source spanning the site is off by default', ApiException::class, function () use ($call, $dev) { $call($dev, 'list_files', array('source' => 2)); });
check('files: spanning source is unavailable', $e && $e->errorCode === 'unavailable');
throws('files: non-local source', ApiException::class, function () use ($call, $dev) { $call($dev, 'list_files', array('source' => 3)); });
$site->options['modxmcp.allow_root_filesystem_read'] = true;
check('files: spanning source works when allowed', count($call($dev, 'list_files', array('source' => 2))['entries']) > 0);
throws('files: configuration stays closed', ApiException::class, function () use ($call, $dev) { $call($dev, 'read_file', array('source' => 2, 'path' => 'core/config/config.inc.php')); });
throws('files: modxMCP data stays closed', ApiException::class, function () use ($call, $dev) { $call($dev, 'list_files', array('source' => 2, 'path' => 'core/modxmcp-data')); });
throws('files: configuration cannot be written', ApiException::class, function () use ($call, $dev) { $call($dev, 'write_file', array('source' => 2, 'path' => 'core/config/x.txt', 'content' => 'x')); });
$site->options['modxmcp.allow_root_filesystem_read'] = false;
$component = $call($actor('read'), 'read_file', array('component' => 'demo', 'path' => 'model/demo.class.php'));
check('files: component code is readable', $component['content'] === '<?php class Demo {}' && $call($actor('read'), 'list_files', array('component' => 'demo'))['writable'] === false);
throws('files: unknown component', ApiException::class, function () use ($call, $dev) { $call($dev, 'list_files', array('component' => 'nope')); });
throws('files: source and component together', ApiException::class, function () use ($call, $dev) { $call($dev, 'list_files', array('source' => 1, 'component' => 'demo')); });

$written = $call($editor, 'write_file', array('source' => 1, 'path' => 'css/new/site.css', 'content' => "a { }\n", 'must_be_new' => true));
check('write_file: creates the file and its directories', file_get_contents($media . '/css/new/site.css') === "a { }\n" && $written['files'][0]['op'] === 'create' && $written['changeset_id'] !== null);
throws('write_file: must_be_new on an existing file', ApiException::class, function () use ($call, $editor) { $call($editor, 'write_file', array('source' => 1, 'path' => 'style.css', 'content' => 'x', 'must_be_new' => true)); });
$replaced = $call($editor, 'write_file', array('source' => 1, 'path' => 'style.css', 'content' => "body { color: blue; }\n", 'expected_revision' => $css['revision']));
check('write_file: replace with the read revision', $replaced['files'][0]['op'] === 'update' && file_get_contents($media . '/style.css') === "body { color: blue; }\n");
$e = throws('write_file: stale revision', ApiException::class, function () use ($call, $editor, $css) { $call($editor, 'write_file', array('source' => 1, 'path' => 'style.css', 'content' => 'x', 'expected_revision' => $css['revision'])); });
check('write_file: stale revision is a conflict', $e && $e->errorCode === 'revision_conflict' && file_get_contents($media . '/style.css') === "body { color: blue; }\n");
check('write_file: dry run', $call($editor, 'write_file', array('source' => 1, 'path' => 'style.css', 'content' => 'never', 'dry_run' => true))['files'][0]['op'] === 'update' && file_get_contents($media . '/style.css') === "body { color: blue; }\n");
$fileDiff = $call($editor, 'history_diff', array('changeset_id' => $replaced['changeset_id']));
check('write_file: journal diff', $fileDiff['objects'][0]['kind'] === 'file' && $fileDiff['objects'][0]['id'] === 's1:style.css' && $fileDiff['objects'][0]['changed']['content']['diff'] === "@@ -1,1 +1,1 @@\n-body { color: red; }\n+body { color: blue; }");
$bin = $call($editor, 'write_file', array('source' => 1, 'path' => 'img/dot.gif', 'content' => base64_encode("GIF\x00\xff"), 'encoding' => 'base64'));
check('write_file: binary through base64', file_get_contents($media . '/img/dot.gif') === "GIF\x00\xff" && $bin['size'] === 5);
$e = throws('write_file: php needs the develop scope', ApiException::class, function () use ($call, $editor) { $call($editor, 'write_file', array('source' => 1, 'path' => 'x.php', 'content' => '<?php echo 1;')); });
check('write_file: php refusal is forbidden_scope', $e && $e->errorCode === 'forbidden_scope' && !file_exists($media . '/x.php'));
throws('write_file: .htaccess needs the develop scope', ApiException::class, function () use ($call, $editor) { $call($editor, 'write_file', array('source' => 1, 'path' => 'img/.htaccess', 'content' => 'x')); });
$e = throws('write_file: broken php is refused', ApiException::class, function () use ($call, $dev) { $call($dev, 'write_file', array('source' => 1, 'path' => 'x.php', 'content' => "<p>ok</p>\n<?php echo (;")); });
check('write_file: lint error with the file line', $e && $e->errorCode === 'lint_failed' && $e->details['line'] === 2 && !file_exists($media . '/x.php'));
check('write_file: valid php with html around it', $call($dev, 'write_file', array('source' => 1, 'path' => 'x.php', 'content' => "<p>ok</p>\n<?php echo 1;"))['changeset_id'] !== null);
throws('write_file: component code is read-only', ApiException::class, function () use ($call, $dev) { $call($dev, 'write_file', array('source' => 1, 'path' => '', 'content' => 'x')); });

$call($editor, 'undo', array('changeset_id' => $replaced['changeset_id'], 'confirm' => true));
check('files: undo of a replace', file_get_contents($media . '/style.css') === "body { color: red; }\n");
$call($editor, 'undo', array('changeset_id' => $written['changeset_id'], 'confirm' => true));
check('files: undo of a create removes the file and its empty directories', !file_exists($media . '/css'));

file_put_contents($media . '/style.css', "edited over ftp\n");
$afterFtp = $call($editor, 'write_file', array('source' => 1, 'path' => 'style.css', 'content' => "final\n"));
$fileHistory = $call($editor, 'history', array('kind' => 'file', 'source' => 'external'));
check('files: an edit over FTP is journaled as external', count($fileHistory['changesets']) === 1 && $fileHistory['changesets'][0]['objects'][0]['id'] === 's1:style.css');

mkdir($media . '/docs/sub', 0755, true);
file_put_contents($media . '/docs/a.txt', 'A');
file_put_contents($media . '/docs/sub/b.txt', 'B');
$move = $call($editor, 'move_path', array('source' => 1, 'path' => 'docs', 'to' => 'archive/docs'));
check('move_path: directory moved as one changeset', file_get_contents($media . '/archive/docs/sub/b.txt') === 'B' && !file_exists($media . '/docs') && count($move['files']) === 4);
throws('move_path: target exists', ApiException::class, function () use ($call, $editor) { $call($editor, 'move_path', array('source' => 1, 'path' => 'style.css', 'to' => 'x.php')); });
throws('move_path: into itself', ApiException::class, function () use ($call, $editor) { $call($editor, 'move_path', array('source' => 1, 'path' => 'archive', 'to' => 'archive/inner')); });
throws('move_path: renaming to php needs develop', ApiException::class, function () use ($call, $editor) { $call($editor, 'move_path', array('source' => 1, 'path' => 'style.css', 'to' => 'style.php')); });
$call($editor, 'undo', array('changeset_id' => $move['changeset_id'], 'confirm' => true));
check('move_path: undo moves everything back', file_get_contents($media . '/docs/sub/b.txt') === 'B' && !file_exists($media . '/archive'));

$dryDel = $call($editor, 'delete_path', array('source' => 1, 'path' => 'docs', 'dry_run' => true));
check('delete_path: dry run counts', $dryDel['count'] === 2 && $dryDel['bytes'] === 2 && $dryDel['directory'] === true && file_exists($media . '/docs/a.txt'));
$deletedDir = $call($editor, 'delete_path', array('source' => 1, 'path' => 'docs'));
check('delete_path: directory removed', !file_exists($media . '/docs') && count($deletedDir['files']) === 2 && $deletedDir['reversible'] === true);
$call($editor, 'undo', array('changeset_id' => $deletedDir['changeset_id'], 'confirm' => true));
check('delete_path: undo restores the tree', file_get_contents($media . '/docs/a.txt') === 'A' && file_get_contents($media . '/docs/sub/b.txt') === 'B');
throws('delete_path: php file needs develop', ApiException::class, function () use ($call, $editor) { $call($editor, 'delete_path', array('source' => 1, 'path' => 'x.php')); });
symlink($siteDir . '/outside.txt', $media . '/docs/link.txt');
throws('delete_path: a tree with a symlink is refused', ApiException::class, function () use ($call, $editor) { $call($editor, 'delete_path', array('source' => 1, 'path' => 'docs')); });
check('delete_path: nothing was removed', file_exists($media . '/docs/a.txt') && file_get_contents($siteDir . '/outside.txt') === 'outside the source');
throws('delete_path: the root cannot be deleted', ApiException::class, function () use ($call, $editor) { $call($editor, 'delete_path', array('source' => 1, 'path' => '.')); });
throws('files: a read token cannot write', ApiException::class, function () use ($call, $actor) { $call($actor('read'), 'write_file', array('source' => 1, 'path' => 'a.txt', 'content' => 'x')); });

// Concurrent MCP writers
$lock = new ModxMCP\Write\Lock($dev->dataDir() . '/locks');
$lock->acquire('chunk:2');
$e = throws('lock: object held by another request', ApiException::class, function () use ($call, $dev) { $call($dev, 'update', array('kind' => 'chunk', 'id' => 2, 'fields' => array('description' => 'x'))); });
check('lock: refusal is locked', $e && $e->errorCode === 'locked');
$lock->releaseAll();

// --- Journal retention on isolated fake sites ---
$retentionSite = function () use ($dev) {
    $dir = sys_get_temp_dir() . '/modxmcp-retention-' . bin2hex(random_bytes(6));
    mkdir($dir . '/core', 0700, true);
    $modx = fakeSite($dir);
    $context = new Context($modx, array('id' => 1, 'label' => 'retention', 'prefix' => 'mcp_test', 'scope' => 'develop',
        'plan_only' => false, 'denied_groups' => array(), 'expires_at' => null), $dev->corePath,
        function () { throw new RuntimeException('no store in retention tests'); });
    $context->sessionId = 'retention-session-0001';
    return array($modx, $context);
};
$ageBlobs = function ($context, $mtime) {
    foreach ($context->blobs()->each() as $blob) {
        touch($context->dataDir() . '/history/' . substr($blob[0], 0, 2) . '/' . $blob[0] . '.php', $mtime);
    }
};
$retention = new ModxMCP\Journal\Retention();
list($retSite, $retContext) = $retentionSite();
$retNow = time();
$oldTime = $retNow - 40 * 86400;
$first = $call($retContext, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'retained', 'content' => 'one')));
$oldCheckpoint = $retContext->journal()->addCheckpoint('old anchor', 1, $retContext->sessionId);
$freshCheckpoint = $retContext->journal()->addCheckpoint('fresh anchor', 1, $retContext->sessionId);
$second = $call($retContext, 'update', array('kind' => 'chunk', 'id' => $first['id'], 'fields' => array('content' => 'two')));
$third = $call($retContext, 'update', array('kind' => 'chunk', 'id' => $first['id'], 'fields' => array('content' => 'three')));
$keptCheckpoint = $retContext->journal()->addCheckpoint('retained anchor', 1, $retContext->sessionId);
$retSite->pdo->exec('UPDATE modx_modxmcp_changesets SET created_at = ' . $oldTime . ' WHERE id IN (1,2)');
$retSite->pdo->exec('UPDATE modx_modxmcp_checkpoints SET created_at = ' . $oldTime . ' WHERE id IN (1,3)');
$extraHash = $retContext->blobs()->put('{"delete_extra":[]}');
$retSite->pdo->exec("UPDATE modx_modxmcp_changes SET extra_blob = '" . $extraHash . "' WHERE changeset_id = 3");
$ageBlobs($retContext, $retNow - 7200);
$expectedBytes = strlen(BlobStore::FILE_GUARD) + strlen($retContext->blobs()->get($first['revision']));
$freshHash = $retContext->blobs()->put('uncommitted snapshot');
$retSite->options['modxmcp.history_max_bytes'] = 0;
$pruned = $retention->run($retContext, $retNow);
check('retention: age removes exactly two changesets and their private blob', $pruned === array('changesets' => 2, 'blobs' => 1, 'bytes' => $expectedBytes)
    && $retContext->journal()->changes(array(1, 2)) === array() && count($retContext->journal()->changes(array(3))) === 1);
check('retention: shared snapshot and extra blob survive', !$retContext->blobs()->has($first['revision'])
    && $retContext->blobs()->has($second['revision']) && $retContext->blobs()->has($third['revision']) && $retContext->blobs()->has($extraHash));
check('retention: reference set includes before, after and extra hashes', $retContext->journal()->referencedBlobs()
    == array($second['revision'] => true, $third['revision'] => true, $extraHash => true));
check('retention: only old checkpoints below retained history are removed', $retContext->journal()->checkpoint($oldCheckpoint['id']) === null
    && $retContext->journal()->checkpoint($freshCheckpoint['id']) !== null && $retContext->journal()->checkpoint($keptCheckpoint['id']) !== null);
$retHistory = $call($retContext, 'history');
$retDiff = $call($retContext, 'history_diff', array('changeset_id' => $third['changeset_id']));
check('retention: remaining history and diff are readable', array_column($retHistory['changesets'], 'changeset_id') === array($third['changeset_id'])
    && $retDiff['objects'][0]['changed']['content']['diff'] === "@@ -1,1 +1,1 @@\n-two\n+three");
check('retention: fresh unreferenced blob survives', $retContext->blobs()->has($freshHash));
$ageBlobs($retContext, $retNow - 3600);
check('retention: blobs exactly one hour old are protected', $retention->run($retContext, $retNow)['blobs'] === 0);
$ageBlobs($retContext, $retNow - 3601);
$collected = $retention->run($retContext, $retNow);
check('retention: old unreferenced blob is collected without deleting more rows', $collected === array('changesets' => 0, 'blobs' => 1,
    'bytes' => strlen(BlobStore::FILE_GUARD . 'uncommitted snapshot')) && !$retContext->blobs()->has($freshHash));

// Size cleanup stops after the first batch once enough disk space has been reclaimed.
list($sizeSite, $sizeContext) = $retentionSite();
$sizeSite->options['modxmcp.history_retention_days'] = 0;
$sizeSite->options['modxmcp.history_max_bytes'] = 0;
$sizeBytes = array();
for ($i = 1; $i <= 103; $i++) {
    $hash = $sizeContext->blobs()->put('size snapshot ' . $i);
    $sizeBytes[$i] = strlen(BlobStore::FILE_GUARD . 'size snapshot ' . $i);
    $sizeContext->journal()->commit(array('tool' => 'test'), array(array('kind' => 'chunk', 'object_key' => $i,
        'label' => 'size', 'op' => 'create', 'before_revision' => null, 'after_revision' => $hash, 'reversible' => true)));
}
$ageBlobs($sizeContext, $retNow - 7200);
$sizeSite->options['modxmcp.history_max_bytes'] = array_sum(array_slice($sizeBytes, 100));
$sizePruned = $retention->run($sizeContext, $retNow);
check('retention: size removes the 100 oldest changesets first', $sizePruned === array('changesets' => 100, 'blobs' => 100,
    'bytes' => array_sum(array_slice($sizeBytes, 0, 100))) && $sizeContext->journal()->oldestChangesetIds(PHP_INT_MAX, 500) === array(101, 102, 103));
$sizeSite->options['modxmcp.history_max_bytes'] = 1;
$sizePruned = $retention->run($sizeContext, $retNow);
check('retention: newest changeset survives even if it alone exceeds the size limit', $sizePruned['changesets'] === 2 && $sizePruned['blobs'] === 2
    && $sizeContext->journal()->oldestChangesetIds(PHP_INT_MAX, 500) === array(103)
    && count(iterator_to_array($sizeContext->blobs()->each(), false)) === 1);
$sizeSite->pdo->exec('UPDATE modx_modxmcp_changesets SET created_at = ' . $oldTime);
$unused = $sizeContext->blobs()->put('disabled cleanup orphan');
$ageBlobs($sizeContext, $retNow - 7200);
$sizeSite->options['modxmcp.history_max_bytes'] = 0;
check('retention: zero/zero disables row deletion and blob collection', $retention->run($sizeContext, $retNow)
    === array('changesets' => 0, 'blobs' => 0, 'bytes' => 0) && $sizeContext->journal()->changeset(103) !== null && $sizeContext->blobs()->has($unused));

// Age batches, strict boundary, and an empty journal's obsolete checkpoints.
for ($i = 0; $i < 501; $i++) { $sizeContext->journal()->commit(array('tool' => 'age batch'), array()); }
$sizeContext->journal()->addCheckpoint('last anchor', 1, $sizeContext->sessionId);
$sizeSite->pdo->exec('UPDATE modx_modxmcp_changesets SET created_at = ' . $oldTime);
$sizeSite->pdo->exec('UPDATE modx_modxmcp_checkpoints SET created_at = ' . $oldTime);
$sizeSite->options['modxmcp.history_retention_days'] = 30;
$sizeSite->pdo->exec('UPDATE modx_modxmcp_changesets SET created_at = ' . ($retNow - 30 * 86400) . ' WHERE id = 604');
check('retention: age batches and cut-off are strict', $retention->run($sizeContext, $retNow)['changesets'] === 501
    && $sizeContext->journal()->oldestChangesetIds(PHP_INT_MAX, 500) === array(604));
check('retention: age may expire the last changeset and its old checkpoints', $retention->run($sizeContext, $retNow + 1)['changesets'] === 1
    && $sizeContext->journal()->lastChangesetId() === 0 && (int) $sizeSite->pdo->query('SELECT COUNT(*) FROM modx_modxmcp_checkpoints')->fetchColumn() === 0);

// The hourly hook is exercised by real Writer commits, including failure isolation.
list($hookSite, $hookContext) = $retentionSite();
$hookFirst = $call($hookContext, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'hook first')));
$stamp = $hookContext->dataDir() . '/prune.stamp';
clearstatcache(true, $stamp);
check('retention hook: missing stamp is created after the first write', is_file($stamp) && filemtime($stamp) >= $retNow);
$hookSite->pdo->exec('UPDATE modx_modxmcp_changesets SET created_at = ' . $oldTime);
$hookSecond = $call($hookContext, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'hook second')));
check('retention hook: fresh stamp skips pruning', $hookContext->journal()->changeset($hookFirst['changeset_id']) !== null);
touch($stamp, $retNow - 7200);
$call($hookContext, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'hook third')));
clearstatcache(true, $stamp);
check('retention hook: stale stamp runs pruning', filemtime($stamp) >= $retNow && $hookContext->journal()->changeset($hookFirst['changeset_id']) === null);
$hookSite->pdo->exec('UPDATE modx_modxmcp_changesets SET created_at = ' . $oldTime . ' WHERE id = ' . $hookSecond['changeset_id']);
unlink($stamp);
$pruneLock = fopen($hookContext->dataDir() . '/prune.lock', 'c');
flock($pruneLock, LOCK_EX | LOCK_NB);
$call($hookContext, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'hook locked')));
check('retention hook: held lock skips pruning without failing the write', !file_exists($stamp) && $hookContext->journal()->changeset($hookSecond['changeset_id']) !== null);
flock($pruneLock, LOCK_UN);
fclose($pruneLock);
ModxMCP\Write\Writer::run($hookContext, 'noop', function ($tx) { return array('result' => array()); });
check('retention hook: no-op write does not prune', !file_exists($stamp));
$call($hookContext, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'hook missing stamp')));
check('retention hook: missing stamp runs pruning', is_file($stamp) && $hookContext->journal()->changeset($hookSecond['changeset_id']) === null);

$hookSite->pdo->exec('UPDATE modx_modxmcp_changesets SET created_at = ' . $oldTime);
$hookSite->pdo->exec("CREATE TRIGGER fail_retention BEFORE DELETE ON modx_modxmcp_changes BEGIN SELECT RAISE(FAIL, 'retention test failure'); END");
touch($stamp, $retNow - 7200);
$oldLog = ini_get('error_log');
$retLog = $hookContext->dataDir() . '/retention-test.log';
ini_set('error_log', $retLog);
try {
    $survived = $call($hookContext, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'write survives pruning failure')));
} finally {
    ini_set('error_log', $oldLog);
    $hookSite->pdo->exec('DROP TRIGGER fail_retention');
}
clearstatcache(true, $stamp);
check('retention hook: failure is logged and never rolls back the successful write', $survived['changeset_id'] !== null
    && $hookSite->row('modChunk', $survived['id'])['name'] === 'write survives pruning failure'
    && $hookContext->journal()->changeset($survived['changeset_id']) !== null && filemtime($stamp) >= $retNow
    && strpos(file_get_contents($retLog), 'Journal retention failed:') !== false && strpos(file_get_contents($retLog), 'retention test failure') !== false);

// Review additions: grace-period blobs do not cost history, and reused blobs are fresh.
list($graceSite, $graceContext) = $retentionSite();
foreach (array('one', 'two', 'three') as $index => $text) {
    $call($graceContext, $index ? 'update' : 'create', $index
        ? array('kind' => 'chunk', 'id' => 1, 'fields' => array('content' => $text))
        : array('kind' => 'chunk', 'fields' => array('name' => 'grace', 'content' => $text)));
}
$referencedBytes = 0;
foreach ($graceContext->blobs()->each() as $blob) { $referencedBytes += $blob[1]; }
$graceContext->blobs()->put(str_repeat('young unreferenced snapshot ', 2000));
$graceSite->options['modxmcp.history_retention_days'] = 0;
$graceSite->options['modxmcp.history_max_bytes'] = $referencedBytes + 100;
$graceRun = $retention->run($graceContext);
check('retention: a blob in its grace period does not push history out', $graceRun['changesets'] === 0 && $graceRun['blobs'] === 0
    && count($graceContext->journal()->changesets(array(), 50)) === 3);
$reusedHash = $graceContext->blobs()->put('reused content');
$reusedPath = $graceContext->dataDir() . '/history/' . substr($reusedHash, 0, 2) . '/' . $reusedHash . '.php';
touch($reusedPath, time() - 7200);
$graceContext->blobs()->put('reused content');
clearstatcache(true, $reusedPath);
check('blobs: storing existing content again refreshes its age', filemtime($reusedPath) > time() - 60);

// Review additions: case-insensitive matching must work for non-ASCII text.
use ModxMCP\Code\CodeSearch;
check('search: Cyrillic is matched regardless of case', CodeSearch::position('<h1>Привет, мир</h1>', 'привет', false) === 4
    && CodeSearch::position('<h1>Привет</h1>', 'привет', true) === false && CodeSearch::position('abc DEF', 'def', false) === 4);
list($cyrSite, $cyrContext) = $retentionSite();
$call($cyrContext, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'cyr', 'content' => "ЗАГОЛОВОК и заголовок\nЦена: \$1")));
$cyrDry = $call($cyrContext, 'replace', array('find' => 'заголовок', 'replacement' => 'Title \\1 $0', 'case_sensitive' => false, 'dry_run' => true));
check('replace: Cyrillic case-insensitive preview', $cyrDry['total_occurrences'] === 2);
$call($cyrContext, 'replace', array('find' => 'заголовок', 'replacement' => 'Title \\1 $0', 'case_sensitive' => false, 'expected_revisions' => (array) $cyrDry['revisions']));
check('replace: the replacement text is literal', $cyrSite->row('modChunk', 1)['snippet'] === "Title \\1 \$0 и Title \\1 \$0\nЦена: \$1");
check('search: Cyrillic through the tool', $call($cyrContext, 'search', array('query' => 'ЦЕНА'))['objects'] === 1);

$inLog = sys_get_temp_dir() . '/modxmcp-log-' . bin2hex(random_bytes(6)) . '.log';
file_put_contents($inLog, "[2026-11-11 20:06:18] (ERROR in modRestCurlClient::__construct @ /var/www/site/core/model/modx/rest/modrestcurlclient.class.php : 26) cURL is missing\n"
    . "[2026-11-11 20:06:19] (ERROR @ /var/www/site/index.php : 5) plain\n");
$inEntries = ModxMCP\Verify\LogReader::tail($inLog)['entries'];
check('log: the "LEVEL in Class::method @ file : line" header is parsed', $inEntries[0] === array('time' => '2026-11-11 20:06:18', 'level' => 'ERROR',
    'in' => 'modRestCurlClient::__construct', 'location' => '/var/www/site/core/model/modx/rest/modrestcurlclient.class.php : 26', 'message' => 'cURL is missing')
    && $inEntries[1] === array('time' => '2026-11-11 20:06:19', 'level' => 'ERROR', 'location' => '/var/www/site/index.php : 5', 'message' => 'plain'));
unlink($inLog);

// Review additions: a static snippet's file carries the PHP tag, its code does not.
list($tagSite, $tagDev, $tagDir) = $staticFixture('phptag');
$tagged = $call($tagDev, 'create', array('kind' => 'snippet', 'fields' => array('name' => 'tagged', 'content' => "return 1;")));
$tagRow = $tagSite->row('modSnippet', $tagged['id']);
$tagFile = str_replace('[[++core_path]]', $tagSite->getOption('core_path'), $tagRow['static_file']);
$tagGot = $call($tagDev, 'get', array('kind' => 'snippet', 'id' => $tagged['id']));
check('static script: file has the PHP tag, the API shows code without it', file_get_contents($tagFile) === "<?php\nreturn 1;" && $tagGot['fields']['content'] === 'return 1;' && $tagGot['revision'] === $tagged['revision']);
$tagEdit = $call($tagDev, 'update', array('kind' => 'snippet', 'id' => $tagged['id'], 'fields' => array('content' => "return 2;")));
check('static script: an update keeps the tag in the file', file_get_contents($tagFile) === "<?php\nreturn 2;" && $call($tagDev, 'view_code', array('kind' => 'snippet', 'id' => $tagged['id']))['numbered'] === "1\treturn 2;");
$tagRevision = $call($tagDev, 'get', array('kind' => 'snippet', 'id' => $tagged['id']))['revision'];
$tagDelete = $call($tagDev, 'delete', array('kind' => 'snippet', 'id' => $tagged['id']));
$call($tagDev, 'undo', array('changeset_id' => $tagDelete['changeset_id'], 'confirm' => true));
check('static script: delete and undo give the identical state and file', $call($tagDev, 'get', array('kind' => 'snippet', 'id' => $tagged['id']))['revision'] === $tagRevision
    && file_get_contents($tagFile) === "<?php\nreturn 2;");
file_put_contents($tagFile, "<?php\r\nreturn 3;");
check('static script: a file edited by hand with CRLF after the tag', $call($tagDev, 'get', array('kind' => 'snippet', 'id' => $tagged['id']))['fields']['content'] === 'return 3;');
file_put_contents($tagFile, "return 4;");
check('static script: a file without a tag is read as it is', $call($tagDev, 'get', array('kind' => 'snippet', 'id' => $tagged['id']))['fields']['content'] === 'return 4;');
$tagChunk = $call($tagDev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'tagchunk', 'content' => '<?php not code')));
$tagChunkFile = str_replace('[[++core_path]]', $tagSite->getOption('core_path'), $tagSite->row('modChunk', $tagChunk['id'])['static_file']);
check('static chunk: files are stored exactly as the content', file_get_contents($tagChunkFile) === '<?php not code');

// --- Objects identified by a string key (the real system_setting kind; task 15 used a test-only kind for this) ---
$keyDir = $siteDir . '/keys';
mkdir($keyDir . '/core', 0700, true);
$keySite = fakeSite($keyDir);
$keyActor = function ($session = 'session-keyaaaaaaaa') use ($keySite, $dev) {
    $c = new Context($keySite, $dev->token, $dev->corePath, function () {});
    $c->sessionId = $session;
    return $c;
};
$keyDev = $keyActor();
$settingState = function ($key) use ($keySite) { $row = $keySite->row('modSystemSetting', $key); return $row ? $row['value'] : null; };

$setCreated = $call($keyDev, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'site_tagline', 'value' => 'Hello', 'area' => 'site')));
check('keys: create carries key, not id', $setCreated['key'] === 'site_tagline' && !array_key_exists('id', $setCreated)
    && preg_match('/^[a-f0-9]{64}$/', $setCreated['revision']) && $setCreated['changeset_id'] !== null && $setCreated['changed'] === array('key', 'value', 'xtype', 'namespace', 'area'));
foreach (array('alpha' => 'one', 'zeta' => 'last', 'Привет' => 'cyrillic') as $settingKey => $settingValue) {
    $call($keyDev, 'create', array('kind' => 'system_setting', 'fields' => array('key' => (string) $settingKey, 'value' => $settingValue)));
}
// MODX refuses to create a setting whose key starts with a digit, so a numeric-looking key is planted as if made in the manager.
$keySite->store('modSystemSetting', array('key' => '404', 'value' => 'numeric key', 'xtype' => 'textfield', 'namespace' => 'core', 'area' => ''));
$keyChunk = $call($keyDev, 'create', array('kind' => 'chunk', 'fields' => array('name' => 'plain chunk', 'content' => 'x')));
check('keys: an integer kind still carries id, not key', $keyChunk['id'] === 1 && !array_key_exists('key', $keyChunk));

$setGot = $call($keyDev, 'get', array('kind' => 'system_setting', 'key' => 'site_tagline'));
check('keys: get by key', $setGot['key'] === 'site_tagline' && !array_key_exists('id', $setGot) && $setGot['fields']['value'] === 'Hello'
    && $setGot['revision'] === $setCreated['revision']);
check('keys: name is an alias of key', $call($keyDev, 'get', array('kind' => 'system_setting', 'name' => 'site_tagline'))['revision'] === $setCreated['revision']);
$e = throws('keys: unknown key', ApiException::class, function () use ($call, $keyDev) { $call($keyDev, 'get', array('kind' => 'system_setting', 'key' => 'missing')); });
check('keys: unknown key is not_found', $e && $e->errorCode === 'not_found' && strpos($e->getMessage(), "key 'missing'") !== false);
$e = throws('keys: id for a string-key kind', ApiException::class, function () use ($call, $keyDev) { $call($keyDev, 'get', array('kind' => 'system_setting', 'id' => 1)); });
check('keys: id for a string-key kind names the key argument', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'addressed by key') !== false);
$e = throws('keys: key for an integer kind', ApiException::class, function () use ($call, $keyDev) { $call($keyDev, 'get', array('kind' => 'chunk', 'key' => '1')); });
check('keys: key for an integer kind names the id argument', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'addressed by id') !== false);
foreach (array('update' => array('fields' => array('value' => 'x')), 'delete' => array(), 'duplicate' => array(), 'view_code' => array(), 'find_usages' => array()) as $actionName => $extraArgs) {
    $e = throws("keys: {$actionName} refuses id for a string-key kind", ApiException::class, function () use ($call, $keyDev, $actionName, $extraArgs) {
        $call($keyDev, $actionName, array('kind' => 'system_setting', 'id' => 1) + $extraArgs);
    });
    check("keys: {$actionName} refusal is validation_failed", $e && $e->errorCode === 'validation_failed');
}
foreach (array('get', 'update', 'delete', 'duplicate', 'invoke', 'view_code', 'edit_code', 'find_usages') as $actionName) {
    $keyProperty = $keyDev->actions->get($actionName)->inputSchema()['properties']['key'];
    check("keys: {$actionName} declares the key argument", $keyProperty['type'] === 'string' && $keyProperty['maxLength'] === 255
        && strpos($keyProperty['description'], 'kinds without a numeric id') !== false);
}

$list = $call($keyDev, 'list', array('kind' => 'system_setting'));
check('keys: list is sorted by key', array_column($list['items'], 'key') === array('404', 'alpha', 'site_tagline', 'zeta', 'Привет') && $list['total'] === 5 && $list['next_start'] === null);
$page = $call($keyDev, 'list', array('kind' => 'system_setting', 'limit' => 2, 'start' => 2));
check('keys: list pages', array_column($page['items'], 'key') === array('site_tagline', 'zeta') && $page['total'] === 5 && $page['next_start'] === 4);
check('keys: list query and where', array_column($call($keyDev, 'list', array('kind' => 'system_setting', 'query' => 'a', 'where' => array('area' => 'site')))['items'], 'key') === array('site_tagline'));

$numeric = $call($keyDev, 'get', array('kind' => 'system_setting', 'key' => '404'));
check('keys: a numeric-looking key stays a string', $numeric['key'] === '404' && $numeric['fields']['key'] === '404' && $numeric['fields']['value'] === 'numeric key');

// update, dry runs, revision checks
$dryUpdate = $call($keyDev, 'update', array('kind' => 'system_setting', 'key' => 'site_tagline', 'fields' => array('value' => 'Changed'), 'dry_run' => true));
check('keys: update dry run', $dryUpdate['dry_run'] === true && $dryUpdate['key'] === 'site_tagline' && $dryUpdate['would_change'] === array('value')
    && $settingState('site_tagline') === 'Hello');
$updated = $call($keyDev, 'update', array('kind' => 'system_setting', 'key' => 'site_tagline', 'fields' => array('value' => 'Changed'), 'expected_revision' => $setCreated['revision']));
check('keys: update with expected_revision', $updated['key'] === 'site_tagline' && !array_key_exists('id', $updated) && $updated['changed'] === array('value')
    && $settingState('site_tagline') === 'Changed' && $updated['revision'] !== $setCreated['revision']
    && $keySite->row('modSystemSetting', 'site_tagline')['area'] === 'site');
$e = throws('keys: stale expected_revision', ApiException::class, function () use ($call, $keyDev, $setCreated) {
    $call($keyDev, 'update', array('kind' => 'system_setting', 'key' => 'site_tagline', 'fields' => array('value' => 'Lost'), 'expected_revision' => $setCreated['revision']));
});
check('keys: stale revision is a conflict that names the key', $e && $e->errorCode === 'revision_conflict' && strpos($e->getMessage(), 'system_setting site_tagline') === 0
    && $settingState('site_tagline') === 'Changed');
$e = throws('keys: duplicate key on create', ApiException::class, function () use ($call, $keyDev) { $call($keyDev, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'alpha'))); });
check('keys: duplicate key is refused by the processor', $e && $e->errorCode === 'validation_failed');

// duplicate (the key is the name)
$copy = $call($keyDev, 'duplicate', array('kind' => 'system_setting', 'key' => 'alpha', 'new_name' => 'alpha_copy'));
check('keys: duplicate', $copy['key'] === 'alpha_copy' && $copy['source_key'] === 'alpha' && !array_key_exists('source_id', $copy) && $settingState('alpha_copy') === 'one');

// delete
$dryDelete = $call($keyDev, 'delete', array('kind' => 'system_setting', 'key' => 'alpha_copy', 'dry_run' => true));
check('keys: delete dry run', $dryDelete['dry_run'] === true && $dryDelete['key'] === 'alpha_copy' && $dryDelete['would_delete']['name'] === 'alpha_copy' && $settingState('alpha_copy') === 'one');
$deleted = $call($keyDev, 'delete', array('kind' => 'system_setting', 'key' => 'alpha_copy'));
check('keys: delete', $deleted['key'] === 'alpha_copy' && $deleted['deleted'] === true && $keySite->row('modSystemSetting', 'alpha_copy') === null);

// journal: history, diff, undo
$history = $call($keyDev, 'history', array('kind' => 'system_setting'));
$entry = $history['changesets'][0]['objects'][0];
check('keys: history entry shows key', $entry === array('kind' => 'system_setting', 'key' => 'alpha_copy', 'name' => 'alpha_copy', 'op' => 'delete'));
$chunkEntry = $call($keyDev, 'history', array('kind' => 'chunk'))['changesets'][0]['objects'][0];
check('keys: an integer kind still shows id in history', $chunkEntry['id'] === 1 && !array_key_exists('key', $chunkEntry));
$updateDiff = $call($keyDev, 'history_diff', array('changeset_id' => $updated['changeset_id']));
check('keys: history_diff entry shows key', $updateDiff['objects'][0]['key'] === 'site_tagline' && !array_key_exists('id', $updateDiff['objects'][0])
    && $updateDiff['objects'][0]['changed'] === array('value' => array('old' => 'Hello', 'new' => 'Changed')));

$undoDeletePlan = $call($keyDev, 'undo', array('changeset_id' => $deleted['changeset_id']));
check('keys: undo plan names the key', $undoDeletePlan['performed'] === false && $undoDeletePlan['objects'][0]['key'] === 'alpha_copy'
    && !array_key_exists('id', $undoDeletePlan['objects'][0]) && $undoDeletePlan['objects'][0]['action'] === 'recreate' && $undoDeletePlan['conflicts'] === 0);
$undoDelete = $call($keyDev, 'undo', array('changeset_id' => $deleted['changeset_id'], 'confirm' => true));
check('keys: undo of a delete brings the setting back with the same key and value', $undoDelete['performed'] === true
    && $settingState('alpha_copy') === 'one' && $keySite->row('modSystemSetting', 'alpha_copy')['key'] === 'alpha_copy'
    && $call($keyDev, 'get', array('kind' => 'system_setting', 'key' => 'alpha_copy'))['revision'] === $copy['revision']);
$undoCreate = $call($keyDev, 'undo', array('changeset_id' => $copy['changeset_id'], 'confirm' => true));
check('keys: undo of a create removes the setting', $undoCreate['objects'][0]['action'] === 'remove' && $keySite->row('modSystemSetting', 'alpha_copy') === null);

// undo of the update is a conflict while a later change exists, and works without one
$later = $call($keyDev, 'update', array('kind' => 'system_setting', 'key' => 'site_tagline', 'fields' => array('value' => 'Later')));
$conflictPlan = $call($keyDev, 'undo', array('changeset_id' => $updated['changeset_id']));
check('keys: undo conflict is detected', $conflictPlan['conflicts'] === 1 && $conflictPlan['objects'][0]['key'] === 'site_tagline'
    && strpos($conflictPlan['objects'][0]['conflict'], "changed later by changeset {$later['changeset_id']}") === 0);
$e = throws('keys: undo over a conflict is refused', ApiException::class, function () use ($call, $keyDev, $updated) { $call($keyDev, 'undo', array('changeset_id' => $updated['changeset_id'], 'confirm' => true)); });
check('keys: refusal is undo_conflict and nothing changed', $e && $e->errorCode === 'undo_conflict' && $settingState('site_tagline') === 'Later');
$call($keyDev, 'undo', array('changeset_id' => $later['changeset_id'], 'confirm' => true));
check('keys: undo of an update restores the earlier value', $settingState('site_tagline') === 'Changed');
$call($keyDev, 'undo', array('changeset_id' => $updated['changeset_id'], 'confirm' => true));
check('keys: and the one before it', $settingState('site_tagline') === 'Hello');

// numeric-looking key through undo
$numericDelete = $call($keyDev, 'delete', array('kind' => 'system_setting', 'key' => '404'));
check('keys: numeric-looking key in a delete result', $numericDelete['key'] === '404' && is_string($numericDelete['key']));
$numericHistory = null;
foreach ($call($keyDev, 'history', array('kind' => 'system_setting'))['changesets'] as $changeset) {
    foreach ($changeset['objects'] as $object) { if ($object['op'] === 'delete' && $object['name'] === '404') { $numericHistory = $object; } }
}
check('keys: a numeric-looking key is a string in history', $numericHistory !== null && $numericHistory['key'] === '404' && !array_key_exists('id', $numericHistory));
$numericUndo = $call($keyDev, 'undo', array('changeset_id' => $numericDelete['changeset_id'], 'confirm' => true));
check('keys: numeric-looking key through undo', $numericUndo['objects'][0]['key'] === '404' && $settingState('404') === 'numeric key'
    && $call($keyDev, 'get', array('kind' => 'system_setting', 'key' => '404'))['key'] === '404');

// a change made behind the journal's back is recorded as external
$keySite->store('modSystemSetting', array('value' => 'edited in the manager') + $keySite->row('modSystemSetting', 'zeta'));
$call($keyDev, 'update', array('kind' => 'system_setting', 'key' => 'zeta', 'fields' => array('area' => 'late')));
$external = $call($keyDev, 'history', array('kind' => 'system_setting', 'source' => 'external'));
check('keys: an outside change is journaled as external', count($external['changesets']) === 1 && $external['changesets'][0]['objects'][0]['key'] === 'zeta'
    && $external['changesets'][0]['objects'][0]['op'] === 'update');

// describe
$describeSetting = $call($keyDev, 'describe', array('kind' => 'system_setting'));
$describeChunk = $call($keyDev, 'describe', array('kind' => 'chunk'));
check('keys: describe shows the key type and field', $describeSetting['key_type'] === 'string' && $describeSetting['key_field'] === 'key'
    && $describeChunk['key_type'] === 'integer' && $describeChunk['key_field'] === 'id');

// rollback after a failure past the save
$stableSetting = $call($keyDev, 'get', array('kind' => 'system_setting', 'key' => 'alpha'));
$historySize = count($call($keyDev, 'history', array('limit' => 100))['changesets']);
$keySite->failProcessor = 'system/settings/update';
throws('keys: failure after save', RuntimeException::class, function () use ($call, $keyDev) { $call($keyDev, 'update', array('kind' => 'system_setting', 'key' => 'alpha', 'fields' => array('value' => 'half done'))); });
$keySite->failProcessor = null;
check('keys: rollback restores the setting', $call($keyDev, 'get', array('kind' => 'system_setting', 'key' => 'alpha'))['revision'] === $stableSetting['revision'] && $settingState('alpha') === 'one');
check('keys: nothing is journaled by a failed write', count($call($keyDev, 'history', array('limit' => 100))['changesets']) === $historySize);

// --- Settings kinds: system_setting, context, context_setting, namespace (task 16) ---
$setDir = $siteDir . '/settings';
mkdir($setDir . '/core', 0700, true);
$setSite = fakeSite($setDir);
$setActor = function ($scope, array $token = array()) use ($setSite, $dev) {
    $c = new Context($setSite, $token + array('scope' => $scope) + $dev->token, $dev->corePath, function () {});
    $c->sessionId = 'session-settings01';
    return $c;
};
$sRead = $setActor('read');
$sContent = $setActor('content');
$sDev = $setActor('develop');
$sAdmin = $setActor('admin');
foreach (array('web' => 'Web', 'mgr' => 'Manager') as $contextKey => $contextName) {
    $setSite->store('modContext', array('key' => $contextKey, 'name' => $contextName, 'description' => '', 'rank' => 0));
}
$setSite->store('modNamespace', array('name' => 'modxmcp', 'path' => '', 'assets_path' => ''));
$settingRow = function ($key) use ($setSite) { return $setSite->row('modSystemSetting', $key); };
$contextSettingRow = function ($context, $key) use ($setSite) { return $setSite->row('modContextSetting', array($context, $key)); };
$refreshes = function () use ($setSite) { return $setSite->getCacheManager()->refreshes; };

// describe
foreach (array('system_setting' => array('develop', 'settings'), 'context' => array('admin', 'contexts'), 'context_setting' => array('develop', 'contexts'), 'namespace' => array('admin', 'namespaces')) as $kindName => $info) {
    $described = $call($sRead, 'describe', array('kind' => $kindName));
    $described['fields'] = array_column($described['fields'], null, 'name');
    check("settings: describe {$kindName}", $described['key_type'] === 'string' && $described['key_field'] === 'key' && $described['write_scope'] === $info[0]
        && $described['read_scope'] === 'read' && $described['group'] === $info[1] && $described['name_field'] === 'key'
        && $described['fields']['key']['required'] === true && $described['fields']['key']['immutable'] === true);
}
$setSite->options['modxmcp.disabled_groups'] = 'settings, contexts, namespaces';
check('settings: the settings group cannot be switched off', $sAdmin->capabilities->groupBlockReason('settings', $sAdmin->token) === null
    && $sAdmin->capabilities->groupBlockReason('contexts', $sAdmin->token) === 'disabled_group'
    && $sAdmin->capabilities->groupBlockReason('namespaces', $sAdmin->token) === 'disabled_group');
foreach (array('context', 'context_setting', 'namespace') as $kindName) {
    foreach (array('list', 'describe') as $action) {
        $e = throws("settings: disabled group blocks {$kindName} {$action}", ApiException::class, function () use ($call, $sAdmin, $kindName, $action) { $call($sAdmin, $action, array('kind' => $kindName)); });
        check("settings: {$kindName} is unavailable when its group is off", $e && $e->errorCode === 'unavailable');
    }
}
check('settings: system_setting stays available when groups are off', $call($sAdmin, 'list', array('kind' => 'system_setting'))['total'] === 0);
$setSite->options['modxmcp.disabled_groups'] = '';

// ---- system_setting: create, get, list, update, duplicate, delete ----
$motto = $call($sDev, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'site_motto', 'value' => 'Привет, мир', 'area' => 'site')));
check('settings: system_setting create', $motto['key'] === 'site_motto' && !array_key_exists('id', $motto) && $motto['changeset_id'] !== null && $motto['reversible'] === true
    && strpos(json_encode($motto, JSON_UNESCAPED_UNICODE), 'Привет') === false);
$mottoGot = $call($sRead, 'get', array('kind' => 'system_setting', 'key' => 'site_motto'));
check('settings: system_setting get', $mottoGot['key'] === 'site_motto' && $mottoGot['fields']['value'] === 'Привет, мир' && $mottoGot['fields']['namespace'] === 'core'
    && $mottoGot['fields']['xtype'] === 'textfield' && $mottoGot['fields']['area'] === 'site' && !isset($mottoGot['secret']) && $mottoGot['revision'] === $motto['revision']);
check('settings: the in-memory configuration is current after a create', $setSite->getOption('site_motto') === 'Привет, мир');
$before = $refreshes();
$mottoUpdated = $call($sDev, 'update', array('kind' => 'system_setting', 'key' => 'site_motto', 'fields' => array('value' => 'Здравствуй'), 'expected_revision' => $motto['revision']));
check('settings: system_setting update', $mottoUpdated['changed'] === array('value') && $settingRow('site_motto')['value'] === 'Здравствуй' && $settingRow('site_motto')['area'] === 'site'
    && $setSite->getOption('site_motto') === 'Здравствуй' && $refreshes() > $before);
$sameKey = $call($sDev, 'update', array('kind' => 'system_setting', 'key' => 'site_motto', 'fields' => array('key' => 'site_motto', 'area' => 'footer')));
check('settings: giving the same key is not a change', $sameKey['changed'] === array('area') && $settingRow('site_motto')['area'] === 'footer');
foreach (array('dry run' => true, 'real' => false) as $label => $dry) {
    $e = throws("settings: system_setting key cannot change ({$label})", ApiException::class, function () use ($call, $sDev, $dry) {
        $call($sDev, 'update', array('kind' => 'system_setting', 'key' => 'site_motto', 'fields' => array('key' => 'site_slogan')) + ($dry ? array('dry_run' => true) : array()));
    });
    check("settings: key immutability is validation_failed ({$label})", $e && $e->errorCode === 'validation_failed' && $settingRow('site_slogan') === null && $settingRow('site_motto') !== null);
}
$e = throws('settings: only the case of a key cannot change either', ApiException::class, function () use ($call, $sDev) { $call($sDev, 'update', array('kind' => 'system_setting', 'key' => 'site_motto', 'fields' => array('key' => 'Site_Motto'))); });
check('settings: case change is refused', $e && $e->errorCode === 'validation_failed');
$ключ = $call($sDev, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'ключ_сайта', 'value' => 'needle', 'namespace' => 'core')));
$call($sDev, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'alpha_option', 'value' => 'haystack', 'area' => 'misc')));
$list = $call($sRead, 'list', array('kind' => 'system_setting'));
check('settings: system_setting list is sorted by key', array_column($list['items'], 'key') === array('alpha_option', 'site_motto', 'ключ_сайта') && $list['total'] === 3
    && array_keys($list['items'][0]) === array('key', 'value', 'xtype', 'namespace', 'area'));
check('settings: list where', array_column($call($sRead, 'list', array('kind' => 'system_setting', 'where' => array('area' => 'misc')))['items'], 'key') === array('alpha_option'));
check('settings: list query finds a Cyrillic key', array_column($call($sRead, 'list', array('kind' => 'system_setting', 'query' => 'люч'))['items'], 'key') === array('ключ_сайта'));
check('settings: list query searches the key, never the value', $call($sRead, 'list', array('kind' => 'system_setting', 'query' => 'needle'))['total'] === 0
    && $call($sRead, 'list', array('kind' => 'system_setting', 'query' => 'haystack'))['total'] === 0);
$e = throws('settings: list cannot filter by value', ApiException::class, function () use ($call, $sRead) { $call($sRead, 'list', array('kind' => 'system_setting', 'where' => array('value' => 'needle'))); });
check('settings: where on value is refused', $e && $e->errorCode === 'validation_failed');
check('settings: name is an alias of key', $call($sRead, 'get', array('kind' => 'system_setting', 'name' => 'ключ_сайта'))['fields']['value'] === 'needle');
$copy = $call($sDev, 'duplicate', array('kind' => 'system_setting', 'key' => 'alpha_option', 'new_name' => 'alpha_option_copy'));
check('settings: system_setting duplicate', $copy['key'] === 'alpha_option_copy' && $copy['source_key'] === 'alpha_option' && $settingRow('alpha_option_copy')['value'] === 'haystack'
    && $settingRow('alpha_option_copy')['area'] === 'misc');
$dryDelete = $call($sDev, 'delete', array('kind' => 'system_setting', 'key' => 'alpha_option_copy', 'dry_run' => true));
check('settings: system_setting delete dry run', $dryDelete['dry_run'] === true && $dryDelete['key'] === 'alpha_option_copy' && $settingRow('alpha_option_copy') !== null);
$deleted = $call($sDev, 'delete', array('kind' => 'system_setting', 'key' => 'alpha_option_copy'));
check('settings: system_setting delete', $deleted['deleted'] === true && $settingRow('alpha_option_copy') === null);
$e = throws('settings: a key starting with a digit is refused by MODX', ApiException::class, function () use ($call, $sDev) { $call($sDev, 'create', array('kind' => 'system_setting', 'fields' => array('key' => '1st'))); });
check('settings: processor refusal passes through', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'number') !== false);
$e = throws('settings: unknown namespace is refused by MODX', ApiException::class, function () use ($call, $sDev) { $call($sDev, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'orphan', 'namespace' => 'nowhere'))); });
check('settings: namespace must exist', $e && $e->errorCode === 'validation_failed');

// history, diff, undo of each operation; the configuration follows
$history = $call($sDev, 'history', array('kind' => 'system_setting'));
check('settings: history entries carry key', $history['changesets'][0]['objects'][0] === array('kind' => 'system_setting', 'key' => 'alpha_option_copy', 'name' => 'alpha_option_copy', 'op' => 'delete'));
$diff = $call($sDev, 'history_diff', array('changeset_id' => $mottoUpdated['changeset_id']));
check('settings: history_diff shows values of an ordinary setting', $diff['objects'][0]['key'] === 'site_motto'
    && $diff['objects'][0]['changed'] === array('value' => array('old' => 'Привет, мир', 'new' => 'Здравствуй')));
$call($sDev, 'undo', array('changeset_id' => $deleted['changeset_id'], 'confirm' => true));
check('settings: undo of a delete', $settingRow('alpha_option_copy')['value'] === 'haystack' && $setSite->getOption('alpha_option_copy') === 'haystack');
$call($sDev, 'undo', array('changeset_id' => $copy['changeset_id'], 'confirm' => true));
check('settings: undo of a create (duplicate) removes it from row and configuration', $settingRow('alpha_option_copy') === null && $setSite->getOption('alpha_option_copy', null, 'gone') === 'gone');
$later = $call($sDev, 'update', array('kind' => 'system_setting', 'key' => 'site_motto', 'fields' => array('value' => 'Ещё раз')));
$call($sDev, 'undo', array('changeset_id' => $later['changeset_id'], 'confirm' => true));
check('settings: undo of an update restores row and configuration', $settingRow('site_motto')['value'] === 'Здравствуй' && $setSite->getOption('site_motto') === 'Здравствуй');
$dropped = $call($sDev, 'delete', array('kind' => 'system_setting', 'key' => 'site_motto'));
check('settings: the configuration forgets a deleted setting', $setSite->getOption('site_motto', null, 'gone') === 'gone');
$call($sDev, 'undo', array('changeset_id' => $dropped['changeset_id'], 'confirm' => true));
check('settings: undo of a delete brings the configuration back', $setSite->getOption('site_motto') === 'Здравствуй');

// scopes
foreach (array('read', 'content') as $scope) {
    $actor1 = $setActor($scope);
    foreach (array('create' => array('fields' => array('key' => 'denied_one')), 'update' => array('key' => 'site_motto', 'fields' => array('area' => 'x')),
        'delete' => array('key' => 'site_motto'), 'duplicate' => array('key' => 'site_motto', 'new_name' => 'denied_two')) as $action => $args) {
        $e = throws("settings: {$scope} cannot {$action} a system setting", ApiException::class, function () use ($call, $actor1, $action, $args) { $call($actor1, $action, array('kind' => 'system_setting') + $args); });
        check("settings: {$scope} write refusal is forbidden_scope", $e && $e->errorCode === 'forbidden_scope');
    }
}

// ---- secrets ----
$secretKeys = array('smtp_pass', 'mail.password', 'db_passwd', 'app_secret', 'api_token', 'service_api_key', 'apikey', 'private_key', 'password_salt', 'x.salt.y', 'Pass', 'токен_token');
$plainKeys = array('passage_count', 'tokenizer', 'compass', 'salty_dog', 'keyboard', 'site_name_x');
foreach (array_merge($secretKeys, $plainKeys) as $secretKey) {
    $call($sDev, 'create', array('kind' => 'system_setting', 'fields' => array('key' => $secretKey, 'value' => 'v-' . $secretKey)));
}
$call($sDev, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'some_credential', 'value' => 'xtype-secret', 'xtype' => 'text-password')));
$call($sDev, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'plain_text_field', 'value' => 'visible', 'xtype' => 'textfield')));
$secretReader = function (Context $c, $key) use ($call) {
    $got = $call($c, 'get', array('kind' => 'system_setting', 'key' => $key));
    $listed = $call($c, 'list', array('kind' => 'system_setting', 'query' => $key, 'limit' => 200));
    foreach ($listed['items'] as $item) { if ($item['key'] === $key) { return array($got, $item); } }
    return array($got, null);
};
foreach (array_merge($secretKeys, array('some_credential')) as $secretKey) {
    $expected = $secretKey === 'some_credential' ? 'xtype-secret' : 'v-' . $secretKey;
    $ok = true;
    foreach (array('read' => $sRead, 'content' => $sContent, 'develop' => $sDev) as $scope => $reader) {
        list($got, $item) = $secretReader($reader, $secretKey);
        $ok = $ok && $got['fields']['value'] === '[hidden]' && $got['secret'] === true && $item !== null && $item['value'] === '[hidden]' && $item['secret'] === true;
    }
    list($got, $item) = $secretReader($sAdmin, $secretKey);
    check("settings: secret {$secretKey} is hidden below admin", $ok);
    check("settings: secret {$secretKey} is shown to admin, flagged", $got['fields']['value'] === $expected && $got['secret'] === true && $item['value'] === $expected && $item['secret'] === true);
}
foreach (array_merge($plainKeys, array('plain_text_field')) as $plainKey) {
    list($got, $item) = $secretReader($sRead, $plainKey);
    check("settings: {$plainKey} is not secret", $got['fields']['value'] === ($plainKey === 'plain_text_field' ? 'visible' : 'v-' . $plainKey)
        && !array_key_exists('secret', $got) && !array_key_exists('secret', $item) && $item['value'] === $got['fields']['value']);
}
check('settings: asking for the value field alone still hides it', $call($sRead, 'get', array('kind' => 'system_setting', 'key' => 'smtp_pass', 'fields' => array('value')))['fields'] === array('value' => '[hidden]'));
check('settings: a list without value still flags secrets', $call($sRead, 'list', array('kind' => 'system_setting', 'query' => 'smtp_pass', 'fields' => array('key')))['items'] === array(array('key' => 'smtp_pass', 'secret' => true)));
$secretWrite = $call($sDev, 'update', array('kind' => 'system_setting', 'key' => 'smtp_pass', 'fields' => array('value' => 'new-s3cret')));
check('settings: a secret can be written with the write scope and the result never echoes it', $settingRow('smtp_pass')['value'] === 'new-s3cret'
    && strpos(json_encode($secretWrite), 's3cret') === false && $secretWrite['changed'] === array('value'));
foreach (array('read' => $sRead, 'develop' => $sDev, 'admin' => $sAdmin) as $scope => $reader) {
    $secretDiff = $call($reader, 'history_diff', array('changeset_id' => $secretWrite['changeset_id']));
    check("settings: history_diff hides a secret value for {$scope}", $secretDiff['objects'][0]['changed'] === array('value' => array('old' => '[hidden]', 'new' => '[hidden]'))
        && strpos(json_encode($secretDiff), 's3cret') === false && strpos(json_encode($secretDiff), 'v-smtp_pass') === false);
}
$xtypeChange = $call($sDev, 'update', array('kind' => 'system_setting', 'key' => 'plain_text_field', 'fields' => array('xtype' => 'text-password', 'value' => 'now-hidden')));
$xtypeDiff = $call($sAdmin, 'history_diff', array('changeset_id' => $xtypeChange['changeset_id']))['objects'][0]['changed'];
check('settings: a setting that becomes a password hides its values in the diff, other columns stay visible', $xtypeDiff['value'] === array('old' => '[hidden]', 'new' => '[hidden]')
    && $xtypeDiff['xtype'] === array('old' => 'textfield', 'new' => 'text-password'));
$call($sDev, 'undo', array('changeset_id' => $secretWrite['changeset_id'], 'confirm' => true));
check('settings: undo restores the real secret value (snapshots keep it)', $settingRow('smtp_pass')['value'] === 'v-smtp_pass');
$dryNumber = $call($sDev, 'update', array('kind' => 'system_setting', 'key' => 'smtp_pass', 'fields' => array('value' => 'zzz'), 'dry_run' => true));
check('settings: a dry run on a secret names the field only', $dryNumber['would_change'] === array('value') && strpos(json_encode($dryNumber), 'zzz') === false);

// ---- modxMCP's own settings are read-only through the API ----
$setSite->store('modSystemSetting', array('key' => 'modxmcp.api_limit', 'value' => '50', 'xtype' => 'textfield', 'namespace' => 'modxmcp', 'area' => 'modxmcp:main'));
$setSite->store('modSystemSetting', array('key' => 'modxmcp.api_token', 'value' => 'tok-secret', 'xtype' => 'textfield', 'namespace' => 'modxmcp', 'area' => 'modxmcp:main'));
$setSite->store('modSystemSetting', array('key' => 'legacy_gate', 'value' => '1', 'xtype' => 'combo-boolean', 'namespace' => 'modxmcp', 'area' => 'modxmcp:main'));
$setSite->store('modSystemSetting', array('key' => 'modxmcp.in_core', 'value' => 'x', 'xtype' => 'textfield', 'namespace' => 'core', 'area' => ''));
$ownBefore = $setSite->tableDump('modSystemSetting');
$historyBefore = $call($sAdmin, 'history', array('limit' => 100))['changesets'];
$ownRefused = function ($label, callable $fn) {
    $e = throws("settings: own setting, {$label}", ApiException::class, $fn);
    check("settings: own setting, {$label}: validation_failed with the explanation", $e && $e->errorCode === 'validation_failed'
        && $e->getMessage() === 'modxMCP settings are changed in the MODX manager, not through the API.');
};
foreach (array('modxmcp.api_limit', 'legacy_gate', 'modxmcp.in_core') as $ownKey) {
    $ownRefused("update {$ownKey}", function () use ($call, $sAdmin, $ownKey) { $call($sAdmin, 'update', array('kind' => 'system_setting', 'key' => $ownKey, 'fields' => array('value' => 'hacked'))); });
    $ownRefused("update dry run {$ownKey}", function () use ($call, $sAdmin, $ownKey) { $call($sAdmin, 'update', array('kind' => 'system_setting', 'key' => $ownKey, 'fields' => array('value' => 'hacked'), 'dry_run' => true)); });
    $ownRefused("delete {$ownKey}", function () use ($call, $sAdmin, $ownKey) { $call($sAdmin, 'delete', array('kind' => 'system_setting', 'key' => $ownKey)); });
    $ownRefused("delete dry run {$ownKey}", function () use ($call, $sAdmin, $ownKey) { $call($sAdmin, 'delete', array('kind' => 'system_setting', 'key' => $ownKey, 'dry_run' => true)); });
    $ownRefused("duplicate {$ownKey}", function () use ($call, $sAdmin, $ownKey) { $call($sAdmin, 'duplicate', array('kind' => 'system_setting', 'key' => $ownKey, 'new_name' => 'plain_copy')); });
}
$ownRefused('create with a modxmcp. key in core', function () use ($call, $sAdmin) { $call($sAdmin, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'modxmcp.new_gate', 'value' => '1'))); });
$ownRefused('create with a modxmcp. key in another namespace', function () use ($call, $sAdmin, $setSite) {
    $setSite->store('modNamespace', array('name' => 'other', 'path' => '', 'assets_path' => ''));
    $call($sAdmin, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'MODXMCP.Upper', 'namespace' => 'other')));
});
$ownRefused('create in the modxmcp namespace', function () use ($call, $sAdmin) { $call($sAdmin, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'plain_key', 'namespace' => 'modxmcp'))); });
$ownRefused('create dry run', function () use ($call, $sAdmin) { $call($sAdmin, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'modxmcp.dry'), 'dry_run' => true)); });
$ownRefused('moving an ordinary setting into the namespace', function () use ($call, $sAdmin) { $call($sAdmin, 'update', array('kind' => 'system_setting', 'key' => 'site_motto', 'fields' => array('namespace' => 'modxmcp'))); });
$ownRefused('duplicate of an ordinary setting into the namespace', function () use ($call, $sAdmin) { $call($sAdmin, 'duplicate', array('kind' => 'system_setting', 'key' => 'site_motto', 'new_name' => 'site_motto_copy', 'fields' => array('namespace' => 'modxmcp'))); });
check('settings: own settings are untouched and nothing is journaled by the refusals', $setSite->tableDump('modSystemSetting') === $ownBefore
    && count($call($sAdmin, 'history', array('limit' => 100))['changesets']) === count($historyBefore));
$ownGot = $call($sRead, 'get', array('kind' => 'system_setting', 'key' => 'modxmcp.api_token'));
check('settings: own settings can be read, and follow the secret rule', $ownGot['fields']['value'] === '[hidden]' && $ownGot['secret'] === true
    && $call($sAdmin, 'get', array('kind' => 'system_setting', 'key' => 'modxmcp.api_token'))['fields']['value'] === 'tok-secret'
    && $call($sRead, 'get', array('kind' => 'system_setting', 'key' => 'modxmcp.api_limit'))['fields']['value'] === '50');
// undo must refuse to restore one: plant journal rows as an earlier allowed state could have left them
$ownKind = $sAdmin->kinds->get('system_setting');
$plant = function ($key, $beforeState, $afterState, $op) use ($sAdmin) {
    $blobs = $sAdmin->blobs();
    foreach (array($beforeState, $afterState) as $state) { if ($state !== null) { $blobs->put(ModxMCP\Journal\Snapshot::encode($state)); } }
    return $sAdmin->journal()->commit(array('source' => 'mcp', 'token_id' => 1, 'session_id' => 'planted', 'request_id' => null, 'tool' => 'update', 'summary' => 'planted ' . $key), array(array(
        'kind' => 'system_setting', 'object_key' => $key, 'label' => $key, 'op' => $op, 'before_revision' => ModxMCP\Journal\Snapshot::revision($beforeState),
        'after_revision' => ModxMCP\Journal\Snapshot::revision($afterState), 'extra_blob' => null, 'reversible' => true, 'note' => '',
    )));
};
$ownNow = ModxMCP\Journal\Snapshot::capture($setSite, $ownKind, 'modxmcp.api_limit');
$ownOlder = $ownNow;
$ownOlder['fields']['value'] = '10';
$updateChangeset = $plant('modxmcp.api_limit', $ownOlder, $ownNow, 'update');
$createChangeset = $plant('modxmcp.api_limit', null, $ownNow, 'create');
foreach (array('update' => $updateChangeset, 'create' => $createChangeset) as $label => $changesetId) {
    foreach (array(false, true) as $confirm) {
        $e = throws("settings: undo of a recorded {$label} of an own setting" . ($confirm ? ' (confirmed)' : ' (plan)'), ApiException::class, function () use ($call, $sAdmin, $changesetId, $confirm) {
            $call($sAdmin, 'undo', array('changeset_id' => $changesetId, 'force' => true) + ($confirm ? array('confirm' => true) : array()));
        });
        check("settings: undo of own {$label} is not_reversible with the explanation", $e && $e->errorCode === 'not_reversible' && strpos($e->getMessage(), 'MODX manager') !== false);
    }
}
check('settings: the refused undo changed nothing', $setSite->tableDump('modSystemSetting') === $ownBefore);
foreach (array('a state' => $ownOlder, 'removal' => null) as $label => $state) {
    $e = throws("settings: Snapshot::restore refuses own settings ({$label})", ApiException::class, function () use ($setSite, $ownKind, $state) { ModxMCP\Journal\Snapshot::restore($setSite, $ownKind, 'modxmcp.api_limit', $state); });
    check("settings: restore guard ({$label}) is not_reversible", $e && $e->errorCode === 'not_reversible');
}
// a change recorded while the setting was still an ordinary one, then moved into the namespace outside the API
$call($sDev, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'moved_later', 'value' => 'a')));
$movedUpdate = $call($sDev, 'update', array('kind' => 'system_setting', 'key' => 'moved_later', 'fields' => array('value' => 'b')));
$setSite->store('modSystemSetting', array('namespace' => 'modxmcp') + $settingRow('moved_later'));
$e = throws('settings: undo over a setting that has since become an own one', ApiException::class, function () use ($call, $sAdmin, $movedUpdate) {
    $call($sAdmin, 'undo', array('changeset_id' => $movedUpdate['changeset_id'], 'confirm' => true, 'force' => true));
});
check('settings: refused although the recorded state was ordinary', $e && $e->errorCode === 'not_reversible' && $settingRow('moved_later')['value'] === 'b');

// ---- context ----
$shop = $call($sAdmin, 'create', array('kind' => 'context', 'fields' => array('key' => 'shop', 'name' => 'Магазин', 'description' => 'Интернет-магазин', 'rank' => 3)));
check('settings: context create', $shop['key'] === 'shop' && !array_key_exists('id', $shop) && $shop['changeset_id'] !== null);
$shopGot = $call($sRead, 'get', array('kind' => 'context', 'key' => 'shop'));
check('settings: context get', $shopGot['fields'] === array('key' => 'shop', 'name' => 'Магазин', 'description' => 'Интернет-магазин', 'rank' => 3) && $shopGot['relations'] === array('settings' => array()));
check('settings: context list, query and where', array_column($call($sRead, 'list', array('kind' => 'context'))['items'], 'key') === array('mgr', 'shop', 'web')
    && array_column($call($sRead, 'list', array('kind' => 'context', 'query' => 'hop'))['items'], 'key') === array('shop')
    && array_column($call($sRead, 'list', array('kind' => 'context', 'where' => array('rank' => 3)))['items'], 'key') === array('shop')
    && $call($sRead, 'list', array('kind' => 'context', 'query' => 'Магазин'))['total'] === 0);
$before = $refreshes();
$shopUpdated = $call($sAdmin, 'update', array('kind' => 'context', 'key' => 'shop', 'fields' => array('name' => 'Лавка'), 'expected_revision' => $shop['revision']));
check('settings: context update', $shopUpdated['changed'] === array('name') && $setSite->row('modContext', 'shop')['name'] === 'Лавка' && $setSite->row('modContext', 'shop')['rank'] === '3' && $refreshes() > $before);
$e = throws('settings: context key cannot change', ApiException::class, function () use ($call, $sAdmin) { $call($sAdmin, 'update', array('kind' => 'context', 'key' => 'shop', 'fields' => array('key' => 'store'))); });
check('settings: context key immutability', $e && $e->errorCode === 'validation_failed' && $setSite->row('modContext', 'store') === null);
$shopCopy = $call($sAdmin, 'duplicate', array('kind' => 'context', 'key' => 'shop', 'new_name' => 'shop2'));
check('settings: context duplicate', $shopCopy['key'] === 'shop2' && $setSite->row('modContext', 'shop2')['name'] === 'Лавка' && $shopCopy['source_key'] === 'shop');
$call($sAdmin, 'delete', array('kind' => 'context', 'key' => 'shop2'));
check('settings: context delete', $setSite->row('modContext', 'shop2') === null);
$call($sAdmin, 'undo', array('last' => 1, 'confirm' => true));
check('settings: undo of a context delete', $setSite->row('modContext', 'shop2')['name'] === 'Лавка');
$call($sAdmin, 'undo', array('changeset_id' => $shopCopy['changeset_id'], 'confirm' => true));
check('settings: undo of a context create', $setSite->row('modContext', 'shop2') === null);
$call($sAdmin, 'undo', array('changeset_id' => $shopUpdated['changeset_id'], 'confirm' => true));
check('settings: undo of a context update', $setSite->row('modContext', 'shop')['name'] === 'Магазин');
foreach (array('web', 'mgr') as $core) {
    $e = throws("settings: MODX refuses to remove {$core}", ApiException::class, function () use ($call, $sAdmin, $core) { $call($sAdmin, 'delete', array('kind' => 'context', 'key' => $core)); });
    check("settings: {$core} refusal is passed through", $e && $e->errorCode === 'validation_failed' && $setSite->row('modContext', $core) !== null);
}
foreach (array('create' => array('fields' => array('key' => 'denied'))) + array('update' => array('key' => 'shop', 'fields' => array('name' => 'x')), 'delete' => array('key' => 'shop')) as $action => $args) {
    $e = throws("settings: develop cannot {$action} a context", ApiException::class, function () use ($call, $sDev, $action, $args) { $call($sDev, $action, array('kind' => 'context') + $args); });
    check("settings: context {$action} needs admin", $e && $e->errorCode === 'forbidden_scope');
}

// ---- context_setting ----
$url = $call($sDev, 'create', array('kind' => 'context_setting', 'fields' => array('context_key' => 'shop', 'key' => 'site_url', 'value' => 'https://магазин.example/', 'area' => 'site')));
check('settings: context_setting create carries the composite key', $url['key'] === 'shop:site_url' && !array_key_exists('id', $url) && $contextSettingRow('shop', 'site_url')['value'] === 'https://магазин.example/'
    && $contextSettingRow('shop', 'site_url')['namespace'] === 'core');
$urlGot = $call($sRead, 'get', array('kind' => 'context_setting', 'key' => 'shop:site_url'));
check('settings: context_setting get', $urlGot['key'] === 'shop:site_url' && $urlGot['fields']['context_key'] === 'shop' && $urlGot['fields']['key'] === 'site_url'
    && $urlGot['fields']['value'] === 'https://магазин.example/' && $urlGot['revision'] === $url['revision']
    && $call($sRead, 'get', array('kind' => 'context_setting', 'name' => 'shop:site_url'))['revision'] === $url['revision']);
$colons = $call($sDev, 'create', array('kind' => 'context_setting', 'fields' => array('context_key' => 'web', 'key' => 'a:b:c', 'value' => 'colons')));
check('settings: a setting key that contains colons splits on the first colon only', $colons['key'] === 'web:a:b:c' && $contextSettingRow('web', 'a:b:c')['value'] === 'colons'
    && $call($sRead, 'get', array('kind' => 'context_setting', 'key' => 'web:a:b:c'))['fields']['key'] === 'a:b:c');
$dotted = $call($sDev, 'create', array('kind' => 'context_setting', 'fields' => array('context_key' => 'web', 'key' => 'some.key', 'value' => 'dot')));
check('settings: web:some.key', $dotted['key'] === 'web:some.key' && $call($sRead, 'get', array('kind' => 'context_setting', 'key' => 'web:some.key'))['fields']['value'] === 'dot');
foreach (array('webnocolon', ':nocontext', 'web:') as $badKey) {
    foreach (array('get', 'update', 'delete', 'duplicate') as $action) {
        $e = throws("settings: malformed composite key {$badKey} ({$action})", ApiException::class, function () use ($call, $sDev, $badKey, $action) {
            $call($sDev, $action, array('kind' => 'context_setting', 'key' => $badKey) + ($action === 'update' ? array('fields' => array('value' => 'x')) : array()));
        });
        check("settings: malformed key {$badKey} is validation_failed for {$action}", $e && $e->errorCode === 'validation_failed');
    }
}
$e = throws('settings: unknown context', ApiException::class, function () use ($call, $sDev) { $call($sDev, 'create', array('kind' => 'context_setting', 'fields' => array('context_key' => 'nope', 'key' => 'x'))); });
check('settings: a missing context is refused by MODX', $e && $e->errorCode === 'validation_failed');
$e = throws('settings: duplicate context setting', ApiException::class, function () use ($call, $sDev) { $call($sDev, 'create', array('kind' => 'context_setting', 'fields' => array('context_key' => 'shop', 'key' => 'site_url'))); });
check('settings: an existing key is refused by MODX', $e && $e->errorCode === 'validation_failed');
$e = throws('settings: id for a context setting', ApiException::class, function () use ($call, $sDev) { $call($sDev, 'get', array('kind' => 'context_setting', 'id' => 1)); });
check('settings: id is refused for string-key kinds', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'addressed by key') !== false);
$listed = $call($sRead, 'list', array('kind' => 'context_setting'));
check('settings: context_setting list is sorted by context and key', array_column($listed['items'], 'context_key') === array('shop', 'web', 'web')
    && array_column($listed['items'], 'key') === array('site_url', 'a:b:c', 'some.key') && $listed['total'] === 3);
check('settings: context_setting list where context_key', array_column($call($sRead, 'list', array('kind' => 'context_setting', 'where' => array('context_key' => 'web')))['items'], 'key') === array('a:b:c', 'some.key'));
check('settings: context_setting query searches the key, not the value', array_column($call($sRead, 'list', array('kind' => 'context_setting', 'query' => 'some'))['items'], 'key') === array('some.key')
    && $call($sRead, 'list', array('kind' => 'context_setting', 'query' => 'colons'))['total'] === 0);
$e = throws('settings: context_setting where value', ApiException::class, function () use ($call, $sRead) { $call($sRead, 'list', array('kind' => 'context_setting', 'where' => array('value' => 'dot'))); });
check('settings: where on a context setting value is refused', $e && $e->errorCode === 'validation_failed');
$before = $refreshes();
$urlUpdated = $call($sDev, 'update', array('kind' => 'context_setting', 'key' => 'shop:site_url', 'fields' => array('value' => 'https://shop.example/'), 'expected_revision' => $url['revision']));
check('settings: context_setting update', $urlUpdated['changed'] === array('value') && $contextSettingRow('shop', 'site_url')['value'] === 'https://shop.example/' && $refreshes() > $before);
foreach (array('context_key' => 'web', 'key' => 'other') as $field => $value) {
    $e = throws("settings: context_setting {$field} cannot change", ApiException::class, function () use ($call, $sDev, $field, $value) { $call($sDev, 'update', array('kind' => 'context_setting', 'key' => 'shop:site_url', 'fields' => array($field => $value))); });
    check("settings: context_setting {$field} immutability", $e && $e->errorCode === 'validation_failed' && $contextSettingRow('shop', 'site_url')['value'] === 'https://shop.example/');
}
$urlCopy = $call($sDev, 'duplicate', array('kind' => 'context_setting', 'key' => 'shop:site_url', 'new_name' => 'site_url2'));
check('settings: context_setting duplicate stays in the context', $urlCopy['key'] === 'shop:site_url2' && $urlCopy['source_key'] === 'shop:site_url' && $contextSettingRow('shop', 'site_url2')['value'] === 'https://shop.example/');
$urlDeleted = $call($sDev, 'delete', array('kind' => 'context_setting', 'key' => 'shop:site_url2'));
check('settings: context_setting delete', $urlDeleted['key'] === 'shop:site_url2' && $contextSettingRow('shop', 'site_url2') === null);
$undoDeleted = $call($sDev, 'undo', array('changeset_id' => $urlDeleted['changeset_id'], 'confirm' => true));
check('settings: undo of a context_setting delete', $undoDeleted['objects'][0]['key'] === 'shop:site_url2' && $contextSettingRow('shop', 'site_url2')['value'] === 'https://shop.example/');
$call($sDev, 'undo', array('changeset_id' => $urlCopy['changeset_id'], 'confirm' => true));
check('settings: undo of a context_setting create', $contextSettingRow('shop', 'site_url2') === null);
$call($sDev, 'undo', array('changeset_id' => $urlUpdated['changeset_id'], 'confirm' => true));
check('settings: undo of a context_setting update', $contextSettingRow('shop', 'site_url')['value'] === 'https://магазин.example/');
$contextHistory = $call($sDev, 'history', array('kind' => 'context_setting'))['changesets'][0]['objects'][0];
check('settings: history of a context_setting shows the composite key', $contextHistory['key'] === 'shop:site_url' && $contextHistory['name'] === 'site_url');
$e = throws('settings: read cannot write a context setting', ApiException::class, function () use ($call, $sContent) { $call($sContent, 'update', array('kind' => 'context_setting', 'key' => 'shop:site_url', 'fields' => array('value' => 'x'))); });
check('settings: context_setting write scope is develop', $e && $e->errorCode === 'forbidden_scope');
// secrets among context settings
$call($sDev, 'create', array('kind' => 'context_setting', 'fields' => array('context_key' => 'shop', 'key' => 'payment_secret', 'value' => 'ctx-s3cret')));
$call($sDev, 'create', array('kind' => 'context_setting', 'fields' => array('context_key' => 'shop', 'key' => 'gate_pw', 'value' => 'pw-s3cret', 'xtype' => 'text-password')));
$call($sDev, 'create', array('kind' => 'context_setting', 'fields' => array('context_key' => 'shop', 'key' => 'passage_count', 'value' => '7')));
foreach (array('read' => $sRead, 'develop' => $sDev) as $scope => $reader) {
    $ctxSecret = $call($reader, 'get', array('kind' => 'context_setting', 'key' => 'shop:payment_secret'));
    $ctxList = array_column($call($reader, 'list', array('kind' => 'context_setting', 'where' => array('context_key' => 'shop')))['items'], null, 'key');
    check("settings: context_setting secrets are hidden for {$scope}", $ctxSecret['fields']['value'] === '[hidden]' && $ctxSecret['secret'] === true
        && $ctxList['payment_secret']['value'] === '[hidden]' && $ctxList['gate_pw']['value'] === '[hidden]' && $ctxList['gate_pw']['secret'] === true
        && $ctxList['passage_count']['value'] === '7' && !array_key_exists('secret', $ctxList['passage_count']) && $ctxList['site_url']['value'] === 'https://магазин.example/');
}
$ctxAdmin = array_column($call($sAdmin, 'list', array('kind' => 'context_setting', 'where' => array('context_key' => 'shop')))['items'], null, 'key');
check('settings: context_setting secrets are shown to admin', $ctxAdmin['payment_secret']['value'] === 'ctx-s3cret' && $ctxAdmin['gate_pw']['value'] === 'pw-s3cret'
    && $call($sAdmin, 'get', array('kind' => 'context_setting', 'key' => 'shop:gate_pw'))['fields']['value'] === 'pw-s3cret');
$ctxSecretWrite = $call($sDev, 'update', array('kind' => 'context_setting', 'key' => 'shop:payment_secret', 'fields' => array('value' => 'ctx-new')));
check('settings: context_setting diff hides a secret for admin', $call($sAdmin, 'history_diff', array('changeset_id' => $ctxSecretWrite['changeset_id']))['objects'][0]['changed']
    === array('value' => array('old' => '[hidden]', 'new' => '[hidden]')));

// context get shows its settings, hiding secrets; delete + undo restores them
$shopRelations = function (Context $c) use ($call) {
    $rows = $call($c, 'get', array('kind' => 'context', 'key' => 'shop'))['relations']['settings'];
    return array_column($rows, 'value', 'key');
};
check('settings: the settings relation of a context hides secrets below admin', $shopRelations($sRead) === array('gate_pw' => '[hidden]', 'passage_count' => 7, 'payment_secret' => '[hidden]', 'site_url' => 'https://магазин.example/')
    && $shopRelations($sDev)['payment_secret'] === '[hidden]');
check('settings: admin sees them', $shopRelations($sAdmin)['payment_secret'] === 'ctx-new' && $shopRelations($sAdmin)['gate_pw'] === 'pw-s3cret');
$shopSettingsBefore = array(
    $contextSettingRow('shop', 'site_url'), $contextSettingRow('shop', 'payment_secret'), $contextSettingRow('shop', 'gate_pw'), $contextSettingRow('shop', 'passage_count'),
);
$shopDeleted = $call($sAdmin, 'delete', array('kind' => 'context', 'key' => 'shop'));
check('settings: deleting a context removes its settings', $setSite->row('modContext', 'shop') === null && $contextSettingRow('shop', 'site_url') === null && $contextSettingRow('shop', 'gate_pw') === null
    && $contextSettingRow('web', 'a:b:c') !== null);
// The settings are journaled as context_setting objects of the same changeset (architect's review).
$byKey = array();
foreach ($call($sRead, 'history_diff', array('changeset_id' => $shopDeleted['changeset_id']))['objects'] as $object) {
    if ($object['kind'] === 'context_setting') { $byKey[substr($object['key'], strlen('shop:'))] = $object['changed']['value']['old']; }
}
check('settings: the diff of a context delete hides secret settings for every scope that may see it', $byKey['payment_secret'] === '[hidden]' && $byKey['gate_pw'] === '[hidden]' && $byKey['passage_count'] === '7'
    && strpos(json_encode($call($sAdmin, 'history_diff', array('changeset_id' => $shopDeleted['changeset_id']))), 's3cret') === false);
$call($sAdmin, 'undo', array('changeset_id' => $shopDeleted['changeset_id'], 'confirm' => true));
check('settings: undo of a context delete restores its settings', $setSite->row('modContext', 'shop')['name'] === 'Магазин'
    && array($contextSettingRow('shop', 'site_url'), $contextSettingRow('shop', 'payment_secret'), $contextSettingRow('shop', 'gate_pw'), $contextSettingRow('shop', 'passage_count')) == $shopSettingsBefore);
// a context with resources is not deleted (MODX would remove them for good)
$setSite->store('modResource', array('id' => 501, 'context_key' => 'shop', 'pagetitle' => 'Каталог'));
foreach (array(true, false) as $dry) {
    $e = throws('settings: a context with resources cannot be deleted', ApiException::class, function () use ($call, $sAdmin, $dry) { $call($sAdmin, 'delete', array('kind' => 'context', 'key' => 'shop') + ($dry ? array('dry_run' => true) : array())); });
    check('settings: context with resources is refused, nothing lost', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), '1 resource') !== false
        && $setSite->row('modContext', 'shop') !== null && $setSite->row('modResource', 501) !== null);
}
// a context that carries a modxMCP setting cannot be deleted through the API
$setSite->store('modContext', array('key' => 'ownctx', 'name' => 'Own', 'description' => '', 'rank' => 0));
$setSite->store('modContextSetting', array('context_key' => 'ownctx', 'key' => 'modxmcp.api_limit', 'value' => '5', 'xtype' => 'textfield', 'namespace' => 'modxmcp', 'area' => ''));
$ownRefused('delete of a context that holds one of its settings', function () use ($call, $sAdmin) { $call($sAdmin, 'delete', array('kind' => 'context', 'key' => 'ownctx')); });
check('settings: that context and its setting are still there', $setSite->row('modContext', 'ownctx') !== null && $contextSettingRow('ownctx', 'modxmcp.api_limit') !== null);
// context settings in the modxMCP namespace or with a modxmcp. key
$ownRefused('context_setting update', function () use ($call, $sAdmin) { $call($sAdmin, 'update', array('kind' => 'context_setting', 'key' => 'ownctx:modxmcp.api_limit', 'fields' => array('value' => '9'))); });
$ownRefused('context_setting delete', function () use ($call, $sAdmin) { $call($sAdmin, 'delete', array('kind' => 'context_setting', 'key' => 'ownctx:modxmcp.api_limit')); });
$ownRefused('context_setting duplicate', function () use ($call, $sAdmin) { $call($sAdmin, 'duplicate', array('kind' => 'context_setting', 'key' => 'ownctx:modxmcp.api_limit', 'new_name' => 'plain_copy')); });
$ownRefused('context_setting create with a modxmcp. key', function () use ($call, $sAdmin) { $call($sAdmin, 'create', array('kind' => 'context_setting', 'fields' => array('context_key' => 'web', 'key' => 'modxmcp.x'))); });
$ownRefused('context_setting create in the namespace', function () use ($call, $sAdmin) { $call($sAdmin, 'create', array('kind' => 'context_setting', 'fields' => array('context_key' => 'web', 'key' => 'plain', 'namespace' => 'modxmcp'))); });
$ownRefused('context_setting moved into the namespace', function () use ($call, $sAdmin) { $call($sAdmin, 'update', array('kind' => 'context_setting', 'key' => 'web:some.key', 'fields' => array('namespace' => 'modxmcp'))); });
$ownCtxState = ModxMCP\Journal\Snapshot::capture($setSite, $sAdmin->kinds->get('context_setting'), 'ownctx:modxmcp.api_limit');
$e = throws('settings: restore guard for a context setting', ApiException::class, function () use ($setSite, $sAdmin, $ownCtxState) { ModxMCP\Journal\Snapshot::restore($setSite, $sAdmin->kinds->get('context_setting'), 'ownctx:modxmcp.api_limit', $ownCtxState); });
check('settings: context_setting restore of an own setting is not_reversible', $e && $e->errorCode === 'not_reversible');
check('settings: own context settings can be read', $call($sRead, 'get', array('kind' => 'context_setting', 'key' => 'ownctx:modxmcp.api_limit'))['fields']['value'] === '5');

// ---- namespace ----
$ns = $call($sAdmin, 'create', array('kind' => 'namespace', 'fields' => array('key' => 'mynamespace', 'path' => '{core_path}components/my/', 'assets_path' => '{assets_path}components/my/')));
check('settings: namespace create', $ns['key'] === 'mynamespace' && !array_key_exists('id', $ns) && $setSite->row('modNamespace', 'mynamespace')['path'] === '{core_path}components/my/');
$nsGot = $call($sRead, 'get', array('kind' => 'namespace', 'key' => 'mynamespace'));
check('settings: namespace get', $nsGot['fields'] === array('key' => 'mynamespace', 'path' => '{core_path}components/my/', 'assets_path' => '{assets_path}components/my/') && $nsGot['revision'] === $ns['revision']
    && $call($sRead, 'get', array('kind' => 'namespace', 'name' => 'mynamespace'))['revision'] === $ns['revision']);
check('settings: namespace list, query and where', array_column($call($sRead, 'list', array('kind' => 'namespace'))['items'], 'key') === array('core', 'modxmcp', 'mynamespace', 'other')
    && array_column($call($sRead, 'list', array('kind' => 'namespace', 'query' => 'space'))['items'], 'key') === array('mynamespace')
    && array_column($call($sRead, 'list', array('kind' => 'namespace', 'where' => array('key' => 'core')))['items'], 'key') === array('core'));
$nsUpdated = $call($sAdmin, 'update', array('kind' => 'namespace', 'key' => 'mynamespace', 'fields' => array('path' => '{core_path}components/changed/'), 'expected_revision' => $ns['revision']));
check('settings: namespace update', $nsUpdated['changed'] === array('path') && $setSite->row('modNamespace', 'mynamespace')['path'] === '{core_path}components/changed/'
    && $setSite->row('modNamespace', 'mynamespace')['assets_path'] === '{assets_path}components/my/');
$e = throws('settings: namespace key cannot change', ApiException::class, function () use ($call, $sAdmin) { $call($sAdmin, 'update', array('kind' => 'namespace', 'key' => 'mynamespace', 'fields' => array('key' => 'renamed'))); });
check('settings: namespace key immutability', $e && $e->errorCode === 'validation_failed' && $setSite->row('modNamespace', 'renamed') === null);
$nsCopy = $call($sAdmin, 'duplicate', array('kind' => 'namespace', 'key' => 'mynamespace', 'new_name' => 'ns2'));
check('settings: namespace duplicate', $nsCopy['key'] === 'ns2' && $setSite->row('modNamespace', 'ns2')['path'] === '{core_path}components/changed/' && $nsCopy['source_key'] === 'mynamespace');
$nsDeleted = $call($sAdmin, 'delete', array('kind' => 'namespace', 'key' => 'ns2'));
check('settings: namespace delete', $nsDeleted['deleted'] === true && $setSite->row('modNamespace', 'ns2') === null);
$call($sAdmin, 'undo', array('changeset_id' => $nsDeleted['changeset_id'], 'confirm' => true));
check('settings: undo of a namespace delete', $setSite->row('modNamespace', 'ns2')['path'] === '{core_path}components/changed/');
$call($sAdmin, 'undo', array('changeset_id' => $nsCopy['changeset_id'], 'confirm' => true));
check('settings: undo of a namespace create', $setSite->row('modNamespace', 'ns2') === null);
$call($sAdmin, 'undo', array('changeset_id' => $nsUpdated['changeset_id'], 'confirm' => true));
check('settings: undo of a namespace update', $setSite->row('modNamespace', 'mynamespace')['path'] === '{core_path}components/my/');
$e = throws('settings: MODX refuses to remove the core namespace', ApiException::class, function () use ($call, $sAdmin) { $call($sAdmin, 'delete', array('kind' => 'namespace', 'key' => 'core')); });
check('settings: core namespace refusal is passed through', $e && $e->errorCode === 'validation_failed' && $setSite->row('modNamespace', 'core') !== null);
foreach (array('create' => array('fields' => array('key' => 'denied')), 'update' => array('key' => 'mynamespace', 'fields' => array('path' => 'x')), 'delete' => array('key' => 'mynamespace')) as $action => $args) {
    $e = throws("settings: develop cannot {$action} a namespace", ApiException::class, function () use ($call, $sDev, $action, $args) { $call($sDev, $action, array('kind' => 'namespace') + $args); });
    check("settings: namespace {$action} needs admin", $e && $e->errorCode === 'forbidden_scope');
}
// removing a namespace removes its settings and lexicon entries with it; undo brings them back
$call($sAdmin, 'create', array('kind' => 'namespace', 'fields' => array('key' => 'withdata')));
$call($sDev, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'wd_option', 'value' => 'Значение', 'namespace' => 'withdata')));
$call($sDev, 'create', array('kind' => 'context_setting', 'fields' => array('context_key' => 'web', 'key' => 'wd_ctx', 'value' => 'c', 'namespace' => 'withdata')));
$setSite->store('modLexiconEntry', array('id' => 7, 'name' => 'setting_wd_option', 'value' => 'Название', 'topic' => 'default', 'namespace' => 'withdata', 'language' => 'ru'));
$nsData = $call($sAdmin, 'delete', array('kind' => 'namespace', 'key' => 'withdata'));
check('settings: deleting a namespace removes what belongs to it', $settingRow('wd_option') === null && $contextSettingRow('web', 'wd_ctx') === null && $setSite->row('modLexiconEntry', 7) === null
    && $setSite->row('modNamespace', 'withdata') === null);
$call($sAdmin, 'undo', array('changeset_id' => $nsData['changeset_id'], 'confirm' => true));
check('settings: undo of a namespace delete restores its settings and entries', $settingRow('wd_option')['value'] === 'Значение' && $contextSettingRow('web', 'wd_ctx')['value'] === 'c'
    && $setSite->row('modLexiconEntry', 7)['value'] === 'Название' && $setSite->row('modNamespace', 'withdata') !== null);
// the namespace of modxMCP itself
$ownRefused('namespace update', function () use ($call, $sAdmin) { $call($sAdmin, 'update', array('kind' => 'namespace', 'key' => 'modxmcp', 'fields' => array('path' => 'x'))); });
$ownRefused('namespace delete', function () use ($call, $sAdmin) { $call($sAdmin, 'delete', array('kind' => 'namespace', 'key' => 'modxmcp')); });
$ownRefused('namespace duplicate', function () use ($call, $sAdmin) { $call($sAdmin, 'duplicate', array('kind' => 'namespace', 'key' => 'modxmcp', 'new_name' => 'modxmcp2')); });
$ownRefused('namespace create', function () use ($call, $sAdmin) { $call($sAdmin, 'create', array('kind' => 'namespace', 'fields' => array('key' => 'ModxMCP'))); });
$setSite->store('modSystemSetting', array('key' => 'modxmcp.stray', 'value' => '1', 'xtype' => 'textfield', 'namespace' => 'withdata', 'area' => ''));
$ownRefused('delete of a namespace that holds one of its settings', function () use ($call, $sAdmin) { $call($sAdmin, 'delete', array('kind' => 'namespace', 'key' => 'withdata')); });
check('settings: that namespace and its settings are intact', $setSite->row('modNamespace', 'withdata') !== null && $settingRow('modxmcp.stray') !== null && $settingRow('wd_option') !== null);
$e = throws('settings: Snapshot::restore refuses the modxmcp namespace', ApiException::class, function () use ($setSite, $sAdmin) {
    ModxMCP\Journal\Snapshot::restore($setSite, $sAdmin->kinds->get('namespace'), 'modxmcp', array('fields' => array('name' => 'modxmcp', 'path' => '', 'assets_path' => ''), 'relations' => array()));
});
check('settings: namespace restore guard', $e && $e->errorCode === 'not_reversible');

// Review additions: a token that may not read a credential cannot make it readable.
list($leakSite, $leakDev) = $retentionSite();
$leakAdmin = new Context($leakSite, array('scope' => 'admin') + $leakDev->token, $leakDev->corePath, function () {});
$call($leakAdmin, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'smtp_password', 'value' => 'секрет-1', 'xtype' => 'textfield')));
$call($leakAdmin, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'mail_box', 'value' => 'секрет-2', 'xtype' => 'text-password')));
$call($leakAdmin, 'create', array('kind' => 'system_setting', 'fields' => array('key' => 'site_slogan', 'value' => 'открыто', 'xtype' => 'textfield')));
foreach (array(
    'copy under a harmless key' => array('duplicate', array('kind' => 'system_setting', 'key' => 'smtp_password', 'new_name' => 'harmless')),
    'copy of a password-typed setting' => array('duplicate', array('kind' => 'system_setting', 'key' => 'mail_box', 'new_name' => 'harmless2', 'fields' => array('xtype' => 'textfield'))),
    'xtype changed to plain text' => array('update', array('kind' => 'system_setting', 'key' => 'mail_box', 'fields' => array('xtype' => 'textfield'))),
) as $label => $attempt) {
    $e = throws('secrets: develop cannot ' . $label, ApiException::class, function () use ($call, $leakDev, $attempt) { $call($leakDev, $attempt[0], $attempt[1]); });
    check('secrets: refusal for ' . $label . ' is forbidden_scope', $e && $e->errorCode === 'forbidden_scope');
}
check('secrets: nothing was copied', $leakSite->getCount('modSystemSetting', array('key' => 'harmless')) === 0 && $leakSite->getCount('modSystemSetting', array('key' => 'harmless2')) === 0);
check('secrets: develop may still change the value of a credential', $call($leakDev, 'update', array('kind' => 'system_setting', 'key' => 'smtp_password', 'fields' => array('value' => 'новый')))['changeset_id'] !== null
    && $call($leakDev, 'get', array('kind' => 'system_setting', 'key' => 'smtp_password'))['fields']['value'] === '[hidden]');
check('secrets: develop may copy an ordinary setting', $call($leakDev, 'duplicate', array('kind' => 'system_setting', 'key' => 'site_slogan', 'new_name' => 'site_slogan_2'))['changeset_id'] !== null);
check('secrets: admin may copy a credential', $call($leakAdmin, 'duplicate', array('kind' => 'system_setting', 'key' => 'smtp_password', 'new_name' => 'smtp_password_old'))['changeset_id'] !== null);

// Review additions: a context's settings are their own objects in the journal.
list($relSite, $relDev) = $retentionSite();
$relAdmin = new Context($relSite, array('scope' => 'admin') + $relDev->token, $relDev->corePath, function () {});
$relAdmin->sessionId = 'related-session-0001';
$relCheckpoint = $call($relAdmin, 'checkpoint', array('label' => 'related'));
$call($relAdmin, 'create', array('kind' => 'context', 'fields' => array('key' => 'shop', 'name' => 'Магазин')));
$relContext = $call($relAdmin, 'get', array('kind' => 'context', 'key' => 'shop'));
$call($relAdmin, 'create', array('kind' => 'context_setting', 'fields' => array('context_key' => 'shop', 'key' => 'site_url', 'value' => 'https://shop.example/')));
$relWithSetting = $call($relAdmin, 'get', array('kind' => 'context', 'key' => 'shop'));
check('related: a context shows its settings, but they do not change its revision', $relWithSetting['revision'] === $relContext['revision']
    && $relWithSetting['relations']['settings'] == array(array('key' => 'site_url', 'value' => 'https://shop.example/')));
$call($relAdmin, 'update', array('kind' => 'context_setting', 'key' => 'shop:site_url', 'fields' => array('value' => 'https://shop2.example/')));
$relDelete = $call($relAdmin, 'delete', array('kind' => 'context', 'key' => 'shop'));
check('related: a context delete journals its settings as deleted objects', array_column($call($relAdmin, 'history_diff', array('changeset_id' => $relDelete['changeset_id']))['objects'], 'kind') === array('context', 'context_setting'));
$call($relAdmin, 'undo', array('changeset_id' => $relDelete['changeset_id'], 'confirm' => true));
check('related: undo brings the setting back with its last value', $relSite->row('modContextSetting', array('context_key' => 'shop', 'key' => 'site_url')) !== null
    && $call($relAdmin, 'get', array('kind' => 'context_setting', 'key' => 'shop:site_url'))['fields']['value'] === 'https://shop2.example/');
$relBack = $call($relAdmin, 'undo', array('to_checkpoint' => $relCheckpoint['checkpoint_id'], 'confirm' => true));
check('related: undo to the checkpoint has no conflict and removes both', $relBack['performed'] === true && $relBack['conflicts'] === 0
    && $relSite->getCount('modContext', array('key' => 'shop')) === 0 && $relSite->getCount('modContextSetting', array('context_key' => 'shop')) === 0);
check('related: nothing was recorded as an outside change', $call($relAdmin, 'history', array('source' => 'external'))['changesets'] === array());

// Review additions: list search is literal.
list($qSite, $qDev) = $retentionSite();
$qAdmin = new Context($qSite, array('scope' => 'admin') + $qDev->token, $qDev->corePath, function () {});
foreach (array('site_name', 'siteXname', 'site%name') as $qKey) { $call($qAdmin, 'create', array('kind' => 'system_setting', 'fields' => array('key' => $qKey, 'value' => 'v'))); }
check('list: query treats _ and % literally', array_column($call($qAdmin, 'list', array('kind' => 'system_setting', 'query' => 'site_'))['items'], 'key') === array('site_name')
    && array_column($call($qAdmin, 'list', array('kind' => 'system_setting', 'query' => 'site%'))['items'], 'key') === array('site%name'));

// --- Kind user (task 17) ---
$userDir = $siteDir . '/users';
mkdir($userDir . '/core', 0700, true);
$uSite = fakeSite($userDir);
$uActor = function ($scope, array $token = array()) use ($uSite, $dev) {
    $c = new Context($uSite, $token + array('scope' => $scope) + $dev->token, $dev->corePath, function () {});
    $c->sessionId = 'session-users00001';
    return $c;
};
$uAdmin = $uActor('admin');
$uSite->options['fake_permissions'] = array('settings' => true, 'set_sudo' => true);
$secretPw = 'Пароль-Секрет-123';
$secretPw2 = 'Новый-Пароль-456';
$secretHashes = function () use ($uSite) {
    $hashes = array();
    foreach ($uSite->tableDump('modUser') as $row) { foreach (array('password', 'salt') as $column) { if (!empty($row[$column])) { $hashes[] = $row[$column]; } } }
    return $hashes;
};
// Nothing a tool answered may carry a password, a hash or a salt, in either JSON spelling.
$leaks = function ($data, array $plain = array()) use ($secretPw, $secretPw2, $secretHashes) {
    foreach (array(JSON_UNESCAPED_UNICODE, 0) as $flags) {
        $text = json_encode($data, $flags);
        foreach (array_merge(array($secretPw, $secretPw2, 'fakehash'), $secretHashes(), $plain) as $needle) {
            if ($needle !== '' && strpos($text, $flags ? $needle : trim(json_encode($needle), '"')) !== false) { return true; }
        }
    }
    return false;
};
$plantUser = function ($id, $name, $email, array $more = array(), array $profile = array()) use ($uSite) {
    $uSite->store('modUser', $more + array('id' => $id, 'username' => $name, 'password' => modX::fakeHash('planted' . $id, 'salt' . $id), 'cachepwd' => '',
        'class_key' => 'modUser', 'active' => 1, 'remote_key' => null, 'remote_data' => null, 'hash_class' => 'hashing.modNative', 'salt' => 'salt' . $id,
        'primary_group' => 0, 'session_stale' => null, 'sudo' => 0, 'createdon' => 1700000000));
    $uSite->store('modUserProfile', $profile + array('id' => $id, 'internalKey' => $id, 'fullname' => '', 'email' => $email, 'phone' => '', 'mobilephone' => '',
        'blocked' => 0, 'blockeduntil' => 0, 'blockedafter' => 0, 'logincount' => 0, 'lastlogin' => 0, 'thislogin' => 0, 'failedlogincount' => 0,
        'sessionid' => 'sess' . $id, 'dob' => 0, 'gender' => 0, 'address' => '', 'country' => '', 'city' => '', 'state' => '', 'zip' => '', 'fax' => '',
        'photo' => '', 'comment' => '', 'website' => '', 'extended' => null));
};
$plantUser(1, 'service', 'service@example.com', array('sudo' => 1));
$plantUser(700, 'суперпользователь', 'super@example.com', array('sudo' => 1));
foreach (array(1 => 'Administrator', 2 => 'Редакторы') as $groupKey => $groupName) {
    $uSite->store('modUserGroup', array('id' => $groupKey, 'name' => $groupName, 'description' => '', 'parent' => 0, 'rank' => 0, 'dashboard' => 1));
}
$uSite->store('modUserGroupRole', array('id' => 2, 'name' => 'Member', 'description' => '', 'authority' => 9999));
$userRow = function ($id) use ($uSite) { return $uSite->row('modUser', $id); };
$profileRow = function ($id) use ($uSite) { $rows = $uSite->getCollection('modUserProfile', array('internalKey' => $id)); return $rows ? $rows[0]->toArray() : null; };

// describe
$uDescribed = $call($uAdmin, 'describe', array('kind' => 'user'));
$uFieldInfo = array_column($uDescribed['fields'], null, 'name');
check('user: describe', $uDescribed['group'] === 'access' && $uDescribed['read_scope'] === 'admin' && $uDescribed['write_scope'] === 'admin' && $uDescribed['name_field'] === 'username'
    && $uDescribed['key_type'] === 'integer' && $uFieldInfo['password']['write_only'] === true && $uFieldInfo['password']['required'] === true
    && $uFieldInfo['sudo']['read_only'] === true && $uFieldInfo['class_key']['read_only'] === true && $uFieldInfo['lastlogin']['read_only'] === true
    && $uFieldInfo['blockeduntil']['format'] === 'datetime' && $uFieldInfo['username']['required'] === true
    && array_column($uDescribed['relations'], 'name') === array('groups') && !isset($uFieldInfo['salt']) && !isset($uFieldInfo['cachepwd']) && !isset($uFieldInfo['sessionid']));
check('user: only an admin token sees the kind', !in_array('user', array_column($call($uActor('develop'), 'describe')['kinds'], 'kind'), true)
    && in_array('user', array_column($call($uAdmin, 'describe')['kinds'], 'kind'), true));

// create
$uHistoryBefore = $uAdmin->journal()->lastChangesetId();
$uCreateDry = $call($uAdmin, 'create', array('kind' => 'user', 'dry_run' => true, 'fields' => array('username' => 'иван', 'password' => $secretPw, 'email' => 'ivan@example.com')));
check('user: create dry run changes nothing and says nothing secret', $uCreateDry['dry_run'] && $uSite->getCount('modUser', array('username' => 'иван')) === 0
    && $uAdmin->journal()->lastChangesetId() === $uHistoryBefore && !$leaks($uCreateDry));
$ivan = $call($uAdmin, 'create', array('kind' => 'user', 'fields' => array('username' => 'иван', 'password' => $secretPw, 'email' => 'ivan@example.com',
    'fullname' => 'Иван Петров', 'phone' => '+7 900 000-00-00', 'comment' => 'Редактор сайта', 'active' => true)));
$ivanId = $ivan['id'];
check('user: create result', is_int($ivanId) && $ivan['changeset_id'] !== null && $ivan['reversible'] === true && !$leaks($ivan)
    && in_array('password', $ivan['changed'], true) && in_array('email', $ivan['changed'], true) && in_array('username', $ivan['changed'], true) && !in_array('profile', $ivan['changed'], true));
check('user: the kind list of an admin token has user after namespace', array_slice(array_column($call($uAdmin, 'describe')['kinds'], 'kind'), 14, 2) === array('namespace', 'user'));
check('user: create stored the user and its profile', $userRow($ivanId)['username'] === 'иван' && $userRow($ivanId)['active'] === '1' && $userRow($ivanId)['sudo'] === '0'
    && $userRow($ivanId)['class_key'] === 'modUser' && $userRow($ivanId)['password'] === modX::fakeHash($secretPw, $userRow($ivanId)['salt'])
    && $profileRow($ivanId)['email'] === 'ivan@example.com' && $profileRow($ivanId)['fullname'] === 'Иван Петров' && $profileRow($ivanId)['comment'] === 'Редактор сайта');
check('user: the summary of the journal has no secret', !$leaks($call($uAdmin, 'history', array('kind' => 'user', 'id' => $ivanId))));
$ivanCreateDiff = $call($uAdmin, 'history_diff', array('changeset_id' => $ivan['changeset_id']));
check('user: the diff of a create hides the hash and shows the contact data', !$leaks($ivanCreateDiff)
    && $ivanCreateDiff['objects'][0]['changed']['password'] === array('old' => '[hidden]', 'new' => '[hidden]')
    && $ivanCreateDiff['objects'][0]['changed']['email']['new'] === 'ivan@example.com' && $ivanCreateDiff['objects'][0]['changed']['fullname']['new'] === 'Иван Петров');
check('user: no password, no profile row-leak in the diff', !isset($ivanCreateDiff['objects'][0]['changed']['profile']) && !strpos(json_encode($ivanCreateDiff), 'sessionid'));
foreach (array(
    array('class_key and sudo cannot be set on create', array('username' => 'x1', 'password' => $secretPw, 'email' => 'a@b.c', 'sudo' => true)),
    array('class_key cannot be set on create', array('username' => 'x2', 'password' => $secretPw, 'email' => 'a@b.c', 'class_key' => 'modUser')),
    array('password is required on create', array('username' => 'x3', 'email' => 'a@b.c')),
    array('username is required on create', array('password' => $secretPw, 'email' => 'a@b.c')),
    array('the derived login data cannot be written', array('username' => 'x4', 'password' => $secretPw, 'email' => 'a@b.c', 'logincount' => 3)),
    array('the hash columns are not fields', array('username' => 'x5', 'password' => $secretPw, 'email' => 'a@b.c', 'salt' => 'x')),
    array('the password must be a string', array('username' => 'x6', 'password' => 12345678, 'email' => 'a@b.c')),
) as $case) {
    $lastUser = $uAdmin->journal()->lastChangesetId();
    $e = throws('user: ' . $case[0], ApiException::class, function () use ($call, $uAdmin, $case) { $call($uAdmin, 'create', array('kind' => 'user', 'fields' => $case[1])); });
    check('user: ' . $case[0] . ' is validation_failed without a changeset', $e && $e->errorCode === 'validation_failed' && $uAdmin->journal()->lastChangesetId() === $lastUser && !$leaks($e->getMessage()));
}
// MODX's own refusals pass through, and the password never shows in them
$passThrough = array(
    'duplicate username' => array(array('username' => 'иван', 'password' => $secretPw, 'email' => 'other@example.com'), 'already exists'),
    'password too short' => array(array('username' => 'короткий', 'password' => 'Ab1', 'email' => 'short@example.com'), 'too short'),
    'invalid password characters' => array(array('username' => 'скобки', 'password' => 'Пароль-(скобки)-123', 'email' => 'br@example.com'), 'invalid characters'),
    'no e-mail' => array(array('username' => 'безпочты', 'password' => $secretPw), 'e-mail'),
);
foreach ($passThrough as $label => $case) {
    $lastUser = $uAdmin->journal()->lastChangesetId();
    $e = throws("user: MODX refuses {$label}", ApiException::class, function () use ($call, $uAdmin, $case) { $call($uAdmin, 'create', array('kind' => 'user', 'fields' => $case[0])); });
    check("user: {$label} is passed through", $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), $case[1]) !== false
        && $uAdmin->journal()->lastChangesetId() === $lastUser && strpos($e->getMessage(), 'Пароль') === false && $uSite->getCount('modUser', array('username' => $case[0]['username'])) === (int) ($label === 'duplicate username'));
}
$uSite->options['allow_multiple_emails'] = false;
$e = throws('user: duplicate e-mail', ApiException::class, function () use ($call, $uAdmin, $secretPw) { $call($uAdmin, 'create', array('kind' => 'user', 'fields' => array('username' => 'двойник', 'password' => $secretPw, 'email' => 'ivan@example.com'))); });
check('user: MODX refuses a duplicate e-mail when it is configured so', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'e-mail') !== false);
$uSite->options['allow_multiple_emails'] = true;
$uSite->options['password_min_length'] = 40;
$e = throws('user: password_min_length', ApiException::class, function () use ($call, $uAdmin, $secretPw) { $call($uAdmin, 'create', array('kind' => 'user', 'fields' => array('username' => 'длинный', 'password' => $secretPw, 'email' => 'long@example.com'))); });
check('user: password_min_length passes through as its own error', $e && strpos($e->getMessage(), 'too short') !== false && !$leaks($e->getMessage()));
unset($uSite->options['password_min_length']);

// get, list
$ivanGot = $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId));
check('user: get', $ivanGot['fields']['username'] === 'иван' && $ivanGot['fields']['email'] === 'ivan@example.com' && $ivanGot['fields']['fullname'] === 'Иван Петров'
    && $ivanGot['fields']['active'] === true && $ivanGot['fields']['blocked'] === false && $ivanGot['fields']['sudo'] === false && $ivanGot['fields']['blockeduntil'] === ''
    && $ivanGot['fields']['logincount'] === 0 && !array_key_exists('password', $ivanGot['fields']) && array_keys((array) $ivanGot['relations']) === array('groups')
    && $ivanGot['relations']['groups'] === array() && !$leaks($ivanGot) && !strpos(json_encode($ivanGot), 'sess'));
check('user: get by name', $call($uAdmin, 'get', array('kind' => 'user', 'name' => 'иван'))['id'] === $ivanId);
$e = throws('user: password cannot be asked for', ApiException::class, function () use ($call, $uAdmin, $ivanId) { $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('username', 'password'))); });
check('user: asking for the password is refused', $e && $e->errorCode === 'validation_failed');
$ivanSelected = $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('email', 'username')));
check('user: get selected profile and user fields', $ivanSelected['fields'] === array('email' => 'ivan@example.com', 'username' => 'иван'));
$plantUser(710, 'мария', 'maria@example.com', array('active' => 0), array('fullname' => 'Мария Иванова', 'blocked' => 1));
$userList = $call($uAdmin, 'list', array('kind' => 'user'));
check('user: list default fields', $userList['total'] === 4 && array_keys($userList['items'][0]) === array('id', 'username', 'active', 'sudo', 'primary_group', 'fullname', 'email', 'blocked') && !$leaks($userList));
$byUser = array_column($userList['items'], null, 'username');
check('user: list shows profile fields', $byUser['мария']['email'] === 'maria@example.com' && $byUser['мария']['blocked'] === true && $byUser['мария']['active'] === false && $byUser['иван']['fullname'] === 'Иван Петров');
check('user: list query is Unicode-aware', array_column($call($uAdmin, 'list', array('kind' => 'user', 'query' => 'мар'))['items'], 'username') === array('мария'));
check('user: list where on a user column', array_column($call($uAdmin, 'list', array('kind' => 'user', 'where' => array('active' => false)))['items'], 'username') === array('мария'));
foreach (array('email' => 'ivan@example.com', 'blocked' => true, 'password' => 'x', 'lastlogin' => '2020-01-01 00:00:00', 'logincount' => 0) as $profileField => $profileValue) {
    $e = throws("user: where on {$profileField}", ApiException::class, function () use ($call, $uAdmin, $profileField, $profileValue) { $call($uAdmin, 'list', array('kind' => 'user', 'where' => array($profileField => $profileValue))); });
    check("user: where on {$profileField} is refused", $e && $e->errorCode === 'validation_failed');
}
$e = throws('user: list fields password', ApiException::class, function () use ($call, $uAdmin) { $call($uAdmin, 'list', array('kind' => 'user', 'fields' => array('password'))); });
check('user: listing the password is refused', $e && $e->errorCode === 'validation_failed');
check('user: list can select profile fields', $call($uAdmin, 'list', array('kind' => 'user', 'fields' => array('phone', 'comment'), 'query' => 'иван'))['items'] === array(array('phone' => '+7 900 000-00-00', 'comment' => 'Редактор сайта')));

// update: profile and user fields; the whole record goes to the processor
$ivanRevision = $ivanGot['revision'];
$updateDry = $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'dry_run' => true, 'fields' => array('email' => 'ivan.new@example.com', 'password' => $secretPw2)));
check('user: update dry run', $updateDry['dry_run'] && $updateDry['would_change'] === array('email', 'password') && $profileRow($ivanId)['email'] === 'ivan@example.com' && !$leaks($updateDry));
$updated = $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('email' => 'ivan.new@example.com', 'fullname' => 'Иван Сергеевич'), 'expected_revision' => $ivanRevision));
check('user: an update that does not touch the password keeps the stored hash', $userRow($ivanId)['password'] === modX::fakeHash($secretPw, $userRow($ivanId)['salt']));
check('user: a profile change is named by its fields, not as "profile"', $updated['changed'] === array('fullname', 'email') && $updated['revision'] !== $ivanRevision
    && $profileRow($ivanId)['email'] === 'ivan.new@example.com' && $userRow($ivanId)['active'] === '1' && $userRow($ivanId)['username'] === 'иван');
$e = throws('user: stale revision', ApiException::class, function () use ($call, $uAdmin, $ivanId, $ivanRevision) { $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('phone' => '1'), 'expected_revision' => $ivanRevision)); });
check('user: the revision covers the profile', $e && $e->errorCode === 'revision_conflict');
$updateDiff = $call($uAdmin, 'history_diff', array('changeset_id' => $updated['changeset_id']))['objects'][0]['changed'];
check('user: update diff names profile fields with old and new values', $updateDiff === array('fullname' => array('old' => 'Иван Петров', 'new' => 'Иван Сергеевич'),
    'email' => array('old' => 'ivan@example.com', 'new' => 'ivan.new@example.com')));
$userFields = $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('username' => 'иван2', 'active' => false, 'primary_group' => 2, 'phone' => '', 'blocked' => true)));
check('user: update of user and profile fields', $userFields['changed'] === array('username', 'active', 'primary_group', 'phone', 'blocked') && $userRow($ivanId)['username'] === 'иван2'
    && $userRow($ivanId)['active'] === '0' && $profileRow($ivanId)['blocked'] === '1' && $profileRow($ivanId)['phone'] === '');
$blockedUntil = '2031-05-06 07:08:09';
$call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('blockeduntil' => $blockedUntil)));
check('user: blockeduntil is written as a date string and read back', $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId))['fields']['blockeduntil'] === $blockedUntil
    && $profileRow($ivanId)['blockeduntil'] === (string) strtotime(str_replace('-', '/', $blockedUntil)));
$call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('email' => 'ivan.again@example.com')));
check('user: an unrelated update keeps the block dates', $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId))['fields']['blockeduntil'] === $blockedUntil);
$call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('blockeduntil' => '')));
check('user: blockeduntil can be cleared', $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId))['fields']['blockeduntil'] === '' && $profileRow($ivanId)['blockeduntil'] === '0');
$e = throws('user: bad date', ApiException::class, function () use ($call, $uAdmin, $ivanId) { $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('blockedafter' => 'не дата'))); });
check('user: MODX date refusal passes through', $e && $e->errorCode === 'validation_failed');
foreach (array(array('sudo', true), array('class_key', 'modUser'), array('logincount', 3), array('lastlogin', '2020-01-01 00:00:00'), array('id', 5), array('salt', 'x')) as $readOnly) {
    $e = throws("user: {$readOnly[0]} cannot be updated", ApiException::class, function () use ($call, $uAdmin, $ivanId, $readOnly) { $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array($readOnly[0] => $readOnly[1]))); });
    check("user: {$readOnly[0]} is read-only", $e && $e->errorCode === 'validation_failed');
}
$e = throws('user: username taken', ApiException::class, function () use ($call, $uAdmin, $ivanId) { $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('username' => 'мария'))); });
check('user: duplicate username on update passes through', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'already exists') !== false && $userRow($ivanId)['username'] === 'иван2');

// the checkbox trap: update processors reset an absent checkbox (sudo included) in some MODX versions; the whole record is always sent
$superBefore = $call($uAdmin, 'get', array('kind' => 'user', 'id' => 700));
foreach (array(false, true) as $unconditional) {
    $uSite->options['fake_unconditional_checkbox'] = $unconditional;
    $mail = $call($uAdmin, 'update', array('kind' => 'user', 'id' => 700, 'fields' => array('email' => 'super' . (int) $unconditional . '@example.com', 'comment' => 'Администратор ' . (int) $unconditional)));
    check('user: updating the e-mail of a sudo user keeps sudo' . ($unconditional ? ' (processor resets absent checkboxes)' : ''),
        $userRow(700)['sudo'] === '1' && $userRow(700)['active'] === '1' && $call($uAdmin, 'get', array('kind' => 'user', 'id' => 700))['fields']['sudo'] === true && $mail['changed'] === array('email', 'comment'));
    $call($uAdmin, 'update', array('kind' => 'user', 'id' => 710, 'fields' => array('fullname' => 'Мария ' . (int) $unconditional)));
    check('user: updating a profile field keeps active and blocked' . ($unconditional ? ' (processor resets absent checkboxes)' : ''),
        $userRow(710)['active'] === '0' && $profileRow(710)['blocked'] === '1');
    $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('comment' => 'Проверка ' . (int) $unconditional)));
    check('user: updating a comment keeps a blocked inactive user as it was' . ($unconditional ? ' (processor resets absent checkboxes)' : ''),
        $userRow($ivanId)['active'] === '0' && $profileRow($ivanId)['blocked'] === '1');
}
$uSite->options['fake_unconditional_checkbox'] = false;

// password
$hashBefore = $userRow($ivanId)['password'];
$revisionBeforePassword = $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId))['revision'];
$changedPassword = $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('password' => $secretPw2)));
check('user: password change', $changedPassword['changed'] === array('password') && $userRow($ivanId)['password'] !== $hashBefore
    && $userRow($ivanId)['password'] === modX::fakeHash($secretPw2, $userRow($ivanId)['salt']) && !$leaks($changedPassword) && $changedPassword['revision'] !== $revisionBeforePassword);
$passwordDiff = $call($uAdmin, 'history_diff', array('changeset_id' => $changedPassword['changeset_id']));
check('user: the diff of a password change shows password as hidden', $passwordDiff['objects'][0]['changed'] === array('password' => array('old' => '[hidden]', 'new' => '[hidden]')) && !$leaks($passwordDiff));
check('user: the history after a password change is clean', !$leaks($call($uAdmin, 'history', array('kind' => 'user', 'id' => $ivanId, 'limit' => 20))));
$lastUser = $uAdmin->journal()->lastChangesetId();
$e = throws('user: short password on update', ApiException::class, function () use ($call, $uAdmin, $ivanId) { $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('password' => 'Ab1'))); });
check('user: MODX password rule on update passes through', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'too short') !== false && $uAdmin->journal()->lastChangesetId() === $lastUser
    && $userRow($ivanId)['password'] === modX::fakeHash($secretPw2, $userRow($ivanId)['salt']));
$undoPassword = $call($uAdmin, 'undo', array('changeset_id' => $changedPassword['changeset_id'], 'confirm' => true));
check('user: undo restores the old hash', $userRow($ivanId)['password'] === $hashBefore && $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId))['revision'] === $revisionBeforePassword && !$leaks($undoPassword));
$ivanLater = $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('email' => 'ivan.final@example.com')));
$undoLater = $call($uAdmin, 'undo', array('changeset_id' => $ivanLater['changeset_id'], 'confirm' => true));
check('user: undo of a profile-only change restores the e-mail and is not a conflict', $undoLater['performed'] && $undoLater['conflicts'] === 0 && $profileRow($ivanId)['email'] === 'ivan.again@example.com');

// groups relation and membership through user_group
$uSite->store('modUserGroup', array('id' => 3, 'name' => 'Авторы', 'description' => '', 'parent' => 0, 'rank' => 0, 'dashboard' => 1));
$ivanBeforeMember = $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId));
$added = $call($uAdmin, 'invoke', array('kind' => 'user_group', 'id' => 2, 'op' => 'add_member', 'args' => array('user' => $ivanId, 'role' => 2)));
$addedChanges = $uAdmin->journal()->changes(array($added['changeset_id']));
check('user: add_member journals the group and the user', count($addedChanges) === 2 && array_column($addedChanges, 'kind') !== array() && in_array('user', array_column($addedChanges, 'kind'), true)
    && in_array('user_group', array_column($addedChanges, 'kind'), true));
$ivanMember = $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId));
check('user: groups relation lists the membership and the primary group follows', $ivanMember['relations']['groups'] === array(array('user_group' => 2, 'role' => 2))
    && $ivanMember['fields']['primary_group'] === 2 && $ivanMember['revision'] !== $ivanBeforeMember['revision']);
$e = throws('user: groups are read-only', ApiException::class, function () use ($call, $uAdmin, $ivanId) { $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'relations' => array('groups' => array()))); });
check('user: groups relation write refused', $e && $e->errorCode === 'validation_failed');
$e = throws('user: profile relation is hidden', ApiException::class, function () use ($call, $uAdmin, $ivanId) { $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'relations' => array('profile' => array()))); });
check('user: the profile relation is not writable either', $e && $e->errorCode === 'validation_failed');
$memberDiff = $call($uAdmin, 'history_diff', array('changeset_id' => $added['changeset_id']));
$memberDiffUser = array_values(array_filter($memberDiff['objects'], function ($item) { return $item['kind'] === 'user'; }))[0];
check('user: the diff of a membership shows the groups of the user', array_keys((array) $memberDiffUser['changed']) === array('groups') && !$leaks($memberDiff));
$uSite->store('modUserSetting', array('user' => $ivanId, 'key' => 'manager_theme', 'value' => 'darkly', 'xtype' => 'textfield', 'namespace' => 'core', 'area' => '', 'editedon' => null));
$ivanFull = $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId));
$deleteDry = $call($uAdmin, 'delete', array('kind' => 'user', 'id' => $ivanId, 'dry_run' => true));
check('user: delete dry run counts what goes with the user', $deleteDry['dry_run'] && $deleteDry['would_delete']['name'] === 'иван2' && $deleteDry['would_delete']['related_rows']['groups'] === 1
    && $deleteDry['would_delete']['related_rows']['settings'] === 1 && $userRow($ivanId) !== null);
$deleted = $call($uAdmin, 'delete', array('kind' => 'user', 'id' => $ivanId));
check('user: delete removes the user, profile, memberships and settings', $deleted['deleted'] === true && $userRow($ivanId) === null && $profileRow($ivanId) === null
    && $uSite->getCount('modUserGroupMember', array('member' => $ivanId)) === 0 && $uSite->getCount('modUserSetting', array('user' => $ivanId)) === 0);
$undoDelete = $call($uAdmin, 'undo', array('changeset_id' => $deleted['changeset_id'], 'confirm' => true));
$ivanRestored = $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId));
check('user: undo of the delete restores user, profile, memberships and settings', $undoDelete['performed'] && $ivanRestored['revision'] === $ivanFull['revision'] && $ivanRestored['fields'] === $ivanFull['fields']
    && $ivanRestored['relations']['groups'] === array(array('user_group' => 2, 'role' => 2)) && $uSite->getCount('modUserSetting', array('user' => $ivanId, 'key' => 'manager_theme')) === 1
    && $userRow($ivanId)['password'] === $hashBefore);
$removedMember = $call($uAdmin, 'invoke', array('kind' => 'user_group', 'id' => 2, 'op' => 'remove_member', 'args' => array('user' => $ivanId)));
check('user: remove_member journals both and resets the primary group', count($uAdmin->journal()->changes(array($removedMember['changeset_id']))) === 2 && $userRow($ivanId)['primary_group'] === '0'
    && $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId))['relations']['groups'] === array());
$call($uAdmin, 'undo', array('changeset_id' => $removedMember['changeset_id'], 'confirm' => true));
check('user: undo of remove_member puts the user back too', $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId))['revision'] === $ivanFull['revision']);
$call($uAdmin, 'undo', array('changeset_id' => $added['changeset_id'], 'confirm' => true));
check('user: undo of add_member restores both sides', $call($uAdmin, 'get', array('kind' => 'user', 'id' => $ivanId))['revision'] === $ivanBeforeMember['revision'] && $uSite->getCount('modUserGroupMember', array('member' => $ivanId)) === 0);

// create, then undo, then redo: the user is removed with its profile
$ephemeral = $call($uAdmin, 'create', array('kind' => 'user', 'fields' => array('username' => 'временный', 'password' => $secretPw, 'email' => 'tmp@example.com')));
$call($uAdmin, 'undo', array('changeset_id' => $ephemeral['changeset_id'], 'confirm' => true));
check('user: undo of a create removes the user and its profile', $userRow($ephemeral['id']) === null && $profileRow($ephemeral['id']) === null);

// duplicate: the password hash is never copied
$lastUser = $uAdmin->journal()->lastChangesetId();
$e = throws('user: duplicate needs a password', ApiException::class, function () use ($call, $uAdmin, $ivanId) { $call($uAdmin, 'duplicate', array('kind' => 'user', 'id' => $ivanId, 'new_name' => 'копия')); });
check('user: duplicate without a password is refused', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'password') !== false && $uAdmin->journal()->lastChangesetId() === $lastUser);
$uSite->options['allow_multiple_emails'] = false;
$e = throws('user: duplicate with the same e-mail', ApiException::class, function () use ($call, $uAdmin, $ivanId, $secretPw) { $call($uAdmin, 'duplicate', array('kind' => 'user', 'id' => $ivanId, 'new_name' => 'копия', 'fields' => array('password' => $secretPw))); });
check('user: MODX refuses the same e-mail on a copy', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'e-mail') !== false && !$leaks($e->getMessage()));
$uSite->options['allow_multiple_emails'] = true;
$copyDry = $call($uAdmin, 'duplicate', array('kind' => 'user', 'id' => $ivanId, 'new_name' => 'копия', 'dry_run' => true, 'fields' => array('password' => $secretPw)));
check('user: duplicate dry run', $copyDry['dry_run'] && $copyDry['would_create'] === array('name' => 'копия') && $uSite->getCount('modUser', array('username' => 'копия')) === 0 && !$leaks($copyDry));
$copy = $call($uAdmin, 'duplicate', array('kind' => 'user', 'id' => $ivanId, 'new_name' => 'копия', 'fields' => array('password' => $secretPw2, 'email' => 'copy@example.com')));
check('user: duplicate creates a user with its own password and the copied profile', $copy['id'] !== $ivanId && !$leaks($copy) && $userRow($copy['id'])['username'] === 'копия'
    && $userRow($copy['id'])['password'] === modX::fakeHash($secretPw2, $userRow($copy['id'])['salt']) && $userRow($copy['id'])['password'] !== $userRow($ivanId)['password']
    && $profileRow($copy['id'])['email'] === 'copy@example.com' && $profileRow($copy['id'])['comment'] === $profileRow($ivanId)['comment'] && $userRow($copy['id'])['sudo'] === '0'
    && $userRow($copy['id'])['primary_group'] === '0' && $userRow($copy['id'])['class_key'] === 'modUser');
check('user: duplicate warns about what it did not copy and nothing about the profile', !strpos(json_encode($uAdmin->warnings), 'profile'));
$e = throws('user: duplicate name taken', ApiException::class, function () use ($call, $uAdmin, $ivanId, $secretPw) { $call($uAdmin, 'duplicate', array('kind' => 'user', 'id' => $ivanId, 'new_name' => 'копия', 'fields' => array('password' => $secretPw, 'email' => 'x@example.com'))); });
check('user: duplicate username is refused', $e && $e->errorCode === 'validation_failed');
$call($uAdmin, 'undo', array('changeset_id' => $copy['changeset_id'], 'confirm' => true));
check('user: undo of a duplicate removes it', $userRow($copy['id']) === null);

// safety rules
$serviceRow = $userRow(1);
$serviceProfile = $profileRow(1);
foreach (array(
    array('update', array('kind' => 'user', 'id' => 1, 'fields' => array('comment' => 'x'))),
    array('update', array('kind' => 'user', 'id' => 1, 'fields' => array('email' => 'x@example.com'), 'dry_run' => true)),
    array('delete', array('kind' => 'user', 'id' => 1)),
    array('delete', array('kind' => 'user', 'id' => 1, 'dry_run' => true)),
    array('duplicate', array('kind' => 'user', 'id' => 1, 'new_name' => 'service2', 'fields' => array('password' => $secretPw, 'email' => 'x@example.com'))),
) as $serviceCase) {
    $lastUser = $uAdmin->journal()->lastChangesetId();
    $e = throws("user: service user {$serviceCase[0]}", ApiException::class, function () use ($call, $uAdmin, $serviceCase) { $call($uAdmin, $serviceCase[0], $serviceCase[1]); });
    check("user: service user {$serviceCase[0]} is refused", $e && $e->errorCode === 'validation_failed' && $uAdmin->journal()->lastChangesetId() === $lastUser && $userRow(1) === $serviceRow && $profileRow(1) === $serviceProfile);
}
$uSite->options['modxmcp.service_user_id'] = 700;
$e = throws('user: the configured service user is protected', ApiException::class, function () use ($call, $uAdmin) { $call($uAdmin, 'update', array('kind' => 'user', 'id' => 700, 'fields' => array('comment' => 'x'))); });
check('user: the service user is the configured one, not id 1', $e && $e->errorCode === 'validation_failed');
$call($uAdmin, 'update', array('kind' => 'user', 'id' => 1, 'fields' => array('comment' => 'теперь обычный sudo')));
check('user: a former service user is an ordinary sudo user', $profileRow(1)['comment'] === 'теперь обычный sudo' && $userRow(1)['sudo'] === '1');
$uSite->options['modxmcp.service_user_id'] = 1;
foreach (array(
    array('delete', array('kind' => 'user', 'id' => 700)),
    array('delete', array('kind' => 'user', 'id' => 700, 'dry_run' => true)),
    array('duplicate', array('kind' => 'user', 'id' => 700, 'new_name' => 'копия-super', 'fields' => array('password' => $secretPw, 'email' => 'x2@example.com'))),
    array('update', array('kind' => 'user', 'id' => 700, 'fields' => array('username' => 'другое-имя'))),
    array('update', array('kind' => 'user', 'id' => 700, 'fields' => array('active' => false))),
    array('update', array('kind' => 'user', 'id' => 700, 'fields' => array('blocked' => true))),
    array('update', array('kind' => 'user', 'id' => 700, 'fields' => array('password' => $secretPw2))),
    array('update', array('kind' => 'user', 'id' => 700, 'fields' => array('password' => $secretPw2), 'dry_run' => true)),
) as $sudoCase) {
    $before700 = array($userRow(700), $profileRow(700));
    $lastUser = $uAdmin->journal()->lastChangesetId();
    $e = throws("user: sudo user {$sudoCase[0]}", ApiException::class, function () use ($call, $uAdmin, $sudoCase) { $call($uAdmin, $sudoCase[0], $sudoCase[1]); });
    check("user: sudo user {$sudoCase[0]} " . json_encode(array_diff_key($sudoCase[1], array('kind' => 1, 'id' => 1)), JSON_UNESCAPED_UNICODE) . ' is refused',
        $e && $e->errorCode === 'validation_failed' && !$leaks($e->getMessage()) && $uAdmin->journal()->lastChangesetId() === $lastUser && $before700 === array($userRow(700), $profileRow(700)));
}
$same = $call($uAdmin, 'update', array('kind' => 'user', 'id' => 700, 'fields' => array('active' => true, 'blocked' => false, 'username' => 'суперпользователь', 'fullname' => 'Суперпользователь')));
check('user: the same login values on a sudo user are no change, contact data may change', $same['changed'] === array('fullname') && $userRow(700)['sudo'] === '1');
$undoSuper = $call($uAdmin, 'undo', array('changeset_id' => $same['changeset_id'], 'confirm' => true));
check('user: a sudo user\'s contact change can be undone', $undoSuper['performed'] && $profileRow(700)['fullname'] === '');
// MODX refuses to delete oneself
$uSite->user = $uSite->getObject('modUser', 710);
$e = throws('user: deleting oneself', ApiException::class, function () use ($call, $uAdmin) { $call($uAdmin, 'delete', array('kind' => 'user', 'id' => 710)); });
check('user: MODX refusal to delete oneself passes through', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'own user') !== false && $userRow(710) !== null);
$uSite->user = null;
// MODX refuses to delete the last Administrator
$uSite->store('modUserGroupMember', array('id' => 91, 'user_group' => 1, 'member' => 710, 'role' => 2, 'rank' => 0));
$e = throws('user: last administrator', ApiException::class, function () use ($call, $uAdmin) { $call($uAdmin, 'delete', array('kind' => 'user', 'id' => 710)); });
check('user: MODX refusal to delete the last administrator passes through', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'Administrator') !== false && $userRow(710) !== null);
$uSite->removeCollection('modUserGroupMember', array('id' => 91));

// side doors: undo of a change to a protected user, and unknown fields
$e = throws('user: unknown field', ApiException::class, function () use ($call, $uAdmin, $ivanId) { $call($uAdmin, 'update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('cachepwd' => 'x'))); });
check('user: hash columns other than password are unknown fields', $e && $e->errorCode === 'validation_failed');

// scopes and groups
foreach (array('read', 'content', 'develop') as $scopeName) {
    $actor = $uActor($scopeName);
    foreach (array(
        array('list', array('kind' => 'user')), array('get', array('kind' => 'user', 'id' => $ivanId)),
        array('create', array('kind' => 'user', 'fields' => array('username' => 'z', 'password' => $secretPw, 'email' => 'z@example.com'))),
        array('update', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('comment' => 'z'))), array('delete', array('kind' => 'user', 'id' => $ivanId)),
        array('duplicate', array('kind' => 'user', 'id' => $ivanId, 'fields' => array('password' => $secretPw))),
    ) as $scopeCase) {
        $e = throws("user: {$scopeName} token {$scopeCase[0]}", ApiException::class, function () use ($call, $actor, $scopeCase) { $call($actor, $scopeCase[0], $scopeCase[1]); });
        check("user: {$scopeName} token cannot {$scopeCase[0]} users", $e && $e->errorCode === 'forbidden_scope');
    }
    $e = throws("user: {$scopeName} token describe", ApiException::class, function () use ($call, $actor) { $call($actor, 'describe', array('kind' => 'user')); });
    check("user: {$scopeName} token cannot describe users", $e && in_array($e->errorCode, array('unavailable', 'forbidden_scope'), true));
}
$readDiff = $call($uActor('read'), 'history_diff', array('changeset_id' => $changedPassword['changeset_id']));
check('user: a read token does not get the diff of a user', !isset($readDiff['objects'][0]['changed']) && !$leaks($readDiff));
$uSite->options['modxmcp.disabled_groups'] = 'access';
foreach (array('list', 'get', 'create') as $groupAction) {
    $args = array('create' => array('kind' => 'user', 'fields' => array('username' => 'z', 'password' => $secretPw)), 'get' => array('kind' => 'user', 'id' => $ivanId), 'list' => array('kind' => 'user'))[$groupAction];
    $e = throws("user: access group off, {$groupAction}", ApiException::class, function () use ($call, $uAdmin, $groupAction, $args) { $call($uAdmin, $groupAction, $args); });
    check("user: {$groupAction} is unavailable when the access group is off", $e && $e->errorCode === 'unavailable');
}
$e = throws('user: access group off, describe', ApiException::class, function () use ($call, $uAdmin) { $call($uAdmin, 'describe', array('kind' => 'user')); });
check('user: describe is unavailable when the access group is off', $e && $e->errorCode === 'unavailable');
$uSite->options['modxmcp.disabled_groups'] = '';
$planOnly = $call($uActor('admin', array('plan_only' => true)), 'create', array('kind' => 'user', 'fields' => array('username' => 'план', 'password' => $secretPw, 'email' => 'plan@example.com')));
check('user: a plan-only token creates nothing', !empty($planOnly['planned']) && $uSite->getCount('modUser', array('username' => 'план')) === 0 && !$leaks($planOnly));
// no secret in the whole journal summary and the stored changeset summaries of this section
$journalDump = json_encode($uAdmin->journal()->changesets(array(), 100), JSON_UNESCAPED_UNICODE);
check('user: no changeset summary of the journal holds a password', strpos($journalDump, $secretPw) === false && strpos($journalDump, $secretPw2) === false && strpos($journalDump, 'fakehash') === false);

// Review additions: logins do not change a user's revision; the service user keeps its groups.
list($loginSite, $loginDev) = $retentionSite();
$loginAdmin = new Context($loginSite, array('scope' => 'admin') + $loginDev->token, $loginDev->corePath, function () {});
$loginSite->options['modxmcp.service_user_id'] = 99;
$loginUser = $call($loginAdmin, 'create', array('kind' => 'user', 'fields' => array('username' => 'ivan', 'password' => 'Пароль-123456', 'email' => 'ivan@example.ru', 'fullname' => 'Иван')));
$loginBefore = $call($loginAdmin, 'get', array('kind' => 'user', 'id' => $loginUser['id']))['revision'];
$profileRow = null;
foreach ($loginSite->getCollection('modUserProfile', array('internalKey' => $loginUser['id'])) as $row) { $profileRow = $row; }
$profileRow->fromArray(array('lastlogin' => 1700000000, 'thislogin' => 1700000500, 'logincount' => 7, 'sessionid' => 'abc', 'failedlogincount' => 1), '', false, true);
$profileRow->save();
$loginSite->store('modUser', array('session_stale' => 'a:1:{i:0;s:3:"web";}') + $loginSite->row('modUser', $loginUser['id']));
check('users: a login does not change the revision', $call($loginAdmin, 'get', array('kind' => 'user', 'id' => $loginUser['id']))['revision'] === $loginBefore);
$loginEdit = $call($loginAdmin, 'update', array('kind' => 'user', 'id' => $loginUser['id'], 'fields' => array('fullname' => 'Иван Петров')));
check('users: no outside change was recorded for the login', $call($loginAdmin, 'history', array('source' => 'external'))['changesets'] === array());
$call($loginAdmin, 'undo', array('changeset_id' => $loginEdit['changeset_id'], 'confirm' => true));
$profileAfter = null;
foreach ($loginSite->getCollection('modUserProfile', array('internalKey' => $loginUser['id'])) as $row) { $profileAfter = $row; }
check('users: undo keeps the login statistics', (int) $profileAfter->get('logincount') === 7 && (int) $profileAfter->get('lastlogin') === 1700000000
    && $call($loginAdmin, 'get', array('kind' => 'user', 'id' => $loginUser['id']))['fields']['fullname'] === 'Иван');
$loginSite->store('modUserGroup', array('id' => 1, 'name' => 'Administrator', 'description' => '', 'parent' => 0, 'rank' => 0, 'dashboard' => 1));
$e = throws('users: the service user cannot leave a group', ApiException::class, function () use ($call, $loginAdmin) {
    $call($loginAdmin, 'invoke', array('kind' => 'user_group', 'id' => 1, 'op' => 'remove_member', 'args' => array('user' => 99)));
});
check('users: the refusal names the service user', $e && $e->errorCode === 'validation_failed' && strpos($e->getMessage(), 'service user') !== false);

// --- Access kinds: access_policy, policy_template, context_access, resourcegroup_access (task 18) ---
$aclDir = $siteDir . '/acl';
mkdir($aclDir . '/core', 0700, true);
$aSite = fakeSite($aclDir);
$aActor = function ($scope, array $token = array()) use ($aSite, $dev) {
    $c = new Context($aSite, $token + array('scope' => $scope) + $dev->token, $dev->corePath, function () {});
    $c->sessionId = 'session-access0001';
    return $c;
};
$aAdmin = $aActor('admin');
$aTables = array('modAccessPolicy', 'modAccessPolicyTemplate', 'modAccessPermission', 'modAccessContext', 'modAccessResourceGroup');
$aState = function () use ($aSite, $aTables) { $dump = array(); foreach ($aTables as $table) { $dump[$table] = $aSite->tableDump($table); } return json_encode($dump); };
$aLast = function () use ($aAdmin) { return $aAdmin->journal()->lastChangesetId(); };
$aFlushes = function () use ($aSite) { return $aSite->getCacheManager()->flushes; };
// A refused write: the right error, no changeset, nothing changed on the site.
$aRefuse = function ($label, callable $fn, $code = 'validation_failed') use ($aState, $aLast) {
    $before = $aState();
    $last = $aLast();
    $e = throws("access: {$label}", ApiException::class, $fn);
    check("access: {$label} is {$code} and changes nothing", $e && $e->errorCode === $code && $aLast() === $last && $aState() === $before);
    return $e;
};
$aRow = function ($class, $id) use ($aSite) { return $aSite->row($class, $id); };
$aPlantPolicy = function ($id, $name, $template, array $permissions, $parent = 0) use ($aSite) {
    $aSite->store('modAccessPolicy', array('id' => $id, 'name' => $name, 'description' => '', 'parent' => $parent, 'template' => $template,
        'class' => '', 'data' => $permissions, 'lexicon' => 'permissions'));
};
$aPlantTemplate = function ($id, $name, array $permissions) use ($aSite) {
    $aSite->store('modAccessPolicyTemplate', array('id' => $id, 'name' => $name, 'description' => '', 'template_group' => 0, 'lexicon' => 'permissions'));
    foreach ($permissions as $permission) {
        $aSite->store('modAccessPermission', array('id' => $aSite->nextId('modAccessPermission'), 'template' => $id, 'name' => $permission, 'description' => $permission . '_desc', 'value' => 1));
    }
};
$aPlantAcl = function ($class, $id, $principal, $target, $policy, array $more = array()) use ($aSite) {
    $aSite->store($class, $more + array('id' => $id, 'target' => $target, 'principal_class' => 'modUserGroup', 'principal' => $principal, 'authority' => 0, 'policy' => $policy)
        + ($class === 'modAccessResourceGroup' ? array('context_key' => 'web') : array()));
};
foreach (array(1 => 'Administrator', 2 => 'Редакторы', 3 => 'Авторы') as $groupId => $groupName) {
    $aSite->store('modUserGroup', array('id' => $groupId, 'name' => $groupName, 'description' => '', 'parent' => 0, 'rank' => 0, 'dashboard' => 1));
}
foreach (array('web' => 'Web', 'mgr' => 'Manager', 'shop' => 'Магазин') as $contextKey => $contextName) {
    $aSite->store('modContext', array('key' => $contextKey, 'name' => $contextName, 'description' => '', 'rank' => 0));
}
$aSite->store('modResourceGroup', array('id' => 1, 'name' => 'Закрытые'));
$aSite->store('modResourceGroup', array('id' => 2, 'name' => 'Public'));
// What MODX installs (ids as in the install vehicles), a custom template and policies, and the Administrator group's own rules.
$aPlantTemplate(1, 'ContextTemplate', array('load', 'list', 'view', 'save'));
$aPlantTemplate(2, 'ResourceTemplate', array('load', 'list', 'view', 'save', 'delete'));
$aPlantTemplate(40, 'Шаблон гостя', array('load', 'view'));
$aPlantTemplate(41, 'Шаблон контекста', array('load', 'list'));
$aPlantTemplate(42, 'Шаблон со встроенной политикой', array('load'));
$aPlantPolicy(1, 'Resource', 2, array('load' => true, 'list' => true, 'view' => true, 'save' => true, 'delete' => true));
$aPlantPolicy(2, 'Administrator', 1, array('load' => true, 'list' => true, 'view' => true, 'save' => true));
$aPlantPolicy(3, 'Load Only', 1, array('load' => true));
$aPlantPolicy(11, 'Context', 1, array('load' => true, 'view' => true));
$aPlantPolicy(30, 'Гость', 40, array('load' => true, 'view' => true));            // used by a resource group rule of group 1
$aPlantPolicy(31, 'Контекстный гость', 41, array('load' => true));                // used by a context rule of group 1
$aPlantPolicy(32, 'Дочерний гость', 41, array('load' => true), 31);              // a child of policy 31
$aPlantPolicy(33, 'load only', 42, array('load' => true));                        // a built-in name in other case, in a custom template
$aPlantPolicy(34, 'Родитель без защиты', 41, array('load' => true));
$aPlantPolicy(35, 'Дитя под защитой', 41, array('load' => true), 34);
$aPlantAcl('modAccessContext', 1, 1, 'web', 2);
$aPlantAcl('modAccessContext', 2, 1, 'mgr', 11);
$aPlantAcl('modAccessContext', 3, 1, 'shopx', 31);                                // group 1 uses policy 31 (a context that does not matter)
$aPlantAcl('modAccessResourceGroup', 1, 1, 1, 30);
$aPlantAcl('modAccessResourceGroup', 2, 1, 2, 35);                                // group 1 uses policy 35, the child of 34
$aAdminRules = $aRow('modAccessContext', 1);

// ---- describe, scopes and groups ----
foreach (array('access_policy', 'policy_template', 'context_access', 'resourcegroup_access') as $kindName) {
    $described = $call($aAdmin, 'describe', array('kind' => $kindName));
    check("access: describe {$kindName}", $described['group'] === 'access' && $described['read_scope'] === 'admin' && $described['write_scope'] === 'admin' && $described['key_type'] === 'integer');
}
$aFields = function ($kindName) use ($call, $aAdmin) { return array_column($call($aAdmin, 'describe', array('kind' => $kindName))['fields'], null, 'name'); };
$policyFields = $aFields('access_policy');
check('access: policy fields', $policyFields['permissions']['type'] === 'object' && $policyFields['name']['required'] === true && $policyFields['template']['required'] === true
    && $policyFields['name']['list'] === true && $policyFields['template']['list'] === true && $call($aAdmin, 'describe', array('kind' => 'access_policy'))['name_field'] === 'name');
$templateDescribed = $call($aAdmin, 'describe', array('kind' => 'policy_template'));
check('access: template relation permissions is read-only', array_column($templateDescribed['relations'], 'name') === array('permissions') && $templateDescribed['relations'][0]['writable'] === false);
$contextFields = $aFields('context_access');
check('access: context rule fields', $call($aAdmin, 'describe', array('kind' => 'context_access'))['name_field'] === 'id' && $contextFields['target']['type'] === 'string' && $contextFields['target']['required'] === true
    && $contextFields['label']['read_only'] === true && $contextFields['label']['list'] === true && $contextFields['principal_class']['read_only'] === true
    && $contextFields['principal']['required'] === true && $contextFields['authority']['required'] === true && $contextFields['policy']['required'] === true);
$groupRuleFields = $aFields('resourcegroup_access');
check('access: resource group rule fields', $groupRuleFields['target']['type'] === 'integer' && $groupRuleFields['context_key']['list'] === true && $groupRuleFields['label']['read_only'] === true);
foreach (array('read', 'content', 'develop') as $scope) {
    foreach (array('access_policy', 'policy_template', 'context_access', 'resourcegroup_access') as $kindName) {
        foreach (array('list' => array('kind' => $kindName), 'get' => array('kind' => $kindName, 'id' => 1), 'create' => array('kind' => $kindName, 'fields' => array('name' => 'x'))) as $action => $args) {
            $e = throws("access: {$scope} token, {$action} {$kindName}", ApiException::class, function () use ($call, $aActor, $scope, $action, $args) { $call($aActor($scope), $action, $args); });
            check("access: {$scope} token cannot {$action} {$kindName}", $e && $e->errorCode === 'forbidden_scope');
        }
    }
}
check('access: an admin token lists the four kinds, a develop token does not', count(array_intersect(array('access_policy', 'policy_template', 'context_access', 'resourcegroup_access'),
    array_column($call($aActor('develop'), 'describe')['kinds'], 'kind'))) === 0);
$aSite->options['modxmcp.disabled_groups'] = 'access';
foreach (array('access_policy', 'policy_template', 'context_access', 'resourcegroup_access') as $kindName) {
    foreach (array('list', 'describe') as $action) {
        $e = throws("access: disabled group blocks {$kindName} {$action}", ApiException::class, function () use ($call, $aAdmin, $kindName, $action) { $call($aAdmin, $action, array('kind' => $kindName)); });
        check("access: {$kindName} is unavailable when the access group is off", $e && $e->errorCode === 'unavailable');
    }
}
$aSite->options['modxmcp.disabled_groups'] = '';

// ---- access_policy: create, get, list ----
$flushBase = $aFlushes();
$editors = $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Редактор контента', 'template' => 2, 'description' => 'Правит страницы')));
$editorsId = $editors['id'];
check('access: policy create', is_int($editorsId) && $editors['changeset_id'] !== null && $editors['reversible'] === true && preg_match('/^[a-f0-9]{64}$/', $editors['revision'])
    && $editors['changed'] === array('name', 'description', 'template', 'lexicon', 'permissions') && $aFlushes() > $flushBase);
$editorsGot = $call($aAdmin, 'get', array('kind' => 'access_policy', 'id' => $editorsId));
check('access: a new policy starts with the template permissions on, keys sorted', json_encode($editorsGot['fields']['permissions']) === '{"delete":true,"list":true,"load":true,"save":true,"view":true}'
    && $editorsGot['fields']['name'] === 'Редактор контента' && $editorsGot['fields']['template'] === 2 && $editorsGot['fields']['parent'] === 0 && $editorsGot['revision'] === $editors['revision']);
$aRefuse('policy get of a name that does not exist', function () use ($call, $aAdmin) { $call($aAdmin, 'get', array('kind' => 'access_policy', 'name' => 'нет такой')); }, 'not_found');
check('access: policy get by name', $call($aAdmin, 'get', array('kind' => 'access_policy', 'name' => 'Редактор контента'))['id'] === $editorsId);
$withMap = $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Только чтение', 'template' => 2, 'permissions' => array('view' => true, 'load' => true, 'save' => false))));
$withMapGot = $call($aAdmin, 'get', array('kind' => 'access_policy', 'id' => $withMap['id']));
check('access: policy create with permissions as an object', json_encode($withMapGot['fields']['permissions']) === '{"load":true,"save":false,"view":true}'
    && $aRow('modAccessPolicy', $withMap['id'])['data'] === '{"load":true,"save":false,"view":true}' && in_array('permissions', $withMap['changed'], true));
$emptyMap = $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Без прав', 'template' => 2, 'permissions' => new stdClass())));
$emptyGot = $call($aAdmin, 'get', array('kind' => 'access_policy', 'id' => $emptyMap['id']));
check('access: an empty permissions object stays empty and encodes as {}', json_encode($emptyGot['fields']['permissions']) === '{}' && json_encode($call($aAdmin, 'get', array('kind' => 'access_policy', 'id' => $emptyMap['id'], 'fields' => array('permissions')))['fields']) === '{"permissions":{}}');
foreach (array(
    'a non-boolean value' => array('load' => 'yes'),
    'a number as value' => array('load' => 1),
    'null as value' => array('load' => null),
    'a nested object' => array('load' => array('x' => true)),
    'a list' => array(true, false),
    'an empty name' => array('' => true),
) as $label => $bad) {
    $aRefuse("permissions with {$label} (create)", function () use ($call, $aAdmin, $bad) { $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Плохая', 'template' => 2, 'permissions' => $bad))); });
    $aRefuse("permissions with {$label} (update)", function () use ($call, $aAdmin, $bad, $editorsId) { $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $editorsId, 'fields' => array('permissions' => $bad))); });
}
$aRefuse('permissions that are a string', function () use ($call, $aAdmin, $editorsId) { $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $editorsId, 'fields' => array('permissions' => '{"load":true}'))); });
$aRefuse('a policy without a template', function () use ($call, $aAdmin) { $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Без шаблона'))); });
$aRefuse('a policy with an unknown template passes the MODX refusal on', function () use ($call, $aAdmin) { $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Сирота', 'template' => 999))); });
$aRefuse('a duplicate policy name is MODX\'s refusal', function () use ($call, $aAdmin) { $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Редактор контента', 'template' => 2))); });
$policyList = $call($aAdmin, 'list', array('kind' => 'access_policy', 'query' => 'контент'));
check('access: policy list with a Cyrillic query', $policyList['total'] === 1 && array_keys($policyList['items'][0]) === array('id', 'name', 'template') && $policyList['items'][0]['name'] === 'Редактор контента');
check('access: policy list where template', $call($aAdmin, 'list', array('kind' => 'access_policy', 'where' => array('template' => 42)))['total'] === 1);
$aRefuse('list where permissions', function () use ($call, $aAdmin) { $call($aAdmin, 'list', array('kind' => 'access_policy', 'where' => array('permissions' => 'x'))); });
check('access: policy list can ask for permissions', $call($aAdmin, 'list', array('kind' => 'access_policy', 'where' => array('template' => 42), 'fields' => array('name', 'permissions')))['items'][0]['permissions'] == (object) array('load' => true));

// ---- access_policy: update replaces the map, diff, dry run, undo ----
$dataBefore = $aRow('modAccessPolicy', $editorsId)['data'];
$describe = $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $editorsId, 'fields' => array('description' => 'Редактирование страниц')));
check('access: updating another field leaves the permissions text alone', $describe['changed'] === array('description') && $aRow('modAccessPolicy', $editorsId)['data'] === $dataBefore);
$flushBase = $aFlushes();
$dryMap = $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $editorsId, 'dry_run' => true, 'fields' => array('permissions' => array('view' => true, 'save' => true, 'load' => true, 'list' => true, 'delete' => true))));
check('access: dry run sees the same map in another key order as no change', $dryMap['would_change'] === array() && $aFlushes() === $flushBase);
$dryMap = $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $editorsId, 'dry_run' => true, 'fields' => array('permissions' => array('view' => true))));
check('access: dry run names a changed map and writes nothing', $dryMap['would_change'] === array('permissions') && $aRow('modAccessPolicy', $editorsId)['data'] === $dataBefore);
$replaced = $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $editorsId, 'fields' => array('permissions' => array('view' => true, 'load' => true, 'publish' => false))));
check('access: update replaces the whole map', $replaced['changed'] === array('permissions') && $replaced['changeset_id'] !== null && $aFlushes() > $flushBase
    && json_encode($call($aAdmin, 'get', array('kind' => 'access_policy', 'id' => $editorsId))['fields']['permissions']) === '{"load":true,"publish":false,"view":true}');
$mapDiff = $call($aAdmin, 'history_diff', array('changeset_id' => $replaced['changeset_id']));
check('access: the diff shows the old and the new map as objects', json_encode($mapDiff['objects'][0]['changed']['permissions']) === '{"old":{"delete":true,"list":true,"load":true,"save":true,"view":true},"new":{"load":true,"publish":false,"view":true}}');
$sameMap = $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $editorsId, 'fields' => array('permissions' => array('publish' => false, 'load' => true, 'view' => true))));
check('access: sending the same map again is no change', $sameMap['changeset_id'] === null && $sameMap['changed'] === array());
$flushBase = $aFlushes();
$undoMap = $call($aAdmin, 'undo', array('changeset_id' => $replaced['changeset_id'], 'confirm' => true));
check('access: undo of a permissions change restores the old map and flushes the permissions', json_encode($call($aAdmin, 'get', array('kind' => 'access_policy', 'id' => $editorsId))['fields']['permissions']) === '{"delete":true,"list":true,"load":true,"save":true,"view":true}'
    && $aFlushes() > $flushBase);
$undoUndo = $call($aAdmin, 'undo', array('changeset_id' => $undoMap['changeset_id'], 'confirm' => true));
check('access: undo of the undo brings the replaced map back', json_encode($call($aAdmin, 'get', array('kind' => 'access_policy', 'id' => $editorsId))['fields']['permissions']) === '{"load":true,"publish":false,"view":true}');

// ---- access_policy: duplicate, delete, undo of each ----
$copy = $call($aAdmin, 'duplicate', array('kind' => 'access_policy', 'id' => $editorsId, 'new_name' => 'Копия редактора'));
$copyGot = $call($aAdmin, 'get', array('kind' => 'access_policy', 'id' => $copy['id']));
check('access: policy duplicate copies the permissions and the template', $copy['name'] === 'Копия редактора' && $copy['source_id'] === $editorsId
    && json_encode($copyGot['fields']['permissions']) === '{"load":true,"publish":false,"view":true}' && $copyGot['fields']['template'] === 2 && $copyGot['fields']['description'] === 'Редактирование страниц');
$copy2 = $call($aAdmin, 'duplicate', array('kind' => 'access_policy', 'id' => $editorsId));
check('access: policy duplicate default name', $copy2['name'] === 'Редактор контента copy');
$call($aAdmin, 'undo', array('changeset_id' => $copy2['changeset_id'], 'confirm' => true));
check('access: undo of a duplicate removes the copy', $aSite->getCount('modAccessPolicy', array('name' => 'Редактор контента copy')) === 0);
$flushBase = $aFlushes();
$deleted = $call($aAdmin, 'delete', array('kind' => 'access_policy', 'id' => $copy['id']));
check('access: policy delete', $deleted['deleted'] === true && $aSite->getCount('modAccessPolicy', array('id' => $copy['id'])) === 0 && $aFlushes() > $flushBase);
$call($aAdmin, 'undo', array('changeset_id' => $deleted['changeset_id'], 'confirm' => true));
check('access: undo of a delete brings the policy back with its id and map', json_encode($call($aAdmin, 'get', array('kind' => 'access_policy', 'id' => $copy['id']))['fields']['permissions']) === '{"load":true,"publish":false,"view":true}'
    && $aFlushes() > $flushBase);
$fresh = $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Временная', 'template' => 1)));
$flushBase = $aFlushes();
$call($aAdmin, 'undo', array('changeset_id' => $fresh['changeset_id'], 'confirm' => true));
check('access: undo of a create removes the policy and flushes', $aSite->getCount('modAccessPolicy', array('id' => $fresh['id'])) === 0 && $aFlushes() > $flushBase);

// ---- access_policy: delete cascades to child policies, and their undo ----
$parent = $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Родительская', 'template' => 1)));
$child = $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Дочерняя', 'template' => 1, 'parent' => $parent['id'], 'permissions' => array('load' => true))));
$grandchild = $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Внучка', 'template' => 1, 'parent' => $child['id'])));
$cascadeDelete = $call($aAdmin, 'delete', array('kind' => 'access_policy', 'id' => $parent['id']));
check('access: deleting a policy deletes its children (MODX composite) and journals each', $aSite->getCount('modAccessPolicy', array('id' => $child['id'])) === 0 && $aSite->getCount('modAccessPolicy', array('id' => $grandchild['id'])) === 0
    && count($call($aAdmin, 'history_diff', array('changeset_id' => $cascadeDelete['changeset_id']))['objects']) === 3);
$call($aAdmin, 'undo', array('changeset_id' => $cascadeDelete['changeset_id'], 'confirm' => true));
check('access: undo of the delete brings parent, child and grandchild back as themselves', $aRow('modAccessPolicy', $parent['id'])['name'] === 'Родительская'
    && (int) $aRow('modAccessPolicy', $child['id'])['parent'] === $parent['id'] && (int) $aRow('modAccessPolicy', $grandchild['id'])['parent'] === $child['id']
    && $aRow('modAccessPolicy', $child['id'])['data'] === '{"load":true}');
$dryCascade = $call($aAdmin, 'delete', array('kind' => 'access_policy', 'id' => $parent['id'], 'dry_run' => true));
check('access: delete dry run changes nothing', $dryCascade['dry_run'] === true && $aSite->getCount('modAccessPolicy', array('id' => $parent['id'])) === 1);
// MODX would leave a rule that uses the policy dangling (architect's review): the delete is refused.
$aPlantAcl('modAccessContext', 50, 2, 'shop', $child['id'], array('authority' => 5));
$aRefuse('delete of a policy still used by a rule', function () use ($call, $aAdmin, $child) { $call($aAdmin, 'delete', array('kind' => 'access_policy', 'id' => $child['id'])); });
$aRefuse('delete of a parent whose child policy is used by a rule', function () use ($call, $aAdmin, $parent) { $call($aAdmin, 'delete', array('kind' => 'access_policy', 'id' => $parent['id'])); });
check('access: the used policy and its rule are untouched', $aRow('modAccessContext', 50) !== null && $aSite->getCount('modAccessPolicy', array('id' => $child['id'])) === 1);
$aSite->drop('modAccessContext', $aRow('modAccessContext', 50));

// ---- access_policy: safety rules ----
foreach (array('Administrator' => 2, 'Resource' => 1, 'Context' => 11, 'Load Only' => 3, 'load only (other case)' => 33) as $label => $builtinId) {
    $aRefuse("update of the built-in policy {$label}", function () use ($call, $aAdmin, $builtinId) { $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $builtinId, 'fields' => array('description' => 'x'))); });
    $aRefuse("permissions of the built-in policy {$label}", function () use ($call, $aAdmin, $builtinId) { $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $builtinId, 'fields' => array('permissions' => array('load' => false)))); });
    $aRefuse("delete of the built-in policy {$label}", function () use ($call, $aAdmin, $builtinId) { $call($aAdmin, 'delete', array('kind' => 'access_policy', 'id' => $builtinId)); });
    $aRefuse("dry-run update of the built-in policy {$label}", function () use ($call, $aAdmin, $builtinId) { $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $builtinId, 'dry_run' => true, 'fields' => array('description' => 'x'))); });
    $aRefuse("dry-run delete of the built-in policy {$label}", function () use ($call, $aAdmin, $builtinId) { $call($aAdmin, 'delete', array('kind' => 'access_policy', 'id' => $builtinId, 'dry_run' => true)); });
}
$adminCopy = $call($aAdmin, 'duplicate', array('kind' => 'access_policy', 'id' => 2));
check('access: a built-in policy can be duplicated and the copy is an ordinary policy', $adminCopy['name'] === 'Administrator copy'
    && $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $adminCopy['id'], 'fields' => array('permissions' => array('load' => true))))['changed'] === array('permissions')
    && $call($aAdmin, 'delete', array('kind' => 'access_policy', 'id' => $adminCopy['id']))['deleted'] === true);
foreach (array('used by a resource group rule of group 1' => 30, 'used by a context rule of group 1' => 31) as $label => $usedId) {
    $aRefuse("update of a policy {$label}", function () use ($call, $aAdmin, $usedId) { $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $usedId, 'fields' => array('description' => 'x'))); });
    $aRefuse("permissions of a policy {$label}", function () use ($call, $aAdmin, $usedId) { $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $usedId, 'fields' => array('permissions' => array()))); });
    $aRefuse("delete of a policy {$label}", function () use ($call, $aAdmin, $usedId) { $call($aAdmin, 'delete', array('kind' => 'access_policy', 'id' => $usedId)); });
}
$usedCopy = $call($aAdmin, 'duplicate', array('kind' => 'access_policy', 'id' => 30, 'new_name' => 'Гость 2'));
check('access: a policy that group 1 uses can be duplicated, the copy is free', $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $usedCopy['id'], 'fields' => array('description' => 'ok')))['changed'] === array('description')
    && $call($aAdmin, 'delete', array('kind' => 'access_policy', 'id' => $usedCopy['id']))['deleted'] === true);
$aRefuse('delete of a policy whose child group 1 uses', function () use ($call, $aAdmin) { $call($aAdmin, 'delete', array('kind' => 'access_policy', 'id' => 34)); });
$aRefuse('delete of a policy whose child group 1 uses (dry run)', function () use ($call, $aAdmin) { $call($aAdmin, 'delete', array('kind' => 'access_policy', 'id' => 34, 'dry_run' => true)); });
$aPlantPolicy(36, 'Родитель встроенной', 1, array());
$aPlantPolicy(37, 'Developer', 1, array(), 36);
$aRefuse('delete of the parent of a built-in policy', function () use ($call, $aAdmin) { $call($aAdmin, 'delete', array('kind' => 'access_policy', 'id' => 36)); });
// the policy of group 3 only is free
$aPlantAcl('modAccessContext', 60, 3, 'shop', $editorsId);
check('access: a policy that another group uses can still be changed', $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $editorsId, 'fields' => array('description' => 'для авторов')))['changed'] === array('description'));
$aSite->drop('modAccessContext', $aRow('modAccessContext', 60));

// undo doors for policies
$door = $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Дверь', 'template' => 1)));
$doorEdit = $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $door['id'], 'fields' => array('description' => 'правка')));
$aPlantAcl('modAccessContext', 70, 1, 'shopx', $door['id']);   // a person made group 1 use it in the manager
$aRefuse('undo of an edit after group 1 started to use the policy', function () use ($call, $aAdmin, $doorEdit) { $call($aAdmin, 'undo', array('changeset_id' => $doorEdit['changeset_id'], 'confirm' => true)); }, 'not_reversible');
$aRefuse('undo of the create after group 1 started to use the policy', function () use ($call, $aAdmin, $door) { $call($aAdmin, 'undo', array('changeset_id' => $door['changeset_id'], 'confirm' => true, 'force' => true)); }, 'not_reversible');
$aRefuse('restoring the state of a policy that group 1 uses', function () use ($aSite, $aAdmin, $door) {
    $kind = $aAdmin->kinds->get('access_policy');
    \ModxMCP\Journal\Snapshot::restore($aSite, $kind, $door['id'], \ModxMCP\Journal\Snapshot::capture($aSite, $kind, $door['id']));
}, 'not_reversible');
$aSite->drop('modAccessContext', $aRow('modAccessContext', 70));
$rename = $call($aAdmin, 'update', array('kind' => 'access_policy', 'id' => $door['id'], 'fields' => array('description' => 'второй')));
$renamed = $aRow('modAccessPolicy', $door['id']);
$aSite->store('modAccessPolicy', array('name' => 'Developer') + $renamed);   // renamed to a built-in name outside the API
$aRefuse('undo of an edit after the policy was renamed to a built-in name', function () use ($call, $aAdmin, $rename) { $call($aAdmin, 'undo', array('changeset_id' => $rename['changeset_id'], 'confirm' => true, 'force' => true)); }, 'not_reversible');
$aSite->store('modAccessPolicy', array('name' => 'Дверь') + $aRow('modAccessPolicy', $door['id']));

// ---- policy_template ----
$tpl = $call($aAdmin, 'create', array('kind' => 'policy_template', 'fields' => array('name' => 'Шаблон редакторов', 'description' => 'Для редакторов', 'template_group' => 0)));
$tplId = $tpl['id'];
$flushBase = $aFlushes();
$tplGot = $call($aAdmin, 'get', array('kind' => 'policy_template', 'id' => $tplId));
check('access: template create and get', $tpl['changed'] === array('name', 'description', 'lexicon') && $tplGot['fields']['name'] === 'Шаблон редакторов' && $tplGot['relations']['permissions'] === array());
foreach (array('load', 'view') as $permission) {
    $aSite->store('modAccessPermission', array('id' => $aSite->nextId('modAccessPermission'), 'template' => $tplId, 'name' => $permission, 'description' => 'Право ' . $permission, 'value' => 1));
}
check('access: the permissions of a template are shown', $call($aAdmin, 'get', array('kind' => 'policy_template', 'id' => $tplId))['relations']['permissions']
    === array(array('name' => 'load', 'description' => 'Право load', 'value' => 1), array('name' => 'view', 'description' => 'Право view', 'value' => 1)));
$aRefuse('permissions of a template cannot be written', function () use ($call, $aAdmin, $tplId) { $call($aAdmin, 'update', array('kind' => 'policy_template', 'id' => $tplId, 'relations' => array('permissions' => array()))); });
check('access: template list', $call($aAdmin, 'list', array('kind' => 'policy_template', 'query' => 'редакторов'))['items'][0]['id'] === $tplId
    && array_keys($call($aAdmin, 'list', array('kind' => 'policy_template'))['items'][0]) === array('id', 'name', 'template_group'));
$tplUpd = $call($aAdmin, 'update', array('kind' => 'policy_template', 'id' => $tplId, 'fields' => array('description' => 'Новое описание', 'template_group' => 2)));
check('access: template update flushes the permissions', $tplUpd['changed'] === array('description', 'template_group') && $aFlushes() > $flushBase
    && $call($aAdmin, 'get', array('kind' => 'policy_template', 'id' => $tplId))['relations']['permissions'] !== array());
$tplDiff = $call($aAdmin, 'history_diff', array('changeset_id' => $tplUpd['changeset_id']));
check('access: template diff', $tplDiff['objects'][0]['changed']['description'] === array('old' => 'Для редакторов', 'new' => 'Новое описание'));
$call($aAdmin, 'undo', array('changeset_id' => $tplUpd['changeset_id'], 'confirm' => true));
check('access: undo of a template update', $call($aAdmin, 'get', array('kind' => 'policy_template', 'id' => $tplId))['fields']['description'] === 'Для редакторов');
$tplContext = $aActor('admin');
$tplCopy = $call($tplContext, 'duplicate', array('kind' => 'policy_template', 'id' => $tplId, 'new_name' => 'Копия шаблона'));
check('access: template duplicate (the permissions are not copied, and it says so)', $tplCopy['name'] === 'Копия шаблона' && in_array('Not copied: permissions', $tplContext->warnings, true)
    && $call($aAdmin, 'get', array('kind' => 'policy_template', 'id' => $tplCopy['id']))['relations']['permissions'] === array());
$call($aAdmin, 'undo', array('changeset_id' => $tplCopy['changeset_id'], 'confirm' => true));
check('access: undo of a template duplicate', $aSite->getCount('modAccessPolicyTemplate', array('name' => 'Копия шаблона')) === 0);
// delete: the template's policies and permissions go with it
$inTpl = $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Политика в шаблоне', 'template' => $tplId, 'permissions' => array('load' => true))));
$inTplChild = $call($aAdmin, 'create', array('kind' => 'access_policy', 'fields' => array('name' => 'Дочерняя в шаблоне', 'template' => $tplId, 'parent' => $inTpl['id'])));
$dryTpl = $call($aAdmin, 'delete', array('kind' => 'policy_template', 'id' => $tplId, 'dry_run' => true));
check('access: template delete dry run reports the permissions', $dryTpl['would_delete']['related_rows'] === array('permissions' => 2) && $aRow('modAccessPolicyTemplate', $tplId) !== null);
$flushBase = $aFlushes();
$tplDel = $call($aAdmin, 'delete', array('kind' => 'policy_template', 'id' => $tplId));
$tplDelDiff = $call($aAdmin, 'history_diff', array('changeset_id' => $tplDel['changeset_id']));
check('access: deleting a template removes its permissions and its policies, each journaled', $aSite->getCount('modAccessPermission', array('template' => $tplId)) === 0
    && $aSite->getCount('modAccessPolicy', array('template' => $tplId)) === 0 && count($tplDelDiff['objects']) === 3 && $aFlushes() > $flushBase
    && array_count_values(array_column($tplDelDiff['objects'], 'kind')) == array('policy_template' => 1, 'access_policy' => 2));
$flushBase = $aFlushes();
$call($aAdmin, 'undo', array('changeset_id' => $tplDel['changeset_id'], 'confirm' => true));
check('access: undo of the template delete restores the template, its permissions and its policies', $aSite->getCount('modAccessPermission', array('template' => $tplId)) === 2
    && (int) $aRow('modAccessPolicy', $inTpl['id'])['template'] === $tplId && (int) $aRow('modAccessPolicy', $inTplChild['id'])['parent'] === $inTpl['id']
    && $aRow('modAccessPolicy', $inTpl['id'])['data'] === '{"load":true}' && $aFlushes() > $flushBase);
$tplFresh = $call($aAdmin, 'create', array('kind' => 'policy_template', 'fields' => array('name' => 'Шаблон-однодневка')));
$call($aAdmin, 'undo', array('changeset_id' => $tplFresh['changeset_id'], 'confirm' => true));
check('access: undo of a template create', $aSite->getCount('modAccessPolicyTemplate', array('name' => 'Шаблон-однодневка')) === 0);
// safety
foreach (array('ContextTemplate' => 1, 'ResourceTemplate' => 2) as $label => $builtinTpl) {
    $aRefuse("update of the built-in template {$label}", function () use ($call, $aAdmin, $builtinTpl) { $call($aAdmin, 'update', array('kind' => 'policy_template', 'id' => $builtinTpl, 'fields' => array('description' => 'x'))); });
    $aRefuse("delete of the built-in template {$label}", function () use ($call, $aAdmin, $builtinTpl) { $call($aAdmin, 'delete', array('kind' => 'policy_template', 'id' => $builtinTpl)); });
    $aRefuse("dry-run delete of the built-in template {$label}", function () use ($call, $aAdmin, $builtinTpl) { $call($aAdmin, 'delete', array('kind' => 'policy_template', 'id' => $builtinTpl, 'dry_run' => true)); });
}
$builtinTplCopy = $call($aAdmin, 'duplicate', array('kind' => 'policy_template', 'id' => 1));
check('access: a built-in template can be duplicated, the copy is free', $builtinTplCopy['name'] === 'ContextTemplate copy'
    && $call($aAdmin, 'update', array('kind' => 'policy_template', 'id' => $builtinTplCopy['id'], 'fields' => array('description' => 'моя')))['changed'] === array('description')
    && $call($aAdmin, 'delete', array('kind' => 'policy_template', 'id' => $builtinTplCopy['id']))['deleted'] === true);
foreach (array('policy 30 (resource group rule)' => 40, 'policy 31 (context rule)' => 41) as $label => $usedTpl) {
    $aRefuse("update of the template of {$label}", function () use ($call, $aAdmin, $usedTpl) { $call($aAdmin, 'update', array('kind' => 'policy_template', 'id' => $usedTpl, 'fields' => array('name' => 'Переименован'))); });
    $aRefuse("delete of the template of {$label}", function () use ($call, $aAdmin, $usedTpl) { $call($aAdmin, 'delete', array('kind' => 'policy_template', 'id' => $usedTpl)); });
}
$usedTplCopy = $call($aAdmin, 'duplicate', array('kind' => 'policy_template', 'id' => 40, 'new_name' => 'Шаблон гостя 2'));
check('access: the template of a policy that group 1 uses can be duplicated', $call($aAdmin, 'delete', array('kind' => 'policy_template', 'id' => $usedTplCopy['id']))['deleted'] === true);
// 42 is free to edit (its policy is only built-in by name) but cannot be deleted: MODX would remove the built-in policy with it
check('access: a template that holds a built-in policy by name can be edited', $call($aAdmin, 'update', array('kind' => 'policy_template', 'id' => 42, 'fields' => array('description' => 'ok')))['changed'] === array('description'));
$aRefuse('delete of a template that would take a built-in policy with it', function () use ($call, $aAdmin) { $call($aAdmin, 'delete', array('kind' => 'policy_template', 'id' => 42)); });
$aRefuse('dry-run delete of a template that would take a built-in policy with it', function () use ($call, $aAdmin) { $call($aAdmin, 'delete', array('kind' => 'policy_template', 'id' => 42, 'dry_run' => true)); });
$tplDoor = $call($aAdmin, 'create', array('kind' => 'policy_template', 'fields' => array('name' => 'Шаблон-дверь')));
$tplDoorEdit = $call($aAdmin, 'update', array('kind' => 'policy_template', 'id' => $tplDoor['id'], 'fields' => array('description' => 'правка')));
$aSite->store('modAccessPolicyTemplate', array('name' => 'AdministratorTemplate') + $aRow('modAccessPolicyTemplate', $tplDoor['id']));
$aRefuse('undo of an edit after the template was renamed to a built-in name', function () use ($call, $aAdmin, $tplDoorEdit) { $call($aAdmin, 'undo', array('changeset_id' => $tplDoorEdit['changeset_id'], 'confirm' => true, 'force' => true)); }, 'not_reversible');
$aSite->store('modAccessPolicyTemplate', array('name' => 'Шаблон-дверь') + $aRow('modAccessPolicyTemplate', $tplDoor['id']));
$aPlantPolicy(80, 'Политика двери', $tplDoor['id'], array('load' => true));
$aPlantAcl('modAccessContext', 81, 1, 'shopx', 80);
$aRefuse('undo of an edit after the template became the template of a policy group 1 uses', function () use ($call, $aAdmin, $tplDoorEdit) { $call($aAdmin, 'undo', array('changeset_id' => $tplDoorEdit['changeset_id'], 'confirm' => true, 'force' => true)); }, 'not_reversible');
$aSite->drop('modAccessContext', $aRow('modAccessContext', 81));

// ---- context_access ----
$aPlantPolicy(90, 'Читатель контекста', 1, array('load' => true, 'view' => true));
$flushBase = $aFlushes();
$ruleDry = $call($aAdmin, 'create', array('kind' => 'context_access', 'dry_run' => true, 'fields' => array('target' => 'shop', 'principal' => 2, 'authority' => 9999, 'policy' => 90)));
check('access: context rule create dry run changes nothing', $ruleDry['dry_run'] === true && $aSite->getCount('modAccessContext', array('target' => 'shop')) === 0);
$rule = $call($aAdmin, 'create', array('kind' => 'context_access', 'fields' => array('target' => 'shop', 'principal' => 2, 'authority' => 9999, 'policy' => 90)));
$ruleId = $rule['id'];
$ruleDiff = $call($aAdmin, 'history_diff', array('changeset_id' => $rule['changeset_id']));
$adminRuleOnShop = $aSite->getCollection('modAccessContext', array('principal' => 1, 'target' => 'shop'));
check('access: context rule create', is_int($ruleId) && $rule['reversible'] === true && $aFlushes() > $flushBase && $aRow('modAccessContext', $ruleId)['principal_class'] === 'modUserGroup'
    && $rule['changed'] === array('target', 'principal', 'authority', 'policy'));
check('access: MODX\'s own Administrator rule on the new context is journaled with the create', count($adminRuleOnShop) === 1 && $adminRuleOnShop[0]->get('policy') == 11 && count($ruleDiff['objects']) === 2
    && array_count_values(array_column($ruleDiff['objects'], 'op')) === array('create' => 2));
$ruleGot = $call($aAdmin, 'get', array('kind' => 'context_access', 'id' => $ruleId));
check('access: context rule get has the computed label', $ruleGot['fields']['label'] === 'Редакторы → shop (Читатель контекста, authority 9999)' && $ruleGot['fields']['principal_class'] === 'modUserGroup'
    && $ruleGot['fields']['target'] === 'shop' && $ruleGot['fields']['principal'] === 2 && $ruleGot['fields']['policy'] === 90);
$ruleList = $call($aAdmin, 'list', array('kind' => 'context_access', 'where' => array('target' => 'shop', 'principal' => 2)));
check('access: context rule list with label, where target and principal', $ruleList['total'] === 1 && array_keys($ruleList['items'][0]) === array('id', 'target', 'principal', 'authority', 'policy', 'label')
    && $ruleList['items'][0]['label'] === 'Редакторы → shop (Читатель контекста, authority 9999)');
check('access: context rule list where policy', $call($aAdmin, 'list', array('kind' => 'context_access', 'where' => array('policy' => 90)))['total'] === 1);
check('access: context rule list shows the Administrator rules too (read-only)', $call($aAdmin, 'list', array('kind' => 'context_access', 'where' => array('principal' => 1)))['total'] === 4);
$aRefuse('list where label', function () use ($call, $aAdmin) { $call($aAdmin, 'list', array('kind' => 'context_access', 'where' => array('label' => 'x'))); });
$e = $aRefuse('context rule lookup by name', function () use ($call, $aAdmin) { $call($aAdmin, 'get', array('kind' => 'context_access', 'name' => 'shop')); });
check('access: the name lookup refusal says to use id', $e && strpos($e->getMessage(), 'use the id') !== false);
$aRefuse('context rule update by name', function () use ($call, $aAdmin) { $call($aAdmin, 'update', array('kind' => 'context_access', 'name' => 'shop', 'fields' => array('authority' => 1))); });
$aRefuse('context rule duplicate with new_name', function () use ($call, $aAdmin, $ruleId) { $call($aAdmin, 'duplicate', array('kind' => 'context_access', 'id' => $ruleId, 'new_name' => 'x')); });
$aRefuse('a context rule for a context that does not exist is MODX\'s refusal', function () use ($call, $aAdmin) { $call($aAdmin, 'create', array('kind' => 'context_access', 'fields' => array('target' => 'нет', 'principal' => 2, 'authority' => 1, 'policy' => 90))); });
$aRefuse('a context rule with a policy that does not exist', function () use ($call, $aAdmin) { $call($aAdmin, 'create', array('kind' => 'context_access', 'fields' => array('target' => 'web', 'principal' => 2, 'authority' => 1, 'policy' => 999))); });
$aRefuse('the same context rule twice is MODX\'s refusal', function () use ($call, $aAdmin) { $call($aAdmin, 'create', array('kind' => 'context_access', 'fields' => array('target' => 'shop', 'principal' => 2, 'authority' => 1, 'policy' => 90))); });
$aRefuse('a context rule without a policy', function () use ($call, $aAdmin) { $call($aAdmin, 'create', array('kind' => 'context_access', 'fields' => array('target' => 'shop', 'principal' => 2, 'authority' => 1))); });
$aRefuse('principal_class cannot be written', function () use ($call, $aAdmin) { $call($aAdmin, 'create', array('kind' => 'context_access', 'fields' => array('target' => 'shop', 'principal' => 3, 'authority' => 1, 'policy' => 90, 'principal_class' => 'modUser'))); });
$aRefuse('label cannot be written', function () use ($call, $aAdmin, $ruleId) { $call($aAdmin, 'update', array('kind' => 'context_access', 'id' => $ruleId, 'fields' => array('label' => 'x'))); });
$flushBase = $aFlushes();
$ruleUpdate = $call($aAdmin, 'update', array('kind' => 'context_access', 'id' => $ruleId, 'fields' => array('authority' => 5)));
check('access: context rule update', $ruleUpdate['changed'] === array('authority') && (int) $aRow('modAccessContext', $ruleId)['authority'] === 5 && $aFlushes() > $flushBase
    && $aRow('modAccessContext', $ruleId)['principal_class'] === 'modUserGroup');
check('access: context rule update dry run', $call($aAdmin, 'update', array('kind' => 'context_access', 'id' => $ruleId, 'dry_run' => true, 'fields' => array('authority' => 5, 'policy' => 11)))['would_change'] === array('policy'));
$ruleLabelDiff = $call($aAdmin, 'history_diff', array('changeset_id' => $ruleUpdate['changeset_id']));
check('access: context rule diff', $ruleLabelDiff['objects'][0]['changed']['authority'] === array('old' => '9999', 'new' => '5'));
$aRefuse('a context rule moved to a context that does not exist', function () use ($call, $aAdmin, $ruleId) { $call($aAdmin, 'update', array('kind' => 'context_access', 'id' => $ruleId, 'fields' => array('target' => 'нет'))); });
$call($aAdmin, 'undo', array('changeset_id' => $ruleUpdate['changeset_id'], 'confirm' => true));
check('access: undo of a context rule update', (int) $aRow('modAccessContext', $ruleId)['authority'] === 9999);
$ruleCopy = $call($aAdmin, 'duplicate', array('kind' => 'context_access', 'id' => $ruleId, 'fields' => array('policy' => 11)));
check('access: context rule duplicate with another policy', !array_key_exists('name', $ruleCopy) && $ruleCopy['source_id'] === $ruleId && $aRow('modAccessContext', $ruleCopy['id'])['policy'] === '11' && $aRow('modAccessContext', $ruleCopy['id'])['target'] === 'shop');
$aRefuse('a copy that is identical is MODX\'s refusal', function () use ($call, $aAdmin, $ruleId) { $call($aAdmin, 'duplicate', array('kind' => 'context_access', 'id' => $ruleId)); });
$flushBase = $aFlushes();
$ruleDelete = $call($aAdmin, 'delete', array('kind' => 'context_access', 'id' => $ruleCopy['id']));
check('access: context rule delete', $ruleDelete['deleted'] === true && $aRow('modAccessContext', $ruleCopy['id']) === null && $aFlushes() > $flushBase);
$flushBase = $aFlushes();
$call($aAdmin, 'undo', array('changeset_id' => $ruleDelete['changeset_id'], 'confirm' => true));
check('access: undo of a context rule delete brings it back with its id', $aRow('modAccessContext', $ruleCopy['id'])['policy'] === '11' && $aFlushes() > $flushBase);
// undo of the create takes MODX's Administrator rule away again (the one place an Administrator rule is removed)
$call($aAdmin, 'undo', array('changeset_id' => $rule['changeset_id'], 'confirm' => true, 'force' => true));
check('access: undo of a context rule create removes it and the Administrator rule MODX added', $aRow('modAccessContext', $ruleId) === null && $aSite->getCount('modAccessContext', array('principal' => 1, 'target' => 'shop')) === 0);
// when the Administrator group already has a rule on the context, nothing extra is created or journaled
$aPlantAcl('modAccessContext', 95, 1, 'shop', 11);
$rule2 = $call($aAdmin, 'create', array('kind' => 'context_access', 'fields' => array('target' => 'shop', 'principal' => 3, 'authority' => 9999, 'policy' => 90)));
check('access: no extra Administrator rule when one exists', count($call($aAdmin, 'history_diff', array('changeset_id' => $rule2['changeset_id']))['objects']) === 1 && $aSite->getCount('modAccessContext', array('principal' => 1, 'target' => 'shop')) === 1);
$call($aAdmin, 'undo', array('changeset_id' => $rule2['changeset_id'], 'confirm' => true));
check('access: and its undo leaves the existing Administrator rule alone', $aRow('modAccessContext', 95) !== null);
$aSite->drop('modAccessContext', $aRow('modAccessContext', 95));

// safety rule 1 for context rules, every write path
$adminRule = $aRow('modAccessContext', 1);
$ruleTwo = $call($aAdmin, 'create', array('kind' => 'context_access', 'fields' => array('target' => 'web', 'principal' => 2, 'authority' => 9999, 'policy' => 90)));
$aRefuse('update of an Administrator context rule', function () use ($call, $aAdmin) { $call($aAdmin, 'update', array('kind' => 'context_access', 'id' => 1, 'fields' => array('authority' => 5))); });
$aRefuse('dry-run update of an Administrator context rule', function () use ($call, $aAdmin) { $call($aAdmin, 'update', array('kind' => 'context_access', 'id' => 1, 'dry_run' => true, 'fields' => array('authority' => 5))); });
$aRefuse('moving an Administrator context rule to another group', function () use ($call, $aAdmin) { $call($aAdmin, 'update', array('kind' => 'context_access', 'id' => 1, 'fields' => array('principal' => 2))); });
$aRefuse('changing the policy of an Administrator context rule', function () use ($call, $aAdmin) { $call($aAdmin, 'update', array('kind' => 'context_access', 'id' => 1, 'fields' => array('policy' => 3))); });
$aRefuse('delete of an Administrator context rule', function () use ($call, $aAdmin) { $call($aAdmin, 'delete', array('kind' => 'context_access', 'id' => 1)); });
$aRefuse('dry-run delete of an Administrator context rule', function () use ($call, $aAdmin) { $call($aAdmin, 'delete', array('kind' => 'context_access', 'id' => 1, 'dry_run' => true)); });
$aRefuse('duplicate of an Administrator context rule', function () use ($call, $aAdmin) { $call($aAdmin, 'duplicate', array('kind' => 'context_access', 'id' => 1, 'fields' => array('policy' => 3))); });
$aRefuse('duplicate of an Administrator context rule giving it to another group', function () use ($call, $aAdmin) { $call($aAdmin, 'duplicate', array('kind' => 'context_access', 'id' => 1, 'fields' => array('principal' => 3))); });
$aRefuse('create of a context rule for group 1', function () use ($call, $aAdmin) { $call($aAdmin, 'create', array('kind' => 'context_access', 'fields' => array('target' => 'shop', 'principal' => 1, 'authority' => 0, 'policy' => 90))); });
$aRefuse('dry-run create of a context rule for group 1', function () use ($call, $aAdmin) { $call($aAdmin, 'create', array('kind' => 'context_access', 'dry_run' => true, 'fields' => array('target' => 'shop', 'principal' => 1, 'authority' => 0, 'policy' => 90))); });
$aRefuse('giving a rule of another group to group 1 (update)', function () use ($call, $aAdmin, $ruleTwo) { $call($aAdmin, 'update', array('kind' => 'context_access', 'id' => $ruleTwo['id'], 'fields' => array('principal' => 1))); });
$aRefuse('giving a rule of another group to group 1 (dry-run update)', function () use ($call, $aAdmin, $ruleTwo) { $call($aAdmin, 'update', array('kind' => 'context_access', 'id' => $ruleTwo['id'], 'dry_run' => true, 'fields' => array('principal' => 1))); });
$aRefuse('copying a rule of another group to group 1', function () use ($call, $aAdmin, $ruleTwo) { $call($aAdmin, 'duplicate', array('kind' => 'context_access', 'id' => $ruleTwo['id'], 'fields' => array('principal' => 1, 'policy' => 3))); });
$aRefuse('a rule that points a user group string at group 1', function () use ($call, $aAdmin, $ruleTwo) { $call($aAdmin, 'update', array('kind' => 'context_access', 'id' => $ruleTwo['id'], 'fields' => array('principal' => '1'))); });
check('access: sending the current principal of an ordinary rule is fine', $call($aAdmin, 'update', array('kind' => 'context_access', 'id' => $ruleTwo['id'], 'fields' => array('principal' => 2, 'authority' => 7)))['changed'] === array('authority'));
check('access: the Administrator rules are untouched', $aRow('modAccessContext', 1) === $adminRule);
// undo doors
$undoEdit = $call($aAdmin, 'update', array('kind' => 'context_access', 'id' => $ruleTwo['id'], 'fields' => array('authority' => 3)));
$aSite->store('modAccessContext', array('principal' => '1') + $aRow('modAccessContext', $ruleTwo['id']));   // a person gave the rule to group 1 in the manager
$aRefuse('undo of an edit of a rule that now belongs to group 1', function () use ($call, $aAdmin, $undoEdit) { $call($aAdmin, 'undo', array('changeset_id' => $undoEdit['changeset_id'], 'confirm' => true, 'force' => true)); }, 'not_reversible');
$aSite->store('modAccessContext', array('principal' => '2') + $aRow('modAccessContext', $ruleTwo['id']));   // back to the ordinary group
$forged = \ModxMCP\Journal\Snapshot::capture($aSite, $aAdmin->kinds->get('context_access'), $ruleTwo['id']);
$forged['fields']['principal'] = '1';
$aRefuse('restoring a state that belongs to group 1 over an ordinary rule', function () use ($aSite, $aAdmin, $ruleTwo, $forged) {
    \ModxMCP\Journal\Snapshot::restore($aSite, $aAdmin->kinds->get('context_access'), $ruleTwo['id'], $forged);
}, 'not_reversible');
$aRefuse('recreating an Administrator rule through restore', function () use ($aSite, $aAdmin, $forged) {
    \ModxMCP\Journal\Snapshot::restore($aSite, $aAdmin->kinds->get('context_access'), 777, $forged);
}, 'not_reversible');

// ---- resourcegroup_access ----
$flushBase = $aFlushes();
$gRule = $call($aAdmin, 'create', array('kind' => 'resourcegroup_access', 'fields' => array('target' => 1, 'principal' => 2, 'authority' => 9999, 'policy' => 90, 'context_key' => 'web')));
$gRuleId = $gRule['id'];
check('access: resource group rule create', is_int($gRuleId) && $gRule['changed'] === array('target', 'principal', 'authority', 'policy', 'context_key') && $aFlushes() > $flushBase
    && $aRow('modAccessResourceGroup', $gRuleId)['principal_class'] === 'modUserGroup' && $aSite->getCount('modAccessContext', array('principal' => 1)) === 3);
$gRuleGot = $call($aAdmin, 'get', array('kind' => 'resourcegroup_access', 'id' => $gRuleId));
check('access: resource group rule get with the label naming the resource group', $gRuleGot['fields']['label'] === 'Редакторы → Закрытые (Читатель контекста, authority 9999)' && $gRuleGot['fields']['target'] === 1
    && $gRuleGot['fields']['context_key'] === 'web');
$gList = $call($aAdmin, 'list', array('kind' => 'resourcegroup_access', 'where' => array('target' => 1, 'principal' => 2, 'policy' => 90)));
check('access: resource group rule list with where on target, principal and policy', $gList['total'] === 1 && $gList['items'][0]['label'] === 'Редакторы → Закрытые (Читатель контекста, authority 9999)'
    && array_keys($gList['items'][0]) === array('id', 'target', 'principal', 'authority', 'policy', 'context_key', 'label'));
$aRefuse('the same resource group rule twice', function () use ($call, $aAdmin) { $call($aAdmin, 'create', array('kind' => 'resourcegroup_access', 'fields' => array('target' => 1, 'principal' => 2, 'authority' => 1, 'policy' => 90, 'context_key' => 'web'))); });
$aRefuse('a resource group rule for a resource group that does not exist', function () use ($call, $aAdmin) { $call($aAdmin, 'create', array('kind' => 'resourcegroup_access', 'fields' => array('target' => 99, 'principal' => 2, 'authority' => 1, 'policy' => 90))); });
$gUpdate = $call($aAdmin, 'update', array('kind' => 'resourcegroup_access', 'id' => $gRuleId, 'fields' => array('authority' => 4, 'context_key' => 'shop')));
check('access: resource group rule update', $gUpdate['changed'] === array('authority', 'context_key') && $aRow('modAccessResourceGroup', $gRuleId)['context_key'] === 'shop');
$call($aAdmin, 'undo', array('changeset_id' => $gUpdate['changeset_id'], 'confirm' => true));
check('access: undo of a resource group rule update', (int) $aRow('modAccessResourceGroup', $gRuleId)['authority'] === 9999 && $aRow('modAccessResourceGroup', $gRuleId)['context_key'] === 'web');
$gCopy = $call($aAdmin, 'duplicate', array('kind' => 'resourcegroup_access', 'id' => $gRuleId, 'fields' => array('context_key' => 'shop')));
check('access: resource group rule duplicate', $aRow('modAccessResourceGroup', $gCopy['id'])['context_key'] === 'shop' && $aRow('modAccessResourceGroup', $gCopy['id'])['target'] === '1');
$gDelete = $call($aAdmin, 'delete', array('kind' => 'resourcegroup_access', 'id' => $gCopy['id']));
$call($aAdmin, 'undo', array('changeset_id' => $gDelete['changeset_id'], 'confirm' => true));
check('access: resource group rule delete and its undo', $aRow('modAccessResourceGroup', $gCopy['id'])['context_key'] === 'shop');
$call($aAdmin, 'undo', array('changeset_id' => $gCopy['changeset_id'], 'confirm' => true, 'force' => true));
check('access: undo of a resource group rule duplicate', $aRow('modAccessResourceGroup', $gCopy['id']) === null);
// safety rule 1
$adminGroupRule = $aRow('modAccessResourceGroup', 1);
$aRefuse('update of an Administrator resource group rule', function () use ($call, $aAdmin) { $call($aAdmin, 'update', array('kind' => 'resourcegroup_access', 'id' => 1, 'fields' => array('authority' => 5))); });
$aRefuse('moving an Administrator resource group rule to another group', function () use ($call, $aAdmin) { $call($aAdmin, 'update', array('kind' => 'resourcegroup_access', 'id' => 1, 'fields' => array('principal' => 3))); });
$aRefuse('delete of an Administrator resource group rule', function () use ($call, $aAdmin) { $call($aAdmin, 'delete', array('kind' => 'resourcegroup_access', 'id' => 1)); });
$aRefuse('duplicate of an Administrator resource group rule', function () use ($call, $aAdmin) { $call($aAdmin, 'duplicate', array('kind' => 'resourcegroup_access', 'id' => 1, 'fields' => array('policy' => 3))); });
$aRefuse('create of a resource group rule for group 1', function () use ($call, $aAdmin) { $call($aAdmin, 'create', array('kind' => 'resourcegroup_access', 'fields' => array('target' => 2, 'principal' => 1, 'authority' => 0, 'policy' => 90))); });
$aRefuse('giving a resource group rule to group 1', function () use ($call, $aAdmin, $gRuleId) { $call($aAdmin, 'update', array('kind' => 'resourcegroup_access', 'id' => $gRuleId, 'fields' => array('principal' => 1))); });
$aRefuse('copying a resource group rule to group 1', function () use ($call, $aAdmin, $gRuleId) { $call($aAdmin, 'duplicate', array('kind' => 'resourcegroup_access', 'id' => $gRuleId, 'fields' => array('principal' => 1, 'context_key' => 'shop'))); });
$gEdit = $call($aAdmin, 'update', array('kind' => 'resourcegroup_access', 'id' => $gRuleId, 'fields' => array('authority' => 8)));
$aSite->store('modAccessResourceGroup', array('principal' => '1') + $aRow('modAccessResourceGroup', $gRuleId));
$aRefuse('undo of an edit of a resource group rule that now belongs to group 1', function () use ($call, $aAdmin, $gEdit) { $call($aAdmin, 'undo', array('changeset_id' => $gEdit['changeset_id'], 'confirm' => true, 'force' => true)); }, 'not_reversible');
check('access: the Administrator resource group rule is untouched', $aRow('modAccessResourceGroup', 1) === $adminGroupRule);

// --- Packages: kinds package and provider, tools install_package and uninstall_package (task 19) ---
$pkDir = $siteDir . '/packages';
mkdir($pkDir . '/core', 0700, true);
$pSite = fakeSite($pkDir);
$pActor = function ($scope, array $token = array()) use ($pSite, $dev) {
    $c = new Context($pSite, $token + array('scope' => $scope) + $dev->token, $dev->corePath, function () {});
    $c->sessionId = 'session-package001';
    return $c;
};
$pAdmin = $pActor('admin');
$pPlan = $pActor('admin', array('plan_only' => true));
$pPlant = function ($signature, $name, $installed, $provider = 1) use ($pSite) {
    $parts = explode('-', $signature);
    $version = explode('.', $parts[1]);
    $release = isset($parts[2]) ? preg_split('/([0-9]+)/', $parts[2], -1, PREG_SPLIT_DELIM_CAPTURE) : array('', '0');
    $pSite->store('transport.modTransportPackage', array('signature' => $signature, 'created' => '2026-01-01 00:00:00', 'updated' => null, 'installed' => $installed, 'state' => 1,
        'workspace' => 1, 'provider' => $provider, 'disabled' => 0, 'source' => $signature . '.transport.zip', 'package_name' => $name,
        'manifest' => '[]', 'attributes' => '[]', 'metadata' => '[]',
        'version_major' => $version[0], 'version_minor' => $version[1], 'version_patch' => $version[2], 'release' => $release[0], 'release_index' => isset($release[1]) ? $release[1] : '0'));
};
$pSite->store('transport.modTransportProvider', array('id' => 1, 'name' => 'modx.com', 'description' => 'The official provider', 'service_url' => 'https://rest.modx.com/extras/', 'username' => '', 'api_key' => '', 'active' => 1, 'priority' => 10));
$pSite->store('transport.modTransportProvider', array('id' => 2, 'name' => 'modstore.pro', 'description' => '', 'service_url' => 'https://modstore.pro/extras/', 'username' => 'shop-owner', 'api_key' => 'live-key-123', 'active' => 1, 'priority' => 5));
$pPlant('pdotools-2.13.1-pl', 'pdoTools', '2026-01-05 10:00:00');
$pPlant('formit-5.0.2-pl', 'FormIt', '2026-01-06 11:00:00');
$pPlant('modxmcp-2.0.0-pl', 'modxMCP', '2026-01-07 12:00:00', 0);
$pPlant('migx-2.13.0-pl', 'MIGX', null);
$pPlant('каталог-1.2.0-beta1', 'Каталог', null, 2);
$pPlant('zero-1.0.0-pl', 'Zero', '0000-00-00 00:00:00');
$pPlant('sneaky-1.0.0-pl', 'modxMCP', null);          // another signature, but the package name is modxMCP's
$pRowOf = function ($name, $version, $release, $description = 'Description', $downloads = '1,234') {
    $signature = mb_strtolower($name, 'UTF-8') . '-' . $version . '-' . $release;
    return array('id' => crc32($signature), 'name' => $name, 'version' => $version, 'release' => $release, 'version-compiled' => $version . '-' . $release, 'signature' => $signature,
        'description' => $description, 'downloads' => $downloads, 'location' => 'https://example.invalid/download/' . $signature);
};
$longText = str_repeat('Очень длинное описание. ', 30);
$pSite->providerCatalog[1] = array(
    $pRowOf('pdoTools', '2.13.1', 'pl'), $pRowOf('pdoTools', '2.13.2', 'pl', '<p>Fast <b>chunks</b> &amp; snippets. ' . $longText . '</p>'), $pRowOf('pdoTools', '2.13.3', 'rc'),
    $pRowOf('FormIt', '5.0.2', 'pl'), $pRowOf('Галерея', '1.0.0', 'pl', 'Фото'), $pRowOf('modxMCP', '2.0.1', 'pl'), $pRowOf('Tickets', '3.0.0', 'pl', 'Comments'),
    $pRowOf('modxMCP-fork', '1.0.0', 'pl'),
);
$pSite->providerCatalog[2] = array($pRowOf('Tickets', '4.0.0', 'pl', 'Comments of modstore'));
$pTables = function () use ($pSite) { return json_encode(array($pSite->tableDump('transport.modTransportPackage'), $pSite->tableDump('transport.modTransportProvider'))); };
$pLast = function () use ($pAdmin) { return $pAdmin->journal()->lastChangesetId(); };
$pCalls = function () use ($pSite) { return array_column($pSite->packageCalls, 0); };
// A refused call: the right error, no changeset, the workspace and the providers as they were, and exactly the expected processor calls.
$pRefuse = function ($label, callable $fn, $code = 'validation_failed', $message = null, $calls = 0) use ($pTables, $pLast, $pSite) {
    $tables = $pTables();
    $last = $pLast();
    $before = count($pSite->packageCalls);
    $e = throws("package: {$label}", ApiException::class, $fn);
    check("package: {$label} is {$code} and changes nothing", $e && $e->errorCode === $code && $pLast() === $last && $pTables() === $tables
        && count($pSite->packageCalls) - $before === $calls && ($message === null || strpos($e->getMessage(), $message) !== false));
    return $e;
};

// ---- kind package: read only ----
$pkDescribe = $call($pAdmin, 'describe', array('kind' => 'package'));
check('package: describe', $pkDescribe['group'] === 'package_management' && $pkDescribe['read_scope'] === 'read' && $pkDescribe['write_scope'] === 'admin' && $pkDescribe['key_type'] === 'string'
    && $pkDescribe['key_field'] === 'key' && $pkDescribe['name_field'] === 'signature' && $pkDescribe['generic_writes'] === false
    && array_column($pkDescribe['fields'], 'name') === array('signature', 'package_name', 'version', 'installed', 'provider', 'source', 'updated', 'workspace'));
$pkList = $call($pActor('read'), 'list', array('kind' => 'package'));
$pkSignatures = array_column($pkList['items'], 'signature');
sort($pkSignatures);
check('package: a read token lists the workspace, installed or only downloaded', $pkList['total'] === 7
    && $pkSignatures === array('formit-5.0.2-pl', 'migx-2.13.0-pl', 'modxmcp-2.0.0-pl', 'pdotools-2.13.1-pl', 'sneaky-1.0.0-pl', 'zero-1.0.0-pl', 'каталог-1.2.0-beta1')
    && array_keys($pkList['items'][0]) === array('signature', 'package_name', 'version', 'installed'));
$pkItems = array_column($pkList['items'], null, 'signature');
check('package: computed version, installed datetime, "" for downloaded or never installed', $pkItems['pdotools-2.13.1-pl']['version'] === '2.13.1-pl' && $pkItems['pdotools-2.13.1-pl']['installed'] === '2026-01-05 10:00:00'
    && $pkItems['migx-2.13.0-pl']['installed'] === '' && $pkItems['zero-1.0.0-pl']['installed'] === '' && $pkItems['каталог-1.2.0-beta1']['version'] === '1.2.0-beta1'
    && $pkItems['каталог-1.2.0-beta1']['package_name'] === 'Каталог');
check('package: query and where', array_column($call($pActor('read'), 'list', array('kind' => 'package', 'query' => 'pdo'))['items'], 'signature') === array('pdotools-2.13.1-pl')
    && array_column($call($pActor('read'), 'list', array('kind' => 'package', 'query' => 'тал'))['items'], 'signature') === array('каталог-1.2.0-beta1')
    && $call($pActor('read'), 'list', array('kind' => 'package', 'where' => array('provider' => 2)))['total'] === 1);
$pRefuse('where on the computed version', function () use ($call, $pAdmin) { $call($pAdmin, 'list', array('kind' => 'package', 'where' => array('version' => '2.13.1-pl'))); });
$pkGot = $call($pActor('read'), 'get', array('kind' => 'package', 'key' => 'pdotools-2.13.1-pl'));
check('package: get by signature', $pkGot['key'] === 'pdotools-2.13.1-pl' && $pkGot['fields']['version'] === '2.13.1-pl' && $pkGot['fields']['provider'] === 1 && $pkGot['fields']['workspace'] === 1
    && $pkGot['fields']['updated'] === '' && preg_match('/^[a-f0-9]{64}$/', $pkGot['revision']) === 1);
check('package: get by name is the signature', $call($pActor('read'), 'get', array('kind' => 'package', 'name' => 'migx-2.13.0-pl'))['fields']['package_name'] === 'MIGX');
$pRefuse('get by numeric id', function () use ($call, $pAdmin) { $call($pAdmin, 'get', array('kind' => 'package', 'id' => 1)); });
$pRefuse('get of a missing package', function () use ($call, $pAdmin) { $call($pAdmin, 'get', array('kind' => 'package', 'key' => 'nope-1.0.0-pl')); }, 'not_found');
$pkMessage = 'Packages are installed and removed with modx_install_package / modx_uninstall_package.';
foreach (array(
    'create' => array('kind' => 'package', 'fields' => array('package_name' => 'X')),
    'create (dry run)' => array('kind' => 'package', 'fields' => array('package_name' => 'X'), 'dry_run' => true),
    'update' => array('kind' => 'package', 'key' => 'pdotools-2.13.1-pl', 'fields' => array('provider' => 2)),
    'update (dry run)' => array('kind' => 'package', 'key' => 'pdotools-2.13.1-pl', 'fields' => array('provider' => 2), 'dry_run' => true),
    'delete' => array('kind' => 'package', 'key' => 'migx-2.13.0-pl'),
    'delete (dry run)' => array('kind' => 'package', 'key' => 'migx-2.13.0-pl', 'dry_run' => true),
    'duplicate' => array('kind' => 'package', 'key' => 'migx-2.13.0-pl'),
    'duplicate (dry run)' => array('kind' => 'package', 'key' => 'migx-2.13.0-pl', 'dry_run' => true),
) as $label => $args) {
    $action = explode(' ', $label)[0];
    $pRefuse("admin {$label} of a package", function () use ($call, $pAdmin, $action, $args) { $call($pAdmin, $action, $args); }, 'validation_failed', $pkMessage);
    $pRefuse("plan-only {$label} of a package", function () use ($call, $pPlan, $action, $args) { $call($pPlan, $action, $args); }, 'validation_failed', $pkMessage);
}
$pRefuse('create of a package by a develop token', function () use ($call, $pActor) { $call($pActor('develop'), 'create', array('kind' => 'package', 'fields' => array('package_name' => 'X'))); }, 'forbidden_scope');
check('package: the kind is never in the journal', $pAdmin->journal()->changesets(array('kind' => 'package'), 50) === array() && $pLast() === 0);

// ---- kind provider ----
$prDescribe = $call($pAdmin, 'describe', array('kind' => 'provider'));
$prFields = array_column($prDescribe['fields'], null, 'name');
check('provider: describe', $prDescribe['group'] === 'package_management' && $prDescribe['read_scope'] === 'read' && $prDescribe['write_scope'] === 'admin' && $prDescribe['key_type'] === 'integer'
    && $prDescribe['name_field'] === 'name' && array_column($prDescribe['fields'], 'name') === array('id', 'name', 'service_url', 'description', 'username', 'api_key', 'priority')
    && $prFields['name']['required'] === true && $prFields['service_url']['required'] === true && !isset($prDescribe['generic_writes'])
    && array_column($prDescribe['operations'], 'op') === array('search') && $prDescribe['operations'][0]['read_only'] === true);
$prCreated = $call($pAdmin, 'create', array('kind' => 'provider', 'fields' => array('name' => 'Провайдер Ёлка', 'service_url' => 'https://elka.example/extras/', 'username' => 'ёлка', 'api_key' => 'elka-key-001', 'description' => 'Мой', 'priority' => 3)));
$prId = $prCreated['id'];
check('provider: create is journaled', is_int($prId) && $prCreated['changeset_id'] !== null && $prCreated['reversible'] === true
    && $pSite->row('transport.modTransportProvider', $prId)['api_key'] === 'elka-key-001' && $pSite->row('transport.modTransportProvider', $prId)['name'] === 'Провайдер Ёлка');
$prAdminGet = $call($pAdmin, 'get', array('kind' => 'provider', 'id' => $prId));
check('provider: an admin token sees the api key', $prAdminGet['fields']['api_key'] === 'elka-key-001' && $prAdminGet['secret'] === true && $prAdminGet['fields']['name'] === 'Провайдер Ёлка');
foreach (array('read', 'content', 'develop') as $scope) {
    $get = $call($pActor($scope), 'get', array('kind' => 'provider', 'id' => $prId));
    $list = $call($pActor($scope), 'list', array('kind' => 'provider', 'fields' => array('name', 'api_key', 'username')));
    $listedKeys = array_column($list['items'], 'api_key');
    sort($listedKeys);
    check("provider: a {$scope} token gets [hidden] in get and list", $get['fields']['api_key'] === '[hidden]' && $get['secret'] === true && $get['fields']['username'] === 'ёлка'
        && strpos(json_encode($get), 'elka-key-001') === false && strpos(json_encode($list), 'live-key-123') === false && strpos(json_encode($list), 'elka-key-001') === false
        && $listedKeys === array('', '[hidden]', '[hidden]'));
    $e = throws("provider: {$scope} token cannot write", ApiException::class, function () use ($call, $pActor, $scope, $prId) { $call($pActor($scope), 'update', array('kind' => 'provider', 'id' => $prId, 'fields' => array('priority' => 1))); });
    check("provider: {$scope} token write is forbidden_scope", $e && $e->errorCode === 'forbidden_scope');
}
$pRefuse('filtering providers by api_key', function () use ($call, $pActor) { $call($pActor('read'), 'list', array('kind' => 'provider', 'where' => array('api_key' => 'live-key-123'))); });
check('provider: query and where on name', array_column($call($pActor('read'), 'list', array('kind' => 'provider', 'query' => 'айдер'))['items'], 'id') === array($prId)
    && array_column($call($pActor('read'), 'list', array('kind' => 'provider', 'where' => array('name' => 'modx.com')))['items'], 'id') === array(1));
$prDiff = $call($pAdmin, 'history_diff', array('changeset_id' => $prCreated['changeset_id']));
check('provider: the create diff hides the api key even for an admin token', $prDiff['objects'][0]['changed']['api_key'] === array('old' => '[hidden]', 'new' => '[hidden]')
    && $prDiff['objects'][0]['changed']['name']['new'] === 'Провайдер Ёлка' && strpos(json_encode($prDiff), 'elka-key-001') === false);
$prUpdated = $call($pAdmin, 'update', array('kind' => 'provider', 'id' => $prId, 'expected_revision' => $prAdminGet['revision'], 'fields' => array('api_key' => 'elka-key-002', 'priority' => 4)));
check('provider: update sends the whole record', $prUpdated['changed'] === array('api_key', 'priority') && $pSite->row('transport.modTransportProvider', $prId)['api_key'] === 'elka-key-002'
    && $pSite->row('transport.modTransportProvider', $prId)['username'] === 'ёлка' && $pSite->row('transport.modTransportProvider', $prId)['description'] === 'Мой');
$prUpdateDiff = $call($pAdmin, 'history_diff', array('changeset_id' => $prUpdated['changeset_id']));
check('provider: the update diff hides the api key, old and new', $prUpdateDiff['objects'][0]['changed']['api_key'] === array('old' => '[hidden]', 'new' => '[hidden]')
    && $prUpdateDiff['objects'][0]['changed']['priority'] === array('old' => '3', 'new' => '4') && strpos(json_encode($prUpdateDiff), 'elka-key') === false);
$pRefuse('update to a url the provider cannot verify', function () use ($call, $pAdmin, $prId) { $call($pAdmin, 'update', array('kind' => 'provider', 'id' => $prId, 'fields' => array('service_url' => 'https://unverified.example/'))); }, 'validation_failed', 'could not be verified');
$pRefuse('create without a service url', function () use ($call, $pAdmin) { $call($pAdmin, 'create', array('kind' => 'provider', 'fields' => array('name' => 'Bare'))); }, 'validation_failed', 'service_url');
$call($pAdmin, 'undo', array('changeset_id' => $prUpdated['changeset_id'], 'confirm' => true));
check('provider: undo of the update restores the real api key', $pSite->row('transport.modTransportProvider', $prId)['api_key'] === 'elka-key-001' && $pSite->row('transport.modTransportProvider', $prId)['priority'] === '3');
$prCopy = $call($pAdmin, 'duplicate', array('kind' => 'provider', 'id' => $prId, 'new_name' => 'Копия Ёлки'));
check('provider: duplicate copies the key for an admin', $pSite->row('transport.modTransportProvider', $prCopy['id'])['api_key'] === 'elka-key-001' && $prCopy['name'] === 'Копия Ёлки');
$pRefuse('duplicate by a develop token', function () use ($call, $pActor, $prId) { $call($pActor('develop'), 'duplicate', array('kind' => 'provider', 'id' => $prId, 'new_name' => 'Leak')); }, 'forbidden_scope');
$prDeleted = $call($pAdmin, 'delete', array('kind' => 'provider', 'id' => $prCopy['id']));
check('provider: delete removes the row', $pSite->row('transport.modTransportProvider', $prCopy['id']) === null && $prDeleted['deleted'] === true);
$call($pAdmin, 'undo', array('changeset_id' => $prDeleted['changeset_id'], 'confirm' => true));
check('provider: undo of the delete brings it back with its key and id', $pSite->row('transport.modTransportProvider', $prCopy['id'])['api_key'] === 'elka-key-001');
$call($pAdmin, 'undo', array('changeset_id' => $prCopy['changeset_id'], 'confirm' => true));
$call($pAdmin, 'undo', array('changeset_id' => $prCreated['changeset_id'], 'confirm' => true, 'force' => true));
check('provider: undo of the creates removes both again', $pSite->row('transport.modTransportProvider', $prId) === null && $pSite->row('transport.modTransportProvider', $prCopy['id']) === null);

// ---- the search operation of a provider ----
$pSite->packageCalls = array();
$pBeforeSearch = $pLast();
$pSearch = $call($pActor('read'), 'invoke', array('kind' => 'provider', 'id' => 1, 'op' => 'search', 'args' => array('query' => 'pdo')));
check('provider search: a read token searches the catalogue', $pSearch['kind'] === 'provider' && $pSearch['op'] === 'search' && $pSearch['id'] === 1 && $pSearch['total'] === 3 && count($pSearch['items']) === 3
    && array_keys($pSearch['items'][0]) === array('name', 'signature', 'version', 'description', 'downloads', 'installed') && $pCalls() === array('workspace/packages/rest/getlist')
    && $pSite->packageCalls[0][1] === array('provider' => 1, 'query' => 'pdo', 'limit' => 20, 'start' => 0));
check('provider search: version, downloads and installed flag', $pSearch['items'][0]['version'] === '2.13.1-pl' && $pSearch['items'][0]['installed'] === true && $pSearch['items'][1]['installed'] === false
    && $pSearch['items'][1]['downloads'] === 1234 && $pSearch['items'][2]['version'] === '2.13.3-rc');
check('provider search: the description is plain text of at most 300 characters', mb_strlen($pSearch['items'][1]['description'], 'UTF-8') === 300 && strpos($pSearch['items'][1]['description'], '<') === false
    && strpos($pSearch['items'][1]['description'], 'Fast chunks & snippets. Очень') === 0 && mb_substr($pSearch['items'][1]['description'], -1, 1, 'UTF-8') === '…');
check('provider search: Cyrillic query, limit and start', array_column($call($pActor('read'), 'invoke', array('kind' => 'provider', 'id' => 1, 'op' => 'search', 'args' => array('query' => 'галер')))['items'], 'name') === array('Галерея')
    && count($call($pActor('read'), 'invoke', array('kind' => 'provider', 'id' => 1, 'op' => 'search', 'args' => array('query' => 'pdo', 'limit' => 1, 'start' => 1)))['items']) === 1);
check('provider search: nothing is journaled', $pLast() === $pBeforeSearch);
$pRefuse('search without a query', function () use ($call, $pActor) { $call($pActor('read'), 'invoke', array('kind' => 'provider', 'id' => 1, 'op' => 'search', 'args' => new stdClass())); });
$pRefuse('search of an unknown provider', function () use ($call, $pActor) { $call($pActor('read'), 'invoke', array('kind' => 'provider', 'id' => 99, 'op' => 'search', 'args' => array('query' => 'x'))); }, 'not_found');
$pSite->failProcessor = 'workspace/packages/rest/getlist';
$pRefuse('search when the provider cannot be reached', function () use ($call, $pActor) { $call($pActor('read'), 'invoke', array('kind' => 'provider', 'id' => 1, 'op' => 'search', 'args' => array('query' => 'x'))); }, 'validation_failed', 'Simulated failure', 1);
$pSite->failProcessor = null;

// ---- install_package: input, plan, plan-only token ----
$pStart = $pLast();
$pSite->packageCalls = array();
$pTablesBefore = $pTables();
$pPlanned = $call($pAdmin, 'install_package', array('package_name' => 'pdotools'));
check('install: without confirm only the plan comes back', $pPlanned['performed'] === false && $pPlanned['signature'] === 'pdotools-2.13.2-pl' && $pPlanned['version'] === '2.13.2-pl' && $pPlanned['provider'] === 1
    && $pPlanned['provider_name'] === 'modx.com' && $pPlanned['needs_download'] === true && $pPlanned['replaces'] === array('signature' => 'pdotools-2.13.1-pl', 'version' => '2.13.1-pl')
    && $pPlanned['reversible'] === false && strpos($pPlanned['warning'], 'modx_undo') !== false && strpos($pPlanned['warning'], 'modx_uninstall_package') !== false && strpos($pPlanned['warning'], 'resolvers') !== false
    && strpos($pPlanned['warning'], 'upgrades the installed pdotools-2.13.1-pl') !== false && !isset($pPlanned['planned']));
check('install: the plan ran the search and nothing else; the newest stable release is chosen', $pCalls() === array('workspace/packages/rest/getlist') && $pTables() === $pTablesBefore && $pLast() === $pStart);
$pSite->packageCalls = array();
$pPlanToken = $call($pPlan, 'install_package', array('package_name' => 'pdoTools', 'confirm' => true));
check('install: a plan-only token never installs, even with confirm: true', $pPlanToken['performed'] === false && $pPlanToken['planned'] === true && $pPlanToken['signature'] === 'pdotools-2.13.2-pl'
    && $pCalls() === array('workspace/packages/rest/getlist') && $pTables() === $pTablesBefore && $pLast() === $pStart);
check('install: a pre-release only when its version is asked for', $call($pAdmin, 'install_package', array('package_name' => 'pdoTools', 'version' => '2.13.3'))['signature'] === 'pdotools-2.13.3-rc'
    && $call($pAdmin, 'install_package', array('package_name' => 'pdoTools', 'version' => '2.13.3-rc'))['signature'] === 'pdotools-2.13.3-rc'
    && $call($pAdmin, 'install_package', array('package_name' => 'pdoTools', 'version' => '2.13.2'))['version'] === '2.13.2-pl');
$pSite->packageCalls = array();
$pTickets = $call($pAdmin, 'install_package', array('package_name' => 'Tickets'));
check('install: the default provider is modx.com', $pTickets['provider'] === 1 && $pTickets['signature'] === 'tickets-3.0.0-pl' && $pTickets['replaces'] === null && $pSite->packageCalls[0][1]['provider'] === 1);
$pTickets2 = $call($pAdmin, 'install_package', array('package_name' => 'Tickets', 'provider' => 2));
check('install: another provider by id', $pTickets2['provider'] === 2 && $pTickets2['provider_name'] === 'modstore.pro' && $pTickets2['signature'] === 'tickets-4.0.0-pl' && $pTickets2['version'] === '4.0.0-pl');
$pSite->providerCatalog[3] = array();
$pSite->store('transport.modTransportProvider', array('id' => 3, 'name' => 'Другой', 'description' => '', 'service_url' => 'https://other.example/', 'username' => '', 'api_key' => '', 'active' => 1, 'priority' => 1));
$pSite->packageCalls = array();
$pByName = $call($pAdmin, 'install_package', array('package_name' => 'ГАЛЕРЕЯ'));
check('install: package names match without regard to case, Cyrillic too', $pByName['signature'] === 'галерея-1.0.0-pl' && $pByName['package_name'] === 'Галерея' && $pByName['needs_download'] === true);
$pRefuse('a package the provider does not have', function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('package_name' => 'Nonexistent')); }, 'not_found', null, 1);
$pRefuse('a version the provider does not have', function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('package_name' => 'pdoTools', 'version' => '9.9.9')); }, 'not_found', '9.9.9', 1);
$pRefuse('an unknown provider id', function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('package_name' => 'Tickets', 'provider' => 99)); }, 'not_found');
$pRefuse('a provider that does not have the package', function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('package_name' => 'Tickets', 'provider' => 3)); }, 'not_found', null, 1);
$pRefuse('neither signature nor package_name', function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('confirm' => true)); });
$pRefuse('both signature and package_name', function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('signature' => 'migx-2.13.0-pl', 'package_name' => 'MIGX')); });
$pRefuse('a signature with a version', function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('signature' => 'migx-2.13.0-pl', 'version' => '2.13.0')); });
$pRefuse('a signature that is not downloaded', function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('signature' => 'nothing-1.0.0-pl')); }, 'not_found');
$pRefuse('a package that is already installed (by signature)', function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('signature' => 'formit-5.0.2-pl', 'confirm' => true)); }, 'validation_failed', 'already installed');
$pRefuse('a package that is already installed (by name)', function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('package_name' => 'FormIt', 'confirm' => true)); }, 'validation_failed', 'already installed', 1);
$pRefuse('an installed version asked for again', function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('package_name' => 'pdoTools', 'version' => '2.13.1-pl')); }, 'validation_failed', 'already installed', 1);
$pSite->packageCalls = array();
$pDownloaded = $call($pAdmin, 'install_package', array('signature' => 'migx-2.13.0-pl'));
check('install: a downloaded package needs no download and no search', $pDownloaded['performed'] === false && $pDownloaded['needs_download'] === false && $pDownloaded['version'] === '2.13.0-pl'
    && $pDownloaded['package_name'] === 'MIGX' && $pDownloaded['replaces'] === null && $pCalls() === array());
check('install: a package whose recorded installation date is all zeros counts as not installed', $call($pAdmin, 'install_package', array('signature' => 'zero-1.0.0-pl'))['performed'] === false);
check('install: a case-different signature finds the downloaded package', $call($pAdmin, 'install_package', array('signature' => 'MIGX-2.13.0-PL'))['signature'] === 'migx-2.13.0-pl');

// ---- install_package: modxMCP itself, on every path ----
$pOwnCases = array(
    'the installed package by signature' => array('signature' => 'modxmcp-2.0.0-pl'),
    'a signature in other case that is not in the workspace' => array('signature' => 'ModxMCP-9.9.9-pl'),
    'a downloaded package whose recorded name is modxMCP' => array('signature' => 'sneaky-1.0.0-pl'),
    'the name modxMCP' => array('package_name' => 'modxMCP'),
    'the name in capitals' => array('package_name' => 'MODXMCP'),
    'a version of it' => array('package_name' => 'modxMCP', 'version' => '2.0.1-pl', 'provider' => 1),
);
foreach ($pOwnCases as $label => $args) {
    foreach (array('' => array(), ' with confirm' => array('confirm' => true)) as $suffix => $more) {
        $pRefuse("install of {$label}{$suffix}", function () use ($call, $pAdmin, $args, $more) { $call($pAdmin, 'install_package', $args + $more); }, 'validation_failed', 'cannot install, replace or remove itself');
        $pRefuse("plan-only install of {$label}{$suffix}", function () use ($call, $pPlan, $args, $more) { $call($pPlan, 'install_package', $args + $more); }, 'validation_failed', 'cannot install, replace or remove itself');
    }
}
$pSite->providerCatalog[1][] = array('name' => 'Другое имя', 'version' => '3.0.0', 'release' => 'pl', 'version-compiled' => '3.0.0-pl', 'signature' => 'modxmcp-3.0.0-pl', 'description' => 'Bad row',
    'downloads' => '1', 'location' => 'https://example.invalid/x');
$pRefuse('a provider row that is named otherwise but has modxMCP\'s signature', function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('package_name' => 'Другое имя', 'confirm' => true)); }, 'validation_failed', 'cannot install, replace or remove itself', 1);
check('install: a package merely starting with modxmcp- is not modxMCP', $call($pAdmin, 'install_package', array('package_name' => 'modxMCP-fork'))['signature'] === 'modxmcp-fork-1.0.0-pl');

// ---- install_package: confirmed ----
$pSite->packageCalls = array();
$pSite->packageLogLines = array('Resolver warning: таблица modx_catalog не найдена');
$pRefreshes = $pSite->getCacheManager()->refreshes;
$pBefore = $pLast();
$pInstalled = $call($pAdmin, 'install_package', array('package_name' => 'pdoTools', 'confirm' => true));
check('install: confirm searches, downloads, installs, then refreshes the cache', $pCalls() === array('workspace/packages/rest/getlist', 'workspace/packages/rest/download', 'workspace/packages/install')
    && $pSite->packageCalls[1][1] === array('info' => 'https://example.invalid/download/pdotools-2.13.2-pl::pdotools-2.13.2-pl', 'provider' => 1)
    && $pSite->packageCalls[2][1] === array('signature' => 'pdotools-2.13.2-pl') && $pSite->getCacheManager()->refreshes === $pRefreshes + 1);
check('install: the result names what was installed', $pInstalled['performed'] === true && $pInstalled['signature'] === 'pdotools-2.13.2-pl' && $pInstalled['installed'] === '2026-02-03 04:05:06'
    && $pInstalled['version'] === '2.13.2-pl' && $pInstalled['needs_download'] === true && $pInstalled['replaces']['signature'] === 'pdotools-2.13.1-pl' && $pInstalled['reversible'] === false
    && $pSite->row('transport.modTransportPackage', 'pdotools-2.13.2-pl')['installed'] === '2026-02-03 04:05:06' && in_array('The install cannot be undone by modx_undo; remove the package with modx_uninstall_package.', $pAdmin->warnings, true));
check('install: the error-log entries written during the install come back', $pInstalled['log']['new_entries'] === 1 && $pInstalled['log']['entries'][0]['level'] === 'ERROR'
    && strpos($pInstalled['log']['entries'][0]['message'], 'таблица modx_catalog не найдена') !== false && $pInstalled['log']['truncated'] === false);
check('install: one changeset, no change rows', $pInstalled['changeset_id'] === $pBefore + 1 && $pLast() === $pBefore + 1 && $pAdmin->journal()->changes(array($pInstalled['changeset_id'])) === array());
$pHistory = $call($pAdmin, 'history', array('limit' => 1))['changesets'][0];
check('install: modx_history shows it as irreversible and without objects', $pHistory['changeset_id'] === $pInstalled['changeset_id'] && $pHistory['summary'] === 'install package pdotools-2.13.2-pl' && $pHistory['reversible'] === false
    && $pHistory['tool'] === 'install_package' && $pHistory['source'] === 'mcp' && $pHistory['status'] === 'committed' && $pHistory['objects'] === array() && $pHistory['this_session'] === true);
$pHistoryDiff = $call($pAdmin, 'history_diff', array('changeset_id' => $pInstalled['changeset_id']));
check('install: modx_history_diff works for it with no objects', $pHistoryDiff['objects'] === array() && $pHistoryDiff['summary'] === 'install package pdotools-2.13.2-pl' && $pHistoryDiff['tool'] === 'install_package');
$pRefuse('installing it again', function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('package_name' => 'pdoTools', 'confirm' => true)); }, 'validation_failed', 'already installed', 1);
check('install: the package list shows it installed', $call($pActor('read'), 'get', array('kind' => 'package', 'key' => 'pdotools-2.13.2-pl'))['fields']['installed'] === '2026-02-03 04:05:06');

// The informational changeset is skipped by every bulk selection and cannot be undone by id; other undos work across it.
$pCheckpoint = $call($pAdmin, 'checkpoint', array('label' => 'around an install'))['checkpoint_id'];
$pBefore = $call($pAdmin, 'create', array('kind' => 'provider', 'fields' => array('name' => 'Before', 'service_url' => 'https://before.example/')));
$pSite->packageLogLines = array();
$pSite->packageCalls = array();
$pMigx = $call($pAdmin, 'install_package', array('signature' => 'migx-2.13.0-pl', 'confirm' => true));
check('install: a downloaded package is installed without download or search', $pMigx['performed'] === true && $pMigx['needs_download'] === false && $pMigx['log']['new_entries'] === 0 && $pMigx['log']['entries'] === array()
    && $pCalls() === array('workspace/packages/install') && $pMigx['installed'] === '2026-02-03 04:05:06');
$pAfter = $call($pAdmin, 'create', array('kind' => 'provider', 'fields' => array('name' => 'After', 'service_url' => 'https://after.example/')));
$pUndoInstall = $pMigx['changeset_id'];
$pRefuse('undo of the install by changeset id', function () use ($call, $pAdmin, $pUndoInstall) { $call($pAdmin, 'undo', array('changeset_id' => $pUndoInstall)); }, 'not_reversible', 'only records');
$pRefuse('confirmed undo of the install by changeset id', function () use ($call, $pAdmin, $pUndoInstall) { $call($pAdmin, 'undo', array('changeset_id' => $pUndoInstall, 'confirm' => true, 'force' => true)); }, 'not_reversible', 'only records');
$pUndoLast = $call($pAdmin, 'undo', array('last' => 2, 'confirm' => true));
check('install: undo last 2 skips the install and undoes the two changes around it', $pUndoLast['performed'] === true && $pUndoLast['changesets'] === array($pAfter['changeset_id'], $pBefore['changeset_id'])
    && $pSite->row('transport.modTransportProvider', $pAfter['id']) === null && $pSite->row('transport.modTransportProvider', $pBefore['id']) === null);
$pHistoryById = array_column($call($pAdmin, 'history', array('limit' => 10))['changesets'], null, 'changeset_id');
check('install: the install changeset is still committed and the package still installed', $pHistoryById[$pUndoInstall]['status'] === 'committed' && $pHistoryById[$pUndoInstall]['reversible'] === false
    && !isset($pHistoryById[$pUndoInstall]['undone_by']) && $pSite->row('transport.modTransportPackage', 'migx-2.13.0-pl')['installed'] === '2026-02-03 04:05:06');
$pToCheckpoint = $call($pAdmin, 'undo', array('to_checkpoint' => $pCheckpoint, 'confirm' => true));
check('install: undo to a checkpoint finds nothing to undo but the install', $pToCheckpoint['performed'] === false && $pToCheckpoint['changesets'] === array() && $pToCheckpoint['objects'] === array());
$pSessionUndo = $call($pAdmin, 'undo', array('this_session' => true));
check('install: undo of this session does not list it either', !in_array($pUndoInstall, $pSessionUndo['changesets'], true) && !in_array($pInstalled['changeset_id'], $pSessionUndo['changesets'], true));

// ---- install_package: failures leave a record ----
$pSite->packageCalls = array();
$pSite->failProcessor = 'workspace/packages/rest/download';
$pFail = throws('package: a failing download', ApiException::class, function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('package_name' => 'Tickets', 'confirm' => true)); });
check('install: a failing download is the processor\'s message, with the log and a changeset', $pFail && $pFail->errorCode === 'validation_failed' && strpos($pFail->getMessage(), 'Simulated failure') !== false
    && isset($pFail->details['log']['entries']) && is_int($pFail->details['changeset_id']) && $pSite->row('transport.modTransportPackage', 'tickets-3.0.0-pl') === null
    && $pAdmin->journal()->changeset($pFail->details['changeset_id'])['summary'] === 'install package tickets-3.0.0-pl (failed)' && $pAdmin->journal()->changeset($pFail->details['changeset_id'])['reversible'] === false
    && $pCalls() === array('workspace/packages/rest/getlist', 'workspace/packages/rest/download'));
$pSite->failProcessor = 'workspace/packages/install';
$pFail = throws('package: a failing install', ApiException::class, function () use ($call, $pAdmin) { $call($pAdmin, 'install_package', array('signature' => 'zero-1.0.0-pl', 'confirm' => true)); });
check('install: a failing install is reported and recorded; the package stays not installed', $pFail && $pFail->errorCode === 'validation_failed' && strpos($pFail->getMessage(), 'Simulated failure') !== false
    && $pAdmin->journal()->changeset($pFail->details['changeset_id'])['summary'] === 'install package zero-1.0.0-pl (failed)' && $pSite->row('transport.modTransportPackage', 'zero-1.0.0-pl')['installed'] === '0000-00-00 00:00:00');
$pSite->failProcessor = null;
$pSite->packageLogLines = array();
$pSite->packageCalls = array();
$pGallery = $call($pAdmin, 'install_package', array('package_name' => 'Галерея', 'confirm' => true));
check('install: Cyrillic package name end to end', $pGallery['performed'] === true && $pGallery['signature'] === 'галерея-1.0.0-pl' && $pSite->row('transport.modTransportPackage', 'галерея-1.0.0-pl')['package_name'] === 'Галерея'
    && $pSite->row('transport.modTransportPackage', 'галерея-1.0.0-pl')['installed'] === '2026-02-03 04:05:06');

// ---- install and uninstall: scopes, tools, groups ----
foreach (array('read', 'content', 'develop') as $scope) {
    foreach (array('install_package' => array('package_name' => 'Tickets'), 'uninstall_package' => array('signature' => 'formit-5.0.2-pl')) as $action => $args) {
        $e = throws("package: {$scope} token, {$action}", ApiException::class, function () use ($call, $pActor, $scope, $action, $args) { $call($pActor($scope), $action, $args); });
        check("package: {$scope} token cannot {$action}", $e && $e->errorCode === 'forbidden_scope'
            && !in_array('modx_' . $action, array_column(Manifest::tools($pActor($scope)), 'name'), true));
    }
}
$pTools = array_column(Manifest::tools($pAdmin), null, 'name');
check('package: the manifest marks both tools destructive and irreversible', $pTools['modx_install_package']['effect'] === array('read_only' => false, 'destructive' => true, 'reversible' => false, 'open_world' => true)
    && $pTools['modx_uninstall_package']['effect'] === array('read_only' => false, 'destructive' => true, 'reversible' => false, 'open_world' => false)
    && $pTools['modx_install_package']['scope'] === 'admin' && $pTools['modx_install_package']['group'] === 'package_management'
    && array_search('modx_install_package', array_keys($pTools), true) === array_search('modx_maintenance', array_keys($pTools), true) + 1);
$pSite->options['modxmcp.disabled_groups'] = 'package_management';
$pOffTools = array_column(Manifest::tools($pAdmin), 'name');
check('package: with the group disabled both tools leave the manifest', !in_array('modx_install_package', $pOffTools, true) && !in_array('modx_uninstall_package', $pOffTools, true));
foreach (array('install_package' => array('package_name' => 'Tickets', 'confirm' => true), 'uninstall_package' => array('signature' => 'formit-5.0.2-pl', 'confirm' => true)) as $action => $args) {
    $e = throws("package: disabled group, {$action}", ApiException::class, function () use ($call, $pAdmin, $action, $args) { $call($pAdmin, $action, $args); });
    check("package: {$action} is unavailable when the group is off", $e && $e->errorCode === 'unavailable');
}
foreach (array('package', 'provider') as $kindName) {
    foreach (array('list' => array('kind' => $kindName), 'describe' => array('kind' => $kindName), 'get' => array('kind' => $kindName, 'key' => 'formit-5.0.2-pl', 'id' => 1)) as $action => $args) {
        if ($kindName === 'package') { unset($args['id']); } else { unset($args['key']); }
        $e = throws("package: disabled group, {$action} {$kindName}", ApiException::class, function () use ($call, $pAdmin, $action, $args) { $call($pAdmin, $action, $args); });
        check("package: kind {$kindName} is unavailable for {$action} when the group is off", $e && $e->errorCode === 'unavailable');
    }
}
$e = throws('package: disabled group, provider search', ApiException::class, function () use ($call, $pActor) { $call($pActor('read'), 'invoke', array('kind' => 'provider', 'id' => 1, 'op' => 'search', 'args' => array('query' => 'x'))); });
check('package: the search is unavailable when the group is off', $e && $e->errorCode === 'unavailable');
check('package: the kinds leave the kind list', count(array_intersect(array('package', 'provider'), array_column($call($pAdmin, 'describe')['kinds'], 'kind'))) === 0);
$pSite->options['modxmcp.disabled_groups'] = '';
check('package: and come back when it is enabled', count(array_intersect(array('package', 'provider'), array_column($call($pAdmin, 'describe')['kinds'], 'kind'))) === 2);

// ---- uninstall_package ----
$pSite->packageCalls = array();
$pTablesBefore = $pTables();
$pStart = $pLast();
$pUPlan = $call($pAdmin, 'uninstall_package', array('signature' => 'formit-5.0.2-pl'));
check('uninstall: without confirm only the plan comes back', $pUPlan['performed'] === false && $pUPlan['signature'] === 'formit-5.0.2-pl' && $pUPlan['package_name'] === 'FormIt' && $pUPlan['version'] === '5.0.2-pl'
    && $pUPlan['installed'] === '2026-01-06 11:00:00' && $pUPlan['will_uninstall'] === true && $pUPlan['will_remove'] === false && $pUPlan['reversible'] === false
    && strpos($pUPlan['warning'], 'resolvers') !== false && strpos($pUPlan['warning'], 'modx_undo') !== false && !isset($pUPlan['planned']));
$pUPlanToken = $call($pPlan, 'uninstall_package', array('signature' => 'formit-5.0.2-pl', 'remove' => true, 'confirm' => true));
check('uninstall: a plan-only token never uninstalls, even with confirm: true', $pUPlanToken['performed'] === false && $pUPlanToken['planned'] === true && $pUPlanToken['will_remove'] === true
    && $pCalls() === array() && $pTables() === $pTablesBefore && $pLast() === $pStart);
check('uninstall: a package that is only downloaded can be planned for removal', $call($pAdmin, 'uninstall_package', array('signature' => 'каталог-1.2.0-beta1', 'remove' => true))['will_uninstall'] === false
    && $call($pAdmin, 'uninstall_package', array('signature' => 'каталог-1.2.0-beta1', 'remove' => true))['will_remove'] === true && $pCalls() === array());
$pRefuse('uninstalling a package that is not installed', function () use ($call, $pAdmin) { $call($pAdmin, 'uninstall_package', array('signature' => 'каталог-1.2.0-beta1', 'confirm' => true)); }, 'validation_failed', 'not installed');
$pRefuse('uninstalling an unknown package', function () use ($call, $pAdmin) { $call($pAdmin, 'uninstall_package', array('signature' => 'nothing-1.0.0-pl', 'confirm' => true)); }, 'not_found');
foreach (array(
    'the installed package' => array('signature' => 'modxmcp-2.0.0-pl'),
    'its signature in other case, not in the workspace' => array('signature' => 'MODXMCP-1.0.0-pl'),
    'a downloaded package whose recorded name is modxMCP' => array('signature' => 'sneaky-1.0.0-pl', 'remove' => true),
) as $label => $args) {
    foreach (array('' => array(), ' with confirm' => array('confirm' => true), ' with remove and confirm' => array('remove' => true, 'confirm' => true)) as $suffix => $more) {
        $pRefuse("uninstall of {$label}{$suffix}", function () use ($call, $pAdmin, $args, $more) { $call($pAdmin, 'uninstall_package', $more + $args); }, 'validation_failed', 'cannot install, replace or remove itself');
        $pRefuse("plan-only uninstall of {$label}{$suffix}", function () use ($call, $pPlan, $args, $more) { $call($pPlan, 'uninstall_package', $more + $args); }, 'validation_failed', 'cannot install, replace or remove itself');
    }
}
$pSite->packageLogLines = array('Uninstall resolver: файлы удалены');
$pBefore = $pLast();
$pUninstalled = $call($pAdmin, 'uninstall_package', array('signature' => 'formit-5.0.2-pl', 'confirm' => true));
check('uninstall: confirm runs the uninstall processor', $pCalls() === array('workspace/packages/uninstall') && $pSite->packageCalls[0][1] === array('signature' => 'formit-5.0.2-pl')
    && $pUninstalled['performed'] === true && $pUninstalled['uninstalled'] === true && $pUninstalled['removed'] === false && $pUninstalled['installed'] === '' && $pUninstalled['reversible'] === false
    && $pSite->row('transport.modTransportPackage', 'formit-5.0.2-pl')['installed'] === null);
check('uninstall: the log entries come back and one changeset without changes is recorded', $pUninstalled['log']['new_entries'] === 1 && strpos($pUninstalled['log']['entries'][0]['message'], 'файлы удалены') !== false
    && $pUninstalled['changeset_id'] === $pBefore + 1 && $pAdmin->journal()->changes(array($pUninstalled['changeset_id'])) === []
    && $call($pAdmin, 'history', array('limit' => 1))['changesets'][0]['summary'] === 'uninstall package formit-5.0.2-pl' && $call($pAdmin, 'history', array('limit' => 1))['changesets'][0]['reversible'] === false);
$pRefuse('undo of the uninstall by changeset id', function () use ($call, $pAdmin, $pUninstalled) { $call($pAdmin, 'undo', array('changeset_id' => $pUninstalled['changeset_id'], 'confirm' => true)); }, 'not_reversible');
$pSite->packageCalls = array();
$pSite->packageLogLines = array();
$pRemoved = $call($pAdmin, 'uninstall_package', array('signature' => 'pdotools-2.13.1-pl', 'remove' => true, 'confirm' => true));
check('uninstall: with remove the package is uninstalled and then deleted', $pCalls() === array('workspace/packages/uninstall', 'workspace/packages/remove') && $pRemoved['uninstalled'] === true && $pRemoved['removed'] === true
    && $pSite->row('transport.modTransportPackage', 'pdotools-2.13.1-pl') === null && $pRemoved['installed'] === ''
    && $pAdmin->journal()->changeset($pRemoved['changeset_id'])['summary'] === 'uninstall package pdotools-2.13.1-pl and remove it');
$pSite->packageCalls = array();
$pRemovedOnly = $call($pAdmin, 'uninstall_package', array('signature' => 'каталог-1.2.0-beta1', 'remove' => true, 'confirm' => true));
check('uninstall: a downloaded package is only removed', $pCalls() === array('workspace/packages/remove') && $pRemovedOnly['uninstalled'] === false && $pRemovedOnly['removed'] === true
    && $pSite->row('transport.modTransportPackage', 'каталог-1.2.0-beta1') === null);
$pSite->packageCalls = array();
$pSite->failProcessor = 'workspace/packages/uninstall';
$pFail = throws('package: a failing uninstall', ApiException::class, function () use ($call, $pAdmin) { $call($pAdmin, 'uninstall_package', array('signature' => 'pdotools-2.13.2-pl', 'remove' => true, 'confirm' => true)); });
check('uninstall: a failure stops before remove, is reported with the log and recorded', $pFail && $pFail->errorCode === 'validation_failed' && strpos($pFail->getMessage(), 'Simulated failure') !== false
    && $pCalls() === array('workspace/packages/uninstall') && $pSite->row('transport.modTransportPackage', 'pdotools-2.13.2-pl')['installed'] === '2026-02-03 04:05:06'
    && $pAdmin->journal()->changeset($pFail->details['changeset_id'])['summary'] === 'uninstall package pdotools-2.13.2-pl and remove it (failed)' && isset($pFail->details['log']['entries']));
$pSite->failProcessor = null;
check('package: nothing in the whole section ever put a package into the journal', $pAdmin->journal()->changesets(array('kind' => 'package'), 100) === array());

// --- Kernel helpers ---
check('canonical: key order does not matter', Kernel::canonical(array('b' => 1, 'a' => array('d' => 1, 'c' => 2))) === array('a' => array('c' => 2, 'd' => 1), 'b' => 1));
check('canonical: list order is kept', Kernel::canonical(array(3, 1, 2)) === array(3, 1, 2));
check('errors: every code has an HTTP status', min(ApiException::CODES) >= 400 && max(ApiException::CODES) <= 599);

echo $failures ? "{$failures} of {$checks} PHP checks failed.\n" : "PHP: {$checks} checks passed.\n";
exit($failures ? 1 : 0);
