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
     *   type        JSON type: string | integer | number (a decimal column, shown as a float) | boolean | object (a JSON object of name => boolean, stored as JSON text)
     *   column      database column when it differs from the api name
     *   description short meaning (optional)
     *   list        included in list results by default
     *   code        holds source code (long; edited with the code tools)
     *   read_only   never writable
     *   derived     maintained by MODX; read_only and never sent to a processor
     *   computed    not a database column (derived as well); cannot be filtered on
     *   format      "datetime": stored as unix time, shown and written as "YYYY-MM-DD HH:MM:SS" ("" = none)
     *   required    required on create
     *   immutable   cannot be changed after creation
     *   no_filter   cannot be used in the `where` of modx_list
     *   via         name of a relation (see relationSpecs()): the value lives in the one related row, read
     *               through viaRow(); sent to the processors like any field, never filterable, not a column
     *               of the model class (snapshots keep it in the relation's rows)
     *   write_only  accepted on write, never read, listed or sent back (a password)
     *   json        with type "object": any JSON object (not only name => boolean), stored as JSON text; shown as the
     *               object it is, written as an object (nested empty objects cannot be told from empty lists)
     */
    abstract public function fields();

    /** Field that names the object for people and for lookup by name. */
    public function nameField() {
        return 'name';
    }

    public function uniqueNames() {
        return true;
    }

    /** False for objects that have no name of their own (access rules): they are addressed by id only. */
    public function hasNames() {
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

    /**
     * False for kinds that are only read through the generic tools: create, update, delete and duplicate are
     * refused with genericWritesMessage() before anything else (packages have their own install and uninstall tools).
     */
    public function genericWrites() {
        return true;
    }

    /** Why the generic write tools refuse this kind; used when genericWrites() is false. */
    public function genericWritesMessage() {
        return "Objects of kind '{$this->name()}' cannot be changed with the generic write tools.";
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
        return array(array($this->column($this->nameField()), 'ASC'), array($this->keyColumn(), 'ASC'));
    }

    // ---- object key: how one object of the kind is identified ----

    /** 'integer' (a numeric id) or 'string' (e.g. a setting key). */
    public function keyType() {
        return 'integer';
    }

    /** Canonical PHP value of a key: int for integer kinds, string for string kinds. */
    public function normalizeKey($raw) {
        return (int) $raw;
    }

    /** xPDO criteria that select the object with this key. */
    public function keyCriteria($key) {
        return array('id' => (int) $key);
    }

    /** Key of a loaded object. */
    public function keyOf($object) {
        return (int) $object->get('id');
    }

    /** Column used as the last sort key in modx_list. */
    public function keyColumn() {
        return 'id';
    }

    /** Properties that identify the object to its update and remove processors. */
    public function processorKey($key) {
        return array('id' => $key);
    }

    /** Key of an object just created, from the create processor's response object and the columns sent. */
    public function createdKey(array $response, array $columns) {
        if (empty($response['id'])) { throw new \RuntimeException('The create processor did not return an id.'); }
        return (int) $response['id'];
    }

    /** Name of the result field that carries the key: "id" for integer kinds, "key" for string kinds. */
    public function keyField() {
        return $this->keyType() === 'string' ? 'key' : 'id';
    }

    /** A key in the type the API shows it in. */
    public function publicKey($key) {
        return $this->keyType() === 'string' ? (string) $key : (int) $key;
    }

    /** Find an object by its key; null when it does not exist. Never cached. */
    public function findByKey(\modX $modx, $key) {
        return $modx->getObject($this->modelClass(), $this->keyCriteria($key), false);
    }

    /** Properties of the final create/update processor call, e.g. defaults a processor insists on. $op is "create" or "update". */
    public function processorData($op, array $data) {
        return $data;
    }

    // ---- hooks of kinds that need extra care; the defaults change nothing ----

    /** Called once when the registry builds the kind for a request; a kind that depends on the site keeps the context. */
    public function attach(Context $context) {
    }

    /** Called before an action works with objects of this kind (registers add-on models); may throw when the add-on is gone. */
    public function prepare(\modX $modx) {
    }

    /** Raw column => value of an object, as the snapshot takes them. Kinds whose objects mix in other data override. */
    public function objectColumns($object) {
        return $object->toArray('', true);
    }

    /** Whether a declared relation exists for this particular object (modx_get shows it, modx_duplicate copies it). */
    /** Message for a write to a relation this object cannot have. */
    public function relationNotApplicable($object, $name) {
        return "Relation '{$name}' does not apply to {$this->name()} " . $this->keyOf($object) . ' (see modx_describe).';
    }

    public function hasRelation(\modX $modx, $object, $name) {
        return true;
    }

    /** Why this token cannot use a relation (a denied group), or null. Checked by modx_get and before relations are written. */
    public function relationBlockReason(Context $context, $name) {
        return null;
    }

    /**
     * Restore the rows of a relation from a recorded state. Return true when the kind did it; false lets the
     * generic restore replace the rows (remove them all, save the recorded ones).
     * @param array $spec the relation spec
     * @param int|string $key key of the object
     * @param array $rows recorded rows (column => string)
     */
    public function restoreRelation(\modX $modx, $name, array $spec, $key, array $rows) {
        return false;
    }

    /** Options of the processor calls (e.g. `processors_path` for an add-on's processors). */
    public function processorOptions() {
        return array();
    }

    /** The one related row that holds the `via` fields of this relation, or null when there is none. */
    public function viaRow($object, $relation) {
        return null;
    }

    /**
     * Called before a create processor runs, after the arguments were validated. A kind may refuse here, and may
     * return a function ($tx, $id) that runs once the object exists and is registered with the transaction (for
     * work the create processor cannot do, or side effects MODX adds that must be journaled). Null for none.
     * @param array $columns normalised column => value of the new object
     * @return callable|null
     */
    public function beforeCreate(Context $context, array $columns) {
        return null;
    }

    /**
     * Refuse to put the object with this key into the recorded state $target (null: remove it), judged on the
     * site as it is now. Runs before an undo touches anything and again where the state is restored.
     * @param array|null $target
     */
    public function assertUndoable(\modX $modx, $key, $target) {
    }

    /** Called after a successful write or delete through the processors, and after an undo has restored an object. */
    public function afterWrite(\modX $modx) {
    }

    /**
     * Columns whose values a diff must hide for this state, or null to use the generic column-name pattern.
     * @return string[]|null
     */
    public function secretColumns(array $state) {
        return null;
    }

    /** Rows of a relation as shown in a diff; kinds hide what must not be shown. */
    public function maskRelationRows($name, array $rows) {
        return $rows;
    }

    /**
     * What this token may see of an object. Gets the API values of the fields and of the relations
     * and returns them, possibly with values hidden, plus extra flags for the result.
     * @return array [fields, relations, flags]
     */
    public function redact(Context $context, $object, array $fields, array $relations) {
        return array($fields, $relations, array());
    }

    /**
     * Refuse a create or update from the normalised columns (column => value) before anything is touched.
     * @param bool $creating
     */
    public function assertColumns(array $columns, $creating) {
    }

    /**
     * Refuse a change to an existing object. $op is update, delete or duplicate.
     */
    public function assertObjectWritable(\modX $modx, $object, $op) {
    }

    /**
     * Refuse an update that must not go through, from the normalised columns sent. Runs before anything is touched.
     */
    public function assertUpdatable(\modX $modx, $object, array $columns) {
    }

    /**
     * Refuse a write by which this token could read a value it may not see, e.g. a copy of a
     * credential under a harmless name. $columns are the normalised columns sent (update) or
     * the overrides (duplicate). $op is update or duplicate.
     */
    public function assertKeepsSecret(Context $context, $object, array $columns, $op) {
    }

    /** Refuse to restore (or remove) an object whose recorded state must not be put back through the API. */
    public function assertRestorable(array $state) {
    }

    /**
     * Columns that make up an object's state. Kinds drop columns MODX rewrites on every save
     * or derives from others, so they neither change the revision nor get restored.
     */
    public function stateColumns(array $columns) {
        return $columns;
    }

    /**
     * Keys of other objects of this kind that the operation will also change (MODX cascades),
     * so they are locked, journaled and restored together with the main object.
     * @param int|string|null $id null for a create
     * @param array    $columns column => new value for create/update
     * @param string   $op      create | update | delete | undelete
     */
    public function alsoAffected(\modX $modx, $id, array $columns, $op) {
        return array();
    }

    /**
     * Objects of other kinds that MODX deletes together with this one, as [kind name, key]
     * pairs. They are touched before the delete, so each is journaled as deleted and an undo
     * brings it back as itself.
     */
    public function deleteCascade(\modX $modx, $key) {
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
     *   ignore_columns columns of the rows that MODX changes by itself (e.g. login statistics):
     *                not part of the state, kept as they are on restore of a one-row relation
     *   hidden       not shown by modx_get and modx_describe (rows that only back `via` fields)
     *   in_state     false: shown by modx_get but not part of the snapshot, because the rows are
     *                objects of another kind, journaled as such (see deleteCascade())
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
        return StaticFiles::makeStatic($context, $tx, $this, $this->keyOf($object));
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

    /** Names of the relations that back `via` fields. */
    public function viaRelations() {
        $out = array();
        foreach ($this->fields() as $definition) {
            if (!empty($definition['via'])) { $out[$definition['via']] = true; }
        }
        return array_keys($out);
    }

    /** Raw stored value of a field: a column of the object, or a column of its `via` row. */
    protected function rawValue($object, $field) {
        $fields = $this->fields();
        if (!empty($fields[$field]['via'])) {
            $row = $this->viaRow($object, $fields[$field]['via']);
            return $row ? $row->get($this->column($field)) : null;
        }
        return $object->get($this->column($field));
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

    /**
     * Find an object by ['id' => …], ['key' => …] or ['name' => …]; null when it does not exist.
     * Never cached. For a string-key kind the name is an alias of the key.
     */
    public function find(\modX $modx, array $ref) {
        foreach (array('id', 'key') as $part) {
            if (isset($ref[$part])) { return $this->findByKey($modx, $this->normalizeKey($ref[$part])); }
        }
        if (isset($ref['name']) && $ref['name'] !== '' && $this->keyType() === 'string') {
            return $this->findByKey($modx, $this->normalizeKey($ref['name']));
        }
        if (isset($ref['name']) && $ref['name'] !== '') {
            return $modx->getObject($this->modelClass(), array($this->column($this->nameField()) => (string) $ref['name']), false);
        }
        return null;
    }

    /**
     * Bytes of a static file for the given code. MODX keeps snippet and plugin files with an
     * opening PHP tag (modScript::setFileContent) while the code itself has none.
     */
    public function fileContent($code) {
        return $this->lint() === 'php' ? "<?php\n" . $code : (string) $code;
    }

    /** Code held by the bytes of a static file: the inverse of fileContent(). */
    public function codeFromFile($bytes) {
        if ($this->lint() !== 'php') { return (string) $bytes; }
        if (strncmp($bytes, '<?php', 5) === 0) {
            $bytes = (string) substr($bytes, 5);
            if (strncmp($bytes, "\r\n", 2) === 0) { return (string) substr($bytes, 2); }
            return strncmp($bytes, "\n", 1) === 0 ? (string) substr($bytes, 1) : $bytes;
        }
        return (string) $bytes;
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
        return array($this->codeFromFile($content), true, $path, false);
    }

    /** API value of one field. */
    public function value($object, $field) {
        $fields = $this->fields();
        if (!empty($fields[$field]['code'])) {
            $content = $this->effectiveContent($object);
            return $content[0];
        }
        if (!empty($fields[$field]['write_only'])) { return ''; }
        $raw = $this->rawValue($object, $field);
        if ($fields[$field]['type'] === 'object') { return !empty($fields[$field]['json']) ? self::jsonValue($raw) : self::objectValue($raw); }
        if (isset($fields[$field]['format']) && $fields[$field]['format'] === 'datetime') {
            return self::datetime($raw);
        }
        switch ($fields[$field]['type']) {
            case 'integer': return (int) $raw;
            case 'number':  return $raw === null ? 0.0 : (float) $raw;
            case 'boolean': return (bool) $raw;
            default:        return $raw === null ? '' : (string) $raw;
        }
    }

    /** A stored JSON object (text or an already decoded map) as the API shows it: keys sorted, always an object. */
    public static function objectValue($raw) {
        $map = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($map)) { $map = array(); }
        $map = array_map(function ($flag) { return (bool) $flag; }, $map);
        ksort($map, SORT_STRING);
        return (object) $map;
    }

    /** A stored JSON object of any content (text or an already decoded map) as the API shows it; always an object. */
    public static function jsonValue($raw) {
        $map = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($map) || !$map) { return new \stdClass(); }
        return (object) $map;
    }

    /** Canonical text of a JSON value for comparing two of them: nested keys sorted, no matter how it was spelled. */
    public static function canonicalJson($raw) {
        $value = is_string($raw) ? json_decode($raw, true) : json_decode(json_encode($raw), true);
        $sort = function (&$node) use (&$sort) {
            if (!is_array($node)) { return; }
            foreach ($node as &$child) { $sort($child); }
            unset($child);
            if ($node && array_keys($node) !== range(0, count($node) - 1)) { ksort($node, SORT_STRING); }
        };
        $sort($value);
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Unix time (or a date string xPDO already formatted) as "YYYY-MM-DD HH:MM:SS"; "" for none. */
    public static function datetime($raw) {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0' || (is_string($raw) && strncmp($raw, '0000-00-00', 10) === 0)) { return ''; }
        $time = is_numeric($raw) ? (int) $raw : strtotime((string) $raw);
        return $time ? date('Y-m-d H:i:s', $time) : '';
    }

    /** Current value of a field in the form the core processors expect. */
    public function processorValue($object, $field) {
        $fields = $this->fields();
        if (isset($fields[$field]['format']) && $fields[$field]['format'] === 'datetime') {
            return self::datetime($this->rawValue($object, $field));
        }
        return $this->rawValue($object, $field);
    }

    /** Stored relation values for copying, without filling in defaults. */
    public function storedRelation(\modX $modx, $object, $name) {
        return $this->presentRelation($modx, $object, $name);
    }

    /** Rows of one relation as the API shows them. */
    public function presentRelation(\modX $modx, $object, $name) {
        $specs = $this->relationSpecs();
        $spec = $specs[$name];
        $criteria = array($spec['fk'] => $this->keyOf($object)) + (isset($spec['where']) ? $spec['where'] : array());
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
            foreach (array('list', 'code', 'read_only', 'required', 'immutable', 'write_only') as $flag) {
                if (!empty($definition[$flag])) { $field[$flag] = true; }
            }
            $fields[] = $field;
        }
        $relations = array();
        foreach ($this->relationSpecs() as $name => $spec) {
            if (!empty($spec['delete_only']) || !empty($spec['hidden'])) { continue; }
            $relations[] = array('name' => $name, 'description' => $spec['description'], 'writable' => !empty($spec['writable']));
        }
        $operations = array();
        foreach ($this->operations() as $op => $definition) {
            $operations[] = array('op' => $op, 'description' => $definition['description'], 'read_only' => !empty($definition['read_only']),
                'needs_object' => !isset($definition['needs_object']) || $definition['needs_object'], 'input' => $definition['input']);
        }
        $out = array(
            'kind'        => $this->name(),
            'label'       => $this->label(),
            'group'       => $this->group(),
            'name_field'  => $this->nameField(),
            'key_type'    => $this->keyType(),
            'key_field'   => $this->keyField(),
            'read_scope'  => $this->readScope(),
            'write_scope' => $this->writeScope(),
            'fields'      => $fields,
            'relations'   => $relations,
            'operations'  => $operations,
        );
        if (!$this->genericWrites()) { $out['generic_writes'] = false; $out['note'] = $this->genericWritesMessage(); }
        return $out;
    }
}
