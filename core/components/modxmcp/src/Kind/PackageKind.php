<?php
namespace ModxMCP\Kind;

use ModxMCP\Auth\Scope;
use ModxMCP\Verify\LogReader;

/**
 * A transport package of the workspace (modTransportPackage): downloaded, installed or both. It is read only here;
 * packages are installed and removed with modx_install_package / modx_uninstall_package, which are irreversible and
 * never go through the journal's object snapshots.
 */
final class PackageKind extends Kind {
    const OWN_NAME = 'modxmcp';
    const OWN_MESSAGE = 'modxMCP cannot install, replace or remove itself through its own API: it would change the code that is running this request. Use the package manager in the MODX manager.';

    public function name() { return 'package'; }
    public function label() { return 'Package'; }
    public function modelClass() { return 'transport.modTransportPackage'; }
    public function processorPath() { return 'workspace/packages/'; }
    public function group() { return 'package_management'; }
    public function writeScope() { return Scope::ADMIN; }
    public function nameField() { return 'signature'; }
    public function processorExtras($op) { return array(); }

    public function genericWrites() { return false; }
    public function genericWritesMessage() { return 'Packages are installed and removed with modx_install_package / modx_uninstall_package.'; }

    public function fields() {
        $readOnly = array('read_only' => true);
        return array(
            'signature'    => array('type' => 'string', 'list' => true, 'description' => 'Package signature, e.g. pdotools-2.13.2-pl; the key of the package.') + $readOnly,
            'package_name' => array('type' => 'string', 'list' => true) + $readOnly,
            'version'      => array('type' => 'string', 'list' => true, 'computed' => true, 'derived' => true,
                'description' => 'major.minor.patch-release, from the version columns.') + $readOnly,
            'installed'    => array('type' => 'string', 'format' => 'datetime', 'list' => true,
                'description' => 'When the package was installed; "" when it is only downloaded.') + $readOnly,
            'provider'     => array('type' => 'integer', 'description' => 'Id of the provider it came from, 0 for none.') + $readOnly,
            'source'       => array('type' => 'string', 'description' => 'Where the package file came from.') + $readOnly,
            'updated'      => array('type' => 'string', 'format' => 'datetime') + $readOnly,
            'workspace'    => array('type' => 'integer') + $readOnly,
        );
    }

    public function defaultSort() { return array(array('package_name', 'ASC'), array('signature', 'ASC')); }
    public function searchColumns() { return array('signature', 'package_name'); }

    public function keyType() { return 'string'; }
    public function normalizeKey($raw) { return (string) $raw; }
    public function keyCriteria($key) { return array('signature' => (string) $key); }
    public function keyOf($object) { return (string) $object->get('signature'); }
    public function keyColumn() { return 'signature'; }
    public function processorKey($key) { return array('signature' => (string) $key); }

    /** The manifest, attributes and metadata of a package are large and say nothing about what a reader asks for. */
    public function stateColumns(array $columns) {
        unset($columns['manifest'], $columns['attributes'], $columns['metadata']);
        return $columns;
    }

    public function value($object, $field) {
        if ($field === 'version') { return self::versionOf($object); }
        return parent::value($object, $field);
    }

    /** "major.minor.patch" plus "-release" and its index, as the signature spells it. */
    public static function versionOf($object) {
        $version = (int) $object->get('version_major') . '.' . (int) $object->get('version_minor') . '.' . (int) $object->get('version_patch');
        $release = (string) $object->get('release');
        if ($release === '') { return $version; }
        $index = (int) $object->get('release_index');
        return $version . '-' . $release . ($index > 0 ? (string) $index : '');
    }

    /**
     * True for modxMCP's own package, judged on the package name and on the name part of the signature
     * ("modxmcp" in modxmcp-2.0.0-pl), case-insensitively.
     */
    public static function isOwn($signature, $packageName = '') {
        $signature = mb_strtolower(trim((string) $signature), 'UTF-8');
        $packageName = mb_strtolower(trim((string) $packageName), 'UTF-8');
        // The name of a signature is what comes before the first "-<digit>".
        $identifier = preg_match('/^(.*?)-\d/u', $signature, $match) ? $match[1] : $signature;
        return $packageName === self::OWN_NAME || $identifier === self::OWN_NAME;
    }

    /** True when a package row is installed (MODX also stores an all-zero date for "never"). */
    public static function isInstalled($object) {
        $installed = $object->get('installed');
        return $installed !== null && $installed !== '' && $installed !== 0 && $installed !== '0' && strncmp((string) $installed, '0000-00-00', 10) !== 0;
    }

    /** Where the MODX error log ends now: the cursor for logSince(). */
    public static function logOffset(\modX $modx) {
        $path = $modx->getCachePath() . 'logs/error.log';
        return LogReader::tail($path, 1, (int) $modx->getOption('modxmcp.max_read_bytes', null, 262144))['end_offset'];
    }

    /**
     * The error-log entries written since logOffset(): package resolvers report their problems only there.
     * @return array {new_entries, entries, truncated}
     */
    public static function logSince(\modX $modx, $offset) {
        $path = $modx->getCachePath() . 'logs/error.log';
        $log = LogReader::tail($path, 50, (int) $modx->getOption('modxmcp.max_read_bytes', null, 262144), '', $offset);
        return array('new_entries' => $log['returned'], 'entries' => $log['entries'], 'truncated' => $log['truncated']);
    }
}
