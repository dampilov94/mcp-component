<?php
namespace ModxMCP\Integration;

use ModxMCP\Context;

/**
 * What the component needs to know about MIGX (verified against 3.0.2): when it can be used, how its xPDO package is
 * registered, and the shape of its configurations.
 */
final class Migx {
    const GROUP = 'migx';
    const PACKAGE = 'migx';

    /** Input type of a TV whose value is a MIGX list. */
    const TV_TYPE = 'migx';

    /** The integration is installed, its code is there and the group is not switched off for the site. */
    public static function siteAvailable(Context $context) {
        $caps = $context->capabilities;
        $status = $caps->integrations();
        return $status[self::GROUP]['available'] && !in_array(self::GROUP, $caps->disabledGroups(), true);
    }

    /** The integration is usable by this token (site-wide switches and the token's denied groups). */
    public static function available(Context $context) {
        return $context->capabilities->groupBlockReason(self::GROUP, $context->token) === null;
    }

    /** Core directory of MIGX with a trailing slash. */
    public static function corePath(\modX $modx) {
        return (new \ModxMCP\Capabilities($modx))->integrationPath(self::GROUP);
    }

    /**
     * Register the xPDO package, as MIGX's own code does (`addPackage('migx', <core>/model/)`). MIGX has no
     * headless processors for its configurations (they need the manager controller), so the models are all we use.
     * Registering twice is harmless.
     */
    public static function load(\modX $modx) {
        $model = self::corePath($modx) . 'model/';
        if (!is_file($model . 'migx/migxconfig.class.php')) {
            throw new \RuntimeException('MIGX is not installed on this site (its model files are missing).');
        }
        if (!isset($modx->packages[self::PACKAGE]) || $modx->packages[self::PACKAGE]['path'] !== $model) {
            $modx->addPackage(self::PACKAGE, $model);
        }
    }
}
