<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;

final class MediaSourceKind extends Kind {
    /** @var Context|null */
    private $context;

    public function attach(Context $context) { $this->context = $context; }

    public function managerEvents() {
        return array(
            'OnMediaSourceBeforeFormSave' => array('role' => 'before_save', 'param' => 'source'),
            'OnMediaSourceFormSave' => array('role' => 'save', 'param' => 'source'),
            'OnMediaSourceBeforeFormDelete' => array('role' => 'before_delete', 'param' => 'source'),
            'OnMediaSourceFormDelete' => array('role' => 'delete', 'param' => 'source'),
        );
    }

    public function name() { return 'media_source'; }

    public function label() { return 'Media source'; }

    public function modelClass() { return 'sources.modMediaSource'; }

    public function group() { return 'files'; }

    public function writeScope() { return Scope::ADMIN; }

    public function processorPath() { return 'source/'; }

    public function processorExtras($op) { return array(); }

    public function fields() {
        return array(
            'id'          => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'        => array('type' => 'string', 'list' => true, 'required' => true),
            'description' => array('type' => 'string'),
            'class_key'   => array('type' => 'string', 'list' => true, 'description' => 'Source class; sources.modFileMediaSource is a local directory.'),
            'base_path'   => array('type' => 'string', 'list' => true, 'derived' => true, 'computed' => true, 'description' => 'Directory of a local source on the server, as resolved now.'),
            'properties'  => array('type' => 'object', 'json' => true, 'no_filter' => true, 'source_properties' => true,
                'description' => 'Settings of the source as {name: value} (basePath, basePathRelative, baseUrl, baseUrlRelative, allowedFileTypes, skipFiles, ... as the class declares). '
                    . 'Shows the effective values (stored over the class defaults); writing merges the given names into the stored definition and keeps each property\'s type and description. '
                    . 'A basePath into core/config, modxmcp-data or outside the site and core directories also needs modxmcp.allow_root_filesystem_read.'),
        );
    }

    public function value($object, $field) {
        if ($field === 'properties') {
            $out = array();
            foreach ($this->defaultsOf($object) as $name => $entry) { $out[$name] = isset($entry['value']) ? $entry['value'] : ''; }
            foreach (Properties::stored($object) as $name => $entry) { $out[$name] = isset($entry['value']) ? $entry['value'] : ''; }
            ksort($out, SORT_STRING);
            return $out ? (object) $out : new \stdClass();
        }
        if ($field !== 'base_path') { return parent::value($object, $field); }
        if (stripos((string) $object->get('class_key'), 'modFileMediaSource') === false) { return ''; }
        try {
            $object->initialize();
            return (string) $object->getBasePath();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** The properties the source's class declares (name => definition); empty when the class cannot say. */
    private function defaultsOf($object) {
        if (!is_object($object) || !method_exists($object, 'getDefaultProperties')) { return array(); }
        try { $defaults = $object->getDefaultProperties(); } catch (\Throwable $e) { return array(); }
        return is_array($defaults) ? $defaults : array();
    }

    // ---- writing ----

    /**
     * The properties to send to the processors: the stored definitions with the given values merged in. A value given for a
     * name the class does not declare (and the source does not store yet) is refused when the class declares anything.
     * @return array name => definition
     */
    private function merged($object, array $given) {
        $defaults = $this->defaultsOf($object);
        $stored = $object !== null && $object->get('id') ? Properties::stored($object) : array();
        $out = $stored;
        foreach ($given as $name => $value) {
            $name = (string) $name;
            if ($name === '') { throw new ApiException('validation_failed', 'Property names must not be empty.'); }
            if (!is_scalar($value) && $value !== null) { throw new ApiException('validation_failed', "Property '{$name}' must be a string, number or boolean."); }
            if ($defaults && !isset($defaults[$name]) && !isset($stored[$name])) {
                throw new ApiException('validation_failed', "Unknown property '{$name}' for this source class. It declares: " . implode(', ', array_keys($defaults)) . '.');
            }
            $entry = isset($stored[$name]) ? $stored[$name] : (isset($defaults[$name]) ? $defaults[$name] : array('name' => $name, 'desc' => '', 'type' => 'textfield', 'options' => array(), 'lexicon' => null));
            $type = isset($entry['type']) ? $entry['type'] : 'textfield';
            if ($type === 'combo-boolean') {
                $flag = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($flag === null) { throw new ApiException('validation_failed', "Property '{$name}' is a yes/no setting: give true or false."); }
                $value = $flag;
            } elseif ($value === null) {
                $value = '';
            }
            $entry['value'] = $value;
            $out[$name] = $entry;
        }
        return $out;
    }

    private function givenProperties(array $columns) {
        if (!array_key_exists('properties', $columns)) { return null; }
        $given = is_string($columns['properties']) ? json_decode($columns['properties'], true) : $columns['properties'];
        if (!is_array($given)) { throw new ApiException('validation_failed', "Field 'properties' must be an object of name => value."); }
        return $given;
    }

    public function processorData($op, array $data) {
        if (!array_key_exists('properties', $data)) { return $data; }
        if (!is_string($data['properties'])) { unset($data['properties']); return $data; }
        $modx = $this->context->modx;
        $object = $op === 'update' && isset($data['id']) ? $modx->getObject($this->modelClass(), array('id' => (int) $data['id'])) : null;
        if ($object === null) {
            $classKey = isset($data['class_key']) && $data['class_key'] !== '' ? $data['class_key'] : 'sources.modFileMediaSource';
            $object = $modx->newObject($classKey);
        }
        $data['properties'] = $this->merged($object, $this->givenProperties($data));
        return $data;
    }

    public function assertColumns(array $columns, $creating) {
        if (!$creating) { return; }
        $given = $this->givenProperties($columns);
        if ($given === null) { return; }
        $classKey = isset($columns['class_key']) && $columns['class_key'] !== '' ? $columns['class_key'] : 'sources.modFileMediaSource';
        $object = $this->context->modx->newObject($classKey);
        $this->assertSafePath($classKey, $this->merged($object, $given));
    }

    public function assertUpdatable(\modX $modx, $object, array $columns) {
        $given = $this->givenProperties($columns);
        if ($given === null) { return; }
        $classKey = isset($columns['class_key']) ? $columns['class_key'] : (string) $object->get('class_key');
        $this->assertSafePath($classKey, $this->merged($object, $given));
    }

    /**
     * A local source must not become a window on protected paths. The directory a basePath resolves to (relative to base_path when
     * basePathRelative, tags like [[++core_path]] filled in) must not be core/config or modxmcp-data or lie in them, and must lie inside
     * base_path or core_path (the same places FileRoot protects); otherwise modxmcp.allow_root_filesystem_read must be on. The kind's
     * write scope (admin) is checked before this. A path that spans the whole site or core is left to FileRoot, which gates every use of it.
     */
    private function assertSafePath($classKey, array $properties) {
        if (stripos((string) $classKey, 'modFileMediaSource') === false) { return; }
        $modx = $this->context->modx;
        $path = isset($properties['basePath']['value']) ? (string) $properties['basePath']['value'] : '';
        $relative = isset($properties['basePathRelative']['value']) ? (bool) $properties['basePathRelative']['value'] : true;
        $expanded = StaticPath::settingTags($modx, $path);
        $why = null;
        if ($expanded === null) { $why = 'it holds tags other than setting tags'; }
        else {
            $absolute = $relative ? rtrim((string) $modx->getOption('base_path'), '/\\') . '/' . ltrim($expanded, '/\\') : $expanded;
            $n = StaticPath::normalize($absolute === '' ? '/' : $absolute);
            if ($n === null || $absolute === '') { $why = "it cannot be resolved ('..' or an empty absolute path)"; }
            else {
                $real = $n[1];
                $core = rtrim((string) $modx->getOption('core_path'), '/\\');
                $roots = array();
                foreach (array((string) $modx->getOption('base_path'), $core) as $root) {
                    $r = $root === '' ? null : StaticPath::normalize($root);
                    if ($r !== null) { $roots[] = $r[1]; }
                }
                foreach (array($core . '/config', $core . '/modxmcp-data') as $protected) {
                    $p = StaticPath::normalize($protected);
                    if ($p !== null && StaticPath::inside($real, $p[1])) { $why = 'it lies in ' . basename($protected); }
                }
                if ($why === null) {
                    $inside = false;
                    foreach ($roots as $root) { if (StaticPath::inside($real, $root)) { $inside = true; } }
                    if (!$inside) { $why = 'it lies outside the site and core directories'; }
                }
            }
        }
        if ($why === null) { return; }
        if (!$modx->getOption('modxmcp.allow_root_filesystem_read', null, false) || ($this->context->token && !Scope::allows($this->context->token['scope'], Scope::ADMIN))) {
            throw new ApiException('validation_failed', "This basePath is refused: {$why}. Such a path needs the admin scope and modxmcp.allow_root_filesystem_read.");
        }
        $this->context->warnings[] = "The basePath is protected ({$why}); allowed because modxmcp.allow_root_filesystem_read is on. The file tools still refuse core/config and modxmcp-data.";
    }
}
