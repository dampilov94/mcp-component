<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Write\Transaction;

/**
 * An access policy (modAccessPolicy). Its permissions are one JSON object {permission: bool} in the column
 * `data`. MODX's own policies, and every policy the Administrator group's rules use, cannot be changed.
 */
final class AccessPolicyKind extends Kind {
    public function name() { return 'access_policy'; }
    public function label() { return 'Access policy'; }
    public function modelClass() { return 'modAccessPolicy'; }
    public function processorPath() { return 'security/access/policy/'; }
    public function group() { return 'access'; }
    public function readScope() { return Scope::ADMIN; }
    public function writeScope() { return Scope::ADMIN; }
    public function processorExtras($op) { return array(); }

    public function fields() {
        return array(
            'id'          => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'        => array('type' => 'string', 'list' => true, 'required' => true),
            'description' => array('type' => 'string'),
            'parent'      => array('type' => 'integer', 'description' => 'Id of the parent policy, 0 for none. Deleting a policy deletes its children.'),
            'template'    => array('type' => 'integer', 'list' => true, 'required' => true, 'description' => 'Id of the policy template (kind policy_template).'),
            'class'       => array('type' => 'string'),
            'lexicon'     => array('type' => 'string'),
            'permissions' => array('type' => 'object', 'column' => 'data',
                'description' => 'Permissions as {name: true|false}. An update replaces the whole map. A new policy starts with every permission of its template on unless permissions are given.'),
        );
    }

    // ---- how MODX takes the permissions: update takes a list of {name, enabled}; create derives them from the template ----

    /** The update processor reads `permissions`; an update that does not change them leaves them out. */
    public function processorValue($object, $field) {
        return $field === 'permissions' ? null : parent::processorValue($object, $field);
    }

    public function processorData($op, array $data) {
        if (array_key_exists('data', $data)) {
            $map = is_string($data['data']) ? json_decode($data['data'], true) : $data['data'];
            unset($data['data']);
            if ($op === 'update' && is_array($map)) { $data['permissions'] = self::permissionList($map); }
        }
        return $data;
    }

    private static function permissionList(array $map) {
        $list = array();
        foreach ($map as $name => $enabled) { $list[] = array('name' => (string) $name, 'enabled' => (bool) $enabled); }
        return $list;
    }

    /** The create processor fills `data` from the template (all on); given permissions are applied with an update right after. */
    public function beforeCreate(Context $context, array $columns) {
        if (!array_key_exists('data', $columns)) { return null; }
        $map = $columns['data'];
        $kind = $this;
        return function (Transaction $tx, $id) use ($context, $kind, $map) {
            $object = $kind->findByKey($context->modx, $id);
            $data = $kind->processorKey($id);
            foreach (array('name', 'description', 'parent', 'template', 'class', 'lexicon') as $field) { $data[$field] = $object->get($field); }
            $data['permissions'] = self::permissionList($map);
            Objects::process($context->modx, $kind->processorPath() . 'update', $data);
        };
    }

    // ---- the safety rules ----

    public function assertObjectWritable(\modX $modx, $object, $op) {
        if ($op === 'duplicate') { return; }   // the copy is a new policy under a new name
        $id = (int) $object->get('id');
        $reason = AccessRules::policyReason($modx, $id, $object->get('name'));
        if ($reason !== null) {
            throw new ApiException('validation_failed', "Cannot {$op} access policy {$id}: {$reason}. Change it in the MODX manager.");
        }
        if ($op === 'delete') {
            // MODX removes the children with it.
            AccessRules::assertPoliciesFree($modx, AccessRules::policyDescendants($modx, $id), "delete access policy {$id}");
            // MODX leaves rules that use the policy in place, pointing at nothing: their groups would
            // silently lose those permissions. Remove or change the rules first.
            $rules = AccessRules::rulesUsingPolicies($modx, array_merge(array($id), AccessRules::policyDescendants($modx, $id)));
            if ($rules) {
                throw new ApiException('validation_failed', "Access policy {$id} (or a policy below it) is still used by {$rules} access rule(s). Change or delete those rules first (modx_list with where: {policy: {$id}} on context_access / resourcegroup_access).");
            }
        }
    }

    public function assertUndoable(\modX $modx, $key, $target) {
        $id = (int) $key;
        $current = \ModxMCP\Journal\Snapshot::capture($modx, $this, $id);
        foreach (array($current, $target) as $state) {
            if ($state !== null && AccessRules::isBuiltinName(isset($state['fields']['name']) ? $state['fields']['name'] : '', AccessRules::BUILTIN_POLICIES)) {
                throw new ApiException('not_reversible', "Access policy {$id} is a policy MODX installs; it is not restored through the API.");
            }
        }
        if (AccessRules::policyUsedByAdmin($modx, $id)) {
            throw new ApiException('not_reversible', "Access policy {$id} is used by access rules of the Administrator group; it is not restored through the API.");
        }
    }

    /** MODX removes the child policies with a policy; each is journaled as a deleted access_policy. */
    public function deleteCascade(\modX $modx, $key) {
        $out = array();
        foreach (AccessRules::policyDescendants($modx, (int) $key) as $id) { $out[] = array('access_policy', $id); }
        return $out;
    }

    /** Like security/access/flush: the cached permissions of every session must be rebuilt. */
    public function afterWrite(\modX $modx) {
        $cache = $modx->getCacheManager();
        if ($cache) { $cache->flushPermissions(); }
    }
}
