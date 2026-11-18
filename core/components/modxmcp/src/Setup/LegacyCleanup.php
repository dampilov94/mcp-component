<?php
namespace ModxMCP\Setup;

/**
 * Removes what a 1.x install leaves behind when the package is upgraded to 2.0 (MODX copies files and keeps settings
 * but never deletes anything). The list is fixed and explicit; nothing else is touched, and nothing outside the
 * component's own core directory. `logs/` (a 1.x audit log the owner may want) is never in the list.
 *
 * When a later stage ships one of the help files below again, take it off HELP: a test fails while it is listed
 * and present in the package.
 */
final class LegacyCleanup {
    /** Directories below core/components/modxmcp/, removed with everything in them. */
    const DIRECTORIES = array('model');
    /** Files below core/components/modxmcp/ that only 1.x had (token processors of the old panel). */
    const FILES = array(
        'processors/mgr/regeneratetoken.class.php',
        'processors/mgr/createtoken.class.php',
        'processors/mgr/revoketoken.class.php',
    );
    /** 1.x help topics 2.0 does not ship; removed only when the 1.x model class shows the old version was installed. */
    const HELP = array('docs/acl.md', 'docs/minishop2.md', 'docs/study_component.md');
    /** The file that only a 1.x install has. */
    const MARKER = 'model/modxmcp.class.php';
    /** Settings nothing reads in 2.0. */
    const SETTINGS = array('modxmcp.audit_log', 'modxmcp.component_code_roots');
    /** Groups that no longer exist, to be taken out of modxmcp.disabled_groups. */
    const GROUPS = array('versionx');

    /**
     * @param string $componentDir core/components/modxmcp/
     * @return string[] one line per removed item
     */
    public static function run(\modX $modx, $componentDir) {
        $base = realpath($componentDir);
        $log = array();
        if ($base === false || !is_dir($base)) { return $log; }
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $wasV1 = is_file($base . '/' . self::MARKER);
        if ($wasV1) {
            foreach (self::HELP as $relative) { self::removeFile($base, $relative, $log); }
        }
        foreach (self::FILES as $relative) { self::removeFile($base, $relative, $log); }
        foreach (self::DIRECTORIES as $relative) { self::removeTree($base, $relative, $log); }
        foreach (self::SETTINGS as $key) {
            $setting = $modx->getObject('modSystemSetting', array('key' => $key));
            if ($setting && $setting->remove()) { $log[] = "Removed the setting {$key}."; }
        }
        $groups = $modx->getObject('modSystemSetting', array('key' => 'modxmcp.disabled_groups'));
        if ($groups) {
            $before = array_map('trim', explode(',', (string) $groups->get('value')));
            $after = array_values(array_filter($before, function ($group) { return $group !== '' && !in_array($group, LegacyCleanup::GROUPS, true); }));
            if ($after !== array_values(array_filter($before, 'strlen'))) {
                $groups->set('value', implode(',', $after));
                if ($groups->save()) { $log[] = 'Removed ' . implode(', ', self::GROUPS) . ' from modxmcp.disabled_groups.'; }
            }
        }
        return $log;
    }

    private static function removeFile($base, $relative, array &$log) {
        $path = $base . '/' . $relative;
        if (!is_file($path) && !is_link($path)) { return; }
        if (@unlink($path)) { $log[] = "Removed the 1.x file {$relative}."; }
    }

    private static function removeTree($base, $relative, array &$log) {
        $path = $base . '/' . $relative;
        if (is_link($path) || !is_dir($path)) { return; }
        if (self::deleteDirectory($path)) { $log[] = "Removed the 1.x directory {$relative}/."; }
    }

    /** Delete a directory and its content; a symbolic link is removed, never followed. */
    private static function deleteDirectory($dir) {
        $ok = true;
        foreach (@scandir($dir) ?: array() as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) { $ok = self::deleteDirectory($path) && $ok; }
            else { $ok = @unlink($path) && $ok; }
        }
        return @rmdir($dir) && $ok;
    }
}
