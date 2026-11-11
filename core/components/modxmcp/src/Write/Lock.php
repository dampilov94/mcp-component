<?php
namespace ModxMCP\Write;

use ModxMCP\ApiException;

/** Per-object locks between concurrent MCP requests. Lock files are never deleted. */
final class Lock {
    private $dir;
    private $handles = array();

    public function __construct($directory) {
        $this->dir = rtrim($directory, '/\\');
    }

    /** Take the lock for a key or fail at once; a second acquire of the same key is a no-op. */
    public function acquire($key) {
        if (isset($this->handles[$key])) { return; }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Cannot create the lock directory.');
        }
        $handle = @fopen($this->dir . '/' . hash('sha256', $key) . '.lock', 'c');
        if (!$handle) { throw new \RuntimeException('Cannot open an object lock.'); }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new ApiException('locked', "{$key} is being changed by another MCP request. Read it again and retry.");
        }
        $this->handles[$key] = $handle;
    }

    public function releaseAll() {
        foreach (array_reverse($this->handles) as $handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->handles = array();
    }
}
