<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Schema;
use ModxMCP\Write\Transaction;

/**
 * One kind of site object. A kind declares how its objects are named, which fields the API
 * exposes and which related rows travel with the object; the generic object tools, snapshots
 * and undo are driven by these declarations.
 */
abstract class Kind {
    /** Kind name used in the API, e.g. "chunk". */
    abstract public function name();

    /** Human label. */
    abstract public function label();

    /** xPDO class of the object. */
    abstract public function modelClass();

    /**
     * Exposed fields: api name => definition.
     *   type        JSON type: string | integer | boolean
     *   column      database column when it differs from the api name
     *   description short meaning (optional)
     *   list        included in list results by default
     *   code        holds source code (long; edited with the code tools)
     *   read_only   never writable
     *   derived     maintained by MODX; read_only and never sent to a processor
     *   computed    not a database column (derived as well); cannot be filtered on
     *   format      "datetime": stored as unix time, shown and written as "YYYY-MM-DD HH:MM:SS" ("" = none)
     *   required    required on create
     */
    abstract public function fields();

    /** Field that names the object for people and for lookup by name. */
    public function nameField() {
        return 'name';
    }

    public function uniqueNames() {
        return true;
    }

    /** API field overrides for a copy; never share a static element's source file by default. */
    public function duplicateOverrides($source) {
        $fields = $this->fields();
        if (isset($fields['static'], $fields['static_file']) && $this->value($source, 'static')) {
            return array('static' => false, 'static_file' => '');
        }
        return array();
    }

    public function group() {
        return 'elements';
    }

    public function readScope() {
        return Scope::READ;
    }

    public function writeScope() {
        return Scope::DEVELOP;
    }

    /** Core processor directory with a trailing slash, e.g. "element/chunk/". */
    abstract public function processorPath();

    /** Processor that deletes an object of this kind. */
    public function deleteProcessor() {
        return 'remove';
    }

    /** Extra properties every create/update processor call needs. $op is "create" or "update". */
    public function processorExtras($op) {
        return $op === 'create' ? array('clearCache' => 1) : array();
    }

    /** Columns searched by the `query` argument of modx_list. */
    public function searchColumns() {
        return array($this->column($this->nameField()));
    }

    /** Default order of modx_list: [[column, direction], …]. */
    public function defaultSort() {
        return array(array($this->column($this->nameField()), 'ASC'), array('id', 'ASC'));
    }

    /**
     * Columns that make up an object's state. Kinds drop columns MODX rewrites on every save
     * or derives from others, so they neither change the revision nor get restored.
     */
    public function stateColumns(array $columns) {
        return $columns;
    }

    /**
     * Ids of other objects of this kind that the operation will also change (MODX cascades),
     * so they are locked, journaled and restored together with the main object.
     * @param int|null $id      null for a create
     * @param array    $columns column => new value for create/update
     * @param string   $op      create | update | delete | undelete
     */
    public function alsoAffected(\modX $modx, $id, array $columns, $op) {
        return array();
    }

    /** Warnings about limits of journaling for this particular object, shown after a write. */
    public function writeWarnings($object) {
        return array();
    }

    /** "php" when the code field must pass a PHP syntax check before it is saved. */
    public function lint() {
        return null;
    }

    /**
     * MODX events announced when an object is restored outside the processors:
     * ['save' => event, 'delete' => event, 'param' => name of the object parameter].
     */
    public function eventNames() {
        return array();
    }

    /**
     * Related rows that belong to the object: name => spec.
     *   class        xPDO class of the rows
     *   fk           column that holds this object's id
     *   where        extra fixed criteria (optional)
     *   present      column, or list of columns, shown by the API
     *   description  meaning, for describe
     *   delete_only  captured only before a delete (data another object owns, e.g. TV values)
     *   writable     can be replaced through the API
     */
    public function relationSpecs() {
        return array();
    }

    /** Special operations: op => description, read_only, needs_object (default true), input schema, handler. */
    public function operations() {
        return array();
    }

    /** Shared operation declaration for the four static-capable code kinds. */
    protected function staticOperations() {
        return array('make_static' => array('description' => 'Move database code into a managed static file.',
            'read_only' => false, 'needs_object' => true, 'input' => Schema::obj(), 'handler' => 'opMakeStatic'));
    }
    public function opMakeStatic(Context $context, Transaction $tx, $object, array $args): array {
        return StaticFiles::makeStatic($context, $tx, $this, (int) $object->get('id'));
    }

    /** Replace a writable relation. $value is the API form (a list). */
    public function writeRelation(\modX $modx, $object, $name, $value) {
        throw new ApiException('validation_failed', "Relation '{$name}' of {$this->name()} cannot be changed through the API.");
    }

    // ---- derived helpers ----

    public function column($field) {
        $fields = $this->fields();
        return isset($fields[$field]['column']) ? $fields[$field]['column'] : $field;
    }

    /** Name of the code field, or null when the kind has none. */
    public function codeField() {
        foreach ($this->fields() as $name => $definition) {
            if (!empty($definition['code'])) { return $name; }
        }
        return null;
    }

    /** Property set links shared by every element type. */
    protected function propertySetRelation() {
        return array(
            'class' => 'modElementPropertySet', 'fk' => 'element', 'where' => array('element_class' => $this->modelClass()),
            'present' => 'property_set', 'description' => 'Ids of property sets attached to the element.',
        );
    }

    /** Fields shared by chunks, snippets, templates and plugins. */
    protected function elementFields($codeColumn, $nameColumn = 'name') {
        $name = array('type' => 'string', 'list' => true, 'required' => true);
        if ($nameColumn !== 'name') { $name['column'] = $nameColumn; }
        $content = array('type' => 'string', 'code' => true, 'description' => 'Source code. For static elements this is the file content.');
        if ($codeColumn !== 'content') { $content['column'] = $codeColumn; }
        return array(
            'id'          => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'        => $name,
            'description' => array('type' => 'string'),
            'category'    => array('type' => 'integer', 'list' => true, 'description' => 'Category id, 0 for none.'),
            'content'     => $content,
            'static'      => array('type' => 'boolean', 'list' => true, 'description' => 'Content is kept in a file.'),
            'static_file' => array('type' => 'string', 'description' => 'File path of a static element.'),
            'source'      => array('type' => 'integer', 'description' => 'Media source id of the static file, 0 for none.'),
            'locked'      => array('type' => 'boolean', 'description' => 'Locked for editing in the manager.'),
        );
    }

    /** Find an object by ['id' => …] or ['name' => …]; null when it does not exist. Never cached. */
    public function find(\modX $modx, array $ref) {
        if (isset($ref['id'])) {
            return $modx->getObject($this->modelClass(), array('id' => (int) $ref['id']), false);
        }
        if (isset($ref['name']) && $ref['name'] !== '') {
            return $modx->getObject($this->modelClass(), array($this->column($this->nameField()) => (string) $ref['name']), false);
        }
        return null;
    }

    /**
     * Code as the site runs it: the file for a static element, the database column otherwise.
     * Reads without synchronising the file back into the database.
     * @return array [content, is static, absolute file path or null, file missing]
     */
    public function effectiveContent($object) {
        $field = $this->codeField();
        if ($field === null) { return array('', false, null, false); }
        $column = $this->column($field);
        $stored = (string) $object->get($column);
        if (!array_key_exists('static', $this->fields()) || !(bool) $object->get('static')) {
            return array($stored, false, null, false);
        }
        $path = $object->getSourceFile();
        if (!$path || strpos($path, '://') !== false) { return array($stored, true, null, true); }
        clearstatcache(true, $path);
        if (!is_file($path)) { return array('', true, $path, true); }
        $content = @file_get_contents($path);
        if ($content === false) { throw new \RuntimeException('Cannot read the static file of ' . $this->name() . ' ' . $object->get('id') . '.'); }
        return array($content, true, $path, false);
    }

    /** API value of one field. */
    public function value($object, $field) {
        $fields = $this->fields();
        if (!empty($fields[$field]['code'])) {
            $content = $this->effectiveContent($object);
            return $content[0];
        }
        $raw = $object->get($this->column($field));
        if (isset($fields[$field]['format']) && $fields[$field]['format'] === 'datetime') {
            return self::datetime($raw);
        }
        switch ($fields[$field]['type']) {
            case 'integer': return (int) $raw;
            case 'boolean': return (bool) $raw;
            default:        return $raw === null ? '' : (string) $raw;
        }
    }

    /** Unix time (or a date string xPDO already formatted) as "YYYY-MM-DD HH:MM:SS"; "" for none. */
    public static function datetime($raw) {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') { return ''; }
        $time = is_numeric($raw) ? (int) $raw : strtotime((string) $raw);
        return $time ? date('Y-m-d H:i:s', $time) : '';
    }

    /** Current value of a field in the form the core processors expect. */
    public function processorValue($object, $field) {
        $fields = $this->fields();
        if (isset($fields[$field]['format']) && $fields[$field]['format'] === 'datetime') {
            return self::datetime($object->get($this->column($field)));
        }
        return $object->get($this->column($field));
    }

    /** Stored relation values for copying, without filling in defaults. */
    public function storedRelation(\modX $modx, $object, $name) {
        return $this->presentRelation($modx, $object, $name);
    }

    /** Rows of one relation as the API shows them. */
    public function presentRelation(\modX $modx, $object, $name) {
        $specs = $this->relationSpecs();
        $spec = $specs[$name];
        $criteria = array($spec['fk'] => $object->get('id')) + (isset($spec['where']) ? $spec['where'] : array());
        $out = array();
        foreach ($modx->getCollection($spec['class'], $criteria) as $row) {
            if (is_array($spec['present'])) {
                $item = array();
                foreach ($spec['present'] as $column) { $item[$column] = self::scalar($row->get($column)); }
                $out[] = $item;
            } else {
                $out[] = self::scalar($row->get($spec['present']));
            }
        }
        usort($out, function ($a, $b) { return strcmp(json_encode($a), json_encode($b)); });
        return $out;
    }

    private static function scalar($value) {
        return is_numeric($value) && (string) (int) $value === (string) $value ? (int) $value : $value;
    }

    /** @return array Schema of this kind for modx_describe. */
    public function describe() {
        $fields = array();
        foreach ($this->fields() as $name => $definition) {
            $field = array('name' => $name, 'type' => $definition['type']);
            if (isset($definition['description'])) { $field['description'] = $definition['description']; }
            if (!empty($definition['derived'])) { $definition['read_only'] = true; }
            if (isset($definition['format'])) { $field['format'] = $definition['format']; }
            foreach (array('list', 'code', 'read_only', 'required') as $flag) {
                if (!empty($definition[$flag])) { $field[$flag] = true; }
            }
            $fields[] = $field;
        }
        $relations = array();
        foreach ($this->relationSpecs() as $name => $spec) {
            if (!empty($spec['delete_only'])) { continue; }
            $relations[] = array('name' => $name, 'description' => $spec['description'], 'writable' => !empty($spec['writable']));
        }
        $operations = array();
        foreach ($this->operations() as $op => $definition) {
            $operations[] = array('op' => $op, 'description' => $definition['description'], 'read_only' => !empty($definition['read_only']),
                'needs_object' => !isset($definition['needs_object']) || $definition['needs_object'], 'input' => $definition['input']);
        }
        return array(
            'kind'        => $this->name(),
            'label'       => $this->label(),
            'group'       => $this->group(),
            'name_field'  => $this->nameField(),
            'read_scope'  => $this->readScope(),
            'write_scope' => $this->writeScope(),
            'fields'      => $fields,
            'relations'   => $relations,
            'operations'  => $operations,
        );
    }
}
