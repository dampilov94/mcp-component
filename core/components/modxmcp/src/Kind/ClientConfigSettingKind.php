<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Capabilities;
use ModxMCP\Context;
use ModxMCP\Integration\ClientConfig;
use ModxMCP\Journal\Snapshot;

/** Existing global ClientConfig values and their stored context overrides. */
final class ClientConfigSettingKind extends Kind {
    const NAME = 'clientconfig_setting';
    const CHECKBOX = array('checkbox', 'xcheckbox');
    const WRITABLE_TYPES = array('textfield', 'textarea', 'numberfield', 'checkbox', 'xcheckbox');

    public function name() { return self::NAME; }
    public function label() { return 'ClientConfig setting'; }
    public function group() { return ClientConfig::GROUP; }
    public function modelClass() { return 'cgSetting'; }
    public function nameField() { return 'key'; }
    public function readScope() { return Scope::ADMIN; }
    public function writeScope() { return Scope::ADMIN; }
    public function processorPath() { return 'mgr/settings/'; }
    public function usesProcessors() { return false; }

    public function prepare(\modX $modx) {
        // Keep kind lookup tolerant of a missing add-on. The group gate checks access separately.
        try { ClientConfig::load($modx); } catch (\RuntimeException $e) { }
    }

    public function find(\modX $modx, array $ref) {
        if (!isset($ref['name'])) { return parent::find($modx, $ref); }
        $exact = null;
        foreach ($modx->getCollection($this->modelClass(), array('key' => $ref['name']), false) as $object) {
            if ($object->get('key') !== $ref['name']) { continue; }
            if ($exact !== null) { throw new ApiException('validation_failed', 'Several ClientConfig settings have this exact key. Give the setting ID.'); }
            $exact = $object;
        }
        return $exact;
    }

    public function fields() {
        $readOnly = array('read_only' => true, 'list' => true);
        return array(
            'id'       => array('type' => 'integer') + $readOnly,
            'key'      => array('type' => 'string') + $readOnly,
            'label'    => array('type' => 'string') + $readOnly,
            'xtype'    => array('type' => 'string') + $readOnly,
            'group'    => array('type' => 'integer') + $readOnly,
            'required' => array('type' => 'boolean', 'column' => 'is_required') + $readOnly,
            'options'  => array('type' => 'string', 'no_filter' => true, 'description' => 'Static option text.') + $readOnly,
            'value'    => array('type' => 'string', 'list' => true, 'no_filter' => true,
                'input_types' => array('string', 'number', 'boolean'), 'description' => 'Stored global value. Input depends on xtype.'),
        );
    }

    public function relationSpecs() {
        return array('context_values' => array('class' => 'cgContextValue', 'fk' => 'setting', 'map' => true, 'writable' => true, 'refresh_cache' => false,
            'description' => 'Stored context key => value overrides. Only explicitly provided keys are written.'));
    }

    public function presentRelation(\modX $modx, $object, $name) {
        $out = array();
        foreach ($modx->getCollection('cgContextValue', array('setting' => (int) $object->get('id')), false) as $row) {
            $out[(string) $row->get('context')] = (string) $row->get('value');
        }
        ksort($out, SORT_STRING);
        return $out ? (object) $out : new \stdClass();
    }

    private static function isSecret(array $fields) {
        return preg_match('/pass(?:word|wd)?/i', (string) ($fields['xtype'] ?? '')) === 1
            || Secrets::isSecret($fields['key'] ?? '', $fields['xtype'] ?? '')
            || Secrets::isOwn($fields['key'] ?? '', '');
    }

    public function redact(Context $context, $object, array $fields, array $relations) {
        $secret = self::isSecret($this->objectColumns($object));
        if (isset($fields['options']) && $object->get('process_options')) { $fields['options'] = ''; }
        if (!$secret) { return array($fields, $relations, array()); }
        foreach (array('value', 'options') as $name) {
            if (isset($fields[$name])) { $fields[$name] = Secrets::HIDDEN; }
        }
        if (isset($relations['context_values'])) {
            $relations['context_values'] = (object) array_fill_keys(array_keys((array) $relations['context_values']), Secrets::HIDDEN);
        }
        return array($fields, $relations, array('secret' => true));
    }

    public function secretColumns(array $state) {
        if (self::isSecret($state['fields'])) { return array('value', 'default', 'options'); }
        return !empty($state['fields']['process_options']) ? array('options') : array();
    }

    public function secretRelations(array $state) {
        return self::isSecret($state['fields']) ? array('context_values') : array();
    }

    private function assertEditable(\modX $modx, array $fields, $code = 'validation_failed') {
        $key = $fields['key'];
        if (self::isSecret($fields) || $modx->getCount('modSystemSetting', array('key' => $key))
            || $modx->getCount('modContextSetting', array('key' => $key))) {
            throw new ApiException($code, 'This ClientConfig key is protected or belongs to a MODX setting.');
        }
        if (!in_array($fields['xtype'], self::WRITABLE_TYPES, true)) {
            throw new ApiException($code, 'Edit this input type through the ClientConfig manager.');
        }
    }

    public function assertColumns(array $columns, $creating) {
        if ($creating) { throw new ApiException('validation_failed', 'Create settings through the ClientConfig manager.'); }
    }

    public function assertObjectWritable(\modX $modx, $object, $op) {
        if ($op !== 'update') { throw new ApiException('validation_failed', 'Metadata, cloning and removal are managed in ClientConfig.'); }
        $this->assertEditable($modx, $this->objectColumns($object));
    }

    private static function normalizeValue(array $fields, $value) {
        $xtype = $fields['xtype'];
        if (in_array($xtype, self::CHECKBOX, true)) {
            if (in_array($value, array(true, 1, 1.0, '1'), true)) { return '1'; }
            if (in_array($value, array(false, 0, 0.0, '0', ''), true)) { return ''; }
            throw new ApiException('validation_failed', 'A checkbox needs true, false, 1, 0 or an empty string.');
        }
        if (!is_string($value) && !($xtype === 'numberfield' && (is_int($value) || is_float($value)))) {
            throw new ApiException('validation_failed', 'Give a string for a text value or a finite value for a number field.');
        }
        $text = (string) $value;
        $trimmed = trim($text);
        if ($trimmed === '' && !empty($fields['is_required'])) { throw new ApiException('validation_failed', 'This ClientConfig value is required.'); }
        if ($xtype === 'numberfield' && $trimmed !== '' && (!is_numeric($trimmed) || !is_finite((float) $trimmed))) {
            throw new ApiException('validation_failed', 'This ClientConfig value must be a finite number.');
        }
        return $text;
    }

    private function contextCriteria(\modX $modx, $id, $key) {
        $context = $modx->getObject('modContext', array('key' => $key), false);
        if (!$context || $context->get('key') !== $key || $key === 'mgr') {
            throw new ApiException('validation_failed', 'A context override needs the exact key of an existing non-manager context.');
        }
        $criteria = array('setting' => $id, 'context' => $key);
        if ($modx->getCount('cgContextValue', $criteria) > 1) {
            throw new ApiException('validation_failed', 'The context override is ambiguous.');
        }
        return $criteria;
    }

    private function assertContextTarget(\modX $modx, $id, $key) {
        if (!$modx->getOption('clientconfig.context_aware')) { throw new ApiException('validation_failed', 'ClientConfig context mode is off.'); }
        if ($modx->getCount('cgSetting', array('key' => 'context'))) {
            throw new ApiException('validation_failed', 'The reserved ClientConfig key context prevents safe context updates.');
        }
        $criteria = array('key' => $key);
        $target = $modx->getObject('cgSetting', $criteria, false);
        if (!$target || $target->get('key') !== $key || (int) $target->get('id') !== $id || (int) $modx->getCount('cgSetting', $criteria) !== 1) {
            throw new ApiException('validation_failed', 'The exact ClientConfig key does not uniquely identify this setting.');
        }
    }

    public function prepareUpdate(Context $context, array $state, array $columns, array $relations) {
        $modx = $context->modx;
        $this->assertEditable($modx, $state);
        if (array_key_exists('value', $columns)) { $columns['value'] = self::normalizeValue($state, $columns['value']); }
        if (!empty($relations['context_values'])) {
            $this->assertContextTarget($modx, (int) $state['id'], $state['key']);
            foreach ($relations['context_values'] as $key => $value) {
                $this->contextCriteria($modx, (int) $state['id'], (string) $key);
                $relations['context_values'][$key] = self::normalizeValue($state, $value);
            }
        }
        return array($columns, $relations);
    }

    public function writeObject(Context $context, $id, array $columns) {
        $modx = $context->modx;
        $object = $id === null ? null : $this->findByKey($modx, $id);
        if (!$object) { throw new ApiException('not_found', 'The ClientConfig setting no longer exists.'); }
        $metadata = $this->objectColumns($object);
        $fields = array('value' => $columns['value'], 'id' => (int) $id,
            'is_required' => $object->get('is_required') ? '1' : '0', 'process_options' => $object->get('process_options') ? '1' : '0') + $metadata;
        ClientConfig::process($modx, 'update', $fields);
        $saved = $this->findByKey($modx, $id);
        if (!$saved || (string) $saved->get('value') !== $columns['value']) {
            throw new \RuntimeException('ClientConfig did not save the expected global value.');
        }
        return (int) $id;
    }

    public function writeRelation(\modX $modx, $object, $name, $values) {
        $id = (int) $object->get('id');
        $key = $object->get('key');
        foreach ($values as $context => $value) {
            $this->assertContextTarget($modx, $id, $key);
            $criteria = $this->contextCriteria($modx, $id, (string) $context);
            $oldPost = $_POST;
            try {
                $_POST = array('values' => json_encode(array('context' => (string) $context, $key => $value), JSON_THROW_ON_ERROR));
                ClientConfig::process($modx, 'save', array());
            } finally {
                $_POST = $oldPost;
            }
            $saved = $modx->getObject('cgContextValue', $criteria, false);
            if (!$saved || (string) $saved->get('value') !== $value) {
                throw new \RuntimeException('ClientConfig did not save the expected context value.');
            }
        }
    }

    public function assertUndoable(\modX $modx, $key, $target) {
        $caps = new Capabilities($modx);
        if ($caps->groupBlockReason(ClientConfig::GROUP, array('denied_groups' => array())) !== null) {
            throw new ApiException('not_reversible', 'Enable ClientConfig before undoing its values.');
        }
        $current = Snapshot::capture($modx, $this, $key);
        if ($target === null || $current === null) { throw new ApiException('not_reversible', 'Only values of an existing ClientConfig setting can be restored.'); }
        $this->assertEditable($modx, $current['fields'], 'not_reversible');
        $before = $target['fields'];
        $now = $current['fields'];
        unset($before['value'], $now['value']);
        if ($before !== $now) { throw new ApiException('not_reversible', 'The ClientConfig setting definition changed.'); }
    }

    public function afterWrite(\modX $modx) {
        ClientConfig::clearCache($modx);
    }
}
