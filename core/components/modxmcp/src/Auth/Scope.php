<?php
namespace ModxMCP\Auth;

use ModxMCP\Draft\DraftPolicy;

/** Token access levels. The draft-only scope also has its own action and kind rules. */
final class Scope {
    const READ = 'read';
    const DRAFT_ONLY = DraftPolicy::SCOPE;
    const CONTENT = 'content';
    const DEVELOP = 'develop';
    const ADMIN = 'admin';

    const LEVELS = array(self::READ => 1, self::DRAFT_ONLY => 2, self::CONTENT => 2, self::DEVELOP => 3, self::ADMIN => 4);

    public static function valid($scope) {
        return is_string($scope) && isset(self::LEVELS[$scope]);
    }

    public static function allows($tokenScope, $required) {
        return self::valid($tokenScope) && self::valid($required)
            && self::LEVELS[$tokenScope] >= self::LEVELS[$required];
    }
}
