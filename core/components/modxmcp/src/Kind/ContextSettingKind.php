<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;

/** A context setting (modContextSetting), identified by "<context_key>:<key>", e.g. "web:site_url". */
final class ContextSettingKind extends AbstractSettingKind {
    public function name() { return 'context_setting'; }
    public function label() { return 'Context setting'; }
    public function modelClass() { return 'modContextSetting'; }
    public function processorPath() { return 'context/setting/'; }
    public function group() { return 'contexts'; }
    public function writeScope() { return Scope::DEVELOP; }

    public function fields() {
        return array(
            'context_key' => array('type' => 'string', 'list' => true, 'required' => true, 'immutable' => true, 'description' => 'Key of the context; fixed after creation.'),
            'key'         => array('type' => 'string', 'list' => true, 'required' => true, 'immutable' => true, 'description' => 'Setting key; fixed after creation.'),
        ) + $this->settingFields();
    }

    /** A copy keeps the context; the new key is new_name, and the processor refuses one that exists. */
    public function uniqueNames() { return false; }

    public function defaultSort() { return array(array('context_key', 'ASC'), array('key', 'ASC')); }

    /** Splits "<context_key>:<key>" on the first colon; the setting key itself may contain colons. */
    public function normalizeKey($raw) {
        $this->split((string) $raw);
        return (string) $raw;
    }

    private function split($key) {
        $position = strpos($key, ':');
        if ($position === false || $position === 0 || $position === strlen($key) - 1) {
            throw new ApiException('validation_failed', "A context setting is addressed as '<context_key>:<key>', e.g. 'web:site_url'.");
        }
        return array(substr($key, 0, $position), substr($key, $position + 1));
    }

    public function keyCriteria($key) {
        list($context, $name) = $this->split((string) $key);
        return array('context_key' => $context, 'key' => $name);
    }

    public function keyOf($object) { return $object->get('context_key') . ':' . $object->get('key'); }

    public function keyColumn() { return 'key'; }

    public function processorKey($key) {
        list($context, $name) = $this->split((string) $key);
        return array('context_key' => $context, 'key' => $name);
    }

    /** context/setting/create names the context by `fk`; update and remove by `context_key`. */
    public function processorData($op, array $data) {
        $data = parent::processorData($op, $data);
        if ($op === 'create' && isset($data['context_key'])) { $data['fk'] = $data['context_key']; }
        return $data;
    }

    public function createdKey(array $response, array $columns) {
        $context = !empty($response['context_key']) ? $response['context_key'] : (isset($columns['context_key']) ? $columns['context_key'] : '');
        $key = !empty($response['key']) ? $response['key'] : (isset($columns['key']) ? $columns['key'] : '');
        if ($context === '' || $key === '') { throw new \RuntimeException('The create processor did not return a key.'); }
        return $context . ':' . $key;
    }
}
