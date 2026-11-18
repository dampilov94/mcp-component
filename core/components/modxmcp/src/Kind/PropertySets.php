<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;

/**
 * The links between property sets and elements (modElementPropertySet), written through the core processors
 * element/propertyset/addelement and removeelement. The same rows are the `elements` of a property set and the
 * `property_sets` of an element, so both views stay consistent.
 */
final class PropertySets {
    /** Kind name => model class of the elements a property set can be attached to. */
    const ELEMENTS = array('chunk' => 'modChunk', 'snippet' => 'modSnippet', 'template' => 'modTemplate', 'plugin' => 'modPlugin', 'tv' => 'modTemplateVar');

    public static function kindName($class) {
        $name = array_search($class, self::ELEMENTS, true);
        return $name === false ? (string) $class : $name;
    }

    /** Ids of the property sets attached to an element, ascending. */
    public static function setsOf(\modX $modx, $class, $id) {
        $ids = array();
        foreach ($modx->getCollection('modElementPropertySet', array('element' => (int) $id, 'element_class' => $class)) as $row) { $ids[] = (int) $row->get('property_set'); }
        sort($ids);
        return $ids;
    }

    /** [kind name, id] of the elements a property set is attached to, ordered. */
    public static function elementsOf(\modX $modx, $setId) {
        $out = array();
        foreach ($modx->getCollection('modElementPropertySet', array('property_set' => (int) $setId)) as $row) {
            $out[] = array(self::kindName($row->get('element_class')), (int) $row->get('element'));
        }
        usort($out, function ($a, $b) { return strcmp($a[0], $b[0]) ?: $a[1] <=> $b[1]; });
        return $out;
    }

    public static function link(\modX $modx, $setId, $class, $id) {
        Objects::process($modx, 'element/propertyset/addelement', array('propertyset' => (int) $setId, 'element' => (int) $id, 'element_class' => $class));
    }

    public static function unlink(\modX $modx, $setId, $class, $id) {
        Objects::process($modx, 'element/propertyset/removeelement', array('propertyset' => (int) $setId, 'element' => (int) $id, 'element_class' => $class));
    }

    /** Check a list of property set ids and return it without repeats. */
    private static function checkSetIds(\modX $modx, $value) {
        $ids = array();
        foreach ((array) $value as $item) {
            if (!is_int($item) || $item < 1) { throw new ApiException('validation_failed', 'property_sets must be a list of property set ids.'); }
            if (!$modx->getCount('modPropertySet', array('id' => $item))) { throw new ApiException('validation_failed', "Property set {$item} does not exist."); }
            $ids[$item] = true;
        }
        $ids = array_keys($ids);
        sort($ids);
        return $ids;
    }

    /** Make the property sets of an element exactly this list. */
    public static function writeForElement(\modX $modx, $class, $id, $value) {
        $wanted = self::checkSetIds($modx, $value);
        $current = self::setsOf($modx, $class, $id);
        foreach (array_diff($current, $wanted) as $setId) { self::unlink($modx, $setId, $class, $id); }
        foreach (array_diff($wanted, $current) as $setId) { self::link($modx, $setId, $class, $id); }
    }

    /** The property sets whose `elements` change when an element's list is replaced. */
    public static function affectedByElement(\modX $modx, $class, $id, $value) {
        $ids = array_unique(array_merge(self::setsOf($modx, $class, $id), self::checkSetIds($modx, $value)));
        $out = array();
        foreach ($ids as $setId) { $out[] = array('property_set', $setId); }
        return $out;
    }

    /**
     * Check a list of {kind, id} and return it as [kind name, id] pairs without repeats. With a context, the token must be able to
     * write each kind (scope and groups).
     */
    public static function checkElements(\modX $modx, $value, Context $context = null) {
        $out = array();
        foreach ((array) $value as $item) {
            if ($item instanceof \stdClass) { $item = (array) $item; }
            if (!is_array($item) || array_diff(array_keys($item), array('kind', 'id')) || !isset($item['kind'], $item['id']) || !is_string($item['kind']) || !is_int($item['id']) || $item['id'] < 1) {
                throw new ApiException('validation_failed', 'elements must be a list of {kind, id} objects.');
            }
            if (!isset(self::ELEMENTS[$item['kind']])) {
                throw new ApiException('validation_failed', "Element kind '{$item['kind']}' cannot have property sets. Kinds: " . implode(', ', array_keys(self::ELEMENTS)) . '.');
            }
            if ($context) { Objects::kind($context, $item['kind'], true); }
            if (!$modx->getCount(self::ELEMENTS[$item['kind']], array('id' => $item['id']))) {
                throw new ApiException('validation_failed', "No {$item['kind']} with id {$item['id']}.");
            }
            $out[$item['kind'] . ':' . $item['id']] = array($item['kind'], $item['id']);
        }
        return array_values($out);
    }

    /** Make the elements of a property set exactly this list ([kind, id] pairs from checkElements()). */
    public static function writeElements(\modX $modx, $setId, array $wanted) {
        $key = function ($pair) { return $pair[0] . ':' . $pair[1]; };
        $current = array();
        foreach (self::elementsOf($modx, $setId) as $pair) { $current[$key($pair)] = $pair; }
        $target = array();
        foreach ($wanted as $pair) { $target[$key($pair)] = $pair; }
        foreach (array_diff_key($current, $target) as $pair) {
            $class = isset(self::ELEMENTS[$pair[0]]) ? self::ELEMENTS[$pair[0]] : $pair[0];
            self::unlink($modx, $setId, $class, $pair[1]);
        }
        foreach (array_diff_key($target, $current) as $pair) { self::link($modx, $setId, self::ELEMENTS[$pair[0]], $pair[1]); }
    }
}
