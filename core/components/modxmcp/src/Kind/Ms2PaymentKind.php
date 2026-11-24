<?php
namespace ModxMCP\Kind;

/** A miniShop2 payment method (msPayment), read only. Orders point at it with their `payment` column. */
final class Ms2PaymentKind extends Ms2MethodKind {
    public function name() { return 'ms2_payment'; }
    public function label() { return 'miniShop2 payment method'; }
    public function modelClass() { return 'msPayment'; }
    public function processorPath() { return 'mgr/settings/payment/'; }

    public function fields() {
        $readOnly = array('read_only' => true);
        return array(
            'id'          => array('type' => 'integer', 'list' => true) + $readOnly,
            'name'        => array('type' => 'string', 'list' => true) + $readOnly,
            'description' => array('type' => 'string') + $readOnly,
            'price'       => array('type' => 'string', 'list' => true, 'description' => 'Added cost: a number, or a percentage such as 5%.') + $readOnly,
            'logo'        => array('type' => 'string') + $readOnly,
            'rank'        => array('type' => 'integer', 'list' => true) + $readOnly,
            'active'      => array('type' => 'boolean', 'list' => true) + $readOnly,
            'class'       => array('type' => 'string', 'description' => 'Handler class of the payment; empty for the standard one.') + $readOnly,
            'properties'  => array('type' => 'object', 'json' => true, 'description' => 'Settings of the handler. Shown only with the admin scope (they usually hold the keys of the payment system).') + $readOnly,
        );
    }
}
