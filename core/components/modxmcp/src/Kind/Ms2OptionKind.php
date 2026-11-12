<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Integration\MiniShop2;
use ModxMCP\Schema;
use ModxMCP\Write\Transaction;

/**
 * A miniShop2 product option (msOption): the declaration of an option that products may carry values for.
 * The values themselves are the relation ms2_options of a product; this kind owns the option and its links to
 * categories. Created, changed and removed with miniShop2's own processors (mgr/settings/option/*).
 */
final class Ms2OptionKind extends Kind {
    /** Products that one operation may journal as a side effect. */
    const PRODUCT_LIMIT = 1000;

    private $context;

    public function name() { return 'ms2_option'; }
    public function label() { return 'miniShop2 product option'; }
    public function modelClass() { return 'msOption'; }
    public function group() { return MiniShop2::GROUP; }
    public function nameField() { return 'key'; }
    public function processorPath() { return 'mgr/settings/option/'; }
    public function processorExtras($op) { return array(); }

    public function attach(Context $context) { $this->context = $context; }

    /** Registers the models. Reading the journal of an option must work without miniShop2, so a missing add-on is not an error here. */
    public function prepare(\modX $modx) {
        try { MiniShop2::load($modx); } catch (\RuntimeException $e) { /* assertUndoable() and the actions report it where it matters */ }
    }

    /** An option cannot be put back (or taken away) without the miniShop2 models. */
    public function assertUndoable(\modX $modx, $key, $target) {
        try { MiniShop2::load($modx); } catch (\RuntimeException $e) { throw new ApiException('not_reversible', 'miniShop2 is not installed; its options cannot be restored.'); }
    }

    /** The processors are miniShop2's own. */
    public function processorOptions() {
        return $this->context ? array('processors_path' => MiniShop2::processorsPath($this->context->modx)) : array();
    }

    public function searchColumns() { return array('key', 'caption'); }

    public function fields() {
        return array(
            'id'           => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'key'          => array('type' => 'string', 'list' => true, 'required' => true, 'immutable' => true,
                'description' => 'Option key, unique; letters, digits, - and _ (a dot becomes _); not a name of a resource or product field. '
                    . 'It cannot be changed: it names the option values of every product.'),
            'caption'      => array('type' => 'string', 'list' => true, 'description' => 'Label shown in the manager.'),
            'description'  => array('type' => 'string'),
            'measure_unit' => array('type' => 'string', 'description' => 'Unit shown after the value.'),
            'category'     => array('type' => 'integer', 'description' => 'Id of the element category (modCategory) the option is filed under, 0 for none.'),
            'type'         => array('type' => 'string', 'list' => true, 'description' => 'Option type: ' . implode(', ', MiniShop2::OPTION_TYPES) . '. Default textfield.'),
            'properties'   => array('type' => 'object', 'json' => true, 'description' => 'Type-specific settings as a JSON object, e.g. the choices of a combo box.'),
        );
    }

    public function relationSpecs() {
        return array(
            'categories' => array(
                'class' => 'msCategoryOption', 'fk' => 'option_id', 'present' => array('category_id', 'active', 'required', 'rank', 'value'),
                'description' => 'miniShop2 categories the option is assigned to. Changed with the operations assign_to_category and remove_from_category.',
            ),
        );
    }

    /** The create processors take the columns of the manager form; two of them have no database default. */
    public function processorData($op, array $data) {
        if ($op === 'create') { $data += array('category' => 0, 'type' => 'textfield'); }
        return $data;
    }

    public function assertColumns(array $columns, $creating) {
        if (isset($columns['type']) && !in_array($columns['type'], MiniShop2::OPTION_TYPES, true)) {
            throw new ApiException('validation_failed', "Unknown option type '{$columns['type']}'. Types: " . implode(', ', MiniShop2::OPTION_TYPES) . '.');
        }
    }

    /** Resource ids of the products whose parent is the category. */
    private function productsOf(\modX $modx, array $categories) {
        if (!$categories) { return array(); }
        $ids = array();
        foreach ($modx->getCollection('modResource', array('parent:IN' => $categories, 'class_key' => 'msProduct')) as $product) { $ids[] = (int) $product->get('id'); }
        if (count($ids) > self::PRODUCT_LIMIT) {
            throw new ApiException('validation_failed', 'More than ' . self::PRODUCT_LIMIT . ' products would change their option values; do it in the manager.');
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** Products among $products that have a value for the option key. */
    private function withValue(\modX $modx, $optionKey, array $products) {
        if (!$products) { return array(); }
        $have = array();
        foreach ($modx->getCollection('msProductOption', array('key' => $optionKey, 'product_id:IN' => $products)) as $row) { $have[(int) $row->get('product_id')] = true; }
        return array_keys($have);
    }

    /**
     * Deleting an option deletes its category links; msCategoryOption::remove() deletes the option values of the products
     * that sit in each of those categories. Those products are journaled with the option. (Values of products elsewhere
     * stay: xPDO cannot remove rows without a primary key.)
     */
    public function deleteCascade(\modX $modx, $key) {
        $option = $this->findByKey($modx, $key);
        if (!$option) { return array(); }
        $categories = array();
        foreach ($modx->getCollection('msCategoryOption', array('option_id' => $key)) as $link) { $categories[] = (int) $link->get('category_id'); }
        $out = array();
        foreach ($this->withValue($modx, (string) $option->get('key'), $this->productsOf($modx, $categories)) as $product) { $out[] = array('resource', $product); }
        return $out;
    }

    public function operations() {
        $category = Schema::int('Id of a miniShop2 category (a resource with class_key msCategory).', array('minimum' => 1));
        return array(
            'assign_to_category' => array('description' => 'Assign the option to a category. Products of the category that have no value for it get the default value.',
                'read_only' => false, 'needs_object' => true, 'handler' => 'opAssign',
                'input' => Schema::obj(array(
                    'category' => $category,
                    'active' => Schema::bool('Offered on the product form; default true.'),
                    'required' => Schema::bool('Must be filled in; default false.'),
                    'value' => Schema::str('Default value for products without one.'),
                ), array('category'))),
            'remove_from_category' => array('description' => 'Take the option out of a category. The option values of the products of that category are deleted, as in the manager.',
                'read_only' => false, 'needs_object' => true, 'handler' => 'opRemove',
                'input' => Schema::obj(array('category' => $category), array('category'))),
        );
    }

    private function touchResources(Context $context, Transaction $tx, array $ids) {
        $resource = $context->kinds->get('resource');
        foreach ($ids as $id) { $tx->touch($resource, $id); }
    }

    private function link(\modX $modx, $optionId, $categoryId) {
        return $modx->getObject('msCategoryOption', array('option_id' => $optionId, 'category_id' => $categoryId), false);
    }

    public function opAssign(Context $context, Transaction $tx, $object, array $args): array {
        $modx = $context->modx;
        $optionId = (int) $object->get('id');
        $categoryId = (int) $args['category'];
        if (!$modx->getCount('modResource', array('id' => $categoryId, 'class_key' => 'msCategory'))) {
            throw new ApiException('validation_failed', "Resource {$categoryId} is not a miniShop2 category (class_key msCategory).");
        }
        if ($this->link($modx, $optionId, $categoryId)) {
            throw new ApiException('validation_failed', "Option '{$object->get('key')}' is already assigned to category {$categoryId}.");
        }
        // The new link gives every product of the category without a value the default value.
        $products = $this->productsOf($modx, array($categoryId));
        $this->touchResources($context, $tx, array_diff($products, $this->withValue($modx, (string) $object->get('key'), $products)));
        $data = array('option_id' => $optionId, 'category_id' => $categoryId);
        foreach (array('active', 'required') as $flag) { if (isset($args[$flag])) { $data[$flag] = $args[$flag] ? 1 : 0; } }
        if (isset($args['value'])) { $data['value'] = $args['value']; }
        Objects::process($modx, 'mgr/settings/option/assign', $data, $this->processorOptions());
        return array('category' => $categoryId);
    }

    public function opRemove(Context $context, Transaction $tx, $object, array $args): array {
        $modx = $context->modx;
        $optionId = (int) $object->get('id');
        $categoryId = (int) $args['category'];
        $link = $this->link($modx, $optionId, $categoryId);
        if (!$link) { throw new ApiException('validation_failed', "Option '{$object->get('key')}' is not assigned to category {$categoryId}."); }
        // The processor renumbers the links that come after this one; their options change too.
        foreach ($modx->getCollection('msCategoryOption', array('category_id' => $categoryId)) as $next) {
            if ((int) $next->get('rank') > (int) $link->get('rank')) { $tx->touch($this, (int) $next->get('option_id')); }
        }
        $this->touchResources($context, $tx, $this->withValue($modx, (string) $object->get('key'), $this->productsOf($modx, array($categoryId))));
        Objects::process($modx, 'mgr/category/option/delete', array('option_id' => $optionId, 'category_id' => $categoryId), $this->processorOptions());
        return array('category' => $categoryId);
    }

    /**
     * Restoring a category link saves an msCategoryOption, and its save() gives every product of the category without a
     * value the default value. A restore puts back what was recorded and nothing more: the values that save() added to
     * products that had none are taken away again.
     */
    public function restoreRelation(\modX $modx, $name, array $spec, $key, array $rows) {
        if ($name !== 'categories') { return false; }
        $modx->removeCollection('msCategoryOption', array('option_id' => $key));
        $option = $this->findByKey($modx, $key);
        foreach ($rows as $row) {
            $products = $this->productsOf($modx, array((int) $row['category_id']));
            $had = $this->withValue($modx, (string) $option->get('key'), $products);
            $link = $modx->newObject('msCategoryOption');
            $link->fromArray($row, '', true, true);
            if (!$link->save()) { throw new \RuntimeException("Cannot restore a category link of option {$key}."); }
            $added = array_values(array_diff($products, $had));
            if ($added) { $modx->removeCollection('msProductOption', array('key' => (string) $option->get('key'), 'product_id:IN' => $added)); }
        }
        return true;
    }
}
