<?php
namespace ModxMCP;

/** Site-wide switches: toggleable groups, optional integrations and gated settings. */
final class Capabilities {
    /** Groups an administrator can switch off for the whole site (modxmcp.disabled_groups). */
    const TOGGLEABLE = array(
        'virtualpage', 'minishop2', 'migx', 'access', 'property_sets', 'contexts',
        'package_management', 'namespaces', 'lexicon',
    );

    /** Add-ons with dedicated support: group => [core-path setting, probe file, package name]. */
    const INTEGRATIONS = array(
        'minishop2'   => array('minishop2.core_path', 'model/minishop2/minishop2.class.php', 'miniShop2'),
        'migx'        => array('migx.core_path', 'model/migx/migxconfig.class.php', 'MIGX'),
        'virtualpage' => array('virtualpage_core_path', 'model/virtualpage/virtualpage.class.php', 'VirtualPage'),
    );

    private $modx;
    private $integrationCache = null;

    public function __construct(\modX $modx) {
        $this->modx = $modx;
    }

    /** @return string[] */
    public function disabledGroups() {
        $out = array();
        foreach (explode(',', (string) $this->modx->getOption('modxmcp.disabled_groups', null, '')) as $group) {
            $group = trim($group);
            if ($group !== '' && in_array($group, self::TOGGLEABLE, true)) { $out[$group] = true; }
        }
        return array_keys($out);
    }

    public function settingEnabled($key) {
        return (bool) $this->modx->getOption('modxmcp.' . $key, null, false);
    }

    private function resolvePath($path) {
        return strtr((string) $path, array(
            '{base_path}' => $this->modx->getOption('base_path'),
            '{core_path}' => $this->modx->getOption('core_path'),
            '{assets_path}' => $this->modx->getOption('assets_path'),
            '[[++base_path]]' => $this->modx->getOption('base_path'),
            '[[++core_path]]' => $this->modx->getOption('core_path'),
            '[[++assets_path]]' => $this->modx->getOption('assets_path'),
        ));
    }

    /**
     * Cheap installation probes; add-on services are never initialised just to list tools.
     * @return array group => [installed, available, reason]
     */
    public function integrations() {
        if ($this->integrationCache !== null) { return $this->integrationCache; }
        $names = array();
        $query = $this->modx->newQuery('modNamespace');
        $query->select(array('name'));
        $query->where(array('name:IN' => array_keys(self::INTEGRATIONS)));
        if (!$query->prepare() || !$query->stmt->execute()) { throw new \RuntimeException('Cannot inspect integration namespaces.'); }
        while ($name = $query->stmt->fetchColumn()) { $names[$name] = true; }
        $out = array();
        foreach (self::INTEGRATIONS as $key => $probe) {
            $path = $this->modx->getOption($probe[0], null, $this->modx->getOption('core_path') . 'components/' . $key . '/');
            $file = rtrim($this->resolvePath($path), '/\\') . '/' . $probe[1];
            clearstatcache(true, $file);
            $installed = isset($names[$key]);
            $available = $installed && is_file($file);
            $out[$key] = array(
                'installed' => $installed,
                'available' => $available,
                'reason'    => !$installed ? 'not_installed' : (!$available ? 'code_missing' : null),
            );
        }
        return $this->integrationCache = $out;
    }

    /** Integration status plus the installed package version, for people (manager page, capabilities). */
    public function integrationsReport() {
        $out = array();
        foreach ($this->integrations() as $key => $status) {
            $version = null;
            if ($status['installed']) {
                $query = $this->modx->newQuery('transport.modTransportPackage');
                $query->where(array('package_name' => self::INTEGRATIONS[$key][2], 'installed:!=' => null));
                $query->sortby('installed', 'DESC');
                $query->limit(1);
                $package = $this->modx->getObject('transport.modTransportPackage', $query);
                if ($package) {
                    $version = trim($package->get('version_major') . '.' . $package->get('version_minor') . '.' . $package->get('version_patch'), '.');
                }
            }
            $out[] = array('key' => $key, 'label' => self::INTEGRATIONS[$key][2], 'version' => $version) + $status;
        }
        return $out;
    }

    /**
     * Why a group cannot be used with this token, or null when it can.
     * Reasons: disabled_group, denied_for_token, not_installed, code_missing.
     */
    public function groupBlockReason($group, array $token) {
        if (in_array($group, $this->disabledGroups(), true)) { return 'disabled_group'; }
        if (in_array($group, $token['denied_groups'], true)) { return 'denied_for_token'; }
        if (isset(self::INTEGRATIONS[$group])) {
            $integrations = $this->integrations();
            if (!$integrations[$group]['available']) { return $integrations[$group]['reason']; }
        }
        return null;
    }
}
