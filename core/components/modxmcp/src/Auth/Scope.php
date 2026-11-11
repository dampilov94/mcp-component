<?php
namespace ModxMCP\Auth;

/** Token access levels; each level includes everything below it. */
final class Scope {
    const READ = 'read';
    const CONTENT = 'content';
    const DEVELOP = 'develop';
    const ADMIN = 'admin';

    const LEVELS = array(self::READ => 1, self::CONTENT => 2, self::DEVELOP => 3, self::ADMIN => 4);

    public static function valid($scope) {
        return is_string($scope) && isset(self::LEVELS[$scope]);
    }

    public static function allows($tokenScope, $required) {
        return self::valid($tokenScope) && self::valid($required)
            && self::LEVELS[$tokenScope] >= self::LEVELS[$required];
    }
}
