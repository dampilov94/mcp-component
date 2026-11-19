<?php
namespace ModxMCP\Files;

final class Path {
    /** A root or a protected directory: placeholders like {core_path} filled in, a relative path taken from base_path. */
    public static function expand(\modX $modx, $entry) {
        $path = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($modx) {
            return (string) $modx->getOption($m[1], null, $m[1] === 'assets_path' ? rtrim((string) $modx->getOption('base_path'), '/\\') . '/assets/' : '');
        }, $entry);
        $path = str_replace('\\', '/', $path);
        if ($path === '' || $path[0] === '/' || preg_match('#^[A-Za-z]:/#', $path)) { return $path; }
        return rtrim(str_replace('\\', '/', (string) $modx->getOption('base_path')), '/') . '/' . $path;
    }

    /**
     * Lexical (no "..", no links followed) and real (nearest existing parent resolved, the rest appended) form of a path,
     * or null for a path that is empty, a stream wrapper, contains "..", or cannot be resolved.
     * @return array|null [lexical, real]
     */
    public static function normalize($path) {
        $path = str_replace('\\', '/', (string) $path);
        if ($path === '' || strpos($path, "\0") !== false || strpos($path, '://') !== false) { return null; }
        if ($path[0] !== '/' && !preg_match('#^[A-Za-z]:/#', $path)) {
            $cwd = getcwd();
            if ($cwd === false) { return null; }
            $path = str_replace('\\', '/', $cwd) . '/' . $path;
        }
        $parts = array();
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { return null; }
            $parts[] = $part;
        }
        $lexical = ($path[0] === '/' ? '/' : '') . implode('/', $parts);
        $probe = $lexical;
        $rest = array();
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) { return null; }
            array_unshift($rest, basename($probe));
            $probe = $parent;
        }
        $real = realpath($probe);
        if ($real === false) { return null; }
        $real = rtrim(str_replace('\\', '/', $real), '/');
        return array($lexical, $real . ($rest ? '/' . implode('/', $rest) : ''));
    }

    /** True when $path is $dir or lies below it (both normalised, no trailing slash needed). */
    public static function inside($path, $dir) {
        return $path === $dir || strpos($path . '/', rtrim($dir, '/') . '/') === 0;
    }
}
