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
        return array('members' => array('class' => 'modUserGroupMember', 'fk' => 'user_group',
            'present' => array('member', 'role'), 'description' => 'Users in the group with their role ids.'));
    }
}
