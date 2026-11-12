<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Integration\MiniShop2;
use ModxMCP\Schema;
use ModxMCP\Write\Transaction;

/**
 * A miniShop2 order (msOrder), read only, with one operation: change_status. Orders hold the customers' names, addresses,
 * phone numbers and e-mails, so the kind needs the admin scope even to be read. The session id, the IP addresses of the log and
 * the free-form properties are never shown.
 *
 * A status change goes through the miniShop2 service (changeOrderStatus(), the path the manager's order form takes as well):
 * it writes the order log, fires msOnBeforeChangeOrderStatus / msOnChangeOrderStatus and sends the e-mails of the status
 * to real people. It cannot be undone, so it is recorded as a changeset without changes and never touches the order's own
 * journal state.
 */
final class Ms2OrderKind extends AbstractMs2Kind {
    /** Contact columns of msOrderAddress that are shown. */
    const ADDRESS_COLUMNS = array('receiver', 'phone', 'email', 'country', 'index', 'region', 'city', 'metro', 'street', 'building', 'entrance', 'floor', 'room', 'comment', 'text_address');

    public function name() { return 'ms2_order'; }
    public function label() { return 'miniShop2 order'; }
    public function modelClass() { return 'msOrder'; }
    public function processorPath() { return 'mgr/orders/'; }
    public function nameField() { return 'num'; }
    public function readScope() { return Scope::ADMIN; }
    public function writeScope() { return Scope::ADMIN; }
    public function uniqueNames() { return false; }
    public function defaultSort() { return array(array('id', 'DESC')); }

    public function genericWrites() { return false; }
    public function genericWritesMessage() {
        return "Orders are not changed with the generic write tools; the only change is modx_invoke {kind: 'ms2_order', op: 'change_status'}.";
    }

    public function fields() {
        $readOnly = array('read_only' => true);
        return array(
            'id'            => array('type' => 'integer', 'list' => true) + $readOnly,
            'num'           => array('type' => 'string', 'list' => true, 'description' => 'Order number, e.g. 2611/42.') + $readOnly,
            'user_id'       => array('type' => 'integer', 'list' => true, 'description' => 'Id of the customer (a MODX user).') + $readOnly,
            'createdon'     => array('type' => 'string', 'format' => 'datetime', 'list' => true) + $readOnly,
            'updatedon'     => array('type' => 'string', 'format' => 'datetime') + $readOnly,
            'cost'          => array('type' => 'number', 'list' => true, 'description' => 'Total: cart cost plus delivery cost.') + $readOnly,
            'cart_cost'     => array('type' => 'number') + $readOnly,
            'delivery_cost' => array('type' => 'number') + $readOnly,
            'weight'        => array('type' => 'number') + $readOnly,
            'status'        => array('type' => 'integer', 'list' => true, 'description' => 'Id of the order status (kind ms2_order_status).') + $readOnly,
            'status_name'   => array('type' => 'string', 'list' => true, 'computed' => true, 'derived' => true, 'description' => 'Name of the status.') + $readOnly,
            'delivery'      => array('type' => 'integer', 'description' => 'Id of the delivery method.') + $readOnly,
            'payment'       => array('type' => 'integer', 'description' => 'Id of the payment method.') + $readOnly,
            'context'       => array('type' => 'string') + $readOnly,
            'comment'       => array('type' => 'string', 'column' => 'order_comment', 'description' => 'Comment of the customer.') + $readOnly,
        );
    }

    public function value($object, $field) {
        if ($field !== 'status_name') { return parent::value($object, $field); }
        $status = $object->xpdo->getObject('msOrderStatus', array('id' => (int) $object->get('status')), false);
        return $status ? (string) $status->get('name') : '';
    }

    // ---- relations (read only) ----

    public function relationSpecs() {
        return array(
            'products' => array('class' => 'msOrderProduct', 'fk' => 'order_id', 'in_state' => false,
                'present' => array('product_id', 'name', 'count', 'price', 'cost', 'options'), 'description' => 'Products of the order as they were when it was placed.'),
            'address'  => array('class' => 'msOrderAddress', 'fk' => 'id', 'in_state' => false,
                'present' => self::ADDRESS_COLUMNS, 'description' => 'Delivery address and contacts of the customer (one row).'),
            'log'      => array('class' => 'msOrderLog', 'fk' => 'order_id', 'in_state' => false,
                'present' => array('timestamp', 'action', 'entry', 'user_id'), 'description' => 'History of the order: status changes and other actions, oldest first.'),
        );
    }

    private static function number($value) { return $value === null ? 0.0 : (float) $value; }

    public function presentRelation(\modX $modx, $object, $name) {
        $out = array();
        if ($name === 'address') {
            $row = $modx->getObject('msOrderAddress', array('id' => (int) $object->get('address')), false);
            if (!$row) { return array(); }
            $item = array();
            foreach (self::ADDRESS_COLUMNS as $column) { $value = $row->get($column); $item[$column] = $value === null ? '' : (string) $value; }
            return array($item);
        }
        if ($name === 'products') {
            foreach ($modx->getCollection('msOrderProduct', array('order_id' => (int) $object->get('id')), false) as $row) {
                $options = $row->get('options');
                if (is_string($options)) { $options = json_decode($options, true); }
                $out[(int) $row->get('id')] = array(
                    'product_id' => (int) $row->get('product_id'), 'name' => (string) $row->get('name'), 'count' => (int) $row->get('count'),
                    'price' => self::number($row->get('price')), 'cost' => self::number($row->get('cost')),
                    'options' => is_array($options) && $options ? $options : new \stdClass(),
                );
            }
        } elseif ($name === 'log') {
            foreach ($modx->getCollection('msOrderLog', array('order_id' => (int) $object->get('id')), false) as $row) {
                $out[(int) $row->get('id')] = array(
                    'timestamp' => Kind::datetime($row->get('timestamp')), 'action' => (string) $row->get('action'),
                    'entry' => (string) $row->get('entry'), 'user_id' => (int) $row->get('user_id'),
                );
            }
        }
        ksort($out, SORT_NUMERIC);
        return array_values($out);
    }

    // ---- change_status ----

    public function operations() {
        return array(
            'change_status' => array(
                'description' => 'Change the status of the order the way miniShop2 does: it writes the order log, fires the miniShop2 events and SENDS THE E-MAILS of the new status '
                    . 'to the customer and the managers. Without confirm: true (or with a plan-only token, or dry_run) it only shows the current and the new status and which e-mails would go out. '
                    . 'Never reversible: modx_undo does not unsend e-mails. The status must exist and be active; an order in a final status, or in a fixed status when the new one has a lower rank, cannot change.',
                'read_only' => false, 'needs_object' => true, 'informational' => true, 'plan' => 'planChangeStatus', 'handler' => 'opChangeStatus',
                'input' => Schema::obj(array(
                    'status' => Schema::int('Id of the new status (kind ms2_order_status).', array('minimum' => 1)),
                    'confirm' => Schema::bool('Really change the status and send the e-mails. Without it only the plan is returned.'),
                ), array('status'))),
        );
    }

    private static function statusName($status, $id) { return $status ? (string) $status->get('name') : '#' . (int) $id; }

    /**
     * The checks of miniShop2::changeOrderStatus() that can be made beforehand (the source reports only the last refusal it finds,
     * so a status the order already has comes first here, then the others in order). A custom order handler may replace that method on a site; its rules are not known here.
     * @return array [new status object, old status object or null]
     */
    private function transition(\modX $modx, $order, $statusId) {
        // The service keeps the last refusal it finds, and "same status" is the last: it wins over the others.
        if ((int) $order->get('status') === $statusId) { throw new ApiException('validation_failed', 'The order already has this status.'); }
        $new = $modx->getObject('msOrderStatus', array('id' => $statusId), false);
        if (!$new) { throw new ApiException('validation_failed', "There is no order status {$statusId} (kind ms2_order_status)."); }
        if (!(int) $new->get('active')) { throw new ApiException('validation_failed', "Order status {$statusId} ('{$new->get('name')}') is not active."); }
        $old = $modx->getObject('msOrderStatus', array('id' => (int) $order->get('status'), 'active' => 1), false);
        if ($old && (int) $old->get('final')) {
            throw new ApiException('validation_failed', "The order is in the final status '{$old->get('name')}'; its status cannot be changed.");
        }
        if ($old && (int) $old->get('fixed') && (int) $new->get('rank') <= (int) $old->get('rank')) {
            throw new ApiException('validation_failed', "The order is in the fixed status '{$old->get('name')}'; the new status must have a higher rank.");
        }
        return array($new, $old);
    }

    private static function hasAddress($value) { return preg_match('#.*?@.*#', (string) $value) === 1; }

    /**
     * Which e-mails changeOrderStatus() sends for the new status, from the same conditions: the manager mail needs a subject and
     * an address in ms2_email_manager (or emailsender); the customer mail needs a subject and a profile with an address.
     * A failure of the mail server is only written to the MODX error log, so this is what is known.
     */
    private function emails(\modX $modx, $order, $status) {
        $manager = false;
        if ((int) $status->get('email_manager') && trim((string) $status->get('subject_manager')) !== '') {
            foreach (explode(',', (string) $modx->getOption('ms2_email_manager', null, $modx->getOption('emailsender'))) as $address) {
                if (self::hasAddress(trim($address))) { $manager = true; }
            }
        }
        $user = false;
        if ((int) $status->get('email_user') && trim((string) $status->get('subject_user')) !== '') {
            $profile = $modx->getObject('modUserProfile', array('internalKey' => (int) $order->get('user_id')), false);
            $user = $profile && self::hasAddress($profile->get('email'));
        }
        return array('user' => $user, 'manager' => $manager);
    }

    public function planChangeStatus(Context $context, $order, array $args) {
        $modx = $context->modx;
        $statusId = (int) $args['status'];
        list($new, ) = $this->transition($modx, $order, $statusId);
        $current = $modx->getObject('msOrderStatus', array('id' => (int) $order->get('status')), false);
        return array(
            'performed' => false,
            'order' => (string) $order->get('num'),
            'from' => array('id' => (int) $order->get('status'), 'name' => self::statusName($current, $order->get('status'))),
            'to' => array('id' => $statusId, 'name' => self::statusName($new, $statusId)),
            'email_user' => (bool) $new->get('email_user'),
            'email_manager' => (bool) $new->get('email_manager'),
            'emails_expected' => $this->emails($modx, $order, $new),
            'reversible' => false,
            'warning' => 'Confirming changes the order and sends the e-mails of the new status to real recipients; modx_undo cannot take that back. Repeat with confirm: true.',
        );
    }

    public function opChangeStatus(Context $context, Transaction $tx, $order, array $args): array {
        $modx = $context->modx;
        $orderId = (int) $order->get('id');
        $statusId = (int) $args['status'];
        list($new, ) = $this->transition($modx, $order, $statusId);
        $oldStatus = $modx->getObject('msOrderStatus', array('id' => (int) $order->get('status')), false);
        $from = array('id' => (int) $order->get('status'), 'name' => self::statusName($oldStatus, $order->get('status')));
        $to = array('id' => $statusId, 'name' => self::statusName($new, $statusId));
        $expected = $this->emails($modx, $order, $new);
        $num = (string) $order->get('num');

        $service = MiniShop2::service($modx);
        // changeOrderStatus() switches to the order's context; this request goes back to the one it was in.
        $previous = isset($modx->context) && is_object($modx->context) ? (string) $modx->context->get('key') : '';
        $thrown = null;
        try {
            $outcome = $service->changeOrderStatus($orderId, $statusId);
        } catch (\Throwable $e) {
            // The status may have been saved before a mailer or a plugin broke: that is judged from the order below.
            $thrown = $e;
            $outcome = $e->getMessage();
        } finally {
            if ($previous !== '' && method_exists($modx, 'switchContext') && (string) $modx->context->get('key') !== $previous) {
                try { $modx->switchContext($previous); } catch (\Throwable $e) { /* the change itself is done */ }
            }
        }
        $current = $modx->getObject('msOrder', array('id' => $orderId), false);
        $changed = $current && (int) $current->get('status') === $statusId;
        if ($thrown !== null && !$changed) { throw $thrown; }
        if ($outcome !== true && !$changed) {
            throw new ApiException('validation_failed', 'miniShop2 refused the change: ' . trim(strip_tags((string) $outcome)));
        }
        $sent = array('user' => false, 'manager' => false);
        if ($outcome === true) {
            $sent = $expected;
        } else {
            // A plugin answered the msOnChangeOrderStatus event with a failure, or something broke, after the status was saved: some e-mails may not have gone out.
            $context->warnings[] = 'The status was changed, but miniShop2 reported: ' . trim(strip_tags((string) $outcome)) . ' The e-mails may not have been sent; check the MODX error log.';
        }
        return array(
            'summary' => "order {$num}: {$from['name']} → {$to['name']}",
            'performed' => true, 'order' => $num, 'from' => $from, 'to' => $to,
            'emails_sent' => $sent,
        );
    }
}
