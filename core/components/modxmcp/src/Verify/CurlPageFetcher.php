<?php
namespace ModxMCP\Verify;

use ModxMCP\Kernel;

final class CurlPageFetcher implements HeaderPageFetcher {
    public function fetch($url, $timeoutSeconds, $maxBytes): array {
        return $this->fetchWithHeaders($url, $timeoutSeconds, $maxBytes, array());
    }
    public function fetchWithHeaders($url, $timeoutSeconds, $maxBytes, array $headers): array {
        if (!function_exists('curl_init')) { throw new \RuntimeException('The PHP curl extension is required.'); }
        $curl = curl_init($url);
        if ($curl === false) { throw new \RuntimeException('Cannot initialize curl.'); }
        $body = '';
        $received = array();
        $sent = array();
        foreach ($headers as $name => $value) {
            if (preg_match('/[\x00-\x1f\x7f:]/', $name . $value)) { throw new \RuntimeException('Invalid request header.'); }
            $sent[] = $name . ': ' . $value;
        }
        $truncated = false;
        try {
            curl_setopt_array($curl, array(
                CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => $sent, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => (int) $timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_NETRC => CURL_NETRC_IGNORED, CURLOPT_USERAGENT => 'modxMCP-check/' . Kernel::VERSION,
                CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$received) {
                    if (preg_match('/^HTTP\//', $line)) { $received = array(); }
                    $colon = strpos($line, ':');
                    if ($colon !== false) { $received[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1)); }
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
            return array('status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'headers' => $received, 'body' => $body,
                'time_ms' => (int) round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000), 'truncated' => $truncated);
        } finally { curl_close($curl); }
    }
}
