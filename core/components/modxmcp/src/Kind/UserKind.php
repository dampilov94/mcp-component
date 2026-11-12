<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;

/**
 * Manager and web users. Contact data and the block state live in the user's profile row
 * (`via` fields); the password is write-only; users with the sudo flag, and the service user
 * the API itself runs as, are protected.
 */
final class UserKind extends Kind {
    const SERVICE_MESSAGE = 'The service user of modxMCP cannot be changed through the API.';
    const SUDO_MESSAGE = 'A user with the sudo flag cannot be deleted or copied, and its username, password, active and blocked state cannot be changed through the API.';

    public function name() { return 'user'; }
    public function label() { return 'User'; }
    public function modelClass() { return 'modUser'; }
    public function processorPath() { return 'security/user/'; }
    public function deleteProcessor() { return 'delete'; }
    public function processorExtras($op) { return array(); }
    public function group() { return 'access'; }
    // Users carry personal data: even reading them needs the admin scope.
    public function readScope() { return Scope::ADMIN; }
    public function writeScope() { return Scope::ADMIN; }
    public function nameField() { return 'username'; }

    public function fields() {
        $profile = function ($type, array $more = array()) { return array('type' => $type, 'via' => 'profile') + $more; };
        return array(
            'id'            => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'username'      => array('type' => 'string', 'list' => true, 'required' => true),
            'active'        => array('type' => 'boolean', 'list' => true, 'description' => 'A new user is inactive unless this is true.'),
            'sudo'          => array('type' => 'boolean', 'list' => true, 'read_only' => true, 'description' => 'Super user; never changed through the API.'),
            'primary_group' => array('type' => 'integer', 'list' => true, 'description' => 'Id of the primary user group, 0 for none.'),
            'class_key'     => array('type' => 'string', 'read_only' => true),
            'fullname'      => $profile('string', array('list' => true)),
            'email'         => $profile('string', array('list' => true)),
            'phone'         => $profile('string'),
            'mobilephone'   => $profile('string'),
            'comment'       => $profile('string'),
            'blocked'       => $profile('boolean', array('list' => true)),
            'blockeduntil'  => $profile('string', array('format' => 'datetime', 'description' => 'Blocked until this time; "" for none.')),
            'blockedafter'  => $profile('string', array('format' => 'datetime', 'description' => 'Blocked after this time; "" for none.')),
            'lastlogin'     => $profile('string', array('format' => 'datetime', 'read_only' => true, 'derived' => true)),
            'thislogin'     => $profile('string', array('format' => 'datetime', 'read_only' => true, 'derived' => true)),
            'logincount'    => $profile('integer', array('read_only' => true, 'derived' => true)),
            'password'      => array('type' => 'string', 'write_only' => true, 'required' => true,
                'description' => 'Write-only: required on create, optional on update, never returned. MODX enforces password_min_length.'),
        );
    }

    public function relationSpecs() {
        return array(
            'profile' => array('class' => 'modUserProfile', 'fk' => 'internalKey', 'present' => 'id', 'hidden' => true,
                'description' => 'The profile row; its fields are the `via` fields of the user.'),
            'groups' => array('class' => 'modUserGroupMember', 'fk' => 'member', 'present' => array('user_group', 'role'),
                'description' => 'Groups of the user with their role ids. Changed with modx_invoke add_member / remove_member on user_group.'),
            'settings' => array('class' => 'modUserSetting', 'fk' => 'user', 'delete_only' => true),
        );
    }

    public function viaRow($object, $relation) {
        return $relation === 'profile' ? $object->getOne('Profile') : null;
    }

    // ---- what the processors need ----

    /**
     * The password goes through the processors' own mechanism: a specified password, never one
     * generated or e-mailed by MODX. Everything else passes unchanged; the update data already
     * carries the current active, blocked and sudo values (Objects::save sends the whole record),
     * which the update processor needs: it resets an absent checkbox, sudo included, to off.
     */
    public function processorData($op, array $data) {
        if (!array_key_exists('password', $data)) { return $data; }
        $password = $data['password'];
        unset($data['password']);
        $data['passwordgenmethod'] = 'spec';
        $data['specifiedpassword'] = $password;
        $data['confirmpassword'] = $password;
        $data['passwordnotifymethod'] = 's';
        if ($op === 'update') { $data['newpassword'] = 'true'; }
        return $data;
    }

    /** A copy has no memberships, so it has no primary group either. */
    public function duplicateOverrides($source) {
        return array('primary_group' => 0) + parent::duplicateOverrides($source);
    }

    // ---- protection ----

    private function isService(\modX $modx, $object) {
        return (int) $object->get('id') === (int) $modx->getOption('modxmcp.service_user_id', null, 1);
    }

    public function assertObjectWritable(\modX $modx, $object, $op) {
        if ($this->isService($modx, $object)) { throw new ApiException('validation_failed', self::SERVICE_MESSAGE); }
        if (($op === 'delete' || $op === 'duplicate') && $object->get('sudo')) {
            throw new ApiException('validation_failed', self::SUDO_MESSAGE);
        }
    }

    public function assertUpdatable(\modX $modx, $object, array $columns) {
        if (!$object->get('sudo')) { return; }
        // The same value is no change; anything else about a sudo user's login is refused.
        $refused = array_key_exists('password', $columns);
        foreach (array('username', 'active', 'blocked') as $column) {
            if (!array_key_exists($column, $columns)) { continue; }
            $current = $this->rawValue($object, $column);
            if ($column === 'username' ? (string) $current !== (string) $columns[$column] : (bool) $current !== (bool) $columns[$column]) { $refused = true; }
        }
        if ($refused) { throw new ApiException('validation_failed', self::SUDO_MESSAGE); }
    }

    // ---- what a diff and the journal may show ----

    /** The stored hash and everything that goes with it; the generic column-name pattern would miss cachepwd and hash_class. */
    public function secretColumns(array $state) {
        return array('password', 'cachepwd', 'salt', 'hash_class', 'remote_key', 'remote_data', 'session_stale');
    }

    public function maskRelationRows($name, array $rows) {
        if ($name !== 'profile') { return $rows; }
        foreach ($rows as $index => $row) { unset($rows[$index]['sessionid']); }
        return $rows;
    }
}
