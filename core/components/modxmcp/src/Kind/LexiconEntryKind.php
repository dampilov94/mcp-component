<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;

/**
 * A lexicon entry, addressed by the key "namespace:topic:language:name" (e.g. core:default:ru:error). What a site shows is the entry of
 * the lexicon file unless a database row overrides it: the API reads the effective value and its source, lists the file entries
 * too, and writes only the override. Deleting removes the override; the file value applies again. Only the override is journaled.
 */
final class LexiconEntryKind extends Kind {
    /** Most (namespace, language, topic) files one list call reads. */
    const MAX_FILES = 500;

    public function name() { return 'lexicon_entry'; }
    public function label() { return 'Lexicon entry'; }
    public function modelClass() { return 'modLexiconEntry'; }
    public function group() { return 'lexicon'; }
    public function processorPath() { return 'workspace/lexicon/'; }
    public function nameField() { return 'name'; }
    public function uniqueNames() { return false; }

    public function fields() {
        return array(
            'namespace'  => array('type' => 'string', 'list' => true, 'required' => true, 'immutable' => true, 'description' => 'Namespace of the entry (core for MODX itself).'),
            'topic'      => array('type' => 'string', 'list' => true, 'required' => true, 'immutable' => true, 'description' => 'Lexicon topic (the file name without .inc.php).'),
            'language'   => array('type' => 'string', 'list' => true, 'required' => true, 'immutable' => true, 'description' => 'Language key, e.g. en or ru.'),
            'name'       => array('type' => 'string', 'list' => true, 'required' => true, 'immutable' => true, 'description' => 'Entry name.'),
            'value'      => array('type' => 'string', 'list' => true, 'description' => 'The value the site shows. Writing stores a database override; writing the value of the file removes the override.'),
            'source'     => array('type' => 'string', 'derived' => true, 'computed' => true, 'description' => 'Where the shown value comes from: file or database.'),
            'overridden' => array('type' => 'boolean', 'list' => true, 'derived' => true, 'computed' => true, 'description' => 'True when a database row overrides an entry of the lexicon file.'),
            'file_value' => array('type' => 'string', 'derived' => true, 'computed' => true, 'description' => 'The value in the lexicon file ("" when the file has no such entry).'),
            'id'         => array('type' => 'integer', 'read_only' => true, 'description' => 'Id of the database override; 0 when the value comes from the file.'),
        );
    }

    // ---- the key ----

    public function keyType() { return 'string'; }

    public static function composeKey($namespace, $topic, $language, $name) { return $namespace . ':' . $topic . ':' . $language . ':' . $name; }

    /** @return string[] [namespace, topic, language, name] */
    public static function parseKey($raw) {
        $parts = explode(':', (string) $raw, 4);
        if (count($parts) !== 4) { throw new ApiException('validation_failed', "A lexicon key is namespace:topic:language:name, e.g. core:default:ru:error (got '{$raw}')."); }
        self::checkParts($parts);
        return $parts;
    }

    private static function checkParts(array $parts) {
        list($namespace, $topic, $language, $name) = $parts;
        if (!preg_match('/^[A-Za-z0-9_\-]{1,40}$/D', $namespace)) { throw new ApiException('validation_failed', "Invalid namespace '{$namespace}'."); }
        if (!preg_match('/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/D', $topic) || strlen($topic) > 191) { throw new ApiException('validation_failed', "Invalid topic '{$topic}'."); }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_\-]{0,19}$/D', $language)) { throw new ApiException('validation_failed', "Invalid language '{$language}'."); }
        if ($name === '' || strlen($name) > 191 || strpos($name, "\0") !== false) { throw new ApiException('validation_failed', 'An entry name must be 1 to 191 characters.'); }
        if (strlen(self::composeKey($namespace, $topic, $language, $name)) > 190) { throw new ApiException('validation_failed', 'The key of the entry is too long.'); }
    }

    public function normalizeKey($raw) {
        $parts = self::parseKey($raw);
        return self::composeKey($parts[0], $parts[1], $parts[2], $parts[3]);
    }

    public function keyOf($object) {
        if ($object instanceof LexiconEntry) { return $object->key(); }
        return self::composeKey($object->get('namespace'), $object->get('topic'), $object->get('language'), $object->get('name'));
    }

    public function keyCriteria($key) {
        $parts = self::parseKey($key);
        return array('namespace' => $parts[0], 'topic' => $parts[1], 'language' => $parts[2], 'name' => $parts[3]);
    }

    // ---- reading ----

    /** The lexicon service (loaded on demand), with the cache manager it needs. */
    public static function service(\modX $modx) {
        if (!isset($modx->lexicon) || !is_object($modx->lexicon)) { $modx->getService('lexicon', 'modLexicon'); }
        if (!$modx->getCacheManager()) { return null; }
        return isset($modx->lexicon) && is_object($modx->lexicon) ? $modx->lexicon : null;
    }

    /** The entries of a lexicon file as name => value, or null when the file does not exist. */
    private static function fileTopic(\modX $modx, $namespace, $language, $topic) {
        $lexicon = self::service($modx);
        $entries = $lexicon ? $lexicon->getFileTopic($language, $namespace, $topic) : false;
        return is_array($entries) ? $entries : null;
    }

    public function findByKey(\modX $modx, $key) {
        $parts = self::parseKey($key);
        $row = $modx->getObject('modLexiconEntry', array('namespace' => $parts[0], 'topic' => $parts[1], 'language' => $parts[2], 'name' => $parts[3]), false);
        $file = self::fileTopic($modx, $parts[0], $parts[2], $parts[1]);
        $value = $file !== null && array_key_exists($parts[3], $file) ? (string) $file[$parts[3]] : null;
        if (!$row && $value === null) { return null; }
        return new LexiconEntry($modx, $parts, $row ? $row : null, $value);
    }

    public function value($object, $field) {
        switch ($field) {
            case 'source': return $object->hasRow() ? 'database' : 'file';
            case 'overridden': return $object->hasRow() && $object->fileValue() !== null;
            case 'file_value': return $object->fileValue() === null ? '' : $object->fileValue();
            default: return parent::value($object, $field);
        }
    }

    public function objectColumns($object) { return $object->toArray('', true); }

    /** The times MODX sets on every save are not part of the state. */
    public function stateColumns(array $columns) {
        unset($columns['createdon'], $columns['editedon']);
        return $columns;
    }

    public function customList(Context $context, array $criteria, $query, $start, $limit) {
        $modx = $context->modx;
        foreach ($criteria as $column => $value) {
            if (!in_array($column, array('namespace', 'topic', 'language', 'name', 'value'), true)) { throw new ApiException('validation_failed', "Cannot filter lexicon_entry by '{$column}'."); }
        }
        if (!isset($criteria['namespace']) && !isset($criteria['topic']) && !isset($criteria['language']) && !isset($criteria['name']) && $query === '') {
            throw new ApiException('validation_failed', 'Narrow the list: give a namespace, a topic, a language, a name or a query (all entries of a site are too many to list).');
        }
        $lexicon = self::service($modx);
        if (!$lexicon) { return array(0, array()); }
        if (isset($criteria['namespace'])) { $namespaces = array((string) $criteria['namespace']); }
        else {
            $namespaces = array('core');
            foreach ($modx->getCollection('modNamespace') as $namespace) { $namespaces[] = (string) $namespace->get('name'); }
            $namespaces = array_values(array_unique($namespaces));
        }
        $found = array();
        $files = 0;
        foreach ($namespaces as $namespace) {
            $languages = isset($criteria['language']) ? array((string) $criteria['language']) : (array) $lexicon->getLanguageList($namespace);
            foreach ($languages as $language) {
                $topics = isset($criteria['topic']) ? array((string) $criteria['topic']) : (array) $lexicon->getTopicList($language, $namespace);
                foreach ($topics as $topic) {
                    try { self::checkParts(array($namespace, $topic, $language, 'x')); } catch (ApiException $e) { continue; }
                    if (++$files > self::MAX_FILES) { throw new ApiException('validation_failed', 'Too many lexicon files match; narrow the list with a namespace, topic or language.'); }
                    $file = self::fileTopic($modx, $namespace, $language, $topic);
                    $rows = array();
                    foreach ($modx->getCollection('modLexiconEntry', array('namespace' => $namespace, 'topic' => $topic, 'language' => $language)) as $row) { $rows[(string) $row->get('name')] = $row; }
                    $names = array_unique(array_merge($file === null ? array() : array_map('strval', array_keys($file)), array_keys($rows)));
                    foreach ($names as $name) {
                        $name = (string) $name;
                        if (isset($criteria['name']) && $name !== (string) $criteria['name']) { continue; }
                        $row = isset($rows[$name]) ? $rows[$name] : null;
                        $fileValue = $file !== null && array_key_exists($name, $file) ? (string) $file[$name] : null;
                        $value = $row ? (string) $row->get('value') : (string) $fileValue;
                        if (isset($criteria['value']) && $value !== (string) $criteria['value']) { continue; }
                        if ($query !== '' && self::position($name, $query) === false && self::position($value, $query) === false) { continue; }
                        $found[] = array(array($namespace, $topic, $language, $name), $row, $fileValue);
                    }
                }
            }
        }
        usort($found, function ($a, $b) {
            foreach (array(0, 1, 2, 3) as $i) {
                $order = strcmp($a[0][$i], $b[0][$i]);
                if ($order !== 0) { return $order; }
            }
            return 0;
        });
        $objects = array();
        foreach (array_slice($found, $start, $limit) as $item) { $objects[] = new LexiconEntry($modx, $item[0], $item[1], $item[2]); }
        return array(count($found), $objects);
    }

    /** Position of the first occurrence, ignoring case also in non-ASCII text, or false. */
    private static function position($text, $query) {
        return function_exists('mb_stripos') && preg_match('//u', $text) === 1 && preg_match('//u', $query) === 1 ? mb_stripos($text, $query, 0, 'UTF-8') : stripos($text, $query);
    }

    // ---- writing ----

    public function assertColumns(array $columns, $creating) {
        if (!$creating) { return; }
        if (!array_key_exists('value', $columns)) { throw new ApiException('validation_failed', "Field 'value' is required."); }
        self::checkParts(array($columns['namespace'], $columns['topic'], $columns['language'], $columns['name']));
    }

    public function assertObjectWritable(\modX $modx, $object, $op) {
        if ($op === 'duplicate') { throw new ApiException('validation_failed', 'A lexicon entry cannot be duplicated: it is addressed by its key.'); }
        if ($op === 'delete' && !$object->hasRow()) {
            throw new ApiException('validation_failed', 'This entry comes from the lexicon file; there is no database override to delete.');
        }
    }

    public function usesProcessors() { return false; }

    /** Create ($id null) or change an override through the lexicon processors. Returns the key. */
    public function writeObject(Context $context, $id, array $columns) {
        $modx = $context->modx;
        if ($id === null) {
            $parts = array($columns['namespace'], $columns['topic'], $columns['language'], $columns['name']);
            if ($columns['namespace'] !== 'core' && !$modx->getCount('modNamespace', array('name' => $columns['namespace']))) {
                throw new ApiException('validation_failed', "Namespace '{$columns['namespace']}' does not exist.");
            }
            if ($modx->getCount('modLexiconEntry', array('namespace' => $parts[0], 'topic' => $parts[1], 'language' => $parts[2], 'name' => $parts[3]))) {
                throw new ApiException('validation_failed', 'This entry already has a database override; use modx_update.');
            }
            self::service($modx);
            Objects::process($modx, 'workspace/lexicon/create', array('name' => $parts[3], 'namespace' => $parts[0], 'language' => $parts[2], 'topic' => $parts[1], 'value' => (string) $columns['value']));
            return self::composeKey($parts[0], $parts[1], $parts[2], $parts[3]);
        }
        $parts = self::parseKey($id);
        $entry = $this->findByKey($modx, $id);
        if (!$entry) { throw new ApiException('not_found', "No lexicon_entry with key '{$id}'."); }
        if (!array_key_exists('value', $columns)) { return $id; }
        $value = (string) $columns['value'];
        // The processor fails on an entry that has no override while its value is the file's; there is nothing to change then.
        if (!$entry->hasRow() && $entry->fileValue() !== null && $entry->fileValue() === $value) { return $id; }
        self::service($modx);
        Objects::process($modx, 'workspace/lexicon/updatefromgrid', array('data' => json_encode(
            array('language' => $parts[2], 'namespace' => $parts[0], 'topic' => $parts[1], 'name' => $parts[3], 'value' => $value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        return $id;
    }

    /** Remove the override: the lexicon file's value applies again. */
    public function removeObject(Context $context, $key) {
        $parts = self::parseKey($key);
        self::service($context->modx);
        Objects::process($context->modx, 'workspace/lexicon/revert', array('name' => $parts[3], 'namespace' => $parts[0], 'language' => $parts[2], 'topic' => $parts[1]));
    }
}
