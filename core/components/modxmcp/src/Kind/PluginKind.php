<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;

final class PluginKind extends Kind {
    public function name() { return 'plugin'; }

    public function label() { return 'Plugin'; }

    public function modelClass() { return 'modPlugin'; }

    public function processorPath() { return 'element/plugin/'; }

    public function lint() { return 'php'; }

    public function eventNames() {
        return array('save' => 'OnPluginFormSave', 'delete' => 'OnPluginFormDelete', 'param' => 'plugin');
    }

    public function fields() {
        return $this->elementFields('plugincode') + array(
            'disabled'            => array('type' => 'boolean', 'list' => true, 'description' => 'A disabled plugin does not run.'),
            'property_preprocess' => array('type' => 'boolean', 'description' => 'Parse tags in property values before use.'),
        );
    }

    public function relationSpecs() {
        return array(
            'events' => array(
                'class' => 'modPluginEvent', 'fk' => 'pluginid', 'present' => array('event', 'priority'), 'writable' => true,
                'description' => 'System events the plugin listens to. Write as a list of event names or {event, priority} objects; the list replaces the current one.',
            ),
            'property_sets' => $this->propertySetRelation(),
        );
    }

    public function writeRelation(\modX $modx, $object, $name, $value) {
        if ($name !== 'events') { return parent::writeRelation($modx, $object, $name, $value); }
        $rows = array();
        foreach ($value as $item) {
            $event = is_array($item) ? (isset($item['event']) ? $item['event'] : null) : $item;
            if (!is_string($event) || $event === '') { throw new ApiException('validation_failed', 'Each plugin event needs an event name.'); }
            if (!$modx->getCount('modEvent', array('name' => $event))) {
                throw new ApiException('validation_failed', "Unknown system event '{$event}'.");
            }
            $rows[$event] = is_array($item) && isset($item['priority']) ? (int) $item['priority'] : 0;
        }
        $id = (int) $object->get('id');
        $modx->removeCollection('modPluginEvent', array('pluginid' => $id));
        foreach ($rows as $event => $priority) {
            $row = $modx->newObject('modPluginEvent');
            $row->fromArray(array('pluginid' => $id, 'event' => $event, 'priority' => $priority, 'propertyset' => 0), '', true, true);
            if (!$row->save()) { throw new \RuntimeException('Cannot save a plugin event.'); }
        }
    }
}
