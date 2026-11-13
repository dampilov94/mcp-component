<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Integration\VirtualPage;

/**
 * What the VirtualPage kinds (vp_event, vp_handler, vp_route) have in common.
 *
 * VirtualPage's manager processors (mgr/event|handler|route/*) are plain xPDO create/update/remove processors that check the
 * manager permission `vpsetting_save` (which an API user need not hold), overwrite the `rank` of a new object with the row count
 * and clear the cache after a save; the remove processors of events and handlers do not even clear it. They add no events and
 * no plugin wiring. So the objects are written directly, inside the transaction, as 1.x did, with the same defaults (rank =
 * number of rows), and VirtualPage's cache is cleared after every write and every undo (afterWrite()).
 */
abstract class AbstractVirtualPageKind extends Kind {
    /** @var Context|null */
    protected $context;

    /** Column => what VirtualPage stores when nothing is set (the defaults of the map), written explicitly on create. */
    abstract protected function defaults();

    public function group() { return VirtualPage::GROUP; }
    public function processorPath() { return ''; }
    public function usesProcessors() { return false; }
    public function processorExtras($op) { return array(); }

    public function attach(Context $context) { $this->context = $context; }

    /** Registers the models. Reading the journal must work without VirtualPage, so a missing add-on is not an error here. */
    public function prepare(\modX $modx) {
        try { VirtualPage::load($modx); } catch (\RuntimeException $e) { /* assertUndoable() and the actions report it where it matters */ }
    }

    /** An object cannot be put back (or taken away) without the VirtualPage models. */
    public function assertUndoable(\modX $modx, $key, $target) {
        try { VirtualPage::load($modx); } catch (\RuntimeException $e) { throw new ApiException('not_reversible', 'VirtualPage is not installed; its objects cannot be restored.'); }
    }

    /** VirtualPage's cache holds the route lists; they are built again after every change (virtualpage::clearAllCache()). */
    public function afterWrite(\modX $modx) {
        VirtualPage::clearCache($modx);
    }

    // ---- shared validation ----

    protected static function checkRank(array $columns) {
        if (isset($columns['rank']) && ((int) $columns['rank'] < 0 || (int) $columns['rank'] > 255)) {
            throw new ApiException('validation_failed', "Field 'rank' must be between 0 and 255 (VirtualPage stores it in a tinyint).");
        }
    }

    protected static function checkName(array $columns, $creating) {
        if (!isset($columns['name'])) { return; }
        $name = (string) $columns['name'];
        if (trim($name) === '') { throw new ApiException('validation_failed', $creating ? "Field 'name' is required." : "Field 'name' cannot be empty."); }
        if (mb_strlen($name, 'UTF-8') > 255) { throw new ApiException('validation_failed', "Field 'name' is too long (at most 255 characters)."); }
    }

    // ---- writing ----

    /** Save an object directly; the columns are set raw, so the text is stored exactly as given. Returns its id. */
    protected function save(\modX $modx, $object, array $data) {
        $object->fromArray($data, '', false, true);
        $created = $object->get('id') ? false : true;
        try {
            if (!$object->save()) { throw new \RuntimeException('Cannot save the VirtualPage object.'); }
        } catch (\Throwable $e) {
            if ($created && $object->get('id')) { $object->remove(); }
            throw $e;
        }
        return (int) $object->get('id');
    }

    /** Create ($id null) or change an object. $columns are the normalised column => value pairs; see prepareColumns(). */
    public function writeObject(Context $context, $id, array $columns) {
        $modx = $context->modx;
        VirtualPage::load($modx);
        $columns = $this->prepareColumns($modx, $columns);
        if ($id === null) {
            $object = $modx->newObject($this->modelClass());
            // The manager's create processors give a new object rank = the number of rows.
            if (!array_key_exists('rank', $columns) && array_key_exists('rank', $this->defaults())) {
                $columns['rank'] = min(255, (int) $modx->getCount($this->modelClass()));
            }
            $created = $this->save($modx, $object, $columns + $this->defaults());
            $this->syncPluginEvents($context, $this->eventNamesOf($modx, $this->findByKey($modx, $created)));
            return $created;
        }
        $object = $this->findByKey($modx, $id);
        if (!$object) { throw new ApiException('not_found', "No {$this->name()} with id {$id}."); }
        $before = $this->eventNamesOf($modx, $object);
        $saved = $this->save($modx, $object, $columns);
        $this->syncPluginEvents($context, array_merge($before, $this->eventNamesOf($modx, $this->findByKey($modx, $saved))));
        return $saved;
    }

    /**
     * VirtualPage 1.x answers routes through its plugin `vpEvent`, which its route processors attach to an event
     * (priority 10) when a route uses it and detach when no route does (virtualpage::doEvent()). 2.x ships no such
     * plugin and needs none. When the plugin exists, keep its events in step for the given event names, touching
     * the plugin first so the change is journaled with the call and undone with it.
     */
    protected function syncPluginEvents(Context $context, array $eventNames) {
        $modx = $context->modx;
        $plugin = $modx->getObject('modPlugin', array('name' => 'vpEvent'), false);
        if (!$plugin) { return; }
        $pluginId = (int) $plugin->get('id');
        $changed = false;
        $pluginKind = $context->kinds->get('plugin');
        if ($pluginKind && $context->transaction) { $context->transaction->touch($pluginKind, $pluginId); }
        foreach (array_unique(array_filter(array_map('strval', $eventNames))) as $name) {
            $used = false;
            foreach ($modx->getCollection('vpEvent', array('name' => $name)) as $event) {
                if ($modx->getCount('vpRoute', array('event' => (int) $event->get('id')))) { $used = true; }
            }
            $row = $modx->getObject('modPluginEvent', array('pluginid' => $pluginId, 'event' => $name));
            if ($used && !$row) {
                if (!$modx->getCount('modEvent', array('name' => $name))) { continue; }
                $row = $modx->newObject('modPluginEvent');
                $row->fromArray(array('pluginid' => $pluginId, 'event' => $name, 'priority' => 10, 'propertyset' => 0), '', true, true);
                $row->save();
                $changed = true;
            } elseif (!$used && $row) {
                $row->remove();
                $changed = true;
            }
        }
        // MODX keeps the event → plugin map in the context cache: without a refresh the site keeps
        // calling (or not calling) the plugin as before.
        if ($changed && $modx->getCacheManager()) { $modx->getCacheManager()->refresh(); }
    }

    /** Event names a write or delete of this object can affect (its own name, its routes' event). */
    protected function eventNamesOf(\modX $modx, $object) {
        return array();
    }

    /** Columns as stored (API spelling turned into what VirtualPage keeps). */
    protected function prepareColumns(\modX $modx, array $columns) {
        return $columns;
    }

    /** xPDO removes the composites with it (an event's or handler's routes); they were touched by deleteCascade(). */
    public function removeObject(Context $context, $key) {
        $modx = $context->modx;
        VirtualPage::load($modx);
        $object = $this->findByKey($modx, $key);
        if (!$object) { throw new ApiException('not_found', "No {$this->name()} with id {$key}."); }
        $names = $this->eventNamesOf($modx, $object);
        if (!$object->remove()) { throw new \RuntimeException('Cannot remove the VirtualPage object.'); }
        $this->syncPluginEvents($context, $names);
    }
}
