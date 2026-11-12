<?php
namespace ModxMCP\Kind;

use ModxMCP\Auth\Scope;

final class RoleKind extends Kind {
    public function name() { return 'role'; }
    public function label() { return 'Role'; }
    public function modelClass() { return 'modUserGroupRole'; }
    public function processorPath() { return 'security/role/'; }
    public function group() { return 'access'; }
    public function writeScope() { return Scope::ADMIN; }
    public function processorExtras($op) { return array(); }
    public function fields() {
        return array(
            'id' => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name' => array('type' => 'string', 'required' => true, 'list' => true),
            'description' => array('type' => 'string'),
            'authority' => array('type' => 'integer', 'list' => true, 'description' => 'Lower values grant more authority.'),
        );
    }
}
