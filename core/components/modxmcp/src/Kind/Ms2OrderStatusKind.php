<?php
namespace ModxMCP\Kind;

/**
 * A miniShop2 order status (msOrderStatus), read only. Shown so that an order's status can be named and a change_status
 * of kind ms2_order can say which e-mails the new status sends. The status settings (e-mail subjects and bodies, the order
 * of statuses) are edited in the manager.
 */
final class Ms2OrderStatusKind extends AbstractMs2Kind {
    public function name() { return 'ms2_order_status'; }
    public function label() { return 'miniShop2 order status'; }
    public function modelClass() { return 'msOrderStatus'; }
    public function processorPath() { return 'mgr/settings/status/'; }
    public function defaultSort() { return array(array('rank', 'ASC'), array('id', 'ASC')); }

    public function genericWrites() { return false; }
    public function genericWritesMessage() { return 'Order statuses are read only here; edit them in the miniShop2 settings of the manager.'; }

    public function fields() {
        $readOnly = array('read_only' => true);
        return array(
            'id'            => array('type' => 'integer', 'list' => true) + $readOnly,
            'name'          => array('type' => 'string', 'list' => true) + $readOnly,
            'color'         => array('type' => 'string', 'description' => 'Colour in the manager, six hex digits.') + $readOnly,
            'email_user'    => array('type' => 'boolean', 'list' => true, 'description' => 'A change to this status sends an e-mail to the customer.') + $readOnly,
            'email_manager' => array('type' => 'boolean', 'list' => true, 'description' => 'A change to this status sends an e-mail to the managers.') + $readOnly,
            'active'        => array('type' => 'boolean', 'list' => true, 'description' => 'Only an active status can be set.') + $readOnly,
            'final'         => array('type' => 'boolean', 'description' => 'An order in a final status cannot change its status.') + $readOnly,
            'fixed'         => array('type' => 'boolean', 'description' => 'An order in a fixed status can only move to a status of a higher rank.') + $readOnly,
            'rank'          => array('type' => 'integer', 'list' => true) + $readOnly,
        );
    }
}
