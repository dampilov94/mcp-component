<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Integration\Migx;

/**
 * A MIGX configuration (class migxConfig of MIGX 3.0.2): the form tabs, grid columns, menus, filters and options a MIGX
 * template variable (or a MIGXdb grid) is built from.
 *
 * MIGX has no processors for it that run without its manager controller (the grid of configurations is served by a
 * generic update processor that needs `$modx->migx` and request state), so the xPDO object is written directly, inside
 * the transaction, the way MIGX's own save does: `migxConfig::save()` also mirrors the form tabs and their fields into
 * the tables migx_formtabs and migx_formtab_fields and writes the row ids back into `formtabs` as MIGX_id. Those two
 * tables are journaled as hidden relations, so an undo puts exactly the same rows back. MIGX fires no events of its own,
 * and it does not cache configurations.
 *
 * MIGX removes configurations in its grid by setting `deleted` (plus `deletedon`, `deletedby`), never from the table;
 * modx_delete does the same, and modx_undo clears the flag.
 */
final class MigxConfigKind extends Kind {
    /** @var Context|null */
    private $context;

    /** Column => what MIGX stores in it when nothing is set (every column has a default in the map). */
    const DEFAULTS = array(
        'name' => '', 'formtabs' => '', 'contextmenus' => '', 'actionbuttons' => '', 'columnbuttons' => '', 'filters' => '',
        'extended' => '', 'permissions' => '', 'fieldpermissions' => '', 'columns' => '', 'category' => '',
        'createdby' => 0, 'createdon' => null, 'editedby' => 0, 'editedon' => null, 'deleted' => 0, 'deletedon' => null, 'deletedby' => 0,
        'published' => 0, 'publishedon' => null, 'publishedby' => 0,
    );

    public function name() { return 'migx_config'; }
    public function label() { return 'MIGX configuration'; }
    public function modelClass() { return 'migxConfig'; }
    public function group() { return Migx::GROUP; }
    public function processorPath() { return ''; }
    public function usesProcessors() { return false; }
    public function processorExtras($op) { return array(); }
    public function searchColumns() { return array('name', 'category'); }

    public function fields() {
        $json = function ($description) { return array('type' => 'json', 'description' => $description); };
        return array(
            'id'               => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'             => array('type' => 'string', 'list' => true, 'required' => true,
                'description' => 'Name a TV refers to (input property "configs"); unique among configurations that are not deleted. At most 100 characters.'),
            'category'         => array('type' => 'string', 'list' => true, 'description' => 'Free-text group in the MIGX grid of configurations. At most 191 characters.'),
            'published'        => array('type' => 'boolean', 'list' => true, 'description' => 'Shown as published in the MIGX grid of configurations (a new configuration is published, as the MIGX form makes it).'),
            'deleted'          => array('type' => 'boolean', 'read_only' => true, 'description' => 'Removed in the MIGX grid (the row stays). Changed with modx_delete and modx_undo; modx_list hides deleted configurations unless where {"deleted": true}.'),
            'formtabs'         => $json('Form tabs of the edit window: a list of {caption, fields: [{field, caption, inputTV?, inputTVtype?, inputOptionValues?, default?, …}]}. '
                . 'MIGX adds MIGX_id and pos to every tab and field when it saves (the ids of the mirrored rows); keep them when you send the list back, otherwise the rows are replaced.'),
            'columns'          => $json('Grid columns: a list of {header, dataIndex, width?, sortable?, renderer?, …}. dataIndex names a field of the form tabs (or a virtual column rendered by renderchunktpl).'),
            'filters'          => $json('Filters above the grid (MIGXdb): a list of objects.'),
            'extended'         => $json('Further settings as an object: formcaption, win_id, maxRecords, addNewItemAt, packageName, classname, … (the MIGX form lists them).'),
            'permissions'      => $json('Permission names MIGX checks, as an object (view, list, save, create, remove, delete, publish, …).'),
            'fieldpermissions' => $json('Per-field permissions, as an object.'),
            'contextmenus'     => array('type' => 'string', 'description' => 'Context menu entries as MIGX stores them: names joined by "||", e.g. "update||duplicate||remove". Not JSON.'),
            'actionbuttons'    => array('type' => 'string', 'description' => 'Grid action buttons as MIGX stores them: names joined by "||". Not JSON.'),
            'columnbuttons'    => array('type' => 'string', 'description' => 'Column buttons as MIGX stores them: names joined by "||". Not JSON.'),
        );
    }

    public function attach(Context $context) { $this->context = $context; }

    /** Registers the models. Reading the journal must work without MIGX, so a missing add-on is not an error here. */
    public function prepare(\modX $modx) {
        try { Migx::load($modx); } catch (\RuntimeException $e) { /* assertUndoable() and the actions report it where it matters */ }
    }

    public function relationSpecs() {
        // The rows migxConfig::save() mirrors the form tabs into; not shown, but part of the state so an undo is exact.
        return array(
            'formtab_rows' => array('class' => 'migxFormtab', 'fk' => 'config_id', 'present' => 'id', 'hidden' => true, 'named_by' => 'formtabs'),
            'formtab_fields' => array('class' => 'migxFormtabField', 'fk' => 'config_id', 'present' => 'id', 'hidden' => true, 'named_by' => 'formtabs'),
        );
    }

    /** editedon and editedby change on every save. */
    public function stateColumns(array $columns) {
        unset($columns['editedon'], $columns['editedby'], $columns['preventformtabhandling']);
        return $columns;
    }

    /** By id, or by the name of a configuration that is not deleted (a deleted one does not take its name any more). */
    public function find(\modX $modx, array $ref) {
        if (isset($ref['id']) || isset($ref['key']) || !isset($ref['name']) || $ref['name'] === '') { return parent::find($modx, $ref); }
        return $modx->getObject('migxConfig', array('name' => (string) $ref['name'], 'deleted' => 0), false);
    }

    /** Deleted configurations are listed only when asked for. */
    public function listCriteria(array $criteria) {
        return array_key_exists('deleted', $criteria) ? $criteria : $criteria + array('deleted' => 0);
    }

    // ---- validation ----

    /** Another configuration that is not deleted has this name. */
    private function nameTaken(\modX $modx, $name, $exceptId) {
        $other = $modx->getObject('migxConfig', array('name' => (string) $name, 'deleted' => 0), false);
        return $other && (int) $other->get('id') !== (int) $exceptId;
    }

    public function assertColumns(array $columns, $creating) {
        if (isset($columns['name'])) {
            $name = (string) $columns['name'];
            if (trim($name) === '') { throw new ApiException('validation_failed', $creating ? "Field 'name' is required." : "Field 'name' cannot be empty."); }
            if (mb_strlen($name, 'UTF-8') > 100) { throw new ApiException('validation_failed', "Field 'name' is too long (at most 100 characters)."); }
        }
        if (isset($columns['category']) && mb_strlen((string) $columns['category'], 'UTF-8') > 191) {
            throw new ApiException('validation_failed', "Field 'category' is too long (at most 191 characters).");
        }
        foreach (array('formtabs', 'columns', 'filters', 'extended', 'permissions', 'fieldpermissions') as $column) {
            if (!isset($columns[$column]) || $columns[$column] === '') { continue; }
            $value = json_decode((string) $columns[$column], true);
            switch ($column) {
                case 'formtabs': self::checkFormtabs($value); break;
                case 'columns': self::checkColumns($value); break;
                case 'filters': self::checkListOfObjects($column, $value); break;
                default: self::checkObject($column, $value);
            }
        }
    }

    private static function isList($value) {
        return is_array($value) && (!$value || array_keys($value) === range(0, count($value) - 1));
    }

    private static function fail($path, $what) {
        throw new ApiException('validation_failed', "{$path} must be {$what}.");
    }

    private static function checkListOfObjects($path, $value) {
        if (!self::isList($value)) { self::fail($path, 'a list of objects'); }
        foreach ($value as $index => $item) {
            if (!is_array($item) || ($item && self::isList($item))) { self::fail("{$path}[{$index}]", 'an object'); }
        }
    }

    private static function checkObject($path, $value) {
        if (!is_array($value) || ($value && self::isList($value))) { self::fail($path, 'an object'); }
    }

    /** formtabs: [{caption, fields: [{field, caption, inputTV?, inputTVtype?, …}]}]; unknown keys are allowed. */
    private static function checkFormtabs($value) {
        self::checkListOfObjects('formtabs', $value);
        foreach ($value as $t => $tab) {
            if (!isset($tab['caption']) || !is_string($tab['caption'])) { self::fail("formtabs[{$t}].caption", 'a string'); }
            if (!isset($tab['fields']) || !self::isList($tab['fields'])) { self::fail("formtabs[{$t}].fields", 'a list of fields'); }
            foreach ($tab['fields'] as $f => $field) {
                $path = "formtabs[{$t}].fields[{$f}]";
                if (!is_array($field) || ($field && self::isList($field))) { self::fail($path, 'an object'); }
                if (!isset($field['field']) || !is_string($field['field']) || trim($field['field']) === '') { self::fail($path . '.field', 'a non-empty string (the key stored in the row)'); }
                if (!isset($field['caption']) || !is_string($field['caption'])) { self::fail($path . '.caption', 'a string'); }
                foreach (array('inputTV', 'inputTVtype') as $optional) {
                    if (isset($field[$optional]) && !is_string($field[$optional])) { self::fail($path . '.' . $optional, 'a string'); }
                }
            }
        }
    }

    /** columns: [{header, dataIndex, …}]. */
    private static function checkColumns($value) {
        self::checkListOfObjects('columns', $value);
        foreach ($value as $c => $column) {
            if (!isset($column['header']) || !is_string($column['header'])) { self::fail("columns[{$c}].header", 'a string'); }
            if (!isset($column['dataIndex']) || !is_string($column['dataIndex']) || $column['dataIndex'] === '') { self::fail("columns[{$c}].dataIndex", 'a non-empty string'); }
        }
    }

    public function beforeCreate(Context $context, array $columns) {
        if ($this->nameTaken($context->modx, $columns['name'], 0)) {
            throw new ApiException('validation_failed', "A migx_config named '{$columns['name']}' already exists.");
        }
        return null;
    }

    public function assertUpdatable(\modX $modx, $object, array $columns) {
        if (isset($columns['name']) && (string) $columns['name'] !== (string) $object->get('name') && $this->nameTaken($modx, $columns['name'], $object->get('id'))) {
            throw new ApiException('validation_failed', "A migx_config named '{$columns['name']}' already exists.");
        }
    }

    public function assertObjectWritable(\modX $modx, $object, $op) {
        if ($op === 'duplicate' || !(int) $object->get('deleted')) { return; }
        throw new ApiException('validation_failed', $op === 'delete'
            ? 'MIGX configuration ' . $object->get('id') . ' is already deleted.'
            : 'MIGX configuration ' . $object->get('id') . ' is deleted; modx_undo brings it back.');
    }

    /** A configuration cannot be put back as live while another live one has its name. */
    public function assertUndoable(\modX $modx, $key, $target) {
        try { Migx::load($modx); } catch (\RuntimeException $e) { throw new ApiException('not_reversible', 'MIGX is not installed; its configurations cannot be restored.'); }
        if ($target !== null && (string) (isset($target['fields']['deleted']) ? $target['fields']['deleted'] : '0') !== '1' && isset($target['fields']['name'])
            && $this->nameTaken($modx, $target['fields']['name'], $key)) {
            throw new ApiException('not_reversible', "Another migx_config is named '{$target['fields']['name']}' now; rename or delete it first.");
        }
    }

    // ---- writing ----

    private function userId(\modX $modx) {
        return !empty($modx->user) ? (int) $modx->user->get('id') : 0;
    }

    public function writeObject(Context $context, $id, array $columns) {
        $modx = $context->modx;
        Migx::load($modx);
        $now = date('Y-m-d H:i:s');
        $user = $this->userId($modx);
        if ($id === null) {
            $object = $modx->newObject('migxConfig');
            $data = $columns + array('published' => 1);
            $data += array('createdon' => $now, 'createdby' => $user);
            if ((int) $data['published']) { $data += array('publishedon' => $now, 'publishedby' => $user); }
            $data += self::DEFAULTS;
        } else {
            $object = $this->findByKey($modx, $id);
            if (!$object) { throw new ApiException('not_found', "No migx_config with id {$id}."); }
            // A JSON value spelled differently but equal (another key order) is not a change: leave the stored text as it is.
            $stored = $object->toArray('', true);   // the stored text (get() decodes the three xPDO json columns)
            foreach (array('formtabs', 'columns', 'filters', 'extended', 'permissions', 'fieldpermissions') as $column) {
                if (isset($columns[$column]) && $columns[$column] !== '' && isset($stored[$column]) && (string) $stored[$column] !== ''
                    && Kind::canonicalValue(json_decode((string) $columns[$column], true)) === Kind::canonicalJson((string) $stored[$column])) {
                    unset($columns[$column]);
                }
            }
            $data = $columns + array('editedon' => $now, 'editedby' => $user);
            if (isset($columns['published']) && (int) $columns['published'] && !(int) $object->get('published')) {
                $data += array('publishedon' => $now, 'publishedby' => $user);
            }
        }
        return $this->save($modx, $object, $data, $id === null || array_key_exists('formtabs', $columns));
    }

    /**
     * Save through xPDO. The columns are set raw, so the text is stored exactly as given. Unless the form tabs
     * change, migxConfig::save() is told not to mirror them again (it would only rewrite what is there).
     */
    private function save(\modX $modx, $object, array $data, $syncFormtabs) {
        $object->fromArray($data, '', false, true);
        if (!$syncFormtabs) { $object->set('preventformtabhandling', 1); }
        $created = $object->get('id') ? false : true;
        try {
            if (!$object->save()) { throw new \RuntimeException('Cannot save the MIGX configuration.'); }
        } catch (\Throwable $e) {
            if ($created && $object->get('id')) { $object->remove(); }
            throw $e;
        }
        return (int) $object->get('id');
    }

    /** MIGX marks a configuration deleted and keeps the row (and the rows of its form tabs). */
    public function removeObject(Context $context, $key) {
        $modx = $context->modx;
        Migx::load($modx);
        $object = $this->findByKey($modx, $key);
        if (!$object) { throw new ApiException('not_found', "No migx_config with id {$key}."); }
        $this->save($modx, $object, array('deleted' => 1, 'deletedon' => date('Y-m-d H:i:s'), 'deletedby' => $this->userId($modx)), false);
    }

    /** An undo saves the recorded columns and rows; migxConfig::save() must not mirror the form tabs again then. */
    public function prepareRestore($object) {
        $object->set('preventformtabhandling', 1);
    }

    // ---- warnings ----

    /** Soft checks of the saved configuration: a column that shows no field, an inputTV that is not a TV. */
    public function writeWarnings($object) {
        $tabs = json_decode((string) $object->get('formtabs'), true);
        if (!is_array($tabs) || !$tabs) { return array(); }
        $warnings = array();
        $fields = array();
        foreach ($tabs as $t => $tab) {
            foreach (isset($tab['fields']) && is_array($tab['fields']) ? $tab['fields'] : array() as $f => $field) {
                if (!is_array($field)) { continue; }
                if (isset($field['field']) && is_string($field['field'])) { $fields[$field['field']] = true; }
                if (isset($field['inputTV']) && is_string($field['inputTV']) && $field['inputTV'] !== '' && $this->context
                    && !$this->context->modx->getCount('modTemplateVar', array('name' => $field['inputTV']))) {
                    $warnings[] = "formtabs[{$t}].fields[{$f}].inputTV '{$field['inputTV']}' is not a template variable on this site; MIGX would show a text field.";
                }
            }
        }
        $columns = json_decode((string) $object->get('columns'), true);
        foreach (is_array($columns) ? $columns : array() as $c => $column) {
            if (is_array($column) && isset($column['dataIndex']) && is_string($column['dataIndex']) && $column['dataIndex'] !== 'MIGX_id'
                && !isset($fields[$column['dataIndex']])) {
                $warnings[] = "columns[{$c}].dataIndex '{$column['dataIndex']}' is not a field of any form tab (fine for a virtual column rendered by renderchunktpl, otherwise the column stays empty).";
            }
        }
        return $warnings;
    }
}
