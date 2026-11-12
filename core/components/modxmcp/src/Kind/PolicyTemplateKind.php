<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;

/** A policy template (modAccessPolicyTemplate): the list of permissions its policies choose from. */
final class PolicyTemplateKind extends Kind {
    public function name() { return 'policy_template'; }
    public function label() { return 'Policy template'; }
    public function modelClass() { return 'modAccessPolicyTemplate'; }
    public function processorPath() { return 'security/access/policy/template/'; }
    public function group() { return 'access'; }
    public function readScope() { return Scope::ADMIN; }
    public function writeScope() { return Scope::ADMIN; }
    public function processorExtras($op) { return array(); }

    public function fields() {
        return array(
            'id'             => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'           => array('type' => 'string', 'list' => true, 'required' => true),
            'description'    => array('type' => 'string'),
            'template_group' => array('type' => 'integer', 'list' => true, 'description' => 'Id of the template group, 0 for none.'),
            'lexicon'        => array('type' => 'string'),
        );
    }

    public function relationSpecs() {
        return array(
            'permissions' => array('class' => 'modAccessPermission', 'fk' => 'template', 'present' => array('name', 'description', 'value'),
                'description' => 'Permissions the template offers (name, description, value). Read-only here; MODX removes them with the template.'),
        );
    }

    // ---- the safety rules ----

    public function assertObjectWritable(\modX $modx, $object, $op) {
        if ($op === 'duplicate') { return; }   // the copy is a new template under a new name
        $id = (int) $object->get('id');
        $reason = AccessRules::templateReason($modx, $id, $object->get('name'));
        if ($reason !== null) {
            throw new ApiException('validation_failed', "Cannot {$op} policy template {$id}: {$reason}. Change it in the MODX manager.");
        }
        if ($op === 'delete') {
            // MODX removes the template's policies (and theirs) with it.
            AccessRules::assertPoliciesFree($modx, AccessRules::templatePolicies($modx, $id), "delete policy template {$id}");
            $rules = AccessRules::rulesUsingPolicies($modx, AccessRules::templatePolicies($modx, $id));
            if ($rules) {
                throw new ApiException('validation_failed', "Policies of template {$id} are still used by {$rules} access rule(s). Change or delete those rules first.");
            }
        }
    }

    public function assertUndoable(\modX $modx, $key, $target) {
        $id = (int) $key;
        $current = \ModxMCP\Journal\Snapshot::capture($modx, $this, $id);
        foreach (array($current, $target) as $state) {
            if ($state !== null && AccessRules::isBuiltinName(isset($state['fields']['name']) ? $state['fields']['name'] : '', AccessRules::BUILTIN_TEMPLATES)) {
                throw new ApiException('not_reversible', "Policy template {$id} is a template MODX installs; it is not restored through the API.");
            }
        }
        foreach (AccessRules::adminPolicyIds($modx) as $policyId) {
            $policy = $modx->getObject('modAccessPolicy', array('id' => $policyId), false);
            if ($policy && (int) $policy->get('template') === $id) {
                throw new ApiException('not_reversible', "Policy template {$id} is the template of a policy used by access rules of the Administrator group; it is not restored through the API.");
            }
        }
    }

    /** MODX removes the template's policies (and the policies below them); each is journaled as a deleted access_policy. */
    public function deleteCascade(\modX $modx, $key) {
        $out = array();
        foreach (AccessRules::templatePolicies($modx, (int) $key) as $id) { $out[] = array('access_policy', $id); }
        return $out;
    }

    public function afterWrite(\modX $modx) {
        $cache = $modx->getCacheManager();
        if ($cache) { $cache->flushPermissions(); }
    }
}
