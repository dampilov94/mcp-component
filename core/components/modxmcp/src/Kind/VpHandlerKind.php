<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Integration\VirtualPage;

/**
 * A VirtualPage handler (class vpHandler): what answers a route. Its stored `type` (virtualpage::handle()):
 * 0 resource (sendForward to the resource `entry`), 1 snippet, 2 chunk (both by `entry`), 3 dynamic resource (a page made up
 * for the request from the template `entry` and the handler's `content`). The API names the types; `template` is accepted as
 * another name of `dynamic_resource` (1.x called it so) and read back as `dynamic_resource`.
 *
 * Removing a handler removes its routes (xPDO composite), journaled through deleteCascade() as deleted vp_route objects.
 */
final class VpHandlerKind extends AbstractVirtualPageKind {
    const DEFAULTS = array('name' => null, 'type' => 0, 'entry' => 0, 'content' => null, 'description' => null, 'cache' => 0, 'rank' => 0, 'active' => 1);

    public function name() { return 'vp_handler'; }
    public function label() { return 'VirtualPage handler'; }
    public function modelClass() { return 'vpHandler'; }
    public function searchColumns() { return array('name', 'description'); }
    public function defaultSort() { return array(array('rank', 'ASC'), array('id', 'ASC')); }
    protected function defaults() { return self::DEFAULTS; }

    /** Deleting a handler removes its routes, whose events may then have none left. */
    protected function eventNamesOf(\modX $modx, $object) {
        $names = array();
        if (!$object) { return $names; }
        foreach ($modx->getCollection('vpRoute', array('handler' => (int) $object->get('id'))) as $route) {
            $event = $modx->getObject('vpEvent', array('id' => (int) $route->get('event')), false);
            if ($event) { $names[] = (string) $event->get('name'); }
        }
        return $names;
    }

    public function fields() {
        return array(
            'id'          => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'        => array('type' => 'string', 'list' => true, 'required' => true, 'description' => 'Name of the handler; unique.'),
            'type'        => array('type' => 'string', 'list' => true,
                'description' => 'What answers: ' . implode(', ', VirtualPage::HANDLER_TYPES) . ' (template is accepted for dynamic_resource). resource: the request is forwarded to the resource `entry`; '
                    . 'snippet / chunk: the output of the snippet / chunk `entry`; dynamic_resource: a page of the template `entry` whose content is `content`. Default resource.'),
            'entry'       => array('type' => 'integer', 'list' => true,
                'description' => 'Id of the object the type uses: resource, snippet, chunk or template (for dynamic_resource; 0 = no template). It must exist when it is not 0.'),
            'content'     => array('type' => 'string', 'code' => true, 'description' => 'Body of a dynamic_resource page (MODX tags work); unused by the other types.'),
            'description' => array('type' => 'string', 'description' => 'Also the page title of a dynamic_resource page when set.'),
            'cache'       => array('type' => 'boolean', 'description' => 'VirtualPage caches the output of this handler.'),
            'rank'        => array('type' => 'integer', 'description' => 'Sort order, 0-255. A new handler gets the number of handlers, as the manager does.'),
            'active'      => array('type' => 'boolean', 'list' => true, 'description' => 'Routes of an inactive handler are not dispatched.'),
        );
    }

    /** The type is stored as a number and shown by name. */
    public function value($object, $field) {
        if ($field !== 'type') { return parent::value($object, $field); }
        return VirtualPage::typeName($object->get('type'));
    }

    /** A filter on `type` may name the type. */
    public function listCriteria(array $criteria) {
        if (isset($criteria['type']) && !is_int($criteria['type'])) {
            $stored = VirtualPage::typeFromName($criteria['type']);
            if ($stored === null) {
                if (!is_numeric($criteria['type'])) { throw new ApiException('validation_failed', $this->typeMessage($criteria['type'])); }
                $stored = (int) $criteria['type'];
            }
            $criteria['type'] = $stored;
        }
        return $criteria;
    }

    private function typeMessage($given) {
        return "Unknown handler type '" . $given . "'. Types: " . implode(', ', VirtualPage::HANDLER_TYPES) . ' (template is accepted for dynamic_resource).';
    }

    protected function prepareColumns(\modX $modx, array $columns) {
        if (isset($columns['type'])) { $columns['type'] = VirtualPage::typeFromName($columns['type']); }
        return $columns;
    }

    // ---- validation ----

    public function assertColumns(array $columns, $creating) {
        self::checkName($columns, $creating);
        self::checkRank($columns);
        if (isset($columns['type']) && VirtualPage::typeFromName($columns['type']) === null) { throw new ApiException('validation_failed', $this->typeMessage($columns['type'])); }
        if (isset($columns['entry']) && (int) $columns['entry'] < 0) { throw new ApiException('validation_failed', "Field 'entry' cannot be negative."); }
    }

    private function nameTaken(\modX $modx, $name, $exceptId) {
        $other = $modx->getObject('vpHandler', array('name' => (string) $name), false);
        return $other && (int) $other->get('id') !== (int) $exceptId;
    }

    /** The entry must exist for the type (1.x assertVirtualPageHandlerEntry); 0 is allowed. */
    private function assertEntry(\modX $modx, $type, $entry) {
        $entry = (int) $entry;
        if ($entry <= 0) { return; }
        if (!isset(VirtualPage::ENTRY_CLASSES[(int) $type]) || !$modx->getCount(VirtualPage::ENTRY_CLASSES[(int) $type], array('id' => $entry))) {
            $what = isset(VirtualPage::HANDLER_TYPES[(int) $type]) ? VirtualPage::HANDLER_TYPES[(int) $type] : (string) $type;
            $kind = array(0 => 'resource', 1 => 'snippet', 2 => 'chunk', 3 => 'template');
            throw new ApiException('validation_failed', "Field 'entry': there is no " . (isset($kind[(int) $type]) ? $kind[(int) $type] : 'object') . " with id {$entry} (needed by a {$what} handler).");
        }
    }

    public function beforeCreate(Context $context, array $columns) {
        $modx = $context->modx;
        if ($this->nameTaken($modx, $columns['name'], 0)) { throw new ApiException('validation_failed', "A vp_handler named '{$columns['name']}' already exists."); }
        $type = isset($columns['type']) ? VirtualPage::typeFromName($columns['type']) : self::DEFAULTS['type'];
        $this->assertEntry($modx, $type, isset($columns['entry']) ? $columns['entry'] : 0);
        return null;
    }

    public function assertUpdatable(\modX $modx, $object, array $columns) {
        if (isset($columns['name']) && (string) $columns['name'] !== (string) $object->get('name') && $this->nameTaken($modx, $columns['name'], $object->get('id'))) {
            throw new ApiException('validation_failed', "A vp_handler named '{$columns['name']}' already exists.");
        }
        // Only a change of the type or the entry is checked; an unrelated edit is not blocked by an entry that has gone since.
        if (isset($columns['type']) || isset($columns['entry'])) {
            $type = isset($columns['type']) ? VirtualPage::typeFromName($columns['type']) : (int) $object->get('type');
            $this->assertEntry($modx, $type, isset($columns['entry']) ? $columns['entry'] : $object->get('entry'));
        }
    }

    public function assertUndoable(\modX $modx, $key, $target) {
        parent::assertUndoable($modx, $key, $target);
        if ($target !== null && isset($target['fields']['name']) && $this->nameTaken($modx, $target['fields']['name'], $key)) {
            throw new ApiException('not_reversible', "Another vp_handler is named '{$target['fields']['name']}' now; rename or delete it first.");
        }
    }

    /** The routes of the handler, which xPDO removes with it. */
    public function deleteCascade(\modX $modx, $key) {
        $out = array();
        foreach ($modx->getCollection('vpRoute', array('handler' => (int) $key)) as $route) { $out[] = array('vp_route', (int) $route->get('id')); }
        if ($out && $this->context) { $this->context->warnings[] = 'The handler\'s ' . count($out) . ' route(s) are removed with it (journaled; modx_undo brings them back).'; }
        return $out;
    }

    public function writeWarnings($object) {
        $type = (int) $object->get('type');
        $warnings = array();
        if (!isset(VirtualPage::HANDLER_TYPES[$type])) {
            $warnings[] = "Handler type {$type} is not one VirtualPage 2.0.6 knows; it answers such requests with the error page.";
        } elseif ($type !== 3 && !(int) $object->get('entry')) {
            $warnings[] = 'A ' . VirtualPage::HANDLER_TYPES[$type] . ' handler needs an entry (the id of the ' . array(0 => 'resource', 1 => 'snippet', 2 => 'chunk')[$type] . ' it uses); entry is 0.';
        }
        return $warnings;
    }
}
