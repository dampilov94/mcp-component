<?php
namespace ModxMCP\Integration;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Capabilities;
use ModxMCP\Context;
use ModxMCP\Db;

/** The Redirector 2.0.11 model, processor and native matching rules. */
final class Redirector {
    const GROUP = 'redirector';
    private const TRACKER = 'modx_resource_uri';

    private static function corePath(\modX $modx) {
        return (new Capabilities($modx))->integrationPath(self::GROUP);
    }

    public static function load(\modX $modx) {
        $model = self::corePath($modx) . 'model/';
        if (!is_file($model . 'redirector/redirector.class.php')) { throw new \RuntimeException('Redirector code is unavailable.'); }
        if (!isset($modx->packages[self::GROUP]) || $modx->packages[self::GROUP]['path'] !== $model) {
            $modx->addPackage(self::GROUP, $model);
        }
    }

    public static function processorsPath(\modX $modx) { return self::corePath($modx) . 'processors/'; }

    public static function checkRenameAccess(Context $context) {
        if (!Scope::allows($context->token['scope'], Scope::ADMIN)) {
            throw new ApiException('forbidden_scope', 'Rename with a redirect requires the admin scope.');
        }
        $reason = $context->capabilities->groupBlockReason(self::GROUP, $context->token);
        if ($reason !== null) { throw new ApiException('unavailable', 'Redirector is unavailable.', array('reason' => $reason)); }
        $modx = $context->modx;
        self::load($modx);
        $plugin = $modx->getObject('modPlugin', array('name' => 'Redirector', 'disabled' => 0), false);
        if (!$plugin || !$modx->getCount('modPluginEvent', array('pluginid' => (int) $plugin->get('id'), 'event' => 'OnPageNotFound'))) {
            throw new ApiException('unavailable', 'Enable Redirector’s OnPageNotFound plugin before renaming a page.');
        }
    }

    public static function assertNoMatchingRule(\modX $modx, $context, $search, $pattern, $excludeId = null) {
        // Exactly the same three comparisons as the plugin, plus the generated literal to avoid creating a duplicate.
        try {
            $table = $modx->getTableName('modRedirect');
            $params = array($context, $search, $pattern, $search, $search);
            $exclude = $excludeId === null ? '' : ' AND `id` <> ?';
            if ($excludeId !== null) { $params[] = (int) $excludeId; }
            $found = Db::run($modx, 'SELECT `id` FROM ' . $table . ' WHERE (`context_key` = ? OR `context_key` IS NULL OR `context_key` = \'\')'
                . ' AND (`pattern` = ? OR `pattern` = ? OR (`active` = 1 AND (? REGEXP `pattern` OR ? REGEXP CONCAT(\'^\', `pattern`, \'$\'))))'
                . $exclude . ' LIMIT 1', $params)->fetchColumn();
        } catch (\Throwable $e) {
            throw new ApiException('validation_failed', 'Could not safely check existing Redirector rules.');
        }
        if ($found !== false) { throw new ApiException('validation_failed', 'An existing Redirector rule matches the old URL.'); }
    }

    /** Temporarily disable native URI tracking, including while a Writer rolls back. */
    public static function withoutTracker(callable $write) {
        $had = isset($_SESSION) && array_key_exists(self::TRACKER, $_SESSION);
        $previous = $had ? $_SESSION[self::TRACKER] : null;
        unset($_SESSION[self::TRACKER]);
        try { return $write(); }
        finally {
            if ($had) { $_SESSION[self::TRACKER] = $previous; }
            else { unset($_SESSION[self::TRACKER]); }
        }
    }
}
