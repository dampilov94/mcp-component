<?php
namespace ModxMCP\Kind;

/** Which settings hold credentials, and the rule that keeps modxMCP's own settings out of the API's reach. */
final class Secrets {
    const PATTERN = '/(^|[_.])(pass|password|passwd|secret|token|api_?key|private_?key|salt)([_.]|$)/iu';
    const HIDDEN = '[hidden]';
    const OWN_NAMESPACE = 'modxmcp';
    const OWN_PREFIX = 'modxmcp.';
    const OWN_MESSAGE = 'modxMCP settings are changed in the MODX manager, not through the API.';

    /** A setting is secret when its xtype is text-password or its key names a credential. */
    public static function isSecret($key, $xtype) {
        if (trim((string) $xtype) === 'text-password') { return true; }
        $match = @preg_match(self::PATTERN, (string) $key);
        // A key the pattern cannot even read (broken encoding) is hidden rather than shown.
        return $match === false || $match === 1;
    }

    /** True for a setting that belongs to modxMCP itself: namespace modxmcp or a key starting with "modxmcp.". */
    public static function isOwn($key, $namespace) {
        return mb_strtolower(trim((string) $namespace), 'UTF-8') === self::OWN_NAMESPACE
            || strncmp(mb_strtolower(trim((string) $key), 'UTF-8'), self::OWN_PREFIX, strlen(self::OWN_PREFIX)) === 0;
    }
}
