<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;

/**
 * Safety rules of the access kinds. A mistake in access control can lock everyone out of the manager,
 * so the API never changes:
 *   1. access rules of the Administrator user group (id 1, or the group named Administrator);
 *   2. a policy such a group's rules use, and the template of that policy;
 *   3. the policies and policy templates MODX installs.
 */
final class AccessRules {
    const ADMIN_GROUP = 1;

    /**
     * Names of the policies and policy templates that a MODX 2.8.8 installation creates. Taken from the
     * install vehicles core/packages/core/modAccessPolicy/*.vehicle and core/packages/core/modAccessPolicyTemplate/*.vehicle
     * (the setup/ directory is not part of the local source tree).
     */
    const BUILTIN_POLICIES = array(
        'Resource', 'Administrator', 'Load Only', 'Load, List and View', 'Object', 'Element', 'Content Editor',
        'Media Source Admin', 'Media Source User', 'Developer', 'Context', 'Hidden Namespace',
    );
    const BUILTIN_TEMPLATES = array(
        'ObjectTemplate', 'AdministratorTemplate', 'ElementTemplate', 'ContextTemplate', 'ResourceTemplate',
        'MediaSourceTemplate', 'NamespaceTemplate',
    );

    /** Classes of access rules that point at a policy and a user group. */
    const ACL_CLASSES = array('modAccessContext', 'modAccessResourceGroup', 'modAccessCategory', 'modAccessNamespace', 'sources.modAccessMediaSource');

    /** Case-insensitive, Unicode-aware comparison of a name with a list of built-in names. */
    public static function isBuiltinName($name, array $list) {
        $name = mb_strtolower(trim((string) $name), 'UTF-8');
        foreach ($list as $builtin) {
            if ($name === mb_strtolower($builtin, 'UTF-8')) { return true; }
        }
        return false;
    }

    /** Ids of the Administrator group: 1, and the group of that name when it has another id. */
    public static function adminGroupIds(\modX $modx) {
        $ids = array(self::ADMIN_GROUP);
        $group = $modx->getObject('modUserGroup', array('name' => 'Administrator'), false);
        if ($group) { $ids[] = (int) $group->get('id'); }
        return array_values(array_unique($ids));
    }

    public static function isAdminGroup(\modX $modx, $principal) {
        return in_array((int) $principal, self::adminGroupIds($modx), true);
    }

    /** True when an access rule of the Administrator group uses this policy (any kind of rule). */
    public static function policyUsedByAdmin(\modX $modx, $policyId) {
        foreach (self::ACL_CLASSES as $class) {
            foreach (self::adminGroupIds($modx) as $group) {
                if ($modx->getCount($class, array('principal' => $group, 'principal_class' => 'modUserGroup', 'policy' => (int) $policyId))) { return true; }
            }
        }
        return false;
    }

    /** Number of access rules of any kind that use one of these policies. */
    public static function rulesUsingPolicies(\modX $modx, array $policyIds) {
        $count = 0;
        foreach (self::ACL_CLASSES as $class) {
            foreach ($policyIds as $id) { $count += (int) $modx->getCount($class, array('policy' => (int) $id)); }
        }
        return $count;
    }

    /** Ids of every policy the Administrator group's rules use. */
    public static function adminPolicyIds(\modX $modx) {
        $ids = array();
        foreach (self::ACL_CLASSES as $class) {
            foreach (self::adminGroupIds($modx) as $group) {
                foreach ($modx->getCollection($class, array('principal' => $group, 'principal_class' => 'modUserGroup')) as $row) {
                    $ids[(int) $row->get('policy')] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /** Why a policy is protected (a sentence), or null. */
    public static function policyReason(\modX $modx, $id, $name) {
        if (self::isBuiltinName($name, self::BUILTIN_POLICIES)) { return "'{$name}' is a policy MODX installs"; }
        if (self::policyUsedByAdmin($modx, $id)) { return "'{$name}' is used by access rules of the Administrator group"; }
        return null;
    }

    /** Why a policy template is protected (a sentence), or null. */
    public static function templateReason(\modX $modx, $id, $name) {
        if (self::isBuiltinName($name, self::BUILTIN_TEMPLATES)) { return "'{$name}' is a policy template MODX installs"; }
        foreach (self::adminPolicyIds($modx) as $policyId) {
            $policy = $modx->getObject('modAccessPolicy', array('id' => $policyId), false);
            if ($policy && (int) $policy->get('template') === (int) $id) { return "'{$name}' is the template of a policy used by access rules of the Administrator group"; }
        }
        return null;
    }

    /** Ids of the policies below a policy (children, their children, …). */
    public static function policyDescendants(\modX $modx, $id) {
        $out = array();
        $queue = array((int) $id);
        while ($queue) {
            $parent = array_shift($queue);
            foreach ($modx->getCollection('modAccessPolicy', array('parent' => $parent)) as $child) {
                $childId = (int) $child->get('id');
                if ($childId === (int) $id || isset($out[$childId])) { continue; }
                $out[$childId] = true;
                $queue[] = $childId;
            }
        }
        return array_keys($out);
    }

    /** Ids of a template's policies and everything below them: what MODX removes with the template. */
    public static function templatePolicies(\modX $modx, $templateId) {
        $out = array();
        foreach ($modx->getCollection('modAccessPolicy', array('template' => (int) $templateId)) as $policy) {
            $id = (int) $policy->get('id');
            $out[$id] = true;
            foreach (self::policyDescendants($modx, $id) as $below) { $out[$below] = true; }
        }
        return array_keys($out);
    }

    /** Refuse (validation_failed) when this policy, or one MODX would remove with it, is protected. */
    public static function assertPoliciesFree(\modX $modx, array $ids, $what) {
        foreach ($ids as $id) {
            $policy = $modx->getObject('modAccessPolicy', array('id' => (int) $id), false);
            if (!$policy) { continue; }
            $reason = self::policyReason($modx, $id, $policy->get('name'));
            if ($reason !== null) { throw new ApiException('validation_failed', "Cannot {$what}: {$reason}. Change it in the MODX manager."); }
        }
    }
}
