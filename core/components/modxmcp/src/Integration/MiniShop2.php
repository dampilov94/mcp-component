<?php
namespace ModxMCP\Integration;

use ModxMCP\Context;

/**
 * What the component needs to know about miniShop2 (verified against 3.0.7): when it can be used, how its xPDO
 * package is registered, and the shape of its product data.
 */
final class MiniShop2 {
    const GROUP = 'minishop2';
    const PACKAGE = 'minishop2';

    /**
     * Columns of msProductData (map of 3.0.7) except the key: column => [API type, read only].
     * Types: string, float, integer, boolean, list (a JSON column holding a list of strings).
     * image and thumb are maintained by the product gallery.
     */
    const DATA_COLUMNS = array(
        'article'   => array('string', false),
        'price'     => array('float', false),
        'old_price' => array('float', false),
        'weight'    => array('float', false),
        'image'     => array('string', true),
        'thumb'     => array('string', true),
        'vendor'    => array('integer', false),
        'made_in'   => array('string', false),
        'new'       => array('boolean', false),
        'popular'   => array('boolean', false),
        'favorite'  => array('boolean', false),
        'tags'      => array('list', false),
        'color'     => array('list', false),
        'size'      => array('list', false),
        'source'    => array('integer', false),
    );

    /**
     * msProductData::save() copies its three JSON columns into msProductOption rows with these keys (and deletes
     * every row with such a key first). Those rows are not options of their own.
     */
    const MIRRORED_OPTIONS = array('tags', 'color', 'size');

    /** Option types miniShop2 3.0.7 ships (processors/mgr/settings/option/types). */
    const OPTION_TYPES = array('checkbox', 'combo-boolean', 'combo-multiple', 'combo-options', 'combobox', 'datefield', 'numberfield', 'textarea', 'textfield');

    /** Link types of msLink (the cases of the productlink processors; the map has no list). */
    const LINK_TYPES = array('many_to_many', 'one_to_many', 'many_to_one', 'one_to_one');

    /** Rows of product links that one operation may journal as a side effect (link-type cascades, many_to_many closures). */
    const ROW_LIMIT = 1000;

    /** The integration is installed, its code is there and the group is not switched off for the site. */
    public static function siteAvailable(Context $context) {
        $caps = $context->capabilities;
        $status = $caps->integrations();
        return $status[self::GROUP]['available'] && !in_array(self::GROUP, $caps->disabledGroups(), true);
    }

    /** The integration is usable by this token (site-wide switches and the token's denied groups). */
    public static function available(Context $context) {
        return $context->capabilities->groupBlockReason(self::GROUP, $context->token) === null;
    }

    /** Core directory of miniShop2 with a trailing slash. */
    public static function corePath(\modX $modx) {
        return (new \ModxMCP\Capabilities($modx))->integrationPath(self::GROUP);
    }

    /** Directory of the miniShop2 core processors, for the `processors_path` option of runProcessor(). */
    public static function processorsPath(\modX $modx) {
        return self::corePath($modx) . 'processors/';
    }

    /**
     * Register the xPDO package, as minishop2.class.php does in its constructor (`addPackage`), without creating
     * the miniShop2 service: the models and the processors of products and options do not need it. Registering
     * twice is harmless. miniShop2's own plugin normally has done it already (OnMODXInit).
     */
    public static function load(\modX $modx) {
        $model = self::corePath($modx) . 'model/';
        if (!is_file($model . 'minishop2/minishop2.class.php')) {
            throw new \RuntimeException('miniShop2 is not installed on this site (its model files are missing).');
        }
        if (!isset($modx->packages[self::PACKAGE]) || $modx->packages[self::PACKAGE]['path'] !== $model) {
            $modx->addPackage(self::PACKAGE, $model);
        }
    }

    /**
     * The miniShop2 service (class miniShop2), for the one thing that only it does: changing the status of an order, which
     * writes the order log, fires miniShop2's events and sends the e-mails. Its constructor registers the package and
     * loads pdoTools; nothing else is initialised until changeOrderStatus() asks for the order's context.
     */
    public static function service(\modX $modx) {
        self::load($modx);
        $service = $modx->getService('miniShop2', 'miniShop2', self::corePath($modx) . 'model/minishop2/');
        if (!is_object($service) || !method_exists($service, 'changeOrderStatus')) {
            throw new \RuntimeException('The miniShop2 service could not be loaded.');
        }
        return $service;
    }
}
