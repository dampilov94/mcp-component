<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Integration\VirtualPage;

/**
 * A VirtualPage event (class vpEvent): the MODX system event (usually OnPageNotFound) whose routes VirtualPage tries. Routes
 * belong to an event; removing an event removes its routes (xPDO composite), which are journaled through deleteCascade() as
 * deleted vp_route objects.
 *
 * VirtualPage's processors do not attach any plugin to the event; the plugin that runs VirtualPage is attached to its
 * events by the package (and by a person in the manager). So saving an event changes nothing outside its row; a warning says
 * when no enabled plugin listens to the event.
 */
final class VpEventKind extends AbstractVirtualPageKind {
    const DEFAULTS = array('name' => null, 'description' => null, 'rank' => 0, 'active' => 1);

    public function name() { return 'vp_event'; }
    public function label() { return 'VirtualPage event'; }
    public function modelClass() { return 'vpEvent'; }
    public function searchColumns() { return array('name', 'description'); }
    public function defaultSort() { return array(array('rank', 'ASC'), array('id', 'ASC')); }
    protected function defaults() { return self::DEFAULTS; }

    /** The event's own name (before and after a rename). */
    protected function eventNamesOf(\modX $modx, $object) {
        return $object ? array((string) $object->get('name')) : array();
    }

    public function fields() {
        return array(
            'id'          => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'        => array('type' => 'string', 'list' => true, 'required' => true,
                'description' => 'Name of a MODX system event, e.g. OnPageNotFound or OnHandleRequest; unique. VirtualPage 2.0.6 answers requests on '
                    . implode(' and ', VirtualPage::DISPATCH_EVENTS) . '.'),
            'description' => array('type' => 'string'),
            'rank'        => array('type' => 'integer', 'description' => 'Sort order, 0-255. A new event gets the number of events, as the manager does.'),
            'active'      => array('type' => 'boolean', 'list' => true, 'description' => 'An inactive event (or one whose routes are all inactive) is not dispatched.'),
        );
    }

    public function assertColumns(array $columns, $creating) {
        self::checkName($columns, $creating);
        self::checkRank($columns);
    }

    private function nameTaken(\modX $modx, $name, $exceptId) {
        $other = $modx->getObject('vpEvent', array('name' => (string) $name), false);
        return $other && (int) $other->get('id') !== (int) $exceptId;
    }

    public function beforeCreate(Context $context, array $columns) {
        if ($this->nameTaken($context->modx, $columns['name'], 0)) {
            throw new ApiException('validation_failed', "A vp_event named '{$columns['name']}' already exists.");
        }
        return null;
    }

    public function assertUpdatable(\modX $modx, $object, array $columns) {
        if (isset($columns['name']) && (string) $columns['name'] !== (string) $object->get('name') && $this->nameTaken($modx, $columns['name'], $object->get('id'))) {
            throw new ApiException('validation_failed', "A vp_event named '{$columns['name']}' already exists.");
        }
    }

    /** An event cannot be put back under a name another event has taken. */
    public function assertUndoable(\modX $modx, $key, $target) {
        parent::assertUndoable($modx, $key, $target);
        if ($target !== null && isset($target['fields']['name']) && $this->nameTaken($modx, $target['fields']['name'], $key)) {
            throw new ApiException('not_reversible', "Another vp_event is named '{$target['fields']['name']}' now; rename or delete it first.");
        }
    }

    /** The routes of the event, which xPDO removes with it. */
    public function deleteCascade(\modX $modx, $key) {
        $out = array();
        foreach ($modx->getCollection('vpRoute', array('event' => (int) $key)) as $route) { $out[] = array('vp_route', (int) $route->get('id')); }
        if ($out && $this->context) { $this->context->warnings[] = 'The event\'s ' . count($out) . ' route(s) are removed with it (journaled; modx_undo brings them back).'; }
        return $out;
    }

    public function writeWarnings($object) {
        $warnings = array();
        $name = (string) $object->get('name');
        if (!in_array($name, VirtualPage::DISPATCH_EVENTS, true)) {
            $warnings[] = "VirtualPage 2.0.6 dispatches routes only on " . implode(' and ', VirtualPage::DISPATCH_EVENTS) . "; routes of '{$name}' are never tried.";
        }
        if ($this->context) {
            $modx = $this->context->modx;
            if (!$modx->getCount('modEvent', array('name' => $name))) {
                $warnings[] = "'{$name}' is not a system event of this site.";
            }
            $listening = false;
            foreach ($modx->getCollection('modPluginEvent', array('event' => $name)) as $row) {
                $plugin = $modx->getObject('modPlugin', array('id' => (int) $row->get('pluginid')), false);
                if ($plugin && !(int) $plugin->get('disabled')) { $listening = true; break; }
            }
            if (!$listening) {
                $warnings[] = "No enabled plugin listens to '{$name}', so VirtualPage's plugin never runs for it. Attach the VirtualPage plugin to the event "
                    . "(modx_update {kind: \"plugin\", …, relations: {events: […]}} replaces the plugin's whole list).";
            }
        }
        return $warnings;
    }
}
