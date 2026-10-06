<?php
/** administration operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPAdministrationTrait {
    private function regenerateToken() {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $token = md5(uniqid('modxmcp', true)) . md5(uniqid('token', true));
        }
        $setting = $this->modx->getObject('modSystemSetting', array('key' => 'modxmcp.api_token'));
        if (!$setting) {
            $setting = $this->modx->newObject('modSystemSetting');
            $setting->fromArray(array(
                'key'       => 'modxmcp.api_token',
                'namespace' => 'modxmcp',
                'area'      => 'modxmcp:main',
                'xtype'     => 'textfield',
            ), '', true, true);
        }
        $setting->set('value', $token);
        if (!$setting->save()) { throw new ModxMCPClientException('regenerate_token: could not save the new token.'); }
        $this->modx->reloadConfig();
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('regenerate_token', 'system', array());
        return array('token' => $token);
    }

    /**
     * Contexts (modContext) + their settings (modContextSetting), via core processors.
     */
    private function contextActionMap() {
        return $this->procMapFor('context');
    }

    private function runContextAction($action, $data) {
        $map = $this->contextActionMap();
        if (!isset($map[$action])) { throw new ModxMCPClientException("Unknown context action: {$action}."); }
        $cfg = $map[$action];
        $props = is_array($data) ? $data : array();
        unset($props['action'], $props['elementType']);
        $this->modx->lexicon->load('core:default', 'core:context', 'core:setting');

        // context/setting/create identifies the context via `fk` (get/remove/getlist use
        // `context_key`); mirror context_key -> fk so a single param works everywhere.
        if (in_array($action, array('create_context_setting', 'update_context_setting'), true)) {
            if (!isset($props['fk']) && isset($props['context_key'])) { $props['fk'] = $props['context_key']; }
            if (!isset($props['namespace'])) { $props['namespace'] = 'core'; }
        }

        $isList = !empty($cfg['list']);
        if ($isList && !isset($props['limit'])) { $props['limit'] = 0; }

        $response = $this->modx->runProcessor($cfg['processor'], $props);
        if (!$response) { throw new ModxMCPClientException("Context processor not found or returned nothing: {$cfg['processor']}"); }
        if ($response->isError()) { throw new ModxMCPClientException($this->formatProcessorErrors($response)); }

        $this->logAudit($action, 'context', array_intersect_key($props, array_flip(array('key', 'context_key', 'name'))));

        if ($isList) {
            $decoded = json_decode($response->getResponse(), true);
            return array(
                'total'   => isset($decoded['total']) ? (int) $decoded['total'] : 0,
                'results' => $this->stripNoiseFields(isset($decoded['results']) ? $decoded['results'] : array()),
            );
        }
        return $this->normalizeProcessorResponse($response);
    }

    /**
     * Map of Access-Control actions to the core MODX security processor that backs them.
     * 'list' => true means the processor returns a getlist {total,results} payload.
     */
    private function aclActionMap() {
        return $this->procMapFor('acl');
    }

    /**
     * Generic Access-Control dispatcher: forwards $data to the mapped security processor,
     * with a few param normalisations that the manager UI would otherwise supply.
     */
    private function runAclAction($action, $data) {
        $map = $this->aclActionMap();
        if (!isset($map[$action])) { throw new ModxMCPClientException("Unknown ACL action: {$action}."); }
        $cfg = $map[$action];
        $props = is_array($data) ? $data : array();
        unset($props['action'], $props['elementType'], $props['type']);

        $this->modx->lexicon->load('core:default', 'core:user', 'core:access', 'core:policy', 'core:role');

        // The 'resources in group' processor expects node-style values (id after the last '_').
        if ($action === 'assign_resource_to_group') {
            if (isset($props['resource']) && ctype_digit((string) $props['resource'])) { $props['resource'] = 'n_' . $props['resource']; }
            if (isset($props['resourceGroup']) && ctype_digit((string) $props['resourceGroup'])) { $props['resourceGroup'] = 'n_' . $props['resourceGroup']; }
        }

        // Creating a user: translate a plain "password" into the manager's password fields.
        if ($action === 'create_user') {
            if (!empty($props['password'])) {
                if (empty($props['passwordgenmethod']))  { $props['passwordgenmethod'] = 'spec'; }
                if (!isset($props['specifiedpassword'])) { $props['specifiedpassword'] = $props['password']; }
                if (!isset($props['confirmpassword']))   { $props['confirmpassword'] = $props['password']; }
                unset($props['password']);
            } elseif (empty($props['specifiedpassword'])) {
                if (empty($props['passwordgenmethod'])) { $props['passwordgenmethod'] = 'g'; }
            }
            if (empty($props['passwordnotifymethod'])) { $props['passwordnotifymethod'] = 's'; }
        }

        // ACL grants/updates target a user group unless told otherwise.
        if (in_array($action, array('grant_context_access', 'update_context_access', 'grant_resourcegroup_access', 'update_resourcegroup_access'), true)) {
            if (empty($props['principal_class'])) { $props['principal_class'] = 'modUserGroup'; }
        }

        $isList = !empty($cfg['list']);
        if ($isList && !isset($props['limit'])) { $props['limit'] = 0; }

        $response = $this->modx->runProcessor($cfg['processor'], $props);
        if (!$response) { throw new ModxMCPClientException("ACL processor not found or returned nothing: {$cfg['processor']}"); }
        if ($response->isError()) { throw new ModxMCPClientException($this->formatProcessorErrors($response)); }

        // Apply access changes immediately (the manager's "Flush Permissions"). The core
        // ACL processors already flush on save; this also covers the ones that don't.
        if (strpos($action, 'list_') !== 0 && strpos($action, 'get_') !== 0 && $this->modx->getCacheManager()) {
            $this->modx->getCacheManager()->flushPermissions();
        }

        $logFields = array_intersect_key($props, array_flip(array('id', 'usergroup', 'user', 'target', 'principal', 'resource', 'resourceGroup', 'name')));
        $this->logAudit($action, 'acl', $logFields);

        if ($isList) {
            $decoded = json_decode($response->getResponse(), true);
            return array(
                'total'   => isset($decoded['total']) ? (int) $decoded['total'] : 0,
                'results' => $this->stripNoiseFields(isset($decoded['results']) ? $decoded['results'] : array()),
            );
        }
        return $this->normalizeProcessorResponse($response);
    }

    // --- Namespaces + Lexicon (toggleable groups) ---
    private function workspaceActionMap() {
        return $this->procMapFor('workspace');
    }

    private function runWorkspaceAction($action, $data) {
        $map = $this->workspaceActionMap();
        if (!isset($map[$action])) { throw new ModxMCPClientException("Unknown workspace action: {$action}."); }
        $cfg = $map[$action];
        $props = is_array($data) ? $data : array();
        unset($props['action'], $props['elementType']);
        $this->modx->lexicon->load('core:default', 'core:workspaces');
        $isList = !empty($cfg['list']);
        if ($isList && !isset($props['limit'])) { $props['limit'] = 0; }
        $response = $this->modx->runProcessor($cfg['processor'], $props);
        if (!$response || $response->isError()) { throw new ModxMCPClientException($response ? $this->formatProcessorErrors($response) : "Workspace processor not found: {$cfg['processor']}"); }
        $this->logAudit($action, 'workspace', array_intersect_key($props, array_flip(array('name', 'namespace', 'topic', 'language'))));
        if ($isList) {
            $decoded = json_decode($response->getResponse(), true);
            return array('total' => isset($decoded['total']) ? (int) $decoded['total'] : 0, 'results' => $this->stripNoiseFields(isset($decoded['results']) ? $decoded['results'] : array()));
        }
        return $this->normalizeProcessorResponse($response);
    }

    // --- Property sets (modPropertySet + modElementPropertySet), direct xPDO ---

    private function propertySetElementClass($data) {
        if (!empty($data['element_class'])) { return (string) $data['element_class']; }
        $map = array('snippet' => 'modSnippet', 'chunk' => 'modChunk', 'template' => 'modTemplate', 'plugin' => 'modPlugin', 'tv' => 'modTemplateVar');
        $t = isset($data['element_type']) ? $data['element_type'] : '';
        if (isset($map[$t])) { return $map[$t]; }
        throw new ModxMCPClientException('property set: element_class or a valid element_type (snippet/chunk/template/plugin/tv) is required.');
    }

    private function listPropertySets($data) {
        $c = $this->modx->newQuery('modPropertySet');
        if (!empty($data['query'])) { $c->where(array('name:LIKE' => '%' . $data['query'] . '%')); }
        $total = $this->modx->getCount('modPropertySet', $c);
        $c->sortby('name', 'ASC');
        $limit = $this->getListLimit($data);
        if ($limit > 0) { $c->limit($limit, $this->getListStart($data)); }
        $rows = array();
        foreach ($this->modx->getCollection('modPropertySet', $c) as $ps) {
            $rows[] = array(
                'id'          => (int) $ps->get('id'),
                'name'        => $ps->get('name'),
                'description' => $ps->get('description'),
                'category'    => (int) $ps->get('category'),
            );
        }
        return array('total' => $total, 'results' => $rows);
    }

    private function getPropertySet($data) {
        if (empty($data['id'])) { throw new ModxMCPClientException('get_property_set: id is required.'); }
        $ps = $this->modx->getObject('modPropertySet', (int) $data['id']);
        if (!$ps) { throw new ModxMCPClientException('get_property_set: property set ' . (int) $data['id'] . ' not found.'); }
        return $ps->toArray();
    }

    private function savePropertySet($data, $isCreate) {
        if ($isCreate) {
            if (empty($data['name'])) { throw new ModxMCPClientException('create_property_set: name is required.'); }
            $ps = $this->modx->newObject('modPropertySet');
        } else {
            if (empty($data['id'])) { throw new ModxMCPClientException('update_property_set: id is required.'); }
            $ps = $this->modx->getObject('modPropertySet', (int) $data['id']);
            if (!$ps) { throw new ModxMCPClientException('update_property_set: property set ' . (int) $data['id'] . ' not found.'); }
        }
        foreach (array('name', 'description', 'category', 'properties') as $f) {
            if (array_key_exists($f, $data)) { $ps->set($f, $data[$f]); }
        }
        if (!$ps->save()) { throw new ModxMCPClientException('save_property_set: save failed.'); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit($isCreate ? 'create_property_set' : 'update_property_set', 'element', array('id' => (int) $ps->get('id'), 'name' => $ps->get('name')));
        return array('id' => (int) $ps->get('id'), 'name' => $ps->get('name'));
    }

    private function deletePropertySet($data) {
        if (empty($data['id'])) { throw new ModxMCPClientException('delete_property_set: id is required.'); }
        $ps = $this->modx->getObject('modPropertySet', (int) $data['id']);
        if (!$ps) { throw new ModxMCPClientException('delete_property_set: property set ' . (int) $data['id'] . ' not found.'); }
        $name = $ps->get('name');
        if (!$ps->remove()) { throw new ModxMCPClientException('delete_property_set: remove failed.'); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('delete_property_set', 'element', array('id' => (int) $data['id'], 'name' => $name));
        return array('deleted' => true, 'id' => (int) $data['id'], 'name' => $name);
    }

    private function assignPropertySet($data) {
        if (empty($data['element']) || empty($data['property_set'])) { throw new ModxMCPClientException('assign_property_set: element and property_set are required.'); }
        $class = $this->propertySetElementClass($data);
        $criteria = array('element' => (int) $data['element'], 'element_class' => $class, 'property_set' => (int) $data['property_set']);
        if ($this->modx->getObject('modElementPropertySet', $criteria)) {
            return array('status' => 'already_assigned', 'element' => (int) $data['element'], 'property_set' => (int) $data['property_set']);
        }
        $eps = $this->modx->newObject('modElementPropertySet');
        $eps->fromArray($criteria, '', true, true);
        if (!$eps->save()) { throw new ModxMCPClientException('assign_property_set: save failed.'); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('assign_property_set', 'element', $criteria);
        return array_merge(array('status' => 'assigned'), $criteria);
    }

    private function unassignPropertySet($data) {
        if (empty($data['element']) || empty($data['property_set'])) { throw new ModxMCPClientException('unassign_property_set: element and property_set are required.'); }
        $class = $this->propertySetElementClass($data);
        $criteria = array('element' => (int) $data['element'], 'element_class' => $class, 'property_set' => (int) $data['property_set']);
        $eps = $this->modx->getObject('modElementPropertySet', $criteria);
        if (!$eps) { return array('status' => 'not_assigned', 'element' => (int) $data['element'], 'property_set' => (int) $data['property_set']); }
        if (!$eps->remove()) { throw new ModxMCPClientException('unassign_property_set: remove failed.'); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('unassign_property_set', 'element', $criteria);
        return array_merge(array('status' => 'unassigned'), $criteria);
    }

    private function listSystemSettings(array $data =[]) {
        $criteria = [];
        if (!empty($data['namespace'])) {
            $criteria['namespace'] = $data['namespace'];
        }
        if (!empty($data['area'])) {
            $criteria['area'] = $data['area'];
        }

        $settings = $this->modx->getCollection('modSystemSetting', $criteria);
        $result = [];
        foreach ($settings as $setting) {
            $result[] = $this->normalizeSystemSetting($setting);
        }
        return $result;
    }

    private function getSystemSetting(array $data) {
        $setting = $this->resolveSystemSetting($data);
        if (!$setting) {
            throw new ModxMCPClientException('System setting not found.');
        }
        return $this->normalizeSystemSetting($setting);
    }

    private function createSystemSetting(array $data) {
        if (empty($data['key'])) {
            throw new ModxMCPClientException('System setting key is required.');
        }
        if ($this->modx->getObject('modSystemSetting', ['key' => $data['key']])) {
            throw new ModxMCPClientException("System setting already exists: {$data['key']}.");
        }

        $setting = $this->modx->newObject('modSystemSetting');
        $setting->fromArray([
            'key' => $data['key'],
            'value' => array_key_exists('value', $data) ? (string)$data['value'] : '',
            'xtype' => !empty($data['xtype']) ? $data['xtype'] : 'textfield',
            'namespace' => !empty($data['namespace']) ? $data['namespace'] : 'core',
            'area' => !empty($data['area']) ? $data['area'] : 'default',
        ], '', true, true);

        if (!$setting->save()) {
            throw new ModxMCPClientException("Failed to create system setting: {$data['key']}.");
        }

        $this->refreshContentCache();
        $this->logAudit('create_system_setting', 'system_setting', ['key' => $data['key']]);
        return $this->normalizeSystemSetting($setting);
    }

    private function updateSystemSetting(array $data) {
        $setting = $this->resolveSystemSetting($data);
        if (!$setting) {
            throw new ModxMCPClientException('System setting not found.');
        }

        $allowedFields = ['key', 'value', 'xtype', 'namespace', 'area'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $setting->set($field, $data[$field]);
            }
        }

        if (!$setting->save()) {
            throw new ModxMCPClientException('Failed to update system setting.');
        }

        $this->refreshContentCache();
        $this->logAudit('update_system_setting', 'system_setting', ['key' => $setting->get('key')]);
        return $this->normalizeSystemSetting($setting);
    }

    private function deleteSystemSetting(array $data) {
        $setting = $this->resolveSystemSetting($data);
        if (!$setting) {
            throw new ModxMCPClientException('System setting not found.');
        }

        $key = $setting->get('key');
        if (!$setting->remove()) {
            throw new ModxMCPClientException("Failed to delete system setting: {$key}.");
        }

        $this->refreshContentCache();
        $this->logAudit('delete_system_setting', 'system_setting', ['key' => $key]);
        return "Successfully deleted system setting ({$key}).";
    }

    private function resolveSystemSetting(array $data) {
        if (!empty($data['key'])) {
            return $this->modx->getObject('modSystemSetting', ['key' => $data['key']]);
        }
        if (!empty($data['id'])) {
            return $this->modx->getObject('modSystemSetting', ['id' => (int)$data['id']]);
        }
        return null;
    }

    private function normalizeSystemSetting(modSystemSetting $setting) {
        return [
            'id' => $setting->get('id'),
            'key' => $setting->get('key'),
            'value' => $setting->get('value'),
            'xtype' => $setting->get('xtype'),
            'namespace' => $setting->get('namespace'),
            'area' => $setting->get('area'),
        ];
    }
}
