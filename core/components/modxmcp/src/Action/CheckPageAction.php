<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Draft\DraftPolicy;
use ModxMCP\Draft\DraftStore;
use ModxMCP\Kind\Objects;
use ModxMCP\Render\RenderKeys;
use ModxMCP\Schema;
use ModxMCP\Verify\HeaderPageFetcher;
use ModxMCP\Verify\LogReader;

final class CheckPageAction extends Action {
    public function name() { return 'check_page'; }
    public function group() { return 'verify'; }
    public function description() {
        return 'Fetch one page of this site as an anonymous visitor and report status, time, title and the error-log entries written '
            . 'during the request — a missing snippet or a broken tag shows up there, because MODX removes unknown tags from the output. '
            . 'Tags that did survive are listed as unparsed. Only this site\'s own addresses. '
            . 'With id, a draft or any unpublished page is rendered too (rendered_as): the site sends itself a one-time key, valid for 60 seconds '
            . 'and for that one page only, so it works over plain HTTP; nothing is published and nothing reaches the page cache.';
    }
    public function inputSchema() {
        return Schema::obj(array('id' => Schema::int(null, array('minimum' => 1)),
            'url_path' => Schema::str('Site-relative path starting with /.', array('maxLength' => 1000)),
            'context' => Schema::str('Context key, default web.'),
            'max_bytes' => Schema::int('Body byte limit, default 500000.', array('minimum' => 1000, 'maximum' => 2000000))));
    }
    public function outputSchema() {
        return Schema::obj(array('url' => Schema::str(), 'status' => Schema::int(), 'time_ms' => Schema::int(),
            'log' => Schema::obj(array(), array(), true)), array('url', 'status', 'time_ms', 'log'), true);
    }
    private function siteUrl($modx, $key) {
        $setting = $modx->getObject('modContextSetting', array('context_key' => $key, 'key' => 'site_url'));
        return $setting && $setting->get('value') !== '' ? (string) $setting->get('value') : (string) $modx->getOption('site_url');
    }
    private function allowedHosts($modx) {
        $urls = array((string) $modx->getOption('site_url'));
        foreach ($modx->getCollection('modContext') as $context) {
            $key = $context->get('key');
            $urls[] = $this->siteUrl($modx, $key);
            $host = $modx->getObject('modContextSetting', array('context_key' => $key, 'key' => 'http_host'));
            if ($host && $host->get('value') !== '') { $urls[] = 'http://' . $host->get('value'); }
        }
        $hosts = array();
        foreach ($urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') { $hosts[mb_strtolower($host, 'UTF-8')] = true; }
        }
        return $hosts;
    }
    private function allowed($url, array $hosts) {
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $url)) { return false; }
        $parts = parse_url($url);
        return is_array($parts) && isset($parts['scheme'], $parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
            && in_array(strtolower($parts['scheme']), array('http', 'https'), true) && isset($hosts[mb_strtolower($parts['host'], 'UTF-8')]);
    }
    private function origin($url) {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new ApiException('validation_failed', 'The context has no valid site URL.');
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
    private function redirectUrl($url, $location) {
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $location)) { return $location; }
        if (substr($location, 0, 2) === '//') { return parse_url($url, PHP_URL_SCHEME) . ':' . $location; }
        if (substr($location, 0, 1) === '/') { return $this->origin($url) . $location; }
        return $this->origin($url) . '/' . $location;
    }
    private function clean($text) {
        return json_decode(json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true);
    }
    /** "draft", "unpublished" or null (a published page, or a deleted one that MODX will not serve anyway). */
    private function renderMode(Context $context, $page) {
        $unpublished = !$page->get('published') && !$page->get('deleted');
        if (DraftPolicy::applies($context)) {
            $link = (new DraftStore($context->modx))->ofDraft($page->get('id'));
            if (!$link || $link['token_id'] !== $context->token['id'] || $link['user_id'] !== null || !$unpublished) {
                throw new ApiException('forbidden_scope', 'A draft-only token can check only its own unpublished page drafts.');
            }
            return 'draft';
        }
        if (!$unpublished) { return null; }
        try {
            return (new DraftStore($context->modx, false))->isDraft($page->get('id')) ? 'draft' : 'unpublished';
        } catch (\Throwable $e) {
            return 'unpublished';
        }
    }
    public function run(array $args, Context $context) {
        if (isset($args['id']) === isset($args['url_path'])) { throw new ApiException('validation_failed', 'Provide exactly one of id or url_path.'); }
        $renderAs = null;
        if (DraftPolicy::applies($context) && !isset($args['id'])) {
            throw new ApiException('forbidden_scope', 'A draft-only token checks its own drafts by id.');
        }
        if (isset($args['id'])) {
            $page = Objects::resolve($context, Objects::kind($context, 'resource'), array('id' => $args['id']));
            $renderAs = $this->renderMode($context, $page);
            $url = $context->modx->makeUrl($args['id'], '', '', 'full');
            if (!is_string($url) || $url === '') { throw new ApiException('validation_failed', 'MODX returned an empty resource URL.'); }
        } else {
            $path = $args['url_path'];
            $decoded = rawurldecode($path);
            $pathPart = explode('?', explode('#', $decoded, 2)[0], 2)[0];
            if (substr($path, 0, 1) !== '/' || substr($decoded, 0, 2) === '//' || preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded)
                || in_array('..', explode('/', $pathPart), true)) {
                throw new ApiException('validation_failed', 'url_path must be a site path without host changes, controls, backslashes or .. segments.');
            }
            $url = $this->origin($this->siteUrl($context->modx, isset($args['context']) ? $args['context'] : 'web')) . $path;
        }
        $hosts = $this->allowedHosts($context->modx);
        if (!$this->allowed($url, $hosts)) { throw new ApiException('validation_failed', 'Only HTTP(S) URLs on this site\'s configured hosts are allowed.'); }
        $headers = array();
        if ($renderAs !== null) {
            if (!($context->pageFetcher() instanceof HeaderPageFetcher)) { throw new ApiException('validation_failed', 'This page fetcher cannot render unpublished pages.'); }
            $ownHost = parse_url((string) $context->modx->getOption('site_url'), PHP_URL_HOST);
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($ownHost) || !is_string($host) || mb_strtolower($ownHost, 'UTF-8') !== mb_strtolower($host, 'UTF-8')) {
                throw new ApiException('validation_failed', 'The page URL is not on the site\'s own host (its context has another site_url), so an unpublished page cannot be rendered safely.');
            }
        }
        $logPath = $context->modx->getCachePath() . 'logs/error.log';
        $maxLog = $context->modx->getOption('modxmcp.max_read_bytes', null, 262144);
        $before = LogReader::tail($logPath, 1, $maxLog)['end_offset'];
        $start = microtime(true);
        try {
            $limit = isset($args['max_bytes']) ? $args['max_bytes'] : 500000;
            if ($renderAs !== null) {
                $key = RenderKeys::forModx($context->modx)->issue($args['id'], isset($context->token['id']) ? $context->token['id'] : null);
                $response = $context->pageFetcher()->fetchWithHeaders($url, 15, $limit, array(RenderKeys::HEADER => $key));
            } else {
                $response = $context->pageFetcher()->fetch($url, 15, $limit);
            }
            $result = array('url' => $url, 'status' => $response['status'], 'time_ms' => $response['time_ms'],
                'bytes' => strlen($response['body']), 'truncated' => $response['truncated'],
                'content_type' => isset($response['headers']['content-type']) ? $response['headers']['content-type'] : '',
                'unparsed_tags' => array(), 'unparsed_count' => 0);
            if ($renderAs !== null) { $result['rendered_as'] = $renderAs; }
            $type = strtolower(trim(explode(';', $result['content_type'], 2)[0]));
            if (substr($type, 0, 5) === 'text/') {
                $body = $this->clean($response['body']);
                if ($type === 'text/html' && preg_match('/<title\b[^>]*>(.*?)<\/title\s*>/isu', $body, $title)) {
                    $text = trim(html_entity_decode(strip_tags($title[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $result['title'] = mb_substr($text, 0, 300, 'UTF-8');
                }
                $scanned = preg_replace_callback('/<(script|style)\b[^>]*>.*?<\/\1\s*>/isu', function ($match) {
                    return preg_replace('/[^\r\n]/', ' ', $match[0]);
                }, $body);
                preg_match_all('/\[\[[^\[\]\r\n]{1,300}\]\]/u', $scanned, $tags, PREG_OFFSET_CAPTURE);
                $result['unparsed_count'] = count($tags[0]);
                foreach (array_slice($tags[0], 0, 20) as $tag) {
                    $result['unparsed_tags'][] = array('tag' => $tag[0], 'line' => substr_count(substr($scanned, 0, $tag[1]), "\n") + 1);
                }
            }
            if ($response['status'] >= 300 && $response['status'] < 400 && isset($response['headers']['location'])) {
                $result['location'] = $this->clean($response['headers']['location']);
                $result['redirect_same_site'] = $this->allowed($this->redirectUrl($url, $result['location']), $hosts);
            }
        } catch (\RuntimeException $e) {
            $result = array('url' => $url, 'status' => 0, 'error' => $this->clean($e->getMessage()), 'time_ms' => (int) round((microtime(true) - $start) * 1000));
        }
        $log = LogReader::tail($logPath, 20, $maxLog, '', $before);
        return $result + array('log' => array('new_entries' => $log['returned'], 'entries' => $log['entries']));
    }
}
