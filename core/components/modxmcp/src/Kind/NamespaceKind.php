<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;

/** A namespace (modNamespace). Its key is the column `name`. */
final class NamespaceKind extends Kind {
    public function name() { return 'namespace'; }
    public function label() { return 'Namespace'; }
    public function modelClass() { return 'modNamespace'; }
    public function processorPath() { return 'workspace/namespace/'; }
    public function group() { return 'namespaces'; }
    public function writeScope() { return Scope::ADMIN; }
    public function nameField() { return 'key'; }
    public function processorExtras($op) { return array(); }

    public function fields() {
        return array(
            'key'         => array('type' => 'string', 'column' => 'name', 'list' => true, 'required' => true, 'immutable' => true, 'description' => 'Namespace name; fixed after creation.'),
            'path'        => array('type' => 'string', 'list' => true),
            'assets_path' => array('type' => 'string'),
        );
    }

    public function relationSpecs() {
        // MODX removes everything of the namespace together with it; kept so that an undo is complete.
        $rows = array(
            'system_settings' => 'modSystemSetting', 'context_settings' => 'modContextSetting', 'user_settings' => 'modUserSetting',
            'lexicon_entries' => 'modLexiconEntry', 'extension_packages' => 'modExtensionPackage',
        );
        $specs = array();
        foreach ($rows as $name => $class) { $specs[$name] = array('class' => $class, 'fk' => 'namespace', 'delete_only' => true); }
        $specs['acl'] = array('class' => 'modAccessNamespace', 'fk' => 'target', 'delete_only' => true);
        return $specs;
    }

    public function defaultSort() { return array(array('name', 'ASC')); }

    public function keyType() { return 'string'; }
    public function normalizeKey($raw) { return (string) $raw; }
    public function keyCriteria($key) { return array('name' => (string) $key); }
    public function keyOf($object) { return (string) $object->get('name'); }
    public function keyColumn() { return 'name'; }
    public function processorKey($key) { return array('name' => (string) $key); }

    public function createdKey(array $response, array $columns) {
        $key = !empty($response['name']) ? $response['name'] : (isset($columns['name']) ? $columns['name'] : '');
        if ($key === '') { throw new \RuntimeException('The create processor did not return a name.'); }
        return (string) $key;
    }

    // ---- the namespace of modxMCP itself is not the API's to change ----

    private static function isOwn($name) {
        return mb_strtolower(trim((string) $name), 'UTF-8') === Secrets::OWN_NAMESPACE;
    }

    public function assertColumns(array $columns, $creating) {
        if ($creating && isset($columns['name']) && self::isOwn($columns['name'])) { throw new ApiException('validation_failed', Secrets::OWN_MESSAGE); }
    }

    public function assertObjectWritable(\modX $modx, $object, $op) {
        $name = $this->keyOf($object);
        if (self::isOwn($name)) { throw new ApiException('validation_failed', Secrets::OWN_MESSAGE); }
        if ($op !== 'delete') { return; }
        // Deleting a namespace deletes its settings; a modxMCP setting must not go that way.
        foreach (array('modSystemSetting', 'modContextSetting') as $class) {
            foreach ($modx->getCollection($class, array('namespace' => $name)) as $row) {
                if (Secrets::isOwn($row->get('key'), '')) { throw new ApiException('validation_failed', Secrets::OWN_MESSAGE); }
            }
        }
    }

    public function assertRestorable(array $state) {
        if (isset($state['fields']['name']) && self::isOwn($state['fields']['name'])) { throw new ApiException('not_reversible', Secrets::OWN_MESSAGE); }
    }
}
