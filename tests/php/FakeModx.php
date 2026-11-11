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
    public function get($key) { return array_key_exists($key, $this->fields) ? $this->fields[$key] : null; }
    public function set($key, $value) { $this->fields[$key] = $value; return true; }
    public function toArray($prefix = '', $raw = false) { return $this->fields; }
    public function fromArray($values, $prefix = '', $setPrimaryKeys = false, $raw = false) {
        foreach ($values as $key => $value) {
            if ($key === 'id' && !$setPrimaryKeys) { continue; }
            $this->fields[$key] = $value;
        }
    }
    public function getSourceFile() { return !empty($this->fields['static']) ? (string) $this->fields['static_file'] : false; }
    public function save() {
        $code = modX::codeColumn($this->class);
        $stored = isset($this->fields['id']) ? $this->modx->row($this->class, $this->fields['id']) : null;
        if (empty($this->fields['id'])) { $this->fields['id'] = $this->modx->nextId($this->class); }
        // Like modElement::save(): the static file is written only when the column changed.
        if ($code && !empty($this->fields['static']) && !empty($this->fields['static_file'])
            && (!$stored || (string) $stored[$code] !== (string) $this->fields[$code])) {
            file_put_contents($this->fields['static_file'], (string) $this->fields[$code]);
        }
        $this->modx->store($this->class, $this->fields);
        $this->isNew = false;
        return true;
    }
    public function remove() {
        $this->modx->drop($this->class, $this->fields);
        foreach (modX::$cascades as $spec) {
            if ($spec[0] === $this->class) { $this->modx->removeCollection($spec[1], array($spec[2] => $this->fields['id']) + $spec[3]); }
        }
        return true;
    }
}

class FakeQuery {
    public $class; public $criteria; public $limit = 0; public $offset = 0; public $sort = array(); public $stmt;
    public function __construct($class, $criteria) { $this->class = $class; $this->criteria = (array) $criteria; $this->stmt = new FakeStatement(); }
    public function sortby($column, $direction = 'ASC') { $this->sort[] = array($column, $direction); return $this; }
    public function limit($limit, $offset = 0) { $this->limit = $limit; $this->offset = $offset; return $this; }
    public function where($criteria) { $this->criteria = (array) $criteria + $this->criteria; return $this; }
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

class modX extends xPDO {
    const LOG_LEVEL_ERROR = 1;
    public $user;
    public $events = array();
    public $failProcessor = null;      // set to a processor path to make it fail after saving
    public static $cascades = array(
        array('modPlugin', 'modPluginEvent', 'pluginid', array()),
        array('modTemplateVar', 'modTemplateVarTemplate', 'tmplvarid', array()),
        array('modTemplateVar', 'modTemplateVarResource', 'tmplvarid', array()),
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
    private static function key(array $fields) {
        return isset($fields['id']) ? 'id:' . $fields['id'] : md5(json_encode($fields));
    }
    public function row($class, $id) { return isset($this->tables[$class]['id:' . $id]) ? $this->tables[$class]['id:' . $id] : null; }
    public function nextId($class) {
        $max = 0;
        foreach (isset($this->tables[$class]) ? $this->tables[$class] : array() as $row) { $max = max($max, isset($row['id']) ? (int) $row['id'] : 0); }
        return $max + 1;
    }
    public function store($class, array $fields) {
        // Values come back from a database as strings.
        foreach ($fields as $key => $value) { $fields[$key] = $value === null ? null : (is_bool($value) ? ($value ? '1' : '0') : (string) $value); }
        if (!isset($fields['id'])) {
            foreach (isset($this->tables[$class]) ? $this->tables[$class] : array() as $key => $row) {
                if ($row == $fields) { unset($this->tables[$class][$key]); }
            }
        }
        $this->tables[$class][self::key($fields)] = $fields;
        $this->persist();
    }
    public function drop($class, array $fields) {
        foreach (isset($this->tables[$class]) ? $this->tables[$class] : array() as $key => $row) {
            if (isset($fields['id']) ? (isset($row['id']) && (string) $row['id'] === (string) $fields['id']) : $row == $fields) {
                unset($this->tables[$class][$key]);
            }
        }
        $this->persist();
    }
    private function match($class, $criteria) {
        $criteria = $criteria instanceof FakeQuery ? $criteria->criteria : (array) $criteria;
        $out = array();
        foreach (isset($this->tables[$class]) ? $this->tables[$class] : array() as $row) {
            foreach ($criteria as $key => $value) {
                if (substr($key, -5) === ':LIKE') {
                    if (stripos((string) $row[substr($key, 0, -5)], trim($value, '%')) === false) { continue 2; }
                } elseif (!array_key_exists($key, $row) || (string) $row[$key] !== (string) $value) { continue 2; }
            }
            $out[] = $row;
        }
        return $out;
    }
    public function getObject($class, $criteria = null, $cacheFlag = true) {
        if ($class === 'modUser') { return new FakeObject($this, 'modUser', array('id' => 1, 'active' => 1), false); }
        $rows = $this->match($class, is_array($criteria) || $criteria instanceof FakeQuery ? $criteria : array('id' => $criteria));
        return $rows ? new FakeObject($this, $class, $rows[0], false) : null;
    }
    public function getCollection($class, $criteria = null) {
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
    public function newQuery($class, $criteria = array()) { return new FakeQuery($class, $criteria); }
    public function newObject($class) { return new FakeObject($this, $class, array(), true); }
    public function removeCollection($class, $criteria) {
        foreach ($this->match($class, $criteria) as $row) { $this->drop($class, $row); }
        return true;
    }
    public function invokeEvent($name, array $params = array()) { $this->events[] = $name; }
    public function getCacheManager() { return $this->cache; }
    public function log($level, $message) { error_log($message); }
    public function getVersionData() { return array('full_version' => '2.8.8-pl'); }

    /** Minimal create / update / remove processors with the validation the real ones have. */
    public function runProcessor($path, array $data = array()) {
        $action = basename($path);
        $base = substr($path, 0, -strlen($action));
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
    $modx->options = $options + array(
        'modxmcp.enabled' => true,
        'modxmcp.core_path' => dirname(dirname(__DIR__)) . '/core/components/modxmcp/',
        'core_path' => $dir . '/core/',
        'table_prefix' => 'modx_',
        'site_name' => 'Test site',
    );
    return $modx;
}
