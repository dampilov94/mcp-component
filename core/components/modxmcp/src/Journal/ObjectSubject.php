<?php
namespace ModxMCP\Journal;

use ModxMCP\Code\Diff;
use ModxMCP\Kind\Kind;

/** A site object of some kind, snapshotted through Snapshot. */
final class ObjectSubject implements Subject {
    /** Columns whose values are never shown in a diff. */
    const SECRET = '/pass|secret|token|salt|hash/i';

    private $modx;
    private $kind;
    private $id;

    public function __construct(\modX $modx, Kind $kind, $id) {
        $this->modx = $modx;
        $this->kind = $kind;
        $this->id = $kind->normalizeKey($id);
    }

    public function kind() { return $this->kind; }

    public function kindName() { return $this->kind->name(); }

    public function key() { return (string) $this->id; }

    public function publicKey() { return $this->kind->publicKey($this->id); }

    public function capture() { return Snapshot::capture($this->modx, $this->kind, $this->id); }

    public function captureDeleteExtra() { return Snapshot::captureDeleteExtra($this->modx, $this->kind, $this->id); }

    public function restore($state, array $extra) { Snapshot::restore($this->modx, $this->kind, $this->id, $state, $extra); }

    public function label($state) {
        $column = $this->kind->column($this->kind->nameField());
        return $state !== null && isset($state['fields'][$column]) ? (string) $state['fields'][$column] : '';
    }

    public function changed($before, $after) { return Snapshot::changed($this->kind, $before, $after); }

    public function describeChange($before, $after) {
        $kind = $this->kind;
        $definitions = $kind->fields();
        $codeColumn = $kind->codeField() !== null ? $kind->column($kind->codeField()) : null;
        $out = array();
        // A kind that answers decides which columns are secret; otherwise the column name does.
        $secretColumns = null;
        foreach (array($before, $after) as $state) {
            $answer = $state === null ? null : $kind->secretColumns($state);
            if ($answer !== null) { $secretColumns = array_merge($secretColumns === null ? array() : $secretColumns, $answer); }
        }
        foreach (Snapshot::changed($kind, $before, $after) as $name) {
            $column = isset($definitions[$name]) ? $kind->column($name) : $name;
            if (isset($definitions[$name])) {
                $old = Snapshot::stateValue($kind, $before, $name);
                $new = Snapshot::stateValue($kind, $after, $name);
            } else {
                $old = $before !== null && array_key_exists($column, $before['fields']) ? $before['fields'][$column] : null;
                $new = $after !== null && array_key_exists($column, $after['fields']) ? $after['fields'][$column] : null;
            }
            if (($before !== null && isset($before['relations'][$name])) || ($after !== null && isset($after['relations'][$name]))) {
                $out[$name] = array(
                    'old' => $kind->maskRelationRows($name, $before !== null && isset($before['relations'][$name]) ? $before['relations'][$name] : array()),
                    'new' => $kind->maskRelationRows($name, $after !== null && isset($after['relations'][$name]) ? $after['relations'][$name] : array()),
                );
            } elseif ($column === $codeColumn) {
                $out[$name] = array('diff' => Diff::lines((string) $old, (string) $new));
            } elseif ($secretColumns !== null ? in_array($column, $secretColumns, true) : preg_match(self::SECRET, $column)) {
                $out[$name] = array('old' => '[hidden]', 'new' => '[hidden]');
            } else {
                $out[$name] = array('old' => $old, 'new' => $new);
            }
        }
        return $out;
    }
}
