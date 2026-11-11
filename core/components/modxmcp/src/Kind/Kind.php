<?php
namespace ModxMCP\Kind;

use ModxMCP\Auth\Scope;

/**
 * One kind of site object. A kind declares how its objects are named, which fields the API
 * exposes and which relations travel with the object; the generic object tools, snapshots
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
     *   required    required on create
     */
    abstract public function fields();

    /** Field that names the object for people and for lookup by name. */
    public function nameField() {
        return 'name';
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

    /** Related data that belongs to the object: name => description. */
    public function relations() {
        return array();
    }

    /** Special operations for modx_invoke: op => [description, read_only]. */
    public function operations() {
        return array();
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

    /** @return array Schema of this kind for modx_describe. */
    public function describe() {
        $fields = array();
        foreach ($this->fields() as $name => $definition) {
            $field = array('name' => $name, 'type' => $definition['type']);
            if (isset($definition['description'])) { $field['description'] = $definition['description']; }
            foreach (array('list', 'code', 'read_only', 'required') as $flag) {
                if (!empty($definition[$flag])) { $field[$flag] = true; }
            }
            $fields[] = $field;
        }
        $relations = array();
        foreach ($this->relations() as $name => $description) {
            $relations[] = array('name' => $name, 'description' => $description);
        }
        $operations = array();
        foreach ($this->operations() as $op => $definition) {
            $operations[] = array('op' => $op, 'description' => $definition[0], 'read_only' => !empty($definition[1]));
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
