<?php
namespace ModxMCP\Draft;

use ModxMCP\Request\RequestStore;

/** The site-local signing key. Only the manager issuance path can provision it. */
final class PreviewKey {
    private $directory;

    public function __construct(\modX $modx) {
        $this->directory = rtrim($modx->getOption('core_path'), '/\\') . '/modxmcp-data/draft-preview';
    }

    public function read() {
        $path = $this->directory . '/key.php';
        if (is_link($this->directory) || is_link($path) || !is_file($path)) { return null; }
        $guard = RequestStore::FILE_GUARD;
        $raw = @file_get_contents($path, false, null, 0, strlen($guard) + 65);
        if (!is_string($raw) || strncmp($raw, $guard, strlen($guard)) !== 0) { return null; }
        $key = substr($raw, strlen($guard));
        return preg_match('/^[a-f0-9]{64}$/D', $key) ? hex2bin($key) : null;
    }

    public function getOrCreate() {
        $key = $this->read();
        if ($key !== null) { return $key; }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not prepare draft preview storage.');
        }
        $lockPath = $this->directory . '/key.lock';
        if (is_link($this->directory) || is_link($lockPath)) { throw new \RuntimeException('Invalid draft preview storage.'); }
        $lock = @fopen($lockPath, 'c');
        if (!$lock) { throw new \RuntimeException('Could not open draft preview storage.'); }
        try {
            if (!@chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) { throw new \RuntimeException('Could not lock draft preview storage.'); }
            $path = $this->directory . '/key.php';
            clearstatcache(true, $path);
            $key = $this->read();
            if ($key !== null) { return $key; }
            if (file_exists($path) || is_link($path)) { throw new \RuntimeException('The draft preview key could not be read.'); }
            $key = random_bytes(32);
            $this->write($path, RequestStore::FILE_GUARD . bin2hex($key));
            return $key;
        } finally {
            fclose($lock);
        }
    }

    private function write($path, $data) {
        $temp = $this->directory . '/key-' . bin2hex(random_bytes(12)) . '.php';
        $file = @fopen($temp, 'xb');
        if (!$file) { throw new \RuntimeException('Could not stage the draft preview key.'); }
        try {
            try {
                if (!@chmod($temp, 0600) || fwrite($file, $data) !== strlen($data) || !fflush($file)) {
                    throw new \RuntimeException('Could not write the draft preview key.');
                }
            } finally {
                fclose($file);
            }
            if (!@rename($temp, $path)) { throw new \RuntimeException('Could not publish the draft preview key.'); }
        } finally {
            if (is_file($temp)) { @unlink($temp); }
        }
    }
}
