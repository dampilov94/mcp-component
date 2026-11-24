<?php
namespace ModxMCP\Kind;

/** A miniShop2 delivery method (msDelivery), read only. Orders point at it with their `delivery` column. */
final class Ms2DeliveryKind extends Ms2MethodKind {
    public function name() { return 'ms2_delivery'; }
    public function label() { return 'miniShop2 delivery method'; }
    public function modelClass() { return 'msDelivery'; }
    public function processorPath() { return 'mgr/settings/delivery/'; }

    public function fields() {
        $readOnly = array('read_only' => true);
        return array(
            'id'                   => array('type' => 'integer', 'list' => true) + $readOnly,
            'name'                 => array('type' => 'string', 'list' => true) + $readOnly,
            'description'          => array('type' => 'string') + $readOnly,
            'price'                => array('type' => 'string', 'list' => true, 'description' => 'Added cost: a number, or a percentage such as 5%.') + $readOnly,
            'weight_price'         => array('type' => 'number', 'description' => 'Added cost per unit of weight.') + $readOnly,
            'distance_price'       => array('type' => 'number', 'description' => 'Added cost per unit of distance.') + $readOnly,
            'logo'                 => array('type' => 'string') + $readOnly,
            'rank'                 => array('type' => 'integer', 'list' => true) + $readOnly,
            'active'               => array('type' => 'boolean', 'list' => true) + $readOnly,
            'class'                => array('type' => 'string', 'description' => 'Handler class of the delivery; empty for the standard one.') + $readOnly,
            'properties'           => array('type' => 'object', 'json' => true, 'description' => 'Settings of the handler. Shown only with the admin scope (they may hold keys of a courier service).') + $readOnly,
            'requires'             => array('type' => 'string', 'description' => 'Order fields the customer must fill in, comma separated.') + $readOnly,
            'free_delivery_amount' => array('type' => 'number', 'description' => 'Order cost from which the delivery is free, 0 for never.') + $readOnly,
        );
    }

    public function relationSpecs() {
        return array(
            'payments' => array('class' => 'msDeliveryMember', 'fk' => 'delivery_id', 'present' => 'payment_id', 'in_state' => false,
                'description' => 'Ids of the payment methods allowed for this delivery (kind ms2_payment). Read only.'),
        );
    }
}
