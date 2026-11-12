<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;

/** A context (modContext), identified by its key. Its settings travel with it in snapshots. */
final class ContextKind extends Kind {
    public function name() { return 'context'; }
    public function label() { return 'Context'; }
    public function modelClass() { return 'modContext'; }
    public function processorPath() { return 'context/'; }
    public function group() { return 'contexts'; }
    public function writeScope() { return Scope::ADMIN; }
    public function nameField() { return 'key'; }
    public function processorExtras($op) { return array(); }

    public function fields() {
        return array(
            'key'         => array('type' => 'string', 'list' => true, 'required' => true, 'immutable' => true, 'description' => 'Context key; fixed after creation.'),
            'name'        => array('type' => 'string', 'list' => true),
            'description' => array('type' => 'string'),
            'rank'        => array('type' => 'integer', 'list' => true),
        );
    }

    public function relationSpecs() {
        return array(
            'settings' => array('class' => 'modContextSetting', 'fk' => 'context_key', 'present' => array('key', 'value'),
                'description' => 'Settings of the context (key and value); change them with the kind context_setting.'),
            // Rows MODX removes together with the context; kept so that an undo is complete.
            'acl' => array('class' => 'modAccessContext', 'fk' => 'target', 'delete_only' => true),
            'source_elements' => array('class' => 'sources.modMediaSourceElement', 'fk' => 'context_key', 'delete_only' => true),
        );
    }

    public function defaultSort() { return array(array('key', 'ASC')); }

    public function keyType() { return 'string'; }
    public function normalizeKey($raw) { return (string) $raw; }
    public function keyCriteria($key) { return array('key' => (string) $key); }
    public function keyOf($object) { return (string) $object->get('key'); }
    public function keyColumn() { return 'key'; }
    public function processorKey($key) { return array('key' => (string) $key); }

    public function createdKey(array $response, array $columns) {
        $key = !empty($response['key']) ? $response['key'] : (isset($columns['key']) ? $columns['key'] : '');
        if ($key === '') { throw new \RuntimeException('The create processor did not return a key.'); }
        return (string) $key;
    }

    /** The configuration MODX holds in memory and its cache must show the change at once. */
    public function afterWrite(\modX $modx) {
        $modx->reloadConfig();
    }

    // ---- credentials among the settings of the context ----

    public function redact(Context $context, $object, array $fields, array $relations) {
        if (empty($relations['settings']) || Scope::allows($context->token['scope'], Scope::ADMIN)) { return array($fields, $relations, array()); }
        $xtypes = array();
        foreach ($context->modx->getCollection('modContextSetting', array('context_key' => $this->keyOf($object))) as $row) {
            $xtypes[(string) $row->get('key')] = (string) $row->get('xtype');
        }
        foreach ($relations['settings'] as $index => $row) {
            $key = (string) $row['key'];
            if (Secrets::isSecret($key, isset($xtypes[$key]) ? $xtypes[$key] : '')) { $relations['settings'][$index]['value'] = Secrets::HIDDEN; }
        }
        return array($fields, $relations, array());
    }

    public function maskRelationRows($name, array $rows) {
        if ($name !== 'settings') { return $rows; }
        foreach ($rows as $index => $row) {
            if (Secrets::isSecret(isset($row['key']) ? $row['key'] : '', isset($row['xtype']) ? $row['xtype'] : '')) { $rows[$index]['value'] = Secrets::HIDDEN; }
        }
        return $rows;
    }

    // ---- deleting a context ----

    public function assertObjectWritable(\modX $modx, $object, $op) {
        if ($op !== 'delete') { return; }
        $key = $this->keyOf($object);
        // MODX deletes the context's own settings with it, and modxMCP's settings are not the API's to remove.
        $own = 0;
        foreach ($modx->getCollection('modContextSetting', array('context_key' => $key)) as $row) {
            if (Secrets::isOwn($row->get('key'), $row->get('namespace'))) { $own++; }
        }
        if ($own) { throw new ApiException('validation_failed', Secrets::OWN_MESSAGE); }
        // The processor also removes every resource of the context and their TV values; none of that can be undone.
        $resources = (int) $modx->getCount('modResource', array('context_key' => $key));
        if ($resources) {
            throw new ApiException('validation_failed', "Context '{$key}' still has {$resources} resource(s). MODX would delete them with the context and an undo cannot bring them back; move or delete them first.");
        }
    }
}
