<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;

/**
 * A miniShop2 vendor (msVendor): the manufacturer a product points at with its `vendor` column (the id). Created, changed and
 * removed with miniShop2's own processors (mgr/settings/vendor/*), journaled and undoable. A vendor that products still use
 * is not deleted: the products would keep an id that names nothing.
 */
final class Ms2VendorKind extends AbstractMs2Kind {
    public function name() { return 'ms2_vendor'; }
    public function label() { return 'miniShop2 vendor'; }
    public function modelClass() { return 'msVendor'; }
    public function processorPath() { return 'mgr/settings/vendor/'; }
    public function searchColumns() { return array('name', 'country'); }
    public function writeScope() { return Scope::CONTENT; }

    public function fields() {
        return array(
            'id'          => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'        => array('type' => 'string', 'list' => true, 'required' => true, 'description' => 'Name of the vendor, unique.'),
            'resource'    => array('type' => 'integer', 'description' => 'Id of the page about the vendor, 0 for none.'),
            'country'     => array('type' => 'string', 'list' => true),
            'logo'        => array('type' => 'string', 'description' => 'Path of the logo image.'),
            'address'     => array('type' => 'string'),
            'phone'       => array('type' => 'string'),
            'fax'         => array('type' => 'string'),
            'email'       => array('type' => 'string'),
            'description' => array('type' => 'string'),
            'properties'  => array('type' => 'object', 'json' => true, 'description' => 'Free-form settings as a JSON object.'),
        );
    }

    /** Products of the shop that carry this vendor. */
    public static function productCount(\modX $modx, $id) {
        return (int) $modx->getCount('msProductData', array('vendor' => (int) $id));
    }

    public function assertObjectWritable(\modX $modx, $object, $op) {
        if ($op !== 'delete') { return; }
        $products = self::productCount($modx, $object->get('id'));
        if ($products > 0) {
            throw new ApiException('validation_failed', "Vendor {$object->get('id')} ('{$object->get('name')}') is used by {$products} product(s). "
                . "Give those products another vendor first (modx_update on the product, relation ms2_data: {vendor: id}).");
        }
    }
}
