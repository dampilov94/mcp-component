<?php
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
$csp = "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";
header('Content-Security-Policy: ' . $csp);

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$token = null;
if ($method === 'POST' && strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0])) === 'application/json') {
    // Read at most one byte past the allowed length, before bootstrap or protected reads.
    $body = @file_get_contents('php://input', false, null, 0, 513);
    $request = is_string($body) && strlen($body) <= 512 ? json_decode($body) : null;
    if ($request instanceof stdClass && count(get_object_vars($request)) === 1 && isset($request->token) && is_string($request->token)) {
        $token = $request->token;
    }
}

$allowed = false;
$fields = null;
if ($method === 'GET' || $token !== null) {
    $bufferLevel = ob_get_level();
    ob_start();
    try {
        require_once dirname(__DIR__, 3) . '/config.core.php';
        require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
        $modx = new modX();
        // Settings and models suffice, without context initialization, events or sessions.
        $modx->getConfig();
        $corePath = $modx->getOption('modxmcp.core_path', null, $modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';
        $allowed = (bool) $modx->getOption('modxmcp.enabled', null, true) && \ModxMCP\Draft\DraftPreview::isHttps($modx, $_SERVER);
        if ($allowed && $method === 'POST') { $fields = (new \ModxMCP\Draft\DraftPreview($modx))->redeem($token); }
    } catch (Throwable $e) {
        $allowed = false;
    } finally {
        while (ob_get_level() > $bufferLevel) { if (!@ob_end_clean()) { break; } }
    }
}

if (!$allowed || $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$allowed || $fields === null) {
        http_response_code(404);
        echo '{"error":"preview_unavailable"}';
    } else {
        echo json_encode(array('fields' => $fields), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    return;
}

$nonce = base64_encode(random_bytes(18));
header('Content-Security-Policy: ' . $csp . "; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; connect-src 'self'");
header('Content-Type: text/html; charset=utf-8');
echo str_replace('PREVIEW_NONCE', $nonce, <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Draft preview</title>
<style nonce="PREVIEW_NONCE">
body { margin: 0; background: #fff; color: #17252c; font: 1rem/1.55 system-ui, sans-serif; }
main { max-width: 46rem; margin: 2.5rem auto; padding: 0 1.25rem; }
h1 { font-size: 1.7rem; }
h2 { font-size: 1rem; margin: 0 0 .5rem; }
section { border-top: 1px solid #d6e0e4; padding: 1rem 0; }
section p { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
</head>
<body>
<main>
<h1 id="title">Draft preview</h1>
<p id="status" role="status">Loading draft…</p>
<noscript>JavaScript is required to open this preview.</noscript>
<div id="fields"></div>
</main>
<script nonce="PREVIEW_NONCE">
(function () {
    'use strict';
    var url = new URL(window.location.href);
    var token = url.hash.slice(1);
    url.hash = '';
    url.search = '';
    window.history.replaceState(null, '', url.href);

    var ru = /^ru\b/i.test(navigator.language || '');
    var labels = ru
        ? {pagetitle: 'Название', longtitle: 'Расширенный заголовок', description: 'Описание', introtext: 'Вступление', content: 'Содержимое', menutitle: 'Название в меню'}
        : {pagetitle: 'Title', longtitle: 'Long title', description: 'Description', introtext: 'Introduction', content: 'Content', menutitle: 'Menu title'};
    var status = document.getElementById('status');
    var failed = function () {
        status.textContent = ru ? 'Ссылка истекла или предпросмотр недоступен.' : 'The link has expired or the preview is unavailable.';
    };
    if (ru) {
        document.documentElement.lang = 'ru';
        document.title = 'Предпросмотр черновика';
        document.getElementById('title').textContent = document.title;
        status.textContent = 'Загрузка черновика…';
    }
    if (!token) { failed(); return; }
    fetch(url.href, {
        method: 'POST', credentials: 'omit', cache: 'no-store', redirect: 'error',
        headers: {'Content-Type': 'application/json'}, body: JSON.stringify({token: token})
    }).then(function (response) {
        if (!response.ok) { throw new Error('Preview unavailable'); }
        return response.json();
    }).then(function (data) {
        if (!data.fields || typeof data.fields !== 'object') { throw new Error('Preview unavailable'); }
        var fields = document.getElementById('fields');
        Object.keys(labels).forEach(function (field) {
            if (typeof data.fields[field] !== 'string') { return; }
            var row = document.createElement('section');
            var title = document.createElement('h2');
            var value = document.createElement('p');
            title.textContent = labels[field];
            value.textContent = data.fields[field] === '' ? (ru ? 'Поле очищено' : 'Field cleared') : data.fields[field];
            row.appendChild(title);
            row.appendChild(value);
            fields.appendChild(row);
        });
        status.textContent = ru ? 'Изменённые текстовые поля' : 'Changed text fields';
    }).catch(failed);
})();
</script>
</body>
</html>
HTML
);
