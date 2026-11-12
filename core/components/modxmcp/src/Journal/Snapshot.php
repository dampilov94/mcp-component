<?php
namespace ModxMCP\Journal;

use ModxMCP\Kind\Kind;
use ModxMCP\Kind\StaticFiles;

/**
 * Canonical state of one object: its raw columns plus the related rows the kind declares.
 * The revision of an object is the SHA-256 of this state, so the same value identifies a
 * state in read results, write results and the journal.
 */
final class Snapshot {
    /** Current state of an object, or null when it does not exist. */
    public static function capture(\modX $modx, Kind $kind, $key) {
        $key = $kind->normalizeKey($key);
        $object = $kind->findByKey($modx, $key);
        if (!$object) { return null; }
        $fields = $kind->stateColumns(self::row($object));
        $state = array('kind' => $kind->name(), 'key' => (string) $key);
        if ($kind->codeField() !== null) {
            // The file is the truth for a static element; a stale database copy must not
            // change the revision.
            $content = $kind->effectiveContent($object);
            $fields[$kind->column($kind->codeField())] = $content[0];
            if ($content[3]) { $state['static_file_missing'] = true; }
        }
        $state['fields'] = $fields;
        $state['relations'] = self::relations($modx, $kind, $object, false);
        return $state;
    }

    /** Rows that exist only to make a delete reversible (data owned by other objects). */
    public static function captureDeleteExtra(\modX $modx, Kind $kind, $key) {
        $object = $kind->findByKey($modx, $kind->normalizeKey($key));
        return $object ? self::relations($modx, $kind, $object, true) : array();
    }

    private static function relations(\modX $modx, Kind $kind, $object, $deleteOnly) {
        $out = array();
        foreach ($kind->relationSpecs() as $name => $spec) {
            // Rows that are objects of their own kind (in_state false) are journaled as those objects.
            if (!empty($spec['delete_only']) !== $deleteOnly || (isset($spec['in_state']) && $spec['in_state'] === false)) { continue; }
            $rows = array();
            foreach ($modx->getCollection($spec['class'], self::criteria($spec, $kind->keyOf($object))) as $row) {
                $values = self::row($row);
                // Columns MODX changes on its own (login statistics) are not part of the state.
                if (!empty($spec['ignore_columns'])) { $values = array_diff_key($values, array_flip($spec['ignore_columns'])); }
                $rows[] = $values;
            }
            usort($rows, function ($a, $b) { return strcmp(json_encode($a), json_encode($b)); });
            $out[$name] = $rows;
        }
        return $out;
    }

    private static function criteria(array $spec, $id) {
        return array($spec['fk'] => $id) + (isset($spec['where']) ? $spec['where'] : array());
    }

    /** Raw column values as strings (or null), keys sorted. */
    private static function row($object) {
        $row = array();
        foreach ($object->toArray('', true) as $column => $value) {
            if ($value === null) { $row[$column] = null; }
            elseif (is_bool($value)) { $row[$column] = $value ? '1' : '0'; }
            elseif (is_scalar($value)) { $row[$column] = (string) $value; }
            else { $row[$column] = json_encode($value); }
        }
        ksort($row, SORT_STRING);
        return $row;
    }

    public static function encode(array $state) {
        return json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Revision of a state; null state (no object) has no revision. */
    public static function revision($state) {
        return $state === null ? null : hash('sha256', self::encode($state));
    }

    /**
     * Raw stored value of an API field in a state: a column of the object, or for a `via` field a
     * column of the first row of the relation it names. Null when the state or the value is absent.
     */
    public static function stateValue(Kind $kind, $state, $name) {
        if ($state === null) { return null; }
        $definitions = $kind->fields();
        $column = $kind->column($name);
        if (!empty($definitions[$name]['via'])) {
            $rows = isset($state['relations'][$definitions[$name]['via']]) ? $state['relations'][$definitions[$name]['via']] : array();
            return $rows && array_key_exists($column, $rows[0]) ? $rows[0][$column] : null;
        }
        return array_key_exists($column, $state['fields']) ? $state['fields'][$column] : null;
    }

    /** relation name => columns of its rows that are `via` fields. */
    private static function viaColumns(Kind $kind) {
        $out = array();
        foreach ($kind->fields() as $name => $definition) {
            if (!empty($definition['via'])) { $out[$definition['via']][] = $kind->column($name); }
        }
        return $out;
    }

    private static function withoutColumns(array $rows, array $columns) {
        foreach ($rows as $index => $row) { $rows[$index] = array_diff_key($row, array_flip($columns)); }
        return $rows;
    }

    /**
     * API-level names of what differs between two states: fields and relations.
     * Either state may be null (object absent).
     */
    public static function changed(Kind $kind, $before, $after) {
        if ($before === null && $after !== null) {
            // A new object: name what it was created with, not every column default.
            $created = array();
            foreach ($kind->fields() as $name => $definition) {
                $value = self::stateValue($kind, $after, $name);
                if (empty($definition['read_only']) && $value !== null && $value !== '' && $value !== '0') { $created[] = $name; }
            }
            foreach ($after['relations'] as $name => $rows) {
                // Rows that back `via` fields are named by those fields.
                if ($rows && !in_array($name, $kind->viaRelations(), true)) { $created[] = $name; }
            }
            return $created;
        }
        $changed = array();
        $a = $before === null ? array() : $before['fields'];
        $b = $after === null ? array() : $after['fields'];
        foreach ($kind->fields() as $name => $definition) {
            if (self::stateValue($kind, $before, $name) !== self::stateValue($kind, $after, $name)) { $changed[] = $name; }
        }
        $ra = $before === null ? array() : $before['relations'];
        $rb = $after === null ? array() : $after['relations'];
        $viaColumns = self::viaColumns($kind);
        foreach (array_unique(array_merge(array_keys($ra), array_keys($rb))) as $name) {
            $left = isset($ra[$name]) ? $ra[$name] : array();
            $right = isset($rb[$name]) ? $rb[$name] : array();
            if (isset($viaColumns[$name])) {
                // The `via` fields were compared above; any other column of the row is a change of the relation itself.
                $left = self::withoutColumns($left, $viaColumns[$name]);
                $right = self::withoutColumns($right, $viaColumns[$name]);
            }
            if ($left !== $right) { $changed[] = $name; }
        }
        // Columns the API does not expose (e.g. properties) still count as a change.
        $hidden = array_diff_key($a + $b, array_flip(array_map(array($kind, 'column'), array_keys($kind->fields()))));
        foreach (array_keys($hidden) as $column) {
            if ((array_key_exists($column, $a) ? $a[$column] : null) !== (array_key_exists($column, $b) ? $b[$column] : null)) {
                $changed[] = $column;
            }
        }
        return array_values(array_unique($changed));
    }

    /**
     * Put an object back into a recorded state. A null state removes the object. Works
     * directly on xPDO objects: the core processors cannot create an object with a given id
     * or restore its related rows as one unit.
     *
     * @param array $extra delete-only relation rows recorded before a delete
     */
    public static function restore(\modX $modx, Kind $kind, $key, $state, array $extra = array()) {
        $key = $kind->normalizeKey($key);
        $object = $kind->findByKey($modx, $key);
        $events = $kind->eventNames();

        // A kind may refuse to put an object back (or take it away) through the API.
        if ($state !== null) { $kind->assertRestorable($state); }
        elseif ($object) { $kind->assertRestorable(self::capture($modx, $kind, $key)); }

        if ($state === null) {
            if (!$object) { return; }
            $staticPath = $kind->effectiveContent($object)[2];
            if (!$object->remove()) { throw new \RuntimeException("Cannot remove {$kind->name()} {$key}."); }
            StaticFiles::removeManaged($modx, $staticPath);
            if (isset($events['delete'])) {
                $modx->invokeEvent($events['delete'], array('id' => $key, $events['param'] => &$object, 'object' => &$object));
            }
            $kind->afterWrite($modx);
            return;
        }

        $isNew = !$object;
        if ($isNew) { $object = $modx->newObject($kind->modelClass()); }
        $fields = $state['fields'];
        $object->fromArray($fields, '', true, true);

        // Write the file before saving: modElement::save() compares the file with the column
        // and would otherwise pull stale file content back into the database.
        if ($kind->codeField() !== null && isset($fields['static']) && $fields['static'] === '1' && empty($state['static_file_missing'])) {
            $path = $object->getSourceFile();
            if ($path && strpos($path, '://') === false) {
                self::writeFile($path, $kind->fileContent((string) $fields[$kind->column($kind->codeField())]));
            }
        }
        if (!$object->save()) { throw new \RuntimeException("Cannot restore {$kind->name()} {$key}."); }

        $rows = $state['relations'] + $extra;
        foreach ($kind->relationSpecs() as $name => $spec) {
            if (!isset($rows[$name])) { continue; }
            // A one-row relation keeps the current values of the columns the state ignores.
            $kept = array();
            if (!empty($spec['ignore_columns']) && count($rows[$name]) === 1) {
                $current = $modx->getCollection($spec['class'], self::criteria($spec, $key));
                if (count($current) === 1) { $kept = array_intersect_key(reset($current)->toArray('', true), array_flip($spec['ignore_columns'])); }
            }
            $modx->removeCollection($spec['class'], self::criteria($spec, $key));
            foreach ($rows[$name] as $row) {
                $related = $modx->newObject($spec['class']);
                $related->fromArray($kept + $row, '', true, true);
                if (!$related->save()) { throw new \RuntimeException("Cannot restore {$name} of {$kind->name()} {$key}."); }
            }
        }
        if (isset($events['save'])) {
            $modx->invokeEvent($events['save'], array(
                'mode' => $isNew ? 'new' : 'upd', 'id' => $key, $events['param'] => &$object, 'object' => &$object,
            ));
        }
        $kind->afterWrite($modx);
    }

    /** Replace a file atomically, creating its directory when needed. */
    public static function writeFile($path, $content) {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { throw new \RuntimeException('Cannot create the static file directory.'); }
        $mode = is_file($path) ? (@fileperms($path) & 0777) : 0644;
        $temp = $dir . '/.modxmcp-' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($temp, $content) === false) { throw new \RuntimeException('Cannot write the static file. Check permissions and free space.'); }
        @chmod($temp, $mode ? $mode : 0644);
        if (!@rename($temp, $path)) { @unlink($temp); throw new \RuntimeException('Cannot replace the static file.'); }
        clearstatcache(true, $path);
    }
}
