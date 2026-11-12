<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Integration\MiniShop2;

/**
 * A link between two products (msProductLink: link type, master, slave; no id of its own), addressed as
 * "<link>:<master>:<slave>". Created and removed with miniShop2's productlink processors, which store more than the one row
 * asked for: many_to_many adds both directions and every pair among the two groups, one_to_one both directions, many_to_one
 * stores the row the other way round (slave to master); removing a many_to_many link takes the slave out of the group
 * altogether (every row where it is master or slave). Each such row is touched before the processor runs, so it is journaled.
 * Create and delete only: a link has nothing to change.
 */
final class Ms2ProductLinkKind extends AbstractMs2Kind {
    public function name() { return 'ms2_product_link'; }
    public function label() { return 'miniShop2 product link'; }
    public function modelClass() { return 'msProductLink'; }
    public function processorPath() { return 'mgr/product/productlink/'; }
    public function nameField() { return 'link'; }
    public function hasNames() { return false; }
    public function uniqueNames() { return false; }
    public function searchColumns() { return array('master', 'slave'); }
    public function defaultSort() { return array(array('link', 'ASC'), array('master', 'ASC'), array('slave', 'ASC')); }

    public function fields() {
        return array(
            'link'   => array('type' => 'integer', 'list' => true, 'required' => true, 'immutable' => true, 'description' => 'Id of the link type (kind ms2_link).'),
            'master' => array('type' => 'integer', 'list' => true, 'required' => true, 'immutable' => true, 'description' => 'Id of the master product (class_key msProduct).'),
            'slave'  => array('type' => 'integer', 'list' => true, 'required' => true, 'immutable' => true,
                'description' => 'Id of the slave product. For a many_to_one link type miniShop2 stores the row the other way round (this field holds the product you named as master).'),
            'label'  => array('type' => 'string', 'list' => true, 'derived' => true, 'computed' => true,
                'description' => '"<link name>: <master title> → <slave title>".'),
        );
    }

    // ---- the key "<link>:<master>:<slave>" ----

    public function keyType() { return 'string'; }
    public function normalizeKey($raw) { return implode(':', $this->split((string) $raw)); }

    /** @return int[] link, master, slave */
    private function split($key) {
        if (!preg_match('/^(\d{1,10}):(\d{1,10}):(\d{1,10})$/', $key, $match) || !(int) $match[1] || !(int) $match[2] || !(int) $match[3]) {
            throw new ApiException('validation_failed', "A product link is addressed as '<link>:<master>:<slave>' (ids), e.g. '2:15:18'.");
        }
        return array((int) $match[1], (int) $match[2], (int) $match[3]);
    }

    public function keyCriteria($key) {
        list($link, $master, $slave) = $this->split((string) $key);
        return array('link' => $link, 'master' => $master, 'slave' => $slave);
    }

    public function keyOf($object) { return (int) $object->get('link') . ':' . (int) $object->get('master') . ':' . (int) $object->get('slave'); }
    public function keyColumn() { return 'link'; }
    public function processorKey($key) { return $this->keyCriteria($key); }

    private static function keyFor($link, $master, $slave) { return (int) $link . ':' . (int) $master . ':' . (int) $slave; }

    public function value($object, $field) {
        if ($field !== 'label') { return parent::value($object, $field); }
        $modx = $object->xpdo;
        $link = $modx->getObject('msLink', array('id' => (int) $object->get('link')), false);
        $title = function ($id) use ($modx) {
            $product = $modx->getObject('modResource', array('id' => (int) $id), false);
            return $product ? (string) $product->get('pagetitle') : '#' . (int) $id;
        };
        return ($link ? (string) $link->get('name') : '#' . (int) $object->get('link')) . ': ' . $title($object->get('master')) . ' → ' . $title($object->get('slave'));
    }

    // ---- what a link does and does not allow ----

    public function assertColumns(array $columns, $creating) {
        foreach (array('link', 'master', 'slave') as $column) {
            if (isset($columns[$column]) && (int) $columns[$column] < 1) { throw new ApiException('validation_failed', "Field '{$column}' must be an id (1 or more)."); }
        }
    }

    public function assertObjectWritable(\modX $modx, $object, $op) {
        if ($op === 'update') { throw new ApiException('validation_failed', 'A product link has nothing to change: create the link you want and delete the one you do not.'); }
        if ($op === 'duplicate') { throw new ApiException('validation_failed', 'A product link cannot be copied: create the link you want with modx_create.'); }
    }

    /** Undo puts rows back directly; both products must still be products. */
    public function assertUndoable(\modX $modx, $key, $target) {
        parent::assertUndoable($modx, $key, $target);
        if ($target === null) { return; }
        foreach (array('master', 'slave') as $column) {
            $id = isset($target['fields'][$column]) ? (int) $target['fields'][$column] : 0;
            if (!$modx->getCount('modResource', array('id' => $id, 'class_key' => 'msProduct'))) {
                throw new ApiException('not_reversible', "Product {$id} of link {$key} is no longer a miniShop2 product.");
            }
        }
    }

    private function linkType(\modX $modx, $link) {
        $object = $modx->getObject('msLink', array('id' => (int) $link), false);
        return $object ? (string) $object->get('type') : null;
    }

    /** Link type exists, both ids are products, they differ. */
    private function validate(\modX $modx, array $columns) {
        $link = (int) $columns['link'];
        $master = (int) $columns['master'];
        $slave = (int) $columns['slave'];
        if ($this->linkType($modx, $link) === null) { throw new ApiException('validation_failed', "There is no link type {$link} (kind ms2_link)."); }
        if ($master === $slave) { throw new ApiException('validation_failed', 'A product cannot be linked to itself.'); }
        foreach (array('master' => $master, 'slave' => $slave) as $role => $id) {
            if (!$modx->getCount('modResource', array('id' => $id, 'class_key' => 'msProduct'))) {
                throw new ApiException('validation_failed', "The {$role} {$id} is not a miniShop2 product (a resource with class_key msProduct).");
            }
        }
    }

    /** Rows of the link type as a set of "master:slave". */
    private function pairs(\modX $modx, $link, array $masters) {
        $out = array();
        if (!$masters) { return $out; }
        foreach ($modx->getCollection('msProductLink', array('link' => $link, 'master:IN' => $masters)) as $row) {
            $out[(int) $row->get('master') . ':' . (int) $row->get('slave')] = true;
        }
        return $out;
    }

    /**
     * Keys of the rows the create processor stores for the link, as "<link>:<master>:<slave>" in the order they come: the row the
     * request names first. Includes rows that already exist (the processor writes them again without a change).
     */
    private function planCreate(\modX $modx, array $columns) {
        $link = (int) $columns['link'];
        $master = (int) $columns['master'];
        $slave = (int) $columns['slave'];
        $type = $this->linkType($modx, $link);
        $pairs = array();
        switch ($type) {
            case 'many_to_many':
                $pairs = array(array($master, $slave), array($slave, $master));
                $known = $this->pairs($modx, $link, array($master, $slave));
                // The slaves of both products (after the two rows above) are all linked to each other.
                $members = array($slave => true, $master => true);
                foreach ($known as $pair => $true) { $members[(int) substr($pair, strpos($pair, ':') + 1)] = true; }
                $members = array_keys($members);
                $existing = $this->pairs($modx, $link, $members);
                $new = 0;
                foreach ($members as $a) {
                    foreach ($members as $b) {
                        if ($a !== $b) {
                            $pairs[] = array($a, $b);
                            if (!isset($existing[$a . ':' . $b])) { $new++; }
                        }
                    }
                }
                if ($new > MiniShop2::ROW_LIMIT) {
                    throw new ApiException('validation_failed', 'This link would add more than ' . MiniShop2::ROW_LIMIT . ' rows to a many_to_many group; split the group in the manager.');
                }
                break;
            case 'one_to_one':
                $pairs = array(array($master, $slave), array($slave, $master));
                break;
            case 'many_to_one':
                $pairs = array(array($slave, $master));
                break;
            case 'one_to_many':
                $pairs = array(array($master, $slave));
                break;
        }
        $out = array();
        foreach ($pairs as $pair) { $out[self::keyFor($link, $pair[0], $pair[1])] = true; }
        return array_keys($out);
    }

    /** Every row of a link the create processor would store besides the first one is touched, so it is journaled and locked. */
    public function alsoAffected(\modX $modx, $id, array $columns, $op) {
        if ($op !== 'create' || !isset($columns['link'], $columns['master'], $columns['slave'])) { return array(); }
        $this->validate($modx, $columns);
        return $this->planCreate($modx, $columns);
    }

    public function beforeCreate(Context $context, array $columns) {
        $modx = $context->modx;
        $this->validate($modx, $columns);
        $planned = $this->planCreate($modx, $columns);
        $requested = self::keyFor($columns['link'], $columns['master'], $columns['slave']);
        $stored = $planned ? $planned[0] : $requested;
        // Only rows that are not there yet are news.
        $fresh = array();
        foreach ($planned as $key) {
            if ($key !== $stored && !$modx->getCount('msProductLink', $this->keyCriteria($key))) { $fresh[] = $key; }
        }
        return function ($tx, $id) use ($context, $fresh, $requested, $stored) {
            $others = $fresh;
            if ($stored !== $requested) { $context->warnings[] = "miniShop2 stores a link of this type from the slave to the master: the row is {$stored}."; }
            if ($others) {
                $context->warnings[] = 'The link type also stores ' . count($others) . ' more row(s) (all journaled): ' . implode(', ', array_slice($others, 0, 10)) . (count($others) > 10 ? ', …' : '') . '.';
            }
        };
    }

    public function createdKey(array $response, array $columns) {
        $modx = $this->context ? $this->context->modx : null;
        $planned = $modx ? $this->planCreate($modx, $columns) : array();
        if ($planned) { return $planned[0]; }
        return self::keyFor($columns['link'], $columns['master'], $columns['slave']);
    }

    /**
     * The other rows the remove processor deletes with this one: many_to_many takes the slave out of the group (every row where
     * it is master or slave), one_to_one removes the reverse row too. They are touched before the delete.
     */
    public function deleteCascade(\modX $modx, $key) {
        list($link, $master, $slave) = $this->split((string) $key);
        $type = $this->linkType($modx, $link);
        $rows = array();
        if ($type === 'many_to_many') {
            foreach (array(array('master' => $slave), array('slave' => $slave)) as $where) {
                foreach ($modx->getCollection('msProductLink', array('link' => $link) + $where) as $row) { $rows[self::keyFor($link, $row->get('master'), $row->get('slave'))] = true; }
            }
        } elseif ($type === 'one_to_one') {
            if ($modx->getCount('msProductLink', array('link' => $link, 'master' => $slave, 'slave' => $master))) { $rows[self::keyFor($link, $slave, $master)] = true; }
        }
        unset($rows[self::keyFor($link, $master, $slave)]);
        if (count($rows) > MiniShop2::ROW_LIMIT) {
            throw new ApiException('validation_failed', 'Removing this link would remove more than ' . MiniShop2::ROW_LIMIT . ' rows; do it in the manager.');
        }
        $keys = array_keys($rows);
        if ($keys && $this->context) {
            $this->context->warnings[] = "A {$type} link also removes " . count($keys) . ' more row(s) (all journaled): ' . implode(', ', array_slice($keys, 0, 10)) . (count($keys) > 10 ? ', …' : '') . '.';
        }
        $out = array();
        foreach ($keys as $other) { $out[] = array($this->name(), $other); }
        return $out;
    }
}
