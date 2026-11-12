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
check('manifest: full tool count', count($all) === 32);
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
check('describe: lists element kinds', array_column($list['kinds'], 'kind') === array('chunk', 'snippet', 'template', 'plugin', 'tv', 'category', 'resource', 'media_source', 'user_group', 'role', 'resource_group'));
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
    && count($accessAdmin->journal()->changes(array($addedMember['changeset_id']))) === 1
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
    check('make_static: conversion ' . $kindName, $converted['status'] === 'converted' && $converted['static_file'] === $stored && file_get_contents($absolute) === $code
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

// --- Kernel helpers ---
check('canonical: key order does not matter', Kernel::canonical(array('b' => 1, 'a' => array('d' => 1, 'c' => 2))) === array('a' => array('c' => 2, 'd' => 1), 'b' => 1));
check('canonical: list order is kept', Kernel::canonical(array(3, 1, 2)) === array(3, 1, 2));
check('errors: every code has an HTTP status', min(ApiException::CODES) >= 400 && max(ApiException::CODES) <= 599);

echo $failures ? "{$failures} of {$checks} PHP checks failed.\n" : "PHP: {$checks} checks passed.\n";
exit($failures ? 1 : 0);
