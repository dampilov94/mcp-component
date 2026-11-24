<?php
namespace ModxMCP\Render;

/**
 * The plugin side of modx_check_page for unpublished pages. It runs on OnWebPageInit, the last event before
 * modRequest::getResource() looks the page up. At that point the alias is already resolved to an id, so the
 * key can be bound to exactly the resource MODX is about to serve; getResource() would filter `published = 1`
 * for an anonymous visitor, but it is skipped when a plugin has already put an object into $modx->resource
 * (modRequest::handleRequest: `if (!is_object($this->modx->resource)) { ... getResource(...) }`).
 * Nothing in the database, the session or the user changes, and the resource is never written to the page cache.
 */
final class RenderGate {
    const EVENT = 'OnWebPageInit';
    const SERVER_KEY = 'HTTP_X_MCP_RENDER';

    /** Never throws and never breaks a normal request. */
    public static function handle(\modX $modx, array $server = null) {
        $server = $server === null ? $_SERVER : $server;
        $value = isset($server[self::SERVER_KEY]) ? $server[self::SERVER_KEY] : '';
        if (!is_string($value) || $value === '') { return false; }
        try {
            $requested = is_object($modx->resource) ? 0 : ($modx->resourceMethod === 'id' ? (int) $modx->resourceIdentifier : 0);
            $id = RenderKeys::forModx($modx)->grant($value, $requested);
            if ($id === null) { return false; }
            $resource = self::load($modx, $id);
            if ($resource === null) { return false; }
            $modx->resource = $resource;
            $modx->resourceGenerated = true;
            if (!headers_sent()) {
                header('X-Robots-Tag: noindex, nofollow');
                header('Cache-Control: no-store');
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The unpublished resource as modRequest::getResource() would load it, or null. */
    private static function load(\modX $modx, $id) {
        $resource = $modx->getObject('modResource', array('id' => (int) $id, 'deleted' => 0));
        if (!$resource || $resource->get('published')) { return null; }
        $context = $modx->context->get('key');
        if ($resource->get('context_key') !== $context && !$modx->getCount('modContextResource', array($context, (int) $id))) { return null; }
        if (!$resource->checkPolicy('view')) { return null; }
        foreach ((array) $resource->getMany('TemplateVars', 'all') as $tv) {
            $resource->set($tv->get('name'), array($tv->get('name'), $tv->getValue($resource->get('id')), $tv->get('display'), $tv->get('display_params'), $tv->get('type')));
        }
        // Only in memory: the object is never saved, and modX::_postProcess() caches only cacheable resources.
        $resource->set('cacheable', 0);
        $resource->_isForward = false;
        return $resource;
    }
}
