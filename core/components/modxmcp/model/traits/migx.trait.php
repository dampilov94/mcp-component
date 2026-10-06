<?php
/** migx operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPMigxTrait {
    /**
     * Load the MIGX model package so the migxConfig class is mapped. MIGX's own config
     * processors (XdbEdit) require the manager controller context, so we operate on the
     * migxConfig xPDO object directly instead.
     */
    private function loadMigx() {
        $core = $this->modx->getOption('migx.core_path', null, $this->modx->getOption('core_path') . 'components/migx/');
        $this->modx->addPackage('migx', $core . 'model/');
        if (!$this->modx->loadClass('migxConfig')) {
            throw new ModxMCPClientException('MIGX is not installed (migxConfig class not found).');
        }
    }

    private function migxConfigFields() {
        return array('name', 'formtabs', 'contextmenus', 'actionbuttons', 'columnbuttons', 'filters', 'extended', 'permissions', 'fieldpermissions', 'columns', 'category', 'published');
    }

    private function listMigxConfigs($data) {
        $this->loadMigx();
        $c = $this->modx->newQuery('migxConfig');
        $c->where(array('deleted' => 0));
        if (!empty($data['query'])) {
            $c->where(array('name:LIKE' => '%' . $data['query'] . '%', 'OR:category:LIKE' => '%' . $data['query'] . '%'));
        }
        if (!empty($data['category'])) { $c->where(array('category' => (string) $data['category'])); }
        $total = $this->modx->getCount('migxConfig', $c);
        $c->sortby('name', 'ASC');
        $limit = $this->getListLimit($data);
        if ($limit > 0) { $c->limit($limit, $this->getListStart($data)); }
        $rows = array();
        foreach ($this->modx->getCollection('migxConfig', $c) as $cfg) {
            $rows[] = array(
                'id'        => (int) $cfg->get('id'),
                'name'      => $cfg->get('name'),
                'category'  => $cfg->get('category'),
                'published' => (int) $cfg->get('published'),
            );
        }
        return array('total' => $total, 'results' => $rows);
    }

    private function getMigxConfig($data) {
        $this->loadMigx();
        if (empty($data['id'])) { throw new ModxMCPClientException('migx_get_config: id is required.'); }
        $cfg = $this->modx->getObject('migxConfig', (int) $data['id']);
        if (!$cfg) { throw new ModxMCPClientException('migx_get_config: config ' . (int) $data['id'] . ' not found.'); }
        return $cfg->toArray();
    }

    private function saveMigxConfig($data, $isCreate) {
        $this->loadMigx();
        if ($isCreate) {
            if (empty($data['name'])) { throw new ModxMCPClientException('migx_create_config: name is required.'); }
            $cfg = $this->modx->newObject('migxConfig');
        } else {
            if (empty($data['id'])) { throw new ModxMCPClientException('migx_update_config: id is required.'); }
            $cfg = $this->modx->getObject('migxConfig', (int) $data['id']);
            if (!$cfg) { throw new ModxMCPClientException('migx_update_config: config ' . (int) $data['id'] . ' not found.'); }
        }
        foreach ($this->migxConfigFields() as $f) {
            if (array_key_exists($f, $data)) { $cfg->set($f, $data[$f]); }
        }
        if (!$cfg->save()) { throw new ModxMCPClientException('migx save_config: save failed.'); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit($isCreate ? 'migx_create_config' : 'migx_update_config', 'migx', array('id' => (int) $cfg->get('id'), 'name' => $cfg->get('name')));
        return array('id' => (int) $cfg->get('id'), 'name' => $cfg->get('name'), 'category' => $cfg->get('category'));
    }

    private function deleteMigxConfig($data) {
        $this->loadMigx();
        if (empty($data['id'])) { throw new ModxMCPClientException('migx_delete_config: id is required.'); }
        $cfg = $this->modx->getObject('migxConfig', (int) $data['id']);
        if (!$cfg) { throw new ModxMCPClientException('migx_delete_config: config ' . (int) $data['id'] . ' not found.'); }
        $name = $cfg->get('name');
        if (!$cfg->remove()) { throw new ModxMCPClientException('migx_delete_config: remove failed.'); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('migx_delete_config', 'migx', array('id' => (int) $data['id'], 'name' => $name));
        return array('deleted' => true, 'id' => (int) $data['id'], 'name' => $name);
    }
}
