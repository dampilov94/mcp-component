<?php
namespace ModxMCP\Kind;

use ModxMCP\Write\Transaction;
use ModxMCP\Context;

/** An access rule of a user group on a context (modAccessContext). */
final class ContextAccessKind extends AbstractAclKind {
    public function name() { return 'context_access'; }
    public function label() { return 'Context access rule'; }
    public function modelClass() { return 'modAccessContext'; }
    public function processorPath() { return 'security/access/usergroup/context/'; }

    public function fields() {
        return $this->aclFields(array('type' => 'string', 'list' => true, 'required' => true, 'description' => 'Key of the context (kind context).'));
    }

    protected function targetLabel(\modX $modx, $object) { return (string) $object->get('target'); }

    /**
     * The create processor also gives the Administrator group a rule on the same context (policy "Context") when
     * it has none there. Journal that rule with the create, so an undo takes it away again.
     */
    public function beforeCreate(Context $context, array $columns) {
        parent::beforeCreate($context, $columns);
        $modx = $context->modx;
        $admin = $modx->getObject('modUserGroup', array('name' => 'Administrator'), false);
        $target = isset($columns['target']) ? (string) $columns['target'] : '';
        $criteria = $admin ? array('principal' => (int) $admin->get('id'), 'principal_class' => 'modUserGroup', 'target' => $target) : null;
        if (!$admin || (int) $admin->get('id') === (int) (isset($columns['principal']) ? $columns['principal'] : -1) || $modx->getCount($this->modelClass(), $criteria)) {
            return null;
        }
        $kind = $this;
        return function (Transaction $tx, $id) use ($modx, $kind, $criteria) {
            $row = $modx->getObject($kind->modelClass(), $criteria, false);
            if ($row) { $tx->created($kind, (int) $row->get('id')); }
        };
    }
}
