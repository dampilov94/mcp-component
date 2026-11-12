<?php
namespace ModxMCP\Files;

use ModxMCP\Code\Diff;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Journal\Subject;

/** One file below a file root. Directories are not subjects: they exist while they hold files. */
final class FileSubject implements Subject {
    private $root;
    private $relative;

    public function __construct(FileRoot $root, $relative) {
        $this->root = $root;
        $this->relative = FileRoot::normalize($relative);
    }

    public function kindName() { return 'file'; }

    public function key() { return $this->root->id . ':' . $this->relative; }

    public function publicKey() { return $this->key(); }

    public function relative() { return $this->relative; }

    /** Split a journal key into [root id, relative path]. */
    public static function parseKey($key) {
        $at = strpos((string) $key, ':');
        return $at === false ? array('', '') : array(substr($key, 0, $at), substr($key, $at + 1));
    }

    public function capture() {
        $path = $this->root->resolve($this->relative);
        clearstatcache(true, $path);
        if (!is_file($path)) { return null; }
        $content = @file_get_contents($path);
        if ($content === false) { throw new \RuntimeException("Cannot read {$this->relative}."); }
        $text = preg_match('//u', $content) === 1 && strpos($content, "\0") === false;
        return array(
            'kind' => 'file', 'key' => $this->key(), 'path' => $this->relative,
            'encoding' => $text ? 'utf8' : 'base64',
            'content' => $text ? $content : base64_encode($content),
            'size' => strlen($content),
            'mode' => substr(sprintf('%o', (int) @fileperms($path)), -3),
        );
    }

    public function captureDeleteExtra() { return array(); }

    /** Raw bytes of a state. */
    public static function bytes(array $state) {
        return $state['encoding'] === 'base64' ? (string) base64_decode($state['content'], true) : $state['content'];
    }

    public function restore($state, array $extra) {
        $path = $this->root->resolve($this->relative);
        if ($state === null) {
            if (is_file($path) && !@unlink($path)) { throw new \RuntimeException("Cannot remove {$this->relative}."); }
            self::pruneEmptyDirectories(dirname($path), $this->root->path);
            return;
        }
        Snapshot::writeFile($path, self::bytes($state));
        if (isset($state['mode']) && preg_match('/^[0-7]{3}$/', $state['mode'])) { @chmod($path, octdec($state['mode'])); }
    }

    /** Remove directories left empty, going up, but never the root. */
    public static function pruneEmptyDirectories($dir, $rootPath) {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        while ($dir !== $rootPath && strpos($dir . '/', $rootPath . '/') === 0 && is_dir($dir)) {
            if (count(array_diff((array) scandir($dir), array('.', '..'))) > 0 || !@rmdir($dir)) { break; }
            $dir = dirname($dir);
        }
    }

    public function label($state) { return $this->relative; }

    public function changed($before, $after) {
        $changed = array();
        if (($before === null) !== ($after === null) || ($before !== null && ($before['content'] !== $after['content'] || $before['encoding'] !== $after['encoding']))) {
            $changed[] = 'content';
        }
        if ($before !== null && $after !== null && $before['mode'] !== $after['mode']) { $changed[] = 'mode'; }
        return $changed;
    }

    public function describeChange($before, $after) {
        $out = array();
        foreach ($this->changed($before, $after) as $name) {
            if ($name === 'mode') { $out['mode'] = array('old' => $before['mode'], 'new' => $after['mode']); continue; }
            $binary = ($before !== null && $before['encoding'] === 'base64') || ($after !== null && $after['encoding'] === 'base64');
            $out['content'] = $binary
                ? array('old' => $before === null ? null : $before['size'] . ' bytes (binary)', 'new' => $after === null ? null : $after['size'] . ' bytes (binary)')
                : array('diff' => Diff::lines($before === null ? '' : $before['content'], $after === null ? '' : $after['content']));
        }
        return $out;
    }
}
