<?php
namespace ModxMCP\Verify;

use ModxMCP\Kernel;

final class CurlPageFetcher implements PageFetcher {
    public function fetch($url, $timeoutSeconds, $maxBytes): array {
        if (!function_exists('curl_init')) { throw new \RuntimeException('The PHP curl extension is required.'); }
        $curl = curl_init($url);
        if ($curl === false) { throw new \RuntimeException('Cannot initialize curl.'); }
        $body = '';
        $headers = array();
        $truncated = false;
        try {
            curl_setopt_array($curl, array(
                CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => (int) $timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_NETRC => CURL_NETRC_IGNORED, CURLOPT_USERAGENT => 'modxMCP-check/' . Kernel::VERSION,
                CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$headers) {
                    if (preg_match('/^HTTP\//', $line)) { $headers = array(); }
                    $colon = strpos($line, ':');
                    if ($colon !== false) { $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1)); }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body, &$truncated, $maxBytes) {
                    $remaining = max(0, (int) $maxBytes - strlen($body));
                    $body .= substr($chunk, 0, $remaining);
                    if (strlen($chunk) > $remaining) { $truncated = true; return 0; }
                    return strlen($chunk);
                },
            ));
            $ok = curl_exec($curl);
            if ($ok === false && !($truncated && curl_errno($curl) === CURLE_WRITE_ERROR)) { throw new \RuntimeException(curl_error($curl)); }
            return array('status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'headers' => $headers, 'body' => $body,
                'time_ms' => (int) round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000), 'truncated' => $truncated);
        } finally { curl_close($curl); }
    }
}
