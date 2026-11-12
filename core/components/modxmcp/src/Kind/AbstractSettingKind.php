<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;

/**
 * What system and context settings have in common: a string key, credentials that are shown
 * only to admin tokens, modxMCP's own settings that the API never changes, and an up-to-date
 * configuration after every write.
 */
abstract class AbstractSettingKind extends Kind {
    public function nameField() { return 'key'; }

    public function keyType() { return 'string'; }

    public function normalizeKey($raw) { return (string) $raw; }

    public function processorExtras($op) { return array(); }

    /** The setting columns shared by both kinds (everything except the key columns). */
    protected function settingFields() {
        return array(
            'value'     => array('type' => 'string', 'list' => true, 'no_filter' => true, 'description' => 'Value; hidden for credentials unless the token has the admin scope.'),
            'xtype'     => array('type' => 'string', 'list' => true, 'description' => 'Input type in the manager, e.g. textfield, combo-boolean, text-password.'),
            'namespace' => array('type' => 'string', 'list' => true, 'description' => 'Namespace key; default core.'),
            'area'      => array('type' => 'string', 'list' => true),
            'editedon'  => array('type' => 'string', 'format' => 'datetime', 'read_only' => true, 'derived' => true),
        );
    }

    /** editedon changes on every save; it is neither part of the state nor restored. */
    public function stateColumns(array $columns) {
        unset($columns['editedon']);
        return $columns;
    }

    /** The processors insist on a namespace; the model default (core) applies when none is given. */
    public function processorData($op, array $data) {
        if ($op === 'create' && (!isset($data['namespace']) || $data['namespace'] === '')) { $data['namespace'] = 'core'; }
        return $data;
    }

    /** The configuration MODX holds in memory and its cache must show the change at once. */
    public function afterWrite(\modX $modx) {
        $modx->reloadConfig();
    }

    // ---- credentials ----

    public function secretColumns(array $state) {
        $fields = $state['fields'];
        return Secrets::isSecret(isset($fields['key']) ? $fields['key'] : '', isset($fields['xtype']) ? $fields['xtype'] : '') ? array('value') : array();
    }

    public function redact(Context $context, $object, array $fields, array $relations) {
        if (!Secrets::isSecret($object->get('key'), $object->get('xtype'))) { return array($fields, $relations, array()); }
        if (array_key_exists('value', $fields) && !Scope::allows($context->token['scope'], Scope::ADMIN)) { $fields['value'] = Secrets::HIDDEN; }
        return array($fields, $relations, array('secret' => true));
    }

    // ---- modxMCP's own settings are read-only through the API ----

    public function assertColumns(array $columns, $creating) {
        $key = isset($columns['key']) ? $columns['key'] : '';
        $namespace = isset($columns['namespace']) ? $columns['namespace'] : '';
        if (Secrets::isOwn($creating ? $key : '', $namespace)) { throw new ApiException('validation_failed', Secrets::OWN_MESSAGE); }
    }

    public function assertObjectWritable(\modX $modx, $object, $op) {
        if (Secrets::isOwn($object->get('key'), $object->get('namespace'))) { throw new ApiException('validation_failed', Secrets::OWN_MESSAGE); }
    }

    public function assertRestorable(array $state) {
        $fields = $state['fields'];
        if (Secrets::isOwn(isset($fields['key']) ? $fields['key'] : '', isset($fields['namespace']) ? $fields['namespace'] : '')) {
            throw new ApiException('not_reversible', Secrets::OWN_MESSAGE);
        }
    }
}
