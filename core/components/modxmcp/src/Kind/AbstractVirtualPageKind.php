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
            return $this->save($modx, $object, $columns + $this->defaults());
        }
        $object = $this->findByKey($modx, $id);
        if (!$object) { throw new ApiException('not_found', "No {$this->name()} with id {$id}."); }
        return $this->save($modx, $object, $columns);
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
        if (!$object->remove()) { throw new \RuntimeException('Cannot remove the VirtualPage object.'); }
    }
}
