<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Files\FileRoot;

/**
 * Where the file of a static element may live. MODX itself accepts any path (its own check,
 * isStaticSourceValidPath(), only looks at the directory of config.inc.php and only when the content
 * changes), so the API restricts it: the file must be inside one of the roots of the setting
 * modxmcp.static_roots, reached without a symbolic link, and not in the protected directories.
 */
final class StaticPath {
    // The whole site and core by default: a develop token can run any PHP through a snippet anyway, so what this rule
    // protects is what code cannot be trusted to keep private (DENIED, credentials files), plus MODX's own code against
    // being overwritten by mistake (WRITE_DENIED). Narrow it with modxmcp.static_roots if a site wants more.
    const DEFAULT_ROOTS = '{base_path},{core_path}';
    /** Directories that never hold a static element, even inside a root. */
    const DENIED = array('{core_path}config/', '{core_path}modxmcp-data/', '{core_path}cache/', '{core_path}packages/');
    /** MODX's own code: may be read as a static file, never written through one. */
    const WRITE_DENIED = array('{core_path}model/', '{core_path}xpdo/', '{core_path}lexicon/', '{core_path}docs/',
        '{manager_path}', '{connectors_path}', '{base_path}manager/', '{base_path}connectors/', '{base_path}setup/', '{base_path}index.php');

    /** The setting as written (or the default), for messages. */
    public static function rootsSetting(\modX $modx) {
        $value = trim((string) $modx->getOption('modxmcp.static_roots', null, ''));
        return $value === '' ? self::DEFAULT_ROOTS : $value;
    }

    /** Absolute (not yet normalised) directories of the roots. */
    public static function roots(\modX $modx) {
        $out = array();
        foreach (explode(',', self::rootsSetting($modx)) as $entry) {
            $entry = trim($entry);
            if ($entry === '') { continue; }
            $out[] = self::expand($modx, $entry);
        }
        return $out;
    }

    /** A root or a protected directory: placeholders like {core_path} filled in, a relative path taken from base_path. */
    private static function expand(\modX $modx, $entry) {
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

    /** True when the absolute path may hold the file of a static element. */
    public static function allowed(\modX $modx, $absolute) {
        if (!is_string($absolute) || $absolute === '') { return false; }
        $n = self::normalize($absolute);
        if ($n === null) { return false; }
        list($lexical, $real) = $n;
        if (preg_match(FileRoot::SECRET_NAMES, basename($lexical)) || preg_match(FileRoot::SECRET_NAMES, basename($real))) { return false; }
        foreach (self::DENIED as $entry) {
            $denied = self::normalize(self::expand($modx, $entry));
            if ($denied !== null && (self::inside($real, $denied[1]) || self::inside($lexical, $denied[0]))) { return false; }
        }
        foreach (self::roots($modx) as $root) {
            $r = self::normalize($root);
            if ($r === null) { continue; }
            $rootLexical = rtrim($r[0], '/');
            $rootReal = rtrim($r[1], '/');
            if (strpos($lexical, $rootLexical . '/') !== 0 || strpos($real, $rootReal . '/') !== 0) { continue; }
            // The file must be where the path says it is: no symbolic link anywhere between the root and the file.
            if ($real !== $rootReal . substr($lexical, strlen($rootLexical))) { continue; }
            $current = $rootLexical;
            $linked = false;
            foreach (explode('/', substr($lexical, strlen($rootLexical) + 1)) as $part) {
                $current .= '/' . $part;
                if (is_link($current)) { $linked = true; break; }
            }
            if (!$linked) { return true; }
        }
        return false;
    }

    /** Replace the setting tags [[++name]] the way MODX does; null when another kind of tag is left in the text. */
    public static function settingTags(\modX $modx, $text) {
        if (strpos($text, '[[') === false) { return $text; }
        $text = preg_replace_callback('/\[\[\+\+([A-Za-z0-9_.\-]+)\]\]/', function ($m) use ($modx) { return (string) $modx->getOption($m[1]); }, $text);
        return strpos($text, '[[') === false ? $text : null;
    }

    /**
     * The absolute path a static file resolves to, from the stored static_file and source, as
     * modElement::getSourceFile() resolves it: setting tags, the base path of a streaming media source, otherwise
     * a path that does not exist as given is taken below element_static_path (components_path).
     * Null when nothing resolves (empty, or a tag other than a setting).
     */
    public static function resolveFor(\modX $modx, $staticFile, $source) {
        $filename = self::settingTags($modx, (string) $staticFile);
        if ($filename === null || $filename === '') { return null; }
        $source = (int) $source;
        if ($source > 0) {
            $media = $modx->getObject('sources.modMediaSource', array('id' => $source));
            if ($media && $media->get('is_stream')) {
                $media->initialize();
                $filename = $media->getBasePath() . $filename;
            }
        }
        if (!file_exists($filename) && $source < 1) {
            $base = $modx->getOption('element_static_path', null, $modx->getOption('components_path', null, rtrim((string) $modx->getOption('core_path'), '/\\') . '/components/'));
            $base = self::settingTags($modx, (string) $base);
            if ($base === null) { return null; }
            return $base . $filename;
        }
        return $filename;
    }

    /** The path MODX itself resolves for a loaded element (null when it is not static or has no file). */
    public static function resolve(\modX $modx, $object) {
        $path = $object->getSourceFile();
        return is_string($path) && $path !== '' ? $path : null;
    }

    /** allowed(), and not inside MODX's own code: where a static file may be written. */
    public static function writable(\modX $modx, $absolute) {
        if (!self::allowed($modx, $absolute)) { return false; }
        $n = self::normalize($absolute);
        foreach (self::WRITE_DENIED as $entry) {
            $denied = self::normalize(self::expand($modx, $entry));
            if ($denied !== null && (self::inside($n[1], $denied[1]) || self::inside($n[0], $denied[0]))) { return false; }
        }
        return true;
    }

    /** Refuse a static file that would resolve outside the roots or into MODX's own code (validation_failed). */
    public static function assertAllowed(\modX $modx, $staticFile, $source) {
        $path = self::resolveFor($modx, $staticFile, $source);
        if ($path === null || !self::writable($modx, $path)) {
            throw new ApiException('validation_failed', 'The static file must be inside one of modxmcp.static_roots: ' . self::rootsSetting($modx)
                . ' (not through a symbolic link, not in config, cache, packages or modxmcp-data, not a credentials file, and not MODX\'s own code).');
        }
    }
}
