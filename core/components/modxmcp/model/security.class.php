<?php
/** Shared matching for socket-peer allowlists and explicitly trusted proxies. */
class ModxMCPSecurity {
    public static function matchesIpList($ip, $rules) {
        $address = @inet_pton(trim((string) $ip));
        if ($address === false) { return false; }
        foreach (explode(',', (string) $rules) as $rule) {
            $parts = explode('/', trim($rule), 2);
            $network = @inet_pton(trim($parts[0]));
            if ($network === false || strlen($network) !== strlen($address)) { continue; }
            if (count($parts) === 1) {
                if ($address === $network) { return true; }
                continue;
            }
            $bitsText = trim($parts[1]);
            if (!preg_match('/^[0-9]{1,3}$/D', $bitsText)) { continue; }
            $bits = (int) $bitsText;
            if ($bits > strlen($address) * 8) { continue; }
            $bytes = intdiv($bits, 8);
            if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) { continue; }
            $remainder = $bits % 8;
            if ($remainder !== 0) {
                $mask = (0xff << (8 - $remainder)) & 0xff;
                if ((ord($address[$bytes]) & $mask) !== (ord($network[$bytes]) & $mask)) { continue; }
            }
            return true;
        }
        return false;
    }

    public static function isHttps(array $server, $trustedProxies) {
        $peer = isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : '';
        if (self::matchesIpList($peer, $trustedProxies)) {
            // Backend TLS does not prove frontend TLS. An explicit proxy must replace
            // client headers and report one original protocol value, never a chain.
            $forwarded = isset($server['HTTP_X_FORWARDED_PROTO']) ? strtolower(trim((string) $server['HTTP_X_FORWARDED_PROTO'])) : '';
            return $forwarded === 'https';
        }
        $https = isset($server['HTTPS']) ? strtolower(trim((string) $server['HTTPS'])) : '';
        return in_array($https, array('on', '1', 'https'), true);
    }
}
