<?php
namespace ModxMCP\Action;

use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;

final class MaintenanceAction extends Action {
    public function name() { return 'maintenance'; }
    public function group() { return 'maintenance'; }
    public function scope() { return Scope::ADMIN; }
    public function readOnly() { return false; }
    public function destructive() { return true; }
    public function reversible() { return false; }
    public function description() {
        return 'Maintenance: clear_cache, refresh_uris, remove_locks, flush_permissions, empty_trash. '
            . 'None is undoable; empty_trash is permanent and needs confirm: true. '
            . 'Object writes already refresh cache, so clear_cache is rarely needed.';
    }
    public function inputSchema() {
        return Schema::obj(array('operation' => Schema::str(null, array('enum' => array(
            'clear_cache', 'refresh_uris', 'remove_locks', 'flush_permissions', 'empty_trash'))),
            'confirm' => Schema::bool('Required for permanent empty_trash.')), array('operation'));
    }
    public function outputSchema() {
        return Schema::obj(array('operation' => Schema::str(), 'performed' => Schema::bool()), array('operation', 'performed'), true);
    }
    public function run(array $args, Context $context) {
        $op = $args['operation'];
        $plan = !empty($context->token['plan_only']);
        $result = array('operation' => $op, 'performed' => false);
        if ($op === 'empty_trash') {
            $query = $context->modx->newQuery('modResource', array('deleted' => 1));
            $count = (int) $context->modx->getCount('modResource', $query);
            if ($plan || empty($args['confirm'])) {
                $query->sortby('id', 'ASC');
                $query->limit(20);
                $resources = array();
                foreach ($context->modx->getCollection('modResource', $query) as $object) {
                    $resources[] = array('id' => (int) $object->get('id'), 'pagetitle' => (string) $object->get('pagetitle'));
                }
                return $result + array('would_remove' => $count, 'resources' => $resources);
            }
            Objects::process($context->modx, 'resource/emptyrecyclebin', array());
            $context->warnings[] = 'Resources removed from the trash cannot be restored by modx_undo.';
            // MODX skips resources its delete policy protects, so report what really went.
            $left = (int) $context->modx->getCount('modResource', array('deleted' => 1));
            $result['removed'] = $count - $left;
            if ($left > 0) { $result['left_in_trash'] = $left; }
        } else {
            if ($plan) { return $result; }
            if ($op === 'clear_cache') {
                // The return value is not a failure signal: MODX reports false for partitions
                // that share a cache key, which is normal on real sites.
                $context->modx->getCacheManager()->refresh();
            } else {
                $processors = array('refresh_uris' => 'system/refreshuris', 'remove_locks' => 'system/remove_locks', 'flush_permissions' => 'security/access/flush');
                Objects::process($context->modx, $processors[$op], array());
            }
        }
        $result['performed'] = true;
        return $result;
    }
}
