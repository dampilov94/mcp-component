<?php
namespace ModxMCP\Integration;

use ModxMCP\Capabilities;
use ModxMCP\Kind\Objects;

/** Native ClientConfig 2.5.0 models, processors and cache entries. */
final class ClientConfig {
    const GROUP = 'clientconfig';

    private static function corePath(\modX $modx) {
        return (new Capabilities($modx))->integrationPath(self::GROUP);
    }

    public static function load(\modX $modx) {
        $model = self::corePath($modx) . 'model/';
        if (!is_file($model . 'clientconfig/clientconfig.class.php')) {
            throw new \RuntimeException('The ClientConfig models are missing.');
        }
        if (!isset($modx->packages[self::GROUP]) || $modx->packages[self::GROUP]['path'] !== $model) {
            $modx->addPackage(self::GROUP, $model);
        }
    }

    public static function process(\modX $modx, $name, array $input) {
        return Objects::process($modx, 'mgr/settings/' . $name, $input, array('processors_path' => self::corePath($modx) . 'processors/'));
    }

    public static function clearCache(\modX $modx) {
        $cache = $modx->getCacheManager();
        if (!$cache) { return; }
        $cache->delete('clientconfig', array(\xPDO::OPT_CACHE_KEY => 'system_settings'));
        if ($modx->getOption('clientconfig.clear_cache', null, true)) {
            $cache->delete('', array(\xPDO::OPT_CACHE_KEY => 'resource'));
        }
    }
}
