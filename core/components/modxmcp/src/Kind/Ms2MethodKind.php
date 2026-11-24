<?php
namespace ModxMCP\Kind;

use ModxMCP\Auth\Scope;
use ModxMCP\Context;

/**
 * What the read-only miniShop2 delivery and payment kinds share. Both are settings of the shop that orders point at by id;
 * they are changed in the miniShop2 settings of the manager. Their `properties` often hold the keys of a payment system or
 * of a courier service, so a token below the admin scope sees only that there are some.
 */
abstract class Ms2MethodKind extends AbstractMs2Kind {
    public function defaultSort() { return array(array('rank', 'ASC'), array('id', 'ASC')); }
    public function searchColumns() { return array('name', 'description'); }

    public function genericWrites() { return false; }
    public function genericWritesMessage() { return 'Change deliveries and payments in the miniShop2 settings of the manager; here they are read only.'; }

    public function redact(Context $context, $object, array $fields, array $relations) {
        if (!array_key_exists('properties', $fields) || Scope::allows($context->token['scope'], Scope::ADMIN)) { return array($fields, $relations, array()); }
        if (!is_object($fields['properties']) || !get_object_vars($fields['properties'])) { return array($fields, $relations, array()); }
        $fields['properties'] = Secrets::HIDDEN;
        return array($fields, $relations, array('secret' => true));
    }
}
