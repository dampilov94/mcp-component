<?php
// Test-only kind: an object identified by a string key (a system setting). It exists so the
// core can be checked against string keys before a real kind of that sort is added.

use ModxMCP\Kind\Kind;

final class TestSettingKind extends Kind {
    public function name() { return 'test_setting'; }

    public function label() { return 'Test setting'; }

    public function modelClass() { return 'modSystemSetting'; }

    public function processorPath() { return 'system/settings/'; }

    public function nameField() { return 'key'; }

    public function fields() {
        return array(
            'key'   => array('type' => 'string', 'list' => true, 'required' => true),
            'value' => array('type' => 'string', 'list' => true),
            'area'  => array('type' => 'string', 'list' => true),
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
        if (empty($response['key'])) { throw new \RuntimeException('The create processor did not return a key.'); }
        return (string) $response['key'];
    }
}
