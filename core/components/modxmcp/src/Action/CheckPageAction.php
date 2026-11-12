<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;
use ModxMCP\Verify\LogReader;

final class CheckPageAction extends Action {
    public function name() { return 'check_page'; }
    public function group() { return 'verify'; }
    public function description() {
        return 'Fetch one page as an anonymous visitor and report status, time, unparsed MODX tags and error-log entries written during the request; only this site\'s own addresses.';
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
    public function run(array $args, Context $context) {
        if (isset($args['id']) === isset($args['url_path'])) { throw new ApiException('validation_failed', 'Provide exactly one of id or url_path.'); }
        if (isset($args['id'])) {
            Objects::resolve($context, Objects::kind($context, 'resource'), array('id' => $args['id']));
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
        $logPath = $context->modx->getCachePath() . 'logs/error.log';
        $maxLog = $context->modx->getOption('modxmcp.max_read_bytes', null, 262144);
        $before = LogReader::tail($logPath, 1, $maxLog)['end_offset'];
        $start = microtime(true);
        try {
            $response = $context->pageFetcher()->fetch($url, 15, isset($args['max_bytes']) ? $args['max_bytes'] : 500000);
            $result = array('url' => $url, 'status' => $response['status'], 'time_ms' => $response['time_ms'],
                'bytes' => strlen($response['body']), 'truncated' => $response['truncated'],
                'content_type' => isset($response['headers']['content-type']) ? $response['headers']['content-type'] : '',
                'unparsed_tags' => array(), 'unparsed_count' => 0);
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
