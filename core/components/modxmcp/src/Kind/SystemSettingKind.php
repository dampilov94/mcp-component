<?php
namespace ModxMCP\Kind;

use ModxMCP\Auth\Scope;

/** A system setting (modSystemSetting), identified by its key. */
final class SystemSettingKind extends AbstractSettingKind {
    public function name() { return 'system_setting'; }
    public function label() { return 'System setting'; }
    public function modelClass() { return 'modSystemSetting'; }
    public function processorPath() { return 'system/settings/'; }
    public function group() { return 'settings'; }
    public function writeScope() { return Scope::DEVELOP; }

    public function fields() {
        return array(
            'key' => array('type' => 'string', 'list' => true, 'required' => true, 'immutable' => true, 'description' => 'Setting key; fixed after creation.'),
        ) + $this->settingFields();
    }

    public function defaultSort() { return array(array('key', 'ASC')); }

    public function keyCriteria($key) { return array('key' => (string) $key); }

    public function keyOf($object) { return (string) $object->get('key'); }

    public function keyColumn() { return 'key'; }

    public function processorKey($key) { return array('key' => (string) $key); }

    public function createdKey(array $response, array $columns) {
        $key = !empty($response['key']) ? $response['key'] : (isset($columns['key']) ? $columns['key'] : '');
        if ($key === '') { throw new \RuntimeException('The create processor did not return a key.'); }
        return (string) $key;
    }
}
