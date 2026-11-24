<?php
namespace ModxMCP\Render;

use ModxMCP\Request\RequestStore;

/**
 * One-time render keys: the site hands one to itself when modx_check_page has to see an unpublished page.
 * A key is 32 random bytes (64 hex characters in the header X-MCP-Render). Only its SHA-256 is stored, as the
 * name of a guarded file that holds the resource id, the creating token and the expiry. It is single-use:
 * consuming claims the file with an atomic rename, so concurrent requests cannot both win.
 */
final class RenderKeys {
    const HEADER = 'X-MCP-Render';
    const TTL = 60;
    /** Files older than this (any state) are removed when a key is issued. */
    private const STALE = 300;
    private const CLEANUP_BATCH = 64;
    private $dir;

    public function __construct($directory) {
        $this->dir = rtrim($directory, '/\\');
    }

    public static function forModx(\modX $modx) {
        return new self(rtrim($modx->getOption('core_path'), '/\\') . '/modxmcp-data/render');
    }

    public static function validKey($key) {
        return is_string($key) && preg_match('/^[a-f0-9]{64}$/D', $key) === 1;
    }

    /** @return string the key; show it to nobody but the request that sends it to the site itself */
    public function issue($resourceId, $tokenId, $now = null) {
        $now = $now === null ? time() : (int) $now;
        $this->ensureDirectory();
        $this->cleanup($now);
        $key = bin2hex(random_bytes(32));
        $hash = hash('sha256', $key);
        $record = json_encode(array('hash' => $hash, 'resource' => (int) $resourceId,
            'token_id' => $tokenId === null ? null : (int) $tokenId, 'expires_at' => $now + self::TTL), JSON_THROW_ON_ERROR);
        $path = $this->dir . '/' . $hash . '.php';
        $file = @fopen($path, 'xb');
        if (!$file) { throw new \RuntimeException('Could not store the render key.'); }
        try {
            $data = RequestStore::FILE_GUARD . $record;
            if (!@chmod($path, 0600) || fwrite($file, $data) !== strlen($data) || !fflush($file)) {
                throw new \RuntimeException('Could not write the render key.');
            }
        } catch (\Throwable $e) {
            fclose($file);
            @unlink($path);
            throw $e;
        }
        fclose($file);
        return $key;
    }

    /**
     * Use a key up: it is gone afterwards whatever the answer.
     * @return array|null ['resource' => int, 'token_id' => int|null] when the key existed and had not expired
     */
    public function consume($key, $now = null) {
        $now = $now === null ? time() : (int) $now;
        if (!self::validKey($key) || is_link($this->dir)) { return null; }
        $hash = hash('sha256', $key);
        $path = $this->dir . '/' . $hash . '.php';
        $claimed = $this->dir . '/' . $hash . '.used-' . bin2hex(random_bytes(6));
        if (is_link($path) || !@rename($path, $claimed)) { return null; }
        $raw = @file_get_contents($claimed, false, null, 0, 2048);
        @unlink($claimed);
        $guard = RequestStore::FILE_GUARD;
        if (!is_string($raw) || strncmp($raw, $guard, strlen($guard)) !== 0) { return null; }
        $record = json_decode(substr($raw, strlen($guard)), true);
        if (!is_array($record) || !isset($record['hash'], $record['resource'], $record['expires_at'])
            || !is_string($record['hash']) || !hash_equals($hash, $record['hash'])
            || !is_int($record['resource']) || !is_int($record['expires_at']) || $record['expires_at'] <= $now) {
            return null;
        }
        return array('resource' => $record['resource'], 'token_id' => isset($record['token_id']) && is_int($record['token_id']) ? $record['token_id'] : null);
    }

    /**
     * The decision of the plugin, free of MODX: the id of the resource to serve, or null to leave MODX alone.
     * The key is consumed in every case that has a well-formed key.
     * @param mixed $headerValue the raw X-MCP-Render value
     * @param int $requestedId the resource MODX is about to serve (0 when unknown)
     */
    public function grant($headerValue, $requestedId, $now = null) {
        if (!is_string($headerValue) || $headerValue === '') { return null; }
        $record = $this->consume($headerValue, $now);
        if ($record === null || (int) $requestedId < 1 || $record['resource'] !== (int) $requestedId) { return null; }
        return $record['resource'];
    }

    private function ensureDirectory() {
        if (is_link($this->dir)) { throw new \RuntimeException('Invalid render key storage.'); }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Could not prepare render key storage.');
        }
    }

    private function cleanup($now) {
        $done = 0;
        foreach (new \DirectoryIterator($this->dir) as $file) {
            if ($done >= self::CLEANUP_BATCH) { break; }
            if (!$file->isFile() || !preg_match('/^[a-f0-9]{64}\.(php|used-[a-f0-9]+)$/D', $file->getFilename())) { continue; }
            if ($file->getMTime() < $now - self::STALE) { @unlink($file->getPathname()); ++$done; }
        }
    }
}
