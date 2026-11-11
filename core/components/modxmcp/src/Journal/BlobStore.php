<?php
namespace ModxMCP\Journal;

/**
 * Content-addressed storage for snapshots: a blob is named by the SHA-256 of its bytes, so
 * identical content is stored once and every read is verified. Lives outside the MODX cache
 * and the component directory, next to the request store.
 */
final class BlobStore {
    const FILE_GUARD = "<?php http_response_code(404); exit; ?>\n";

    private $dir;
    private $maxBytes;

    public function __construct($directory, $maxBytes = 5242880) {
        $this->dir = rtrim($directory, '/\\');
        $this->maxBytes = max(1024, (int) $maxBytes);
    }

    public static function hash($data) {
        return hash('sha256', $data);
    }

    private function path($hash) {
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) { throw new \InvalidArgumentException('Invalid blob hash.'); }
        return $this->dir . '/' . substr($hash, 0, 2) . '/' . $hash . '.php';
    }

    /** Store data; returns its hash, or null when it is over the size limit and was not stored. */
    public function put($data) {
        if (strlen($data) > $this->maxBytes) { return null; }
        $hash = self::hash($data);
        $path = $this->path($hash);
        if (is_file($path)) {
            // Reused content counts as fresh: retention must not collect it before the
            // change that refers to it has been written to the journal.
            @touch($path);
            return $hash;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { throw new \RuntimeException('Cannot create history storage.'); }
        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($temp, self::FILE_GUARD . $data) === false) { throw new \RuntimeException('Cannot write history storage.'); }
        @chmod($temp, 0600);
        if (!@rename($temp, $path)) { @unlink($temp); throw new \RuntimeException('Cannot publish a history blob.'); }
        return $hash;
    }

    public function has($hash) {
        return is_file($this->path($hash));
    }

    /** Stored blobs as [hash, on-disk bytes (including the guard), mtime]. */
    public function each() {
        if (!file_exists($this->dir)) { return; }
        $directories = @scandir($this->dir);
        if ($directories === false) { throw new \RuntimeException('Cannot read history storage.'); }
        foreach ($directories as $prefix) {
            $dir = $this->dir . '/' . $prefix;
            if (!preg_match('/^[a-f0-9]{2}$/D', $prefix) || is_link($dir) || !is_dir($dir)) { continue; }
            $files = @scandir($dir);
            if ($files === false) { throw new \RuntimeException('Cannot read history storage directory.'); }
            foreach ($files as $file) {
                if (!preg_match('/^([a-f0-9]{64})\.php$/D', $file, $match) || substr($match[1], 0, 2) !== $prefix) { continue; }
                $path = $dir . '/' . $file;
                clearstatcache(true, $path);
                if (is_link($path) || !is_file($path)) { continue; }
                $stat = @stat($path);
                if ($stat === false) { throw new \RuntimeException('Cannot inspect history blob.'); }
                yield array($match[1], (int) $stat['size'], (int) $stat['mtime']);
            }
        }
    }

    /** Remove one blob and its shard directory if empty. */
    public function delete($hash) {
        $path = $this->path($hash);
        clearstatcache(true, $path);
        if (!is_file($path)) { return false; }
        if (!@unlink($path)) { throw new \RuntimeException('Cannot remove history blob.'); }
        @rmdir(dirname($path)); // Another blob (or a concurrent writer) may still use the shard.
        return true;
    }

    /** Returns the stored bytes, or null when the blob is absent. Throws when it is corrupt. */
    public function get($hash) {
        $path = $this->path($hash);
        if (!is_file($path)) { return null; }
        $raw = @file_get_contents($path);
        $guard = strlen(self::FILE_GUARD);
        if ($raw === false || substr($raw, 0, $guard) !== self::FILE_GUARD) { throw new \RuntimeException('History blob is unreadable.'); }
        $data = substr($raw, $guard);
        if (!hash_equals($hash, self::hash($data))) { throw new \RuntimeException('History blob is corrupt.'); }
        return $data;
    }
}
