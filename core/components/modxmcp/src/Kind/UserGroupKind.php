<?php
namespace ModxMCP\Kind;

use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Schema;
use ModxMCP\Write\Transaction;

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
    public function operations() {
        $user = Schema::int(null, array('minimum' => 1));
        return array(
            'add_member' => array('description' => 'Add a user to the group.', 'read_only' => false,
                'input' => Schema::obj(array('user' => $user, 'role' => $user), array('user')), 'handler' => 'opAddMember'),
            'remove_member' => array('description' => 'Remove a user from the group.', 'read_only' => false,
                'input' => Schema::obj(array('user' => $user), array('user')), 'handler' => 'opRemoveMember'),
            'member_count' => array('description' => 'Count members of the group.', 'read_only' => true,
                'input' => Schema::obj(), 'handler' => 'opMemberCount'),
        );
    }
    public function opAddMember(Context $context, Transaction $tx, $object, array $args): array {
        $this->touchUser($context, $tx, $args['user']);
        Objects::process($context->modx, 'security/group/user/create', array('usergroup' => (int) $object->get('id')) + $args);
        return array();
    }
    public function opRemoveMember(Context $context, Transaction $tx, $object, array $args): array {
        // The API runs as the service user: taking its groups away could cut the API off.
        if ((int) $args['user'] === (int) $context->modx->getOption('modxmcp.service_user_id', null, 1)) {
            throw new \ModxMCP\ApiException('validation_failed', 'The service user of modxMCP cannot be removed from a group through the API.');
        }
        $this->touchUser($context, $tx, $args['user']);
        Objects::process($context->modx, 'security/group/user/remove', array('usergroup' => (int) $object->get('id')) + $args);
        return array();
    }
    /** Membership rows and the primary group are part of the user's state too: journal the user with the group. */
    private function touchUser(Context $context, Transaction $tx, $userId) {
        $user = $context->kinds->get('user');
        if ($user) { $tx->touch($user, $userId); }
    }
    public function opMemberCount(Context $context, $object, array $args): array {
        return array('members' => (int) $context->modx->getCount('modUserGroupMember', array('user_group' => $object->get('id'))));
    }

}
