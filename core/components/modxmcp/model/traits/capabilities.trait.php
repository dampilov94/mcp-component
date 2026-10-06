<?php
/** capabilities operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPCapabilitiesTrait {
    /**
     * Add-ons that modxMCP has DEDICATED tooling for (their own manager UI + built-in MCP
     * actions). Snippet/chunk-only add-ons are intentionally NOT listed here — MCP can read
     * and call those itself via the generic element tools, so flagging them adds no value.
     */
    private function knownIntegrations() {
        return array(
            array('ns' => 'minishop2',   'label' => 'miniShop2',   'note' => 'Dedicated tools: product options, products, links, categories, orders (ms2_*).'),
            array('ns' => 'migx',        'label' => 'MIGX',        'note' => 'Dedicated tools: MIGX configs CRUD (migx_*) + create MIGX-type TVs.'),
            array('ns' => 'versionx',    'label' => 'VersionX',    'note' => 'Dedicated tools: element/resource history + rollback (versionx_*).'),
            array('ns' => 'virtualpage', 'label' => 'VirtualPage', 'note' => 'Dedicated tools: events / handlers / routes CRUD + resolve (virtualpage_*).'),
        );
    }

    /**
     * Public: report which known add-ons are installed and what modxMCP can do with each.
     * Read-only (namespace / snippet presence + best-effort installed package version).
     */
    public function getIntegrationsReport() {
        $out = array();
        $availability = $this->integrationAvailability();
        foreach ($this->knownIntegrations() as $def) {
            $installed = (bool) $this->modx->getObject('modNamespace', array('name' => $def['ns']));
            if (!$installed && !empty($def['snippet'])) {
                $installed = (bool) $this->modx->getObject('modSnippet', array('name' => $def['snippet']));
            }
            $version = null;
            if ($installed) {
                $c = $this->modx->newQuery('transport.modTransportPackage');
                $c->where(array('package_name' => $def['label'], 'installed:!=' => null));
                $c->sortby('installed', 'DESC');
                $c->limit(1);
                $pkg = $this->modx->getObject('transport.modTransportPackage', $c);
                if ($pkg) {
                    $version = trim($pkg->get('version_major') . '.' . $pkg->get('version_minor') . '.' . $pkg->get('version_patch'), '.');
                }
            }
            $out[] = array(
                'key'       => $def['ns'],
                'label'     => $def['label'],
                'installed' => $installed,
                'available' => $availability[$def['ns']]['available'],
                'unavailable_reason' => $availability[$def['ns']]['reason'],
                'version'   => $version,
                'note'      => $def['note'],
            );
        }
        return array('integrations' => $out);
    }

    private function toggleableGroupKeys() {
        return array('versionx', 'virtualpage', 'minishop2', 'migx', 'access', 'property_sets', 'contexts', 'package_management', 'namespaces', 'lexicon');
    }

    private function disabledGroups() {
        $raw = (string) $this->modx->getOption('modxmcp.disabled_groups', null, '');
        $out = array();
        foreach (explode(',', $raw) as $g) { $g = trim($g); if ($g !== '') { $out[$g] = true; } }
        return $out;
    }

    private function actionToGroup() {
        $groups = $this->listSupportedActions();
        $toggle = array_flip($this->toggleableGroupKeys());
        $map = array();
        foreach ($groups as $g => $actions) {
            if (!isset($toggle[$g])) { continue; }
            foreach ($actions as $a) { $map[$a] = $g; }
        }
        return $map;
    }

    private function assertCapabilityEnabled($action) {
        $map = $this->actionToGroup();
        if (!isset($map[$action])) { return; }
        $disabled = $this->disabledGroups();
        if (isset($disabled[$map[$action]])) {
            throw new ModxMCPClientException("Возможность '{$map[$action]}' выключена в modxMCP — включите её в админке: Дополнения → modxMCP. (Capability '{$map[$action]}' is disabled; enable it in Components > modxMCP.)");
        }
        if (in_array($map[$action], array('minishop2', 'migx', 'versionx', 'virtualpage'), true)) {
            $availability = $this->integrationAvailability();
            if (!$availability[$map[$action]]['available']) {
                throw new ModxMCPClientException('Integration ' . $map[$action] . ' is unavailable (' . $availability[$map[$action]]['reason'] . '). Install or repair it before using its tools.');
            }
        }
    }

    /** Cheap installation probes; do not initialise add-on services merely to list tools. */
    private function integrationAvailability() {
        if ($this->integrationAvailabilityCache !== null) { return $this->integrationAvailabilityCache; }
        $probes = array(
            'minishop2' => array('minishop2.core_path', 'model/minishop2/minishop2.class.php'),
            'migx' => array('migx.core_path', 'model/migx/migxconfig.class.php'),
            'versionx' => array('versionx.core_path', 'model/versionx.class.php'),
            'virtualpage' => array('virtualpage_core_path', 'model/virtualpage/virtualpage.class.php'),
        );
        $names = array();
        $query = $this->modx->newQuery('modNamespace');
        $query->select(array('name'));
        $query->where(array('name:IN' => array_keys($probes)));
        if (!$query->prepare() || !$query->stmt->execute()) { throw new Exception('Cannot inspect integration namespaces.'); }
        while ($name = $query->stmt->fetchColumn()) { $names[$name] = true; }
        $out = array();
        foreach ($probes as $key => $probe) {
            $path = $this->modx->getOption($probe[0], null, $this->modx->getOption('core_path') . 'components/' . $key . '/');
            $file = rtrim($this->resolveModxPathPlaceholders((string) $path), '/\\') . '/' . $probe[1];
            clearstatcache(true, $file);
            $present = isset($names[$key]);
            $code = $present && is_file($file);
            $out[$key] = array('installed' => $present, 'available' => $code,
                'reason' => !$present ? 'not_installed' : (!$code ? 'code_missing' : null));
        }
        $this->integrationAvailabilityCache = $out;
        return $out;
    }

    public function getCapabilities() {
        $groups = $this->listSupportedActions();
        $toggle = $this->toggleableGroupKeys();
        $disabled = $this->disabledGroups();
        $availability = $this->integrationAvailability();
        $supported = array();
        $disabledActions = array();
        $unavailableActions = array();
        $unavailableGroups = array();
        $reasons = array();
        $integrations = array();
        foreach ($availability as $key => $status) {
            $integrations[$key] = $status['available'];
            if (!$status['available']) { $unavailableGroups[] = $key; $reasons[$key] = $status['reason']; }
        }
        foreach ($groups as $group => $actions) {
            $supported = array_merge($supported, $actions);
            if (in_array($group, $toggle, true) && isset($disabled[$group])) {
                $disabledActions = array_merge($disabledActions, $actions);
            }
            if (isset($availability[$group]) && !$availability[$group]['available']) {
                $unavailableActions = array_merge($unavailableActions, $actions);
            }
        }
        if (!$this->modx->getOption('modxmcp.allow_run_processor', null, false)) {
            $unavailableActions[] = 'run_processor';
            $reasons['run_processor'] = 'disabled_by_setting';
        }
        return array(
            'toggleable_groups' => $toggle,
            'disabled_groups' => array_keys($disabled),
            'disabled_actions' => array_values(array_unique($disabledActions)),
            'supported_actions' => array_values(array_unique($supported)),
            'available_actions' => array_values(array_diff($supported, $disabledActions, $unavailableActions)),
            'unavailable_groups' => $unavailableGroups,
            'unavailable_actions' => array_values(array_unique($unavailableActions)),
            'unavailable_reasons' => (object) $reasons,
            'integrations' => (object) $integrations,
            'fingerprint' => $this->capabilitiesFingerprint(),
        );
    }

    /** Detect group, installation and gated-processor changes on ordinary API responses. */
    public function capabilitiesFingerprint($refresh = false) {
        if ($refresh) { $this->integrationAvailabilityCache = null; }
        $integrations = array();
        foreach ($this->integrationAvailability() as $key => $status) { $integrations[$key] = $status['available']; }
        return hash('sha256', json_encode(array(
            'groups' => (string) $this->modx->getOption('modxmcp.disabled_groups', null, ''),
            'integrations' => $integrations,
            'run_processor' => (bool) $this->modx->getOption('modxmcp.allow_run_processor', null, false),
            'actions' => $this->listSupportedActions(),
        ), JSON_UNESCAPED_UNICODE));
    }
}
