<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Files\FileRoot;
use ModxMCP\Files\Path;
use ModxMCP\Files\WritePathPolicy;

/**
 * Where the file of a static element may live. MODX itself accepts any path (its own check,
 * isStaticSourceValidPath(), only looks at the directory of config.inc.php and only when the content
 * changes), so the API restricts it: the file must be inside one of the roots of the setting
 * modxmcp.static_roots, reached without a symbolic link, and not in the protected directories.
 */
final class StaticPath {
    // The whole site and core by default: a develop token can run any PHP through a snippet anyway, so what this rule
    // protects is what code cannot be trusted to keep private (DENIED, credentials files), plus MODX's own code against
    // being overwritten by mistake. Narrow it with modxmcp.static_roots if a site wants more.
    const DEFAULT_ROOTS = '{base_path},{core_path}';
    /** Directories that never hold a static element, even inside a root. */
    const DENIED = array('{core_path}config/', '{core_path}modxmcp-data/', '{core_path}cache/', '{core_path}packages/');
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
            $out[] = Path::expand($modx, $entry);
        }
        return $out;
    }

    /** True when the absolute path may hold the file of a static element. */
    public static function allowed(\modX $modx, $absolute) {
        if (!is_string($absolute) || $absolute === '') { return false; }
        $paths = Path::normalize($absolute);
        return $paths !== null && self::allowedPaths($modx, $paths);
    }

    private static function allowedPaths(\modX $modx, array $paths) {
        list($lexical, $real) = $paths;
        if (preg_match(FileRoot::SECRET_NAMES, basename($lexical)) || preg_match(FileRoot::SECRET_NAMES, basename($real))) { return false; }
        foreach (self::DENIED as $entry) {
            $denied = Path::normalize(Path::expand($modx, $entry));
            if ($denied !== null && (Path::inside($real, $denied[1]) || Path::inside($lexical, $denied[0]))) { return false; }
        }
        foreach (self::roots($modx) as $root) {
            $r = Path::normalize($root);
            if ($r === null) { continue; }
            if (Path::inside($lexical, $r[1])) { $r[0] = $r[1]; }
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

    /** Readable as static content and outside all protected write locations. */
    public static function writable(\modX $modx, $absolute) {
        if (!is_string($absolute) || $absolute === '') { return false; }
        $paths = Path::normalize($absolute);
        if ($paths === null || !self::allowedPaths($modx, $paths)) { return false; }
        $protected = WritePathPolicy::protectedPaths($modx, (string) $modx->getOption('modxmcp.core_path', null, ''));
        foreach ($paths as $path) {
            if (WritePathPolicy::isServerConfig($path) || WritePathPolicy::contains($path, $protected)) { return false; }
        }
        return true;
    }

    /** Refuse a static file that would resolve outside the roots or into MODX's own code (validation_failed). */
    public static function assertAllowed(\modX $modx, $staticFile, $source) {
        $path = self::resolveFor($modx, $staticFile, $source);
        if ($path === null || !self::writable($modx, $path)) {
            $filename = self::settingTags($modx, (string) $staticFile);
            if ((int) $source < 1 && is_string($filename) && preg_match('#^(?:[A-Za-z]:[/\\\\]|/)#D', $filename)
                && !file_exists($filename) && self::writable($modx, $filename)) {
                throw new ApiException('validation_failed', 'MODX resolves a missing absolute file under element_static_path, outside modxmcp.static_roots. Use make_static to create the file, or select an existing file.');
            }
            throw new ApiException('validation_failed', 'The static file must be inside one of modxmcp.static_roots: ' . self::rootsSetting($modx)
                . ' (not through a symbolic link, not in config, cache, packages or modxmcp-data, not a credentials file, and not protected code or server configuration).');
        }
    }
}
