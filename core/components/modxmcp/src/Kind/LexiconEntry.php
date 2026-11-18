<?php
namespace ModxMCP\Kind;

/**
 * One lexicon entry as the API sees it: the value a site shows for a key, which is the entry of a lexicon file or, when
 * a database row (modLexiconEntry) exists for the key, that override. It stands in for an xPDO object in the generic
 * code that reads, snapshots and restores objects: a state without an `id` means "no override" (restoring it removes the
 * row), a state with one is the row.
 */
final class LexiconEntry {
    /** @var \modX The services of the entry's site (kept like an xPDO object keeps its xpdo). */
    public $xpdo;
    private $namespace;
    private $topic;
    private $language;
    private $name;
    /** @var object|null The modLexiconEntry row of the override. */
    private $row;
    /** @var string|null The value in the lexicon file; null when the file has no such entry. */
    private $file;
    private $pending = null;

    public function __construct(\modX $modx, array $parts, $row, $file) {
        $this->xpdo = $modx;
        list($this->namespace, $this->topic, $this->language, $this->name) = $parts;
        $this->row = $row;
        $this->file = $file;
    }

    public function key() { return LexiconEntryKind::composeKey($this->namespace, $this->topic, $this->language, $this->name); }

    public function hasRow() { return $this->row !== null; }

    public function fileValue() { return $this->file; }

    /** The value the site shows. */
    public function effective() { return $this->row ? (string) $this->row->get('value') : (string) $this->file; }

    public function get($field) {
        switch ($field) {
            case 'namespace': return $this->namespace;
            case 'topic': return $this->topic;
            case 'language': return $this->language;
            case 'name': return $this->name;
            case 'value': return $this->effective();
            default: return $this->row ? $this->row->get($field) : null;
        }
    }

    /** Raw columns: the row's, or for an entry that only the file has, its key and value without an id. */
    public function toArray($prefix = '', $raw = false) {
        if ($this->row) { return $this->row->toArray('', true); }
        return array('language' => $this->language, 'name' => $this->name, 'namespace' => $this->namespace, 'topic' => $this->topic, 'value' => (string) $this->file);
    }

    /** Collects the recorded columns for save(). */
    public function fromArray($values, $prefix = '', $setPrimaryKeys = false, $raw = false) { $this->pending = (array) $values; }

    /** Put the entry into the state given to fromArray(): a row for a state with an id, no row for one without. */
    public function save() {
        $state = $this->pending;
        $this->pending = null;
        if ($state === null) { return true; }
        if (!isset($state['id'])) { return $this->remove(); }
        $row = $this->row ? $this->row : $this->xpdo->newObject('modLexiconEntry');
        $row->fromArray($state, '', true, true);
        if (!$row->save()) { return false; }
        $this->row = $row;
        $this->clearCache();
        return true;
    }

    /** Remove the override (the file value applies again). True when there is nothing left to remove. */
    public function remove() {
        if (!$this->row) { return true; }
        if (!$this->row->remove()) { return false; }
        $this->row = null;
        $this->clearCache();
        return true;
    }

    private function clearCache() {
        $lexicon = LexiconEntryKind::service($this->xpdo);
        if ($lexicon) { $lexicon->clearCache($this->language . '/' . $this->namespace . '/' . $this->topic . '.cache.php'); }
    }
}
