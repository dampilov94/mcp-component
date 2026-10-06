<?php
/** packages operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPPackagesTrait {
    /**
     * Capability groups that the admin can switch off (via modxmcp.disabled_groups). Core
     * groups (elements, system, media, ops, ...) are always on. Disabling a group makes the
     * server reject its actions AND makes the client stop advertising those tools.
     */
    // --- Package management (toggleable group) ---
    private function defaultProviderId() {
        $c = $this->modx->newQuery('transport.modTransportProvider');
        $c->where(array('name:=' => 'modx.com', 'OR:name:=' => 'modxcms.com'));
        $p = $this->modx->getObject('transport.modTransportProvider', $c);
        if (!$p) { $p = $this->modx->getObject('transport.modTransportProvider', array('id:>' => 0)); }
        return $p ? (int) $p->get('id') : 0;
    }

    private function installPackage($data) {
        $name = isset($data['package']) ? trim((string) $data['package']) : '';
        if ($name === '') { throw new ModxMCPClientException('install_package: "package" (name) is required.'); }
        $providerId = isset($data['provider']) ? (int) $data['provider'] : $this->defaultProviderId();
        if (!$providerId) { throw new ModxMCPClientException('install_package: no transport provider is configured.'); }
        $existing = $this->modx->getObject('transport.modTransportPackage', array('package_name' => $name, 'installed:!=' => null));
        if ($existing) { return array('status' => 'already_installed', 'package' => $name, 'signature' => $existing->get('signature')); }
        $listResp = $this->modx->runProcessor('workspace/packages/rest/getlist', array('provider' => $providerId, 'query' => $name, 'limit' => 20));
        if (!$listResp || $listResp->isError()) { throw new ModxMCPClientException('install_package: provider search failed: ' . ($listResp ? $this->formatProcessorErrors($listResp) : 'no response')); }
        $listData = json_decode($listResp->getResponse(), true);
        $rows = isset($listData['results']) ? $listData['results'] : array();
        if (empty($rows)) { throw new ModxMCPClientException("install_package: no package named '{$name}' found on the provider."); }
        $chosen = null;
        foreach ($rows as $row) { if (isset($row['name']) && strcasecmp($row['name'], $name) === 0) { $chosen = $row; break; } }
        if (!$chosen) { $chosen = $rows[0]; }
        if (empty($chosen['location']) || empty($chosen['signature'])) { throw new ModxMCPClientException('install_package: provider result is missing location/signature.'); }
        $dlResp = $this->modx->runProcessor('workspace/packages/rest/download', array('info' => $chosen['location'] . '::' . $chosen['signature'], 'provider' => $providerId));
        if (!$dlResp || $dlResp->isError()) { throw new ModxMCPClientException('install_package: download failed: ' . ($dlResp ? $this->formatProcessorErrors($dlResp) : 'no response')); }
        $dlObj = $dlResp->getObject();
        $signature = (is_array($dlObj) && !empty($dlObj['signature'])) ? $dlObj['signature'] : $chosen['signature'];
        $instResp = $this->modx->runProcessor('workspace/packages/install', array('signature' => $signature));
        if (!$instResp || $instResp->isError()) { throw new ModxMCPClientException('install_package: install failed: ' . ($instResp ? $this->formatProcessorErrors($instResp) : 'no response')); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('install_package', 'system', array('package' => $name, 'signature' => $signature));
        return array('status' => 'installed', 'package' => isset($chosen['name']) ? $chosen['name'] : $name, 'signature' => $signature, 'version' => isset($chosen['version']) ? $chosen['version'] : null);
    }

    private function uninstallPackage($data) {
        $sig = isset($data['signature']) ? (string) $data['signature'] : '';
        if ($sig === '') { throw new ModxMCPClientException('uninstall_package: "signature" is required (e.g. migx-2.13.0-pl).'); }
        $resp = $this->modx->runProcessor('workspace/packages/uninstall', array('signature' => $sig));
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'uninstall_package: no response.'); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('uninstall_package', 'system', array('signature' => $sig));
        return $this->normalizeProcessorResponse($resp);
    }

    private function listProviders($data) {
        $resp = $this->modx->runProcessor('workspace/providers/getlist', array('limit' => 0));
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'list_providers: no response.'); }
        $d = json_decode($resp->getResponse(), true);
        return array('total' => isset($d['total']) ? (int) $d['total'] : 0, 'results' => isset($d['results']) ? $d['results'] : array());
    }

    private function searchPackages($data) {
        $providerId = isset($data['provider']) ? (int) $data['provider'] : $this->defaultProviderId();
        if (!$providerId) { throw new ModxMCPClientException('search_packages: no transport provider configured.'); }
        $params = array('provider' => $providerId, 'query' => isset($data['query']) ? (string) $data['query'] : '', 'limit' => isset($data['limit']) ? (int) $data['limit'] : 20, 'start' => isset($data['start']) ? (int) $data['start'] : 0);
        $resp = $this->modx->runProcessor('workspace/packages/rest/getlist', $params);
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'search_packages: no response (provider unreachable?).'); }
        $d = json_decode($resp->getResponse(), true);
        return array('provider' => $providerId, 'total' => isset($d['total']) ? (int) $d['total'] : 0, 'results' => isset($d['results']) ? $d['results'] : array());
    }

    private function saveProvider($data, $isCreate) {
        $props = is_array($data) ? $data : array();
        unset($props['action'], $props['elementType']);
        if ($isCreate) {
            if (empty($props['name']) || empty($props['service_url'])) { throw new ModxMCPClientException('create_provider: name and service_url are required.'); }
            $resp = $this->modx->runProcessor('workspace/providers/create', $props);
        } else {
            if (empty($props['id'])) { throw new ModxMCPClientException('update_provider: id is required.'); }
            $resp = $this->modx->runProcessor('workspace/providers/update', $props);
        }
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'provider: no response.'); }
        $this->logAudit($isCreate ? 'create_provider' : 'update_provider', 'provider', array_intersect_key($props, array_flip(array('id', 'name', 'service_url'))));
        return $this->normalizeProcessorResponse($resp);
    }

    private function deleteProvider($data) {
        if (empty($data['id'])) { throw new ModxMCPClientException('delete_provider: id is required.'); }
        $resp = $this->modx->runProcessor('workspace/providers/remove', array('id' => (int) $data['id']));
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'delete_provider: no response.'); }
        $this->logAudit('delete_provider', 'provider', array('id' => (int) $data['id']));
        return array('deleted' => true, 'id' => (int) $data['id']);
    }
}
