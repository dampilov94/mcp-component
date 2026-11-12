<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;

/**
 * An access rule of a user group (modAccessContext, modAccessResourceGroup). A rule has no name: it is addressed by
 * id and shown with a computed `label`. Rules of the Administrator group are never changed through the API.
 */
abstract class AbstractAclKind extends Kind {
    /** Fields the two kinds share, with the kind's own `target` (and extras) placed after the id. */
    protected function aclFields(array $target, array $extra = array()) {
        return array(
            'id'              => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'target'          => $target,
            'principal'       => array('type' => 'integer', 'list' => true, 'required' => true, 'description' => 'Id of the user group the rule is for (not 1, the Administrator group).'),
            'authority'       => array('type' => 'integer', 'list' => true, 'required' => true, 'description' => 'Minimum authority (role authority; lower is stronger) a member needs.'),
            'policy'          => array('type' => 'integer', 'list' => true, 'required' => true, 'description' => 'Id of the access policy (kind access_policy).'),
        ) + $extra + array(
            'principal_class' => array('type' => 'string', 'read_only' => true, 'description' => 'Always modUserGroup for rules made through the API.'),
            'label'           => array('type' => 'string', 'list' => true, 'derived' => true, 'computed' => true,
                'description' => '"<group name> → <target> (<policy name>, authority N)".'),
        );
    }

    public function group() { return 'access'; }
    public function readScope() { return Scope::ADMIN; }
    public function writeScope() { return Scope::ADMIN; }
    public function nameField() { return 'id'; }
    public function hasNames() { return false; }
    public function uniqueNames() { return false; }
    public function processorExtras($op) { return array(); }
    public function searchColumns() { return array('target'); }
    public function defaultSort() { return array(array('target', 'ASC'), array('principal', 'ASC'), array('id', 'ASC')); }

    /** What the label shows for the target of the rule. */
    abstract protected function targetLabel(\modX $modx, $object);

    public function value($object, $field) {
        if ($field !== 'label') { return parent::value($object, $field); }
        $modx = $object->xpdo;
        $principal = (int) $object->get('principal');
        $group = $principal === 0 ? null : $modx->getObject('modUserGroup', array('id' => $principal), false);
        $policy = $modx->getObject('modAccessPolicy', array('id' => (int) $object->get('policy')), false);
        return ($principal === 0 ? '(anonymous)' : ($group ? $group->get('name') : '#' . $principal)) . ' → ' . $this->targetLabel($modx, $object)
            . ' (' . ($policy ? $policy->get('name') : '#' . (int) $object->get('policy')) . ', authority ' . (int) $object->get('authority') . ')';
    }

    // ---- the safety rule: no rule of group 1 is created, changed, deleted or copied ----

    /** Plain comparison with group 1: catches the rule in dry runs, which do not look at the site. */
    public function assertColumns(array $columns, $creating) {
        if (isset($columns['principal']) && (int) $columns['principal'] === AccessRules::ADMIN_GROUP) {
            throw new ApiException('validation_failed', 'Access rules of the Administrator group (user group 1) are changed in the MODX manager, not through the API.');
        }
    }

    public function beforeCreate(Context $context, array $columns) {
        $this->assertNotAdmin($context->modx, isset($columns['principal']) ? $columns['principal'] : 0, 'create an access rule for');
        return null;
    }

    public function assertObjectWritable(\modX $modx, $object, $op) {
        $this->assertNotAdmin($modx, $object->get('principal'), $op . ' an access rule of');
    }

    public function assertUpdatable(\modX $modx, $object, array $columns) {
        if (isset($columns['principal'])) { $this->assertNotAdmin($modx, $columns['principal'], 'give an access rule to'); }
    }

    private function assertNotAdmin(\modX $modx, $principal, $what) {
        if (AccessRules::isAdminGroup($modx, $principal)) {
            throw new ApiException('validation_failed', "Cannot {$what} the Administrator group: access rules of that group are changed in the MODX manager, not through the API.");
        }
    }

    /**
     * Undo may not recreate or change a rule of the Administrator group. Removing one is allowed: the only such rule
     * the API ever journals is the one MODX itself adds when a context rule is created (see the context kind), and
     * undoing that create must take it away again.
     */
    public function assertUndoable(\modX $modx, $key, $target) {
        if ($target === null) { return; }
        $current = Snapshot::capture($modx, $this, (int) $key);
        foreach (array($target, $current) as $state) {
            if ($state !== null && isset($state['fields']['principal']) && AccessRules::isAdminGroup($modx, $state['fields']['principal'])) {
                throw new ApiException('not_reversible', "Access rule {$key} belongs to the Administrator group; it is not restored through the API.");
            }
        }
    }

    /** Like security/access/flush. */
    public function afterWrite(\modX $modx) {
        $cache = $modx->getCacheManager();
        if ($cache) { $cache->flushPermissions(); }
    }
}
