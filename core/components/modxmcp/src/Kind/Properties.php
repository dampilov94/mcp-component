<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;

/**
 * The `properties` column of elements, property sets and media sources: a map name => {name, desc, type, options, value,
 * lexicon, area}, stored as a serialized PHP array (JSON in a few old rows). The API shows and takes it as
 * {name: {type, value, desc?, options?, lexicon?, area?}}.
 */
final class Properties {
    /** The stored map of an object, read from the raw column (not through get(), which a property set decorates with translations). */
    public static function stored($object) {
        $raw = $object->toArray('', true);
        return self::decode(is_array($raw) && array_key_exists('properties', $raw) ? $raw['properties'] : null);
    }

    /** A raw column value (serialized text, JSON text or an array) as a map name => entry. */
    public static function decode($raw) {
        if (is_string($raw) && $raw !== '') {
            $value = @unserialize($raw, array('allowed_classes' => false, 'max_depth' => 64));
            if ($value === false && $raw !== 'b:0;') { $value = json_decode($raw, true); }
            $raw = $value;
        }
        if (!is_array($raw)) { return array(); }
        $out = array();
        foreach ($raw as $key => $entry) {
            if (!self::isPlain($entry)) { continue; }
            if (!is_array($entry)) { $entry = array('value' => $entry); }
            $name = isset($entry['name']) && is_scalar($entry['name']) && $entry['name'] !== '' ? (string) $entry['name'] : (string) $key;
            $out[$name] = $entry + array('name' => $name);
        }
        return $out;
    }

    private static function isPlain($value, $depth = 0) {
        if ($depth > 64) { return false; }
        if (!is_array($value)) { return $value === null || is_scalar($value); }
        foreach ($value as $item) {
            if (!self::isPlain($item, $depth + 1)) { return false; }
        }
        return true;
    }

    /** Property values only, as media sources present them. */
    public static function values(array $stored) {
        ksort($stored, SORT_STRING);
        $out = array();
        foreach ($stored as $name => $entry) { $out[$name] = $entry['value'] ?? ''; }
        return (object) $out;
    }

    /** Show recorded properties in the diff without publishing known credential values. */
    public static function forHistory($raw, $valuesOnly) {
        if ($raw === null) { return null; }
        $stored = self::decode($raw);
        foreach ($stored as $name => &$entry) {
            $key = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string) $name);
            $key = str_replace('-', '_', $key);
            $type = isset($entry['type']) && is_string($entry['type']) ? $entry['type'] : '';
            if (Secrets::isSecret($key, $type) || strtolower($key) === 'key' || preg_match('/(?:^|_)access_?key(?:_id)?$/i', $key)) {
                $entry = array('type' => $type, 'value' => Secrets::HIDDEN);
            }
        }
        unset($entry);
        return $valuesOnly ? self::values($stored) : self::present($stored);
    }

    /** The stored map as the API shows it: always an object, keys sorted, empty parts left out. */
    public static function present(array $stored) {
        ksort($stored, SORT_STRING);
        $out = array();
        foreach ($stored as $name => $entry) {
            $item = array('type' => isset($entry['type']) && $entry['type'] !== '' ? (string) $entry['type'] : 'textfield', 'value' => array_key_exists('value', $entry) ? $entry['value'] : '');
            foreach (array('desc', 'lexicon', 'area') as $part) {
                if (isset($entry[$part]) && $entry[$part] !== '' && $entry[$part] !== null) { $item[$part] = (string) $entry[$part]; }
            }
            if (!empty($entry['options'])) { $item['options'] = $entry['options']; }
            $out[$name] = $item;
        }
        return $out ? (object) $out : new \stdClass();
    }

    /**
     * Check the API form (a decoded JSON object name => definition) and return the stored map. A definition without a type is a
     * textfield; `value` is required and must be a scalar; options are a list of {text, value}.
     * @param array|\stdClass $api
     */
    public static function fromApi($api) {
        $api = json_decode(json_encode($api), true);
        if (!is_array($api) || ($api && array_keys($api) === range(0, count($api) - 1))) {
            throw new ApiException('validation_failed', "Field 'properties' must be an object of name => {type, value, desc?, options?, lexicon?, area?}.");
        }
        $out = array();
        foreach ($api as $name => $definition) {
            $name = (string) $name;
            if ($name === '') { throw new ApiException('validation_failed', 'Property names must not be empty.'); }
            if (!is_array($definition) || !array_key_exists('value', $definition) || (!is_scalar($definition['value']) && $definition['value'] !== null)) {
                throw new ApiException('validation_failed', "Property '{$name}' must be an object with a scalar 'value'.");
            }
            foreach (array_diff(array_keys($definition), array('type', 'value', 'desc', 'options', 'lexicon', 'area')) as $unknown) {
                throw new ApiException('validation_failed', "Property '{$name}': unknown key '{$unknown}' (use type, value, desc, options, lexicon, area).");
            }
            $type = isset($definition['type']) ? $definition['type'] : 'textfield';
            if (!is_string($type) || $type === '') { throw new ApiException('validation_failed', "Property '{$name}': type must be a non-empty string."); }
            foreach (array('desc', 'lexicon', 'area') as $part) {
                if (isset($definition[$part]) && !is_string($definition[$part])) { throw new ApiException('validation_failed', "Property '{$name}': {$part} must be a string."); }
            }
            $options = array();
            if (isset($definition['options'])) {
                if (!is_array($definition['options'])) { throw new ApiException('validation_failed', "Property '{$name}': options must be a list of {text, value}."); }
                foreach ($definition['options'] as $option) {
                    if (!is_array($option) || !isset($option['value']) || !is_scalar($option['value']) || (isset($option['text']) && !is_string($option['text']))) {
                        throw new ApiException('validation_failed', "Property '{$name}': every option must be an object {text, value}.");
                    }
                    $options[] = array('text' => isset($option['text']) ? $option['text'] : (string) $option['value'], 'value' => $option['value']);
                }
            }
            $value = $definition['value'] === null ? '' : $definition['value'];
            // As modElement::setProperties() does: a number given to a boolean property is a boolean.
            if ($type === 'combo-boolean' && is_numeric($value)) { $value = (bool) $value; }
            $out[$name] = array(
                'name' => $name, 'desc' => isset($definition['desc']) ? $definition['desc'] : '', 'type' => $type, 'options' => $options, 'value' => $value,
                'lexicon' => isset($definition['lexicon']) && $definition['lexicon'] !== '' ? $definition['lexicon'] : null, 'area' => isset($definition['area']) ? $definition['area'] : '',
            );
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
