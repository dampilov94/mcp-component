<?php
namespace ModxMCP;

/**
 * Builders and a validator for the JSON Schema subset used by action contracts:
 * type, properties, required, additionalProperties (boolean), items, enum, minimum, maximum,
 * minLength, maxLength, maxItems, pattern. Action schemas must stay inside this subset so the
 * server validates exactly what the manifest advertises.
 *
 * Values are validated in their json_decode($json, false) form: objects are stdClass,
 * arrays are PHP lists.
 */
final class Schema {
    /** Object schema; an empty property map still encodes as a JSON object. */
    public static function obj(array $properties = array(), array $required = array(), $additional = false) {
        $schema = array('type' => 'object', 'properties' => $properties ? $properties : new \stdClass());
        if ($required) { $schema['required'] = array_values($required); }
        $schema['additionalProperties'] = (bool) $additional;
        return $schema;
    }

    public static function str($description = null, array $extra = array()) {
        return self::typed('string', $description, $extra);
    }

    public static function int($description = null, array $extra = array()) {
        return self::typed('integer', $description, $extra);
    }

    public static function bool($description = null) {
        return self::typed('boolean', $description, array());
    }

    public static function arr(array $items, $description = null, array $extra = array()) {
        return self::typed('array', $description, array('items' => $items) + $extra);
    }

    private static function typed($type, $description, array $extra) {
        $schema = array('type' => $type);
        if ($description !== null) { $schema['description'] = $description; }
        return $schema + $extra;
    }

    /** Returns null when valid, otherwise a message naming the first failing path. */
    public static function validate(array $schema, $value, $path = 'args') {
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $matched = false;
            foreach ($types as $type) {
                if (self::isType($type, $value)) { $matched = true; break; }
            }
            if (!$matched) { return $path . ' must be ' . implode(' or ', $types) . '.'; }
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return $path . ' must be one of: ' . implode(', ', array_map('strval', $schema['enum'])) . '.';
        }
        if (is_string($value)) {
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if (isset($schema['minLength']) && $length < $schema['minLength']) { return $path . ' is too short.'; }
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) { return $path . ' is too long.'; }
            if (isset($schema['pattern']) && !preg_match('~' . str_replace('~', '\~', $schema['pattern']) . '~u', $value)) {
                return $path . ' has an invalid format.';
            }
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) { return $path . ' must be at least ' . $schema['minimum'] . '.'; }
            if (isset($schema['maximum']) && $value > $schema['maximum']) { return $path . ' must be at most ' . $schema['maximum'] . '.'; }
        }
        if (is_array($value)) {
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) { return $path . ' has too many items.'; }
            if (isset($schema['items'])) {
                foreach ($value as $index => $item) {
                    $error = self::validate($schema['items'], $item, $path . '[' . $index . ']');
                    if ($error !== null) { return $error; }
                }
            }
        }
        if ($value instanceof \stdClass) {
            $properties = isset($schema['properties']) ? (array) $schema['properties'] : array();
            foreach (isset($schema['required']) ? $schema['required'] : array() as $name) {
                if (!property_exists($value, $name)) { return $path . '.' . $name . ' is required.'; }
            }
            foreach (get_object_vars($value) as $name => $item) {
                if (isset($properties[$name])) {
                    $error = self::validate($properties[$name], $item, $path . '.' . $name);
                    if ($error !== null) { return $error; }
                } elseif (isset($schema['additionalProperties']) && $schema['additionalProperties'] === false) {
                    return $path . '.' . $name . ' is not a known argument.';
                }
            }
        }
        return null;
    }

    private static function isType($type, $value) {
        switch ($type) {
            case 'object':  return $value instanceof \stdClass;
            case 'array':   return is_array($value);
            case 'string':  return is_string($value);
            case 'integer': return is_int($value);
            case 'number':  return is_int($value) || is_float($value);
            case 'boolean': return is_bool($value);
            case 'null':    return $value === null;
        }
        return false;
    }
}
