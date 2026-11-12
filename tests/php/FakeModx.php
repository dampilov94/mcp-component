<?php
// In-memory stand-in for the parts of MODX/xPDO the component uses, for offline tests.
// Objects are kept per class and persisted to a JSON file so that the built-in-server
// harness keeps state between requests. The component's own tables are real SQL (SQLite).

class FakeObject {
    public $class;
    public $fields;
    public $isNew;
    private $modx;

    public function __construct(modX $modx, $class, array $fields, $isNew) {
        $this->modx = $modx;
        $this->class = $class;
        $this->fields = $fields;
        $this->isNew = $isNew;
    }
    public function get($key) {
        $value = array_key_exists($key, $this->fields) ? $this->fields[$key] : null;
        if (in_array($key, array('properties', 'input_properties'), true) && is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) { return $decoded; }
        }
        return $value;
    }
    public function set($key, $value) { $this->fields[$key] = $value; return true; }
    public function toArray($prefix = '', $raw = false) { return $this->fields; }
    public function fromArray($values, $prefix = '', $setPrimaryKeys = false, $raw = false) {
        foreach ($values as $key => $value) {
            if ($key === 'id' && !$setPrimaryKeys) { continue; }
            $this->fields[$key] = $value;
        }
    }
    public function initialize() { return true; }
    public function getBasePath() { return (string) $this->get('basePath'); }
    public function getSourceFile() { return !empty($this->fields['static']) ? str_replace('[[++core_path]]', $this->modx->getOption('core_path'), (string) $this->fields['static_file']) : false; }
    public function joinGroup($id) {
        if (!$this->modx->getObject('modResourceGroup', $id)) { return false; }
        $where = array('document' => $this->get('id'), 'document_group' => $id);
        if ($this->modx->getCount('modResourceGroupResource', $where)) { return false; }
        $row = $this->modx->newObject('modResourceGroupResource');
        $row->fromArray($where);
        return $row->save();
    }
    public function leaveGroup($id) {
        $row = $this->modx->getObject('modResourceGroupResource', array('document' => $this->get('id'), 'document_group' => $id));
        return $row ? $row->remove() : false;
    }
    public function setTVValue($tvId, $value) {
        $this->modx->removeCollection('modTemplateVarResource', array('tmplvarid' => $tvId, 'contentid' => $this->fields['id']));
        $this->modx->store('modTemplateVarResource', array(
            'id' => $this->modx->nextId('modTemplateVarResource'), 'tmplvarid' => $tvId, 'contentid' => $this->fields['id'], 'value' => $value,
        ));
        return true;
    }
    public function save() {
        $code = modX::codeColumn($this->class);
        $pk = modX::primaryKey($this->class);
        $stored = $this->modx->rowOf($this->class, $this->fields);
        if ($pk === 'id' && empty($this->fields['id'])) { $this->fields['id'] = $this->modx->nextId($this->class); }
        // Like modElement::save(): the static file is written only when the column changed.
        if ($code && !empty($this->fields['static']) && !empty($this->fields['static_file'])
            && (!$stored || (string) $stored[$code] !== (string) $this->fields[$code])) {
            $path = $this->getSourceFile();
            // Like modScript::setFileContent(): snippet and plugin files get an opening PHP tag.
            $script = in_array($this->class, array('modSnippet', 'modPlugin'), true);
            $bytes = ($script ? "<?php\n" : '') . (string) $this->fields[$code];
            if (!is_file($path) || file_get_contents($path) !== $bytes) {
                file_put_contents($path, $bytes);
            }
        }
        $this->modx->store($this->class, $this->fields);
        $this->isNew = false;
        return true;
    }
    public function remove() {
        $this->modx->drop($this->class, $this->fields);
        foreach (modX::$cascades as $spec) {
            if ($spec[0] === $this->class) { $this->modx->removeCollection($spec[1], array($spec[2] => $this->fields[isset($spec[4]) ? $spec[4] : 'id']) + $spec[3]); }
        }
        return true;
    }
}

class FakeQuery {
    public $class; public $criteria; public $limit = 0; public $offset = 0; public $sort = array(); public $stmt; public $groups = array(); public $broken = false;
    public function __construct($class, $criteria) { $this->class = $class; $this->criteria = (array) $criteria; $this->stmt = new FakeStatement(); }
    public function sortby($column, $direction = 'ASC') {
        // Like MySQL: an unquoted reserved word is a syntax error, and the query returns nothing.
        if (in_array(strtolower($column), array('key', 'order', 'group', 'rank'), true)) { $this->broken = true; }
        $this->sort[] = array(trim($column, '`'), $direction);
        return $this;
    }
    public function limit($limit, $offset = 0) { $this->limit = $limit; $this->offset = $offset; return $this; }
    public function where($criteria) {
        // A list holding one array is an OR group: [['a:LIKE' => x, 'OR:b:LIKE' => x]].
        if (isset($criteria[0]) && is_array($criteria[0])) { $this->groups[] = $criteria[0]; return $this; }
        $this->criteria = (array) $criteria + $this->criteria;
        return $this;
    }
    public function __call($name, $arguments) { return $this; }
    public function prepare() { return true; }
}
class FakeStatement {
    public function execute() { return true; }
    public function fetch() { return false; }
    public function fetchColumn() { return false; }
}
class FakeResponse {
    private $error; private $object;
    public function __construct($error, $object = array()) { $this->error = $error; $this->object = $object; }
    public function isError() { return $this->error !== null; }
    public function getMessage() { return (string) $this->error; }
    public function getAllErrors() { return array(); }
    public function getObject() { return $this->object; }
}
class FakeCache { public $refreshes = 0; public function refresh() { $this->refreshes++; return true; } }

class xPDO {
    public $pdo;
    public $options = array();
    public function getOption($key, $options = null, $default = null) {
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }
    public function prepare($sql) { return $this->pdo->prepare($sql); }
    public function exec($sql) { return $this->pdo->exec($sql); }
    public function lastInsertId() { return $this->pdo->lastInsertId(); }
}

/** Stand-in for the core resource processors, with the behaviours that matter to callers. */
trait FakeResourceProcessors {
    private function descendantIds($id) {
        $out = array();
        foreach ($this->getCollection('modResource', array('parent' => $id)) as $child) {
            $out[] = $child->get('id');
            $out = array_merge($out, $this->descendantIds($child->get('id')));
        }
        return $out;
    }
    private function fixFolder($id) {
        $parent = $id ? $this->getObject('modResource', array('id' => $id)) : null;
        if ($parent) { $parent->set('isfolder', $this->getCount('modResource', array('parent' => $id)) > 0 ? 1 : 0); $parent->save(); }
    }
    public function resourceProcessor($action, array $data, $path) {
        if ($action === 'delete' || $action === 'undelete') {
            $resource = $this->getObject('modResource', array('id' => $data['id']));
            if (!$resource) { return new FakeResponse('Resource not found.'); }
            foreach (array_merge(array($data['id']), $this->descendantIds($data['id'])) as $id) {
                $item = $this->getObject('modResource', array('id' => $id));
                $item->set('deleted', $action === 'delete' ? 1 : 0);
                $item->set('deletedon', $action === 'delete' ? 1700000000 : 0);
                $item->save();
            }
            return new FakeResponse(null, array('id' => $data['id']));
        }
        if (!isset($data['pagetitle']) || $data['pagetitle'] === '') { return new FakeResponse('Please specify a title.'); }
        $syncsite = !empty($data['syncsite']);
        unset($data['syncsite'], $data['clearCache']);
        if ($action === 'create') {
            $resource = $this->newObject('modResource');
            // MODX generates an alias from the title when create receives an empty alias.
            if (empty($data['alias'])) { $data['alias'] = mb_strtolower(str_replace(' ', '-', $data['pagetitle']), 'UTF-8'); }
            $data += array(
                'longtitle' => '', 'description' => '', 'introtext' => '', 'menutitle' => '', 'alias' => mb_strtolower(str_replace(' ', '-', $data['pagetitle']), 'UTF-8'),
                'uri_override' => 0, 'parent' => 0, 'context_key' => 'web', 'template' => 0, 'content' => '', 'published' => 0, 'deleted' => 0,
                'deletedon' => 0, 'hidemenu' => 0, 'isfolder' => 0, 'menuindex' => 0, 'richtext' => 1, 'searchable' => 1, 'cacheable' => 1,
                'class_key' => 'modDocument', 'content_type' => 1, 'link_attributes' => '', 'pub_date' => 0, 'unpub_date' => 0,
                'publishedon' => 0, 'createdon' => 1700000000, 'properties' => null,
            );
            $oldParent = 0;
        } else {
            $resource = $this->getObject('modResource', array('id' => $data['id']));
            if (!$resource) { return new FakeResponse('Resource not found.'); }
            if (!isset($data['class_key'])) { return new FakeResponse('class_key is required.'); }
            // Like modResourceUpdateProcessor::handleCheckBoxes(): an absent checkbox means "off".
            foreach (array('hidemenu', 'isfolder', 'richtext', 'published', 'cacheable', 'searchable', 'deleted', 'uri_override') as $box) {
                $data[$box] = empty($data[$box]) ? 0 : 1;
            }
            $oldParent = (int) $resource->get('parent');
        }
        // Like setPublishDate(): a value strtotime() cannot read, or a past one, publishes at once.
        foreach (array('pub_date', 'unpub_date') as $date) {
            if (!array_key_exists($date, $data)) { continue; }
            if (empty($data[$date])) { $data[$date] = 0; continue; }
            $time = strtotime($data[$date]);
            if ($date === 'pub_date' && $time <= time()) { $data['published'] = 1; $time = 0; }
            $data[$date] = (int) $time;
        }
        $data['editedon'] = ((int) $resource->get('editedon')) + 1;   // changes on every save
        if (empty($data['uri_override'])) { $data['uri'] = isset($data['alias']) ? $data['alias'] : $resource->get('alias'); }
        $resource->fromArray($data, '', false, true);
        $resource->save();
        $this->fixFolder((int) $resource->get('parent'));
        if ($oldParent !== (int) $resource->get('parent')) { $this->fixFolder($oldParent); }
        if ($syncsite) { $this->getCacheManager()->refresh(); }
        if ($this->failProcessor === $path) { throw new RuntimeException('Simulated failure after save.'); }
        return new FakeResponse(null, array('id' => $resource->get('id')));
    }
}

class modX extends xPDO {
    use FakeResourceProcessors;
    public $maintenanceProcessors = array();
    const LOG_LEVEL_ERROR = 1;
    public $user;
    public $events = array();
    public $failProcessor = null;      // set to a processor path to make it fail after saving
    public static $cascades = array(
        array('modPlugin', 'modPluginEvent', 'pluginid', array()),
        array('modTemplateVar', 'modTemplateVarTemplate', 'tmplvarid', array()),
        array('modTemplateVar', 'modTemplateVarResource', 'tmplvarid', array()),
        array('modResource', 'modTemplateVarResource', 'contentid', array()),
        array('modUserGroup', 'modUserGroupMember', 'user_group', array()),
        array('modResourceGroup', 'modResourceGroupResource', 'document_group', array()),
        // Composites that MODX removes together with a context or a namespace.
        array('modContext', 'modContextSetting', 'context_key', array(), 'key'),
        array('modContext', 'modAccessContext', 'target', array(), 'key'),
        array('modNamespace', 'modSystemSetting', 'namespace', array(), 'name'),
        array('modNamespace', 'modContextSetting', 'namespace', array(), 'name'),
        array('modNamespace', 'modLexiconEntry', 'namespace', array(), 'name'),
    );
    private static $processors = array(
        'element/chunk/' => array('modChunk', 'name'), 'element/snippet/' => array('modSnippet', 'name'),
        'element/template/' => array('modTemplate', 'templatename'), 'element/plugin/' => array('modPlugin', 'name'),
        'element/tv/' => array('modTemplateVar', 'name'), 'element/category/' => array('modCategory', 'category'),
    );
    private $file;
    private $tables = array();
    private $cache;

    public function __construct($file = null) {
        $this->file = $file;
        $this->cache = new FakeCache();
        if ($file && is_file($file)) { $this->tables = json_decode(file_get_contents($file), true); }
    }
    public static function codeColumn($class) {
        $map = array('modChunk' => 'snippet', 'modSnippet' => 'snippet', 'modTemplate' => 'content', 'modPlugin' => 'plugincode');
        return isset($map[$class]) ? $map[$class] : null;
    }
    private function persist() { if ($this->file) { file_put_contents($this->file, json_encode($this->tables)); } }
    /** Primary key column(s) per class; rows of other classes are matched by id (or by their whole content without one). */
    public static $primaryKeys = array('modSystemSetting' => 'key', 'modContext' => 'key', 'modNamespace' => 'name', 'modContextSetting' => array('context_key', 'key'));
    public static function primaryKey($class) { return isset(self::$primaryKeys[$class]) ? self::$primaryKeys[$class] : 'id'; }
    /** The identity of a row as one string, or null when a primary key column is missing. */
    private static function identity($class, array $fields) {
        $parts = array();
        foreach ((array) self::primaryKey($class) as $column) {
            if (!isset($fields[$column])) { return null; }
            $parts[] = (string) $fields[$column];
        }
        return implode("\x1f", $parts);
    }
    private static function key($class, array $fields) {
        $identity = self::identity($class, $fields);
        return $identity !== null ? 'pk:' . $identity : md5(json_encode($fields));
    }
    /** A row by its primary key: a scalar, or a list of values for a composite key. */
    public function row($class, $id) {
        $key = 'pk:' . implode("\x1f", array_map('strval', (array) $id));
        return isset($this->tables[$class][$key]) ? $this->tables[$class][$key] : null;
    }
    public function rowOf($class, array $fields) {
        $identity = self::identity($class, $fields);
        return $identity !== null && isset($this->tables[$class]['pk:' . $identity]) ? $this->tables[$class]['pk:' . $identity] : null;
    }
    /** All rows of a class, for checks that nothing changed. */
    public function tableDump($class) { $rows = isset($this->tables[$class]) ? $this->tables[$class] : array(); ksort($rows); return $rows; }
    public function nextId($class) {
        $max = 0;
        foreach (isset($this->tables[$class]) ? $this->tables[$class] : array() as $row) { $max = max($max, isset($row['id']) ? (int) $row['id'] : 0); }
        return $max + 1;
    }
    public function store($class, array $fields) {
        // Values come back from a database as strings.
        foreach ($fields as $key => $value) { $fields[$key] = $value === null ? null : (is_array($value) || is_object($value) ? json_encode($value) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value)); }
        if (self::identity($class, $fields) === null) {
            foreach (isset($this->tables[$class]) ? $this->tables[$class] : array() as $key => $row) {
                if ($row == $fields) { unset($this->tables[$class][$key]); }
            }
        }
        $this->tables[$class][self::key($class, $fields)] = $fields;
        $this->persist();
    }
    public function drop($class, array $fields) {
        $identity = self::identity($class, $fields);
        foreach (isset($this->tables[$class]) ? $this->tables[$class] : array() as $key => $row) {
            if ($identity !== null ? self::identity($class, $row) === $identity : $row == $fields) {
                unset($this->tables[$class][$key]);
            }
        }
        $this->persist();
    }
    private static function likes($row, $key, $value) {
        $pattern = '';
        for ($i = 0; $i < strlen($value); $i++) {
            if ($value[$i] === '\\' && $i + 1 < strlen($value)) { $pattern .= preg_quote($value[++$i], '/'); }
            elseif ($value[$i] === '%') { $pattern .= '.*'; }
            elseif ($value[$i] === '_') { $pattern .= '.'; }
            else { $pattern .= preg_quote($value[$i], '/'); }
        }
        return preg_match('/^' . $pattern . '$/is', (string) $row[substr($key, 0, -5)]) === 1;
    }
    private function match($class, $criteria) {
        $groups = $criteria instanceof FakeQuery ? $criteria->groups : array();
        $criteria = $criteria instanceof FakeQuery ? $criteria->criteria : (array) $criteria;
        $out = array();
        foreach (isset($this->tables[$class]) ? $this->tables[$class] : array() as $row) {
            foreach ($groups as $group) {
                $any = false;
                foreach ($group as $key => $value) {
                    if (self::likes($row, preg_replace('/^OR:/', '', $key), $value)) { $any = true; }
                }
                if (!$any) { continue 2; }
            }
            foreach ($criteria as $key => $value) {
                if (substr($key, -3) === ':IN') {
                    if (!in_array((string) $row[substr($key, 0, -3)], array_map('strval', $value), true)) { continue 2; }
                } elseif (substr($key, -5) === ':LIKE') {
                    if (!self::likes($row, $key, $value)) { continue 2; }
                } elseif (!array_key_exists($key, $row) || (string) $row[$key] !== (string) $value) { continue 2; }
            }
            $out[] = $row;
        }
        return $out;
    }
    public function getObject($class, $criteria = null, $cacheFlag = true) {
        if ($class === 'modUser' && (is_array($criteria) ? (isset($criteria['id']) ? $criteria['id'] : null) : $criteria) == 1 && !$this->row('modUser', 1)) { return new FakeObject($this, 'modUser', array('id' => 1, 'active' => 1), false); }
        $rows = $this->match($class, is_array($criteria) || $criteria instanceof FakeQuery ? $criteria : array(is_array(self::primaryKey($class)) ? 'id' : self::primaryKey($class) => $criteria));
        return $rows ? new FakeObject($this, $class, $rows[0], false) : null;
    }
    public function getCollection($class, $criteria = null) {
        if ($criteria instanceof FakeQuery && $criteria->broken) { return array(); }
        $rows = $this->match($class, $criteria);
        if ($criteria instanceof FakeQuery) {
            foreach (array_reverse($criteria->sort) as $sort) {
                usort($rows, function ($a, $b) use ($sort) {
                    $result = is_numeric($a[$sort[0]]) && is_numeric($b[$sort[0]]) ? $a[$sort[0]] - $b[$sort[0]] : strcmp($a[$sort[0]], $b[$sort[0]]);
                    return $sort[1] === 'DESC' ? -$result : $result;
                });
            }
            if ($criteria->limit) { $rows = array_slice($rows, $criteria->offset, $criteria->limit); }
        }
        $out = array();
        foreach ($rows as $row) { $out[] = new FakeObject($this, $class, $row, false); }
        return $out;
    }
    public function getCount($class, $criteria = null) { return count($this->match($class, $criteria)); }
    public function escape($name) { return '`' . str_replace('`', '', $name) . '`'; }
    public function newQuery($class, $criteria = array()) { return new FakeQuery($class, $criteria); }
    public function newObject($class) { return new FakeObject($this, $class, array(), true); }
    public function removeCollection($class, $criteria) {
        foreach ($this->match($class, $criteria) as $row) { $this->drop($class, $row); }
        return true;
    }
    public function invokeEvent($name, array $params = array()) { $this->events[] = $name; }
    public function makeUrl($id, $context = '', $args = '', $scheme = -1) {
        $urls = $this->getOption('fake_urls', null, array());
        if (array_key_exists($id, $urls)) { return $urls[$id]; }
        $resource = $this->getObject('modResource', $id);
        if (!$resource) { return ''; }
        $key = $context !== '' ? $context : ($resource->get('context_key') ?: 'web');
        $setting = $this->getObject('modContextSetting', array('context_key' => $key, 'key' => 'site_url'));
        $siteUrl = $setting ? $setting->get('value') : $this->getOption('site_url');
        return rtrim($siteUrl, '/') . '/' . ltrim($resource->get('uri') ?: $resource->get('alias'), '/');
    }
    public function hasPermission($permission) {
        $permissions = $this->getOption('fake_permissions', null, array('settings' => true));
        return !empty($permissions[$permission]);
    }
    public function addPackage($name, $path) { return true; }
    public function getCachePath() { return $this->getOption('core_path') . 'cache/'; }
    public function getCacheManager() { return $this->cache; }
    /** Like modX::reloadConfig(): the in-memory configuration is read again from the system settings. */
    public $settingOptions = array();
    public function reloadConfig() {
        foreach ($this->settingOptions as $key) { unset($this->options[$key]); }
        $this->settingOptions = array();
        foreach (isset($this->tables['modSystemSetting']) ? $this->tables['modSystemSetting'] : array() as $row) {
            $this->options[$row['key']] = $row['value'];
            $this->settingOptions[] = $row['key'];
        }
        $this->cache->refresh();
        return $this->options;
    }
    public function getContext($key) { return $this->getObject('modContext', array('key' => $key)); }
    public function log($level, $message) { error_log($message); }
    public function getVersionData() { return array('full_version' => '2.8.8-pl'); }

    /** Security processors: model defaults and core refusals from MODX 2.8.8. */
    private function accessProcessor($base, $action, array $data) {
        $classes = array('security/group/' => 'modUserGroup', 'security/role/' => 'modUserGroupRole', 'security/resourcegroup/' => 'modResourceGroup');
        $class = $classes[$base];
        $object = $action === 'create' ? $this->newObject($class) : $this->getObject($class, array('id' => $data['id']));
        if (!$object) { return new FakeResponse('Object not found.'); }
        if ($action === 'remove') {
            if ($class === 'modUserGroup' && ((int) $object->get('id') === 1 || $object->get('name') === 'Administrator')) {
                return new FakeResponse('You cannot remove the Administrator group.');
            }
            if ($class === 'modUserGroupRole') {
                if (in_array($object->get('name'), array('Member', 'Super User'), true)) {
                    return new FakeResponse('The role you are trying to delete is the admin role. This role cannot be deleted!');
                }
                if ($this->getCount('modUserGroupMember', array('role' => $object->get('id')))) {
                    return new FakeResponse('There are users with this role. It cannot be deleted.');
                }
            }
            $object->remove();
            return new FakeResponse(null, array('id' => $data['id']));
        }
        // The group update processor checks uniqueness, but does not reject an empty name.
        if (($class !== 'modUserGroup' || $action === 'create') && empty($data['name'])) { return new FakeResponse('Please specify a name.'); }
        foreach ($this->getCollection($class, array('name' => $data['name'])) as $existing) {
            if ($action === 'create' || (int) $existing->get('id') !== (int) $data['id']) {
                return new FakeResponse('An object with that name already exists.');
            }
        }
        if ($action === 'create') {
            if ($class === 'modUserGroup') { $data += array('description' => null, 'parent' => 0, 'rank' => 0, 'dashboard' => 1); }
            elseif ($class === 'modUserGroupRole') { $data += array('description' => null, 'authority' => 9999); }
            else { $data += array('private_memgroup' => 0, 'private_webgroup' => 0); }
        }
        $object->fromArray($data, '', false, true);
        $object->save();
        return new FakeResponse(null, array('id' => $object->get('id')));
    }

    /** The checks of modSystemSettingsCreate/UpdateProcessor and the context setting processors on namespace and key. */
    private function checkSetting($action, array $data) {
        if ($action === 'remove') { return null; }
        if (empty($data['namespace'])) { return 'Please specify a namespace.'; }
        if (!$this->getObject('modNamespace', array('name' => $data['namespace']))) { return 'The namespace could not be found.'; }
        if ($action === 'create' && ctype_digit(substr(trim($data['key']), 0, 1))) { return 'A setting key cannot start with a number.'; }
        return null;
    }

    /**
     * system/settings/* and context/setting/*: a setting is identified by its key (and its context).
     * Like the real processors these do not reload the configuration on their own here: the kind must.
     */
    private function settingProcessor($action, array $data, $path, $class = 'modSystemSetting') {
        if (!isset($data['key']) || $data['key'] === '') { return new FakeResponse('Please specify a key.'); }
        $criteria = array('key' => $data['key']);
        if ($class === 'modContextSetting') {
            $contextKey = isset($data['context_key']) ? $data['context_key'] : (isset($data['fk']) ? $data['fk'] : '');
            if ($action === 'create') { $contextKey = isset($data['fk']) ? $data['fk'] : ''; }
            if ($contextKey === '' || !$this->getContext($contextKey)) { return new FakeResponse('The context could not be found.'); }
            $criteria['context_key'] = $contextKey;
            $data['context_key'] = $contextKey;
            unset($data['fk']);
        }
        $object = $this->getObject($class, $criteria);
        if ($action === 'create') {
            if ($object) { return new FakeResponse('A setting with that key already exists.'); }
            $object = $this->newObject($class);
            $data += array('value' => '', 'area' => '', 'xtype' => 'textfield', 'namespace' => 'core');
        } elseif (!$object) {
            return new FakeResponse('Setting not found.');
        }
        if ($error = $this->checkSetting($action, $data)) { return new FakeResponse($error); }
        if ($action === 'remove') {
            $object->remove();
            return new FakeResponse(null, array('key' => $data['key']));
        }
        unset($data['clearCache']);
        $object->fromArray($data, '', true, true);
        $object->save();
        if ($this->failProcessor === $path) { throw new RuntimeException('Simulated failure after save.'); }
        return new FakeResponse(null, $object->toArray());
    }

    /** context/*: MODX refuses to remove web and mgr; removing a context also removes its resources. */
    private function contextProcessor($action, array $data) {
        if (!isset($data['key']) || $data['key'] === '') { return new FakeResponse('Please specify a key.'); }
        $object = $this->getObject('modContext', array('key' => $data['key']));
        if ($action === 'create') {
            if ($object) { return new FakeResponse('A context with that key already exists.'); }
            $object = $this->newObject('modContext');
            $data += array('name' => '', 'description' => '', 'rank' => 0);
        } elseif (!$object) {
            return new FakeResponse('Context not found.');
        }
        if ($action === 'remove') {
            if ($data['key'] === 'web' || $data['key'] === 'mgr') { return new FakeResponse('Permission denied.'); }
            $object->remove();
            foreach ($this->getCollection('modResource', array('context_key' => $data['key'])) as $resource) {
                $this->removeCollection('modTemplateVarResource', array('contentid' => $resource->get('id')));
                $resource->remove();
            }
            return new FakeResponse(null, array('key' => $data['key']));
        }
        $object->fromArray($data, '', true, true);
        $object->save();
        return new FakeResponse(null, $object->toArray());
    }

    /** workspace/namespace/*: the core namespace cannot be removed; removing one removes its settings and lexicon entries. */
    private function namespaceProcessor($action, array $data) {
        if (!isset($data['name']) || $data['name'] === '') { return new FakeResponse('Please specify a name.'); }
        $object = $this->getObject('modNamespace', array('name' => $data['name']));
        if ($action === 'create') {
            if ($object) { return new FakeResponse('The namespace could not be saved.'); }
            $object = $this->newObject('modNamespace');
            $data += array('path' => '', 'assets_path' => '');
        } elseif (!$object) {
            return new FakeResponse('Namespace not found.');
        }
        if ($action === 'remove') {
            if ($data['name'] === 'core') { return new FakeResponse('The namespace could not be removed.'); }
            $object->remove();
            return new FakeResponse(null, array('name' => $data['name']));
        }
        $object->fromArray($data, '', true, true);
        $object->save();
        return new FakeResponse(null, $object->toArray());
    }

    /** Minimal create / update / remove processors with the validation the real ones have. */
    public function runProcessor($path, array $data = array()) {
        if ($path === 'security/group/user/create' || $path === 'security/group/user/remove') {
            $where = array('user_group' => $data['usergroup'], 'member' => $data['user']);
            $member = $this->getObject('modUserGroupMember', $where);
            $user = $this->getObject('modUser', $data['user']);
            $group = $this->getObject('modUserGroup', $data['usergroup']);
            if ($path === 'security/group/user/create') {
                if (!$user || !$group || (!empty($data['role']) && !$this->getObject('modUserGroupRole', $data['role']))) { return new FakeResponse('User, group or role not found.'); }
                if ($member) { return new FakeResponse('User is already in this group.'); }
                $member = $this->newObject('modUserGroupMember');
                $rank = $this->getCount('modUserGroupMember', array('member' => $data['user']));
                $member->fromArray($where + array('role' => isset($data['role']) ? $data['role'] : 0, 'rank' => $rank));
                $this->invokeEvent('OnUserBeforeAddToGroup');
                $member->save();
                $this->invokeEvent('OnUserAddToGroup');
                if ($rank === 0) { $user->set('primary_group', $data['usergroup']); $user->save(); }
            } else {
                if (!$member) { return new FakeResponse('Membership not found.'); }
                $member->remove();
                if ($user && $group && $user->get('primary_group') == $group->get('id')) { $user->set('primary_group', 0); $user->save(); }
            }
            return new FakeResponse(null, $member->toArray());
        }

        if (in_array($path, array('system/refreshuris', 'system/remove_locks', 'security/access/flush', 'resource/emptyrecyclebin'), true)) {
            $this->maintenanceProcessors[] = $path;
            if ($this->failProcessor === $path) { return new FakeResponse('Maintenance processor refused.'); }
            if ($path === 'resource/emptyrecyclebin') {
                $this->invokeEvent('OnBeforeEmptyTrash');
                foreach ($this->getCollection('modResource', array('deleted' => 1)) as $resource) {
                    $this->removeCollection('modResourceGroupResource', array('document' => $resource->get('id')));
                    $resource->remove();
                }
                $this->invokeEvent('OnEmptyTrash');
            }
            return new FakeResponse(null);
        }
        $action = basename($path);
        $base = substr($path, 0, -strlen($action));
        if ($base === 'system/settings/') { return $this->settingProcessor($action, $data, $path); }
        if ($base === 'context/setting/') { return $this->settingProcessor($action, $data, $path, 'modContextSetting'); }
        if ($base === 'context/') { return $this->contextProcessor($action, $data); }
        if ($base === 'workspace/namespace/') { return $this->namespaceProcessor($action, $data); }
        if (in_array($base, array('security/group/', 'security/role/', 'security/resourcegroup/'), true)) { return $this->accessProcessor($base, $action, $data); }
        if ($base === 'resource/') { return $this->resourceProcessor($action, $data, $path); }
        if (!isset(self::$processors[$base])) { return null; }
        list($class, $nameColumn) = self::$processors[$base];
        unset($data['clearCache']);
        if ($action === 'remove') {
            $object = $this->getObject($class, array('id' => $data['id']));
            if (!$object) { return new FakeResponse('Object not found.'); }
            $object->remove();
            return new FakeResponse(null, array('id' => $data['id']));
        }
        if (!isset($data[$nameColumn]) || $data[$nameColumn] === '') { return new FakeResponse('Please specify a name.'); }
        $object = $action === 'create' ? $this->newObject($class) : $this->getObject($class, array('id' => $data['id']));
        if (!$object) { return new FakeResponse('Object not found.'); }
        foreach ($this->match($class, array($nameColumn => $data[$nameColumn])) as $row) {
            if ($action === 'create' || (string) $row['id'] !== (string) $data['id']) { return new FakeResponse('An object with that name already exists.'); }
        }
        if ($action === 'create') {
            $defaults = array('description' => '', 'category' => 0, 'locked' => 0);
            if (self::codeColumn($class)) { $defaults += array(self::codeColumn($class) => '', 'static' => 0, 'static_file' => '', 'source' => 0, 'properties' => null); }
            if (in_array($class, array('modChunk', 'modSnippet', 'modPlugin'), true)) { $defaults['property_preprocess'] = 0; }
            if ($class === 'modPlugin') { $defaults['disabled'] = 0; }
            if ($class === 'modTemplate') { $defaults['icon'] = ''; }
            $data += $defaults;
        }
        $object->fromArray($data, '', false, true);
        $object->save();
        $this->cache->refresh();
        if ($this->failProcessor === $path) { throw new RuntimeException('Simulated failure after save.'); }
        return new FakeResponse(null, array('id' => $object->get('id')));
    }
}

/** SQLite versions of the component tables (the component's own DDL is MySQL). */
function fakeSchema(PDO $pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS modx_modxmcp_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT, prefix TEXT, hash TEXT UNIQUE,'
        . ' scope TEXT, plan_only INTEGER DEFAULT 0, denied_groups TEXT, expires_at INTEGER, last_used_at INTEGER,'
        . ' created_by INTEGER DEFAULT 0, created_at INTEGER, revoked_at INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS modx_modxmcp_changesets (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at INTEGER, source TEXT,'
        . ' token_id INTEGER, user_id INTEGER, session_id TEXT, request_id TEXT, tool TEXT, summary TEXT, status TEXT,'
        . ' reversible INTEGER DEFAULT 1, undoes TEXT, undone_by INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS modx_modxmcp_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, changeset_id INTEGER, seq INTEGER,'
        . ' kind TEXT, object_key TEXT, label TEXT, op TEXT, before_revision TEXT, after_revision TEXT, extra_blob TEXT,'
        . ' reversible INTEGER DEFAULT 1, note TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS modx_modxmcp_checkpoints (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at INTEGER, token_id INTEGER,'
        . ' session_id TEXT, label TEXT, last_changeset_id INTEGER DEFAULT 0)');
}

/** A fake site rooted in $dir: objects in objects.json, component tables in site.sqlite. */
function fakeSite($dir, array $options = array()) {
    $modx = new modX($dir . '/objects.json');
    $modx->pdo = new PDO('sqlite:' . $dir . '/site.sqlite');
    fakeSchema($modx->pdo);
    $modx->store('modNamespace', array('name' => 'core', 'path' => '{core_path}', 'assets_path' => '{assets_path}'));
    $modx->options = $options + array(
        'modxmcp.enabled' => true,
        'modxmcp.auto_static' => true,
        'modxmcp.core_path' => dirname(dirname(__DIR__)) . '/core/components/modxmcp/',
        'core_path' => $dir . '/core/',
        'table_prefix' => 'modx_',
        'site_name' => 'Test site',
    );
    return $modx;
}
