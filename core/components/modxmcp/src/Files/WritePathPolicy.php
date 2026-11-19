<?php
namespace ModxMCP\Files;

/** Filesystem boundaries shared by media-source mutations and static elements. */
final class WritePathPolicy {
    /** Normalised protected paths, including the nearest existing parent of missing paths. */
    public static function protectedPaths(\modX $modx, $componentPath = '') {
        $paths = array(
            '{core_path}components/modxmcp/', '{assets_path}components/modxmcp/',
            '{core_path}config/', '{core_path}modxmcp-data/', '{core_path}cache/', '{core_path}packages/',
            '{core_path}model/', '{core_path}xpdo/', '{core_path}lexicon/', '{core_path}docs/',
            '{manager_path}', '{connectors_path}', '{base_path}manager/', '{base_path}connectors/',
            '{base_path}setup/', '{base_path}index.php', $componentPath,
        );
        return self::resolvePaths($modx, $paths);
    }

    public static function componentDirectories(\modX $modx) {
        return self::resolvePaths($modx, array('{core_path}components/', '{assets_path}components/'));
    }

    private static function resolvePaths(\modX $modx, array $paths) {
        $resolved = array();
        foreach ($paths as $path) {
            $pair = Path::normalize(Path::expand($modx, $path));
            if ($pair !== null) { $resolved[] = $pair; }
        }
        return $resolved;
    }

    /** Compare either spelling with both forms of each protected location. */
    public static function contains($path, array $protected) {
        foreach ($protected as $pair) {
            if (Path::inside($path, $pair[0]) || Path::inside($path, $pair[1])) { return true; }
        }
        return false;
    }

    public static function isServerConfig($path) {
        return in_array(strtolower(basename($path)), array('.htaccess', '.user.ini', 'web.config'), true);
    }
}
