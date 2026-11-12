<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Integration\MiniShop2;

/**
 * A miniShop2 product link type (msLink): "related products", "colour variants" and the like. Its type fixes how the links
 * between products are stored (see ms2_product_link). Created, changed and removed with miniShop2's own processors
 * (mgr/settings/link/*). Removing a type removes its product links with it (xPDO composite), so they are journaled
 * through deleteCascade() as deleted ms2_product_link objects.
 */
final class Ms2LinkKind extends AbstractMs2Kind {
    public function name() { return 'ms2_link'; }
    public function label() { return 'miniShop2 product link type'; }
    public function modelClass() { return 'msLink'; }
    public function processorPath() { return 'mgr/settings/link/'; }
    public function searchColumns() { return array('name', 'description'); }

    public function fields() {
        return array(
            'id'          => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'        => array('type' => 'string', 'list' => true, 'required' => true, 'description' => 'Name of the link type, unique.'),
            'type'        => array('type' => 'string', 'list' => true, 'required' => true, 'immutable' => true,
                'description' => 'How links are stored: ' . implode(', ', MiniShop2::LINK_TYPES) . '. many_to_many: all products of a group are linked to each other; '
                    . 'one_to_one: two products linked both ways; one_to_many: the master points to its slaves; many_to_one: stored the other way round '
                    . '(the slave points to the master). Fixed after creation (the update processor ignores it).'),
            'description' => array('type' => 'string'),
        );
    }

    public function assertColumns(array $columns, $creating) {
        if (isset($columns['type']) && !in_array($columns['type'], MiniShop2::LINK_TYPES, true)) {
            throw new ApiException('validation_failed', "Unknown link type '{$columns['type']}'. Types: " . implode(', ', MiniShop2::LINK_TYPES) . '.');
        }
    }

    /** The product links of the type, which MODX removes with it. */
    public function deleteCascade(\modX $modx, $key) {
        $rows = $modx->getCollection('msProductLink', array('link' => (int) $key));
        if (count($rows) > MiniShop2::ROW_LIMIT) {
            throw new ApiException('validation_failed', 'The link type holds more than ' . MiniShop2::ROW_LIMIT . ' product links; remove it in the manager.');
        }
        $out = array();
        foreach ($rows as $row) { $out[] = array('ms2_product_link', (int) $row->get('link') . ':' . (int) $row->get('master') . ':' . (int) $row->get('slave')); }
        return $out;
    }
}
