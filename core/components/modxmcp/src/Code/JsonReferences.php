<?php
namespace ModxMCP\Code;

/**
 * Names held in the JSON of MIGX (the form tabs and columns of a configuration, the input options of a MIGX TV, which keep
 * further JSON as strings): `inputTV` names a TV, `renderchunktpl`, `chunk` and `formtabs_chunk` name a chunk (optionally as
 * `@CHUNK name`). Mirrors Graph\IntegrationReferences and GraphBuilder::scanTvInputRefs.
 */
final class JsonReferences {
    const CHUNK_KEYS = array('renderchunktpl', 'chunk', 'formtabs_chunk');
    const TV_KEYS = array('inputTV');

    /**
     * @param mixed $value decoded JSON (objects as stdClass) or a map of options
     * @param string $kind chunk or tv: which names to rewrite
     * @return array [new value, changes [{where, old, new, via}]]
     */
    public static function rewrite($value, $kind, $old, $new) {
        $changes = array();
        $result = self::walk($value, $kind === 'tv' ? self::TV_KEYS : self::CHUNK_KEYS, $kind === 'chunk', $old, $new, '', 0, $changes);
        return array($result, $changes);
    }

    private static function walk($value, array $keys, $allowBinding, $old, $new, $path, $depth, array &$changes) {
        if ($depth > 16) { return $value; }
        if (is_array($value) || is_object($value)) {
            $isObject = is_object($value);
            $copy = $isObject ? clone $value : $value;
            foreach (($isObject ? get_object_vars($value) : $value) as $key => $item) {
                $where = is_int($key) ? $path . '[' . $key . ']' : ($path === '' ? (string) $key : $path . '.' . $key);
                $replacement = $item;
                if (is_string($item) && in_array((string) $key, $keys, true)) {
                    $pattern = '/^(\s*' . ($allowBinding ? '(?:(?i:@CHUNK)\s+)?' : '') . ')' . preg_quote($old, '/') . '(\s*)$/u';
                    if (preg_match($pattern, $item, $m)) {
                        $replacement = $m[1] . $new . $m[2];
                        $changes[] = array('where' => $where, 'old' => $item, 'new' => $replacement, 'via' => 'migx');
                    }
                } elseif (is_string($item) && preg_match('/^\s*[\[{]/', $item)) {
                    $decoded = json_decode($item, false);
                    if (is_array($decoded) || is_object($decoded)) {
                        $inner = array();
                        $changed = self::walk($decoded, $keys, $allowBinding, $old, $new, $where, $depth + 1, $inner);
                        if ($inner) {
                            $replacement = self::encodeLike($changed, $item);
                            foreach ($inner as $change) { $changes[] = $change; }
                        }
                    }
                } elseif (is_array($item) || is_object($item)) {
                    $replacement = self::walk($item, $keys, $allowBinding, $old, $new, $where, $depth + 1, $changes);
                }
                if ($isObject) { $copy->$key = $replacement; } else { $copy[$key] = $replacement; }
            }
            return $copy;
        }
        return $value;
    }

    /** JSON text in the style of the original: escaped or literal non-ASCII text and slashes, one line or indented. */
    private static function encodeLike($value, $original) {
        $flags = JSON_PRESERVE_ZERO_FRACTION;
        if (strpos($original, '\\u') === false) { $flags |= JSON_UNESCAPED_UNICODE; }
        if (strpos($original, '\\/') === false) { $flags |= JSON_UNESCAPED_SLASHES; }
        if (strpos($original, "\n") !== false) { $flags |= JSON_PRETTY_PRINT; }
        return json_encode($value, $flags);
    }
}
