<?php
namespace ModxMCP\Kind;

use ModxMCP\Auth\Scope;

final class UserGroupKind extends Kind {
    public function name() { return 'user_group'; }
    public function label() { return 'User group'; }
    public function modelClass() { return 'modUserGroup'; }
    public function processorPath() { return 'security/group/'; }
    public function group() { return 'access'; }
    public function writeScope() { return Scope::ADMIN; }
    public function processorExtras($op) { return array(); }
    public function fields() {
        return array(
            'id' => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name' => array('type' => 'string', 'required' => true, 'list' => true),
            'description' => array('type' => 'string'),
            'parent' => array('type' => 'integer', 'list' => true),
            'rank' => array('type' => 'integer'),
            'dashboard' => array('type' => 'integer'),
        );
    }
    public function relationSpecs() {
        $relations = array('members' => array('class' => 'modUserGroupMember', 'fk' => 'user_group',
            'present' => array('member', 'role'), 'description' => 'Users in the group with their role ids.'));
        // Access rules granted to the group go when it is deleted; keep them so an undo is complete.
        foreach (array(
            'acl_contexts' => 'modAccessContext', 'acl_resource_groups' => 'modAccessResourceGroup',
            'acl_categories' => 'modAccessCategory', 'acl_media_sources' => 'sources.modAccessMediaSource',
            'acl_namespaces' => 'modAccessNamespace',
        ) as $name => $class) {
            $relations[$name] = array('class' => $class, 'fk' => 'principal', 'where' => array('principal_class' => 'modUserGroup'), 'delete_only' => true);
        }
        return $relations;
    }
}
