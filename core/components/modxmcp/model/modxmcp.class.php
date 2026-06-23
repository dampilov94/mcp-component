<?php
if (!class_exists("ModxMCPClientException")) {
    /** Expected/validation error whose message is safe to return to the client. */
    class ModxMCPClientException extends Exception {}
}
class modxMCP {
    const VERSION = '1.8.18';
    public $modx;
    public $config =[];
    private $actionSpecsCache = null;
    private $allowedElementTypes = ['chunk', 'snippet', 'template', 'resource', 'tv', 'category', 'plugin'];
    private $versionXTypes = [
        'resource' => ['class' => 'vxResource', 'processor' => 'resources', 'label' => 'title', 'content_class' => 'modResource'],
        'chunk' => ['class' => 'vxChunk', 'processor' => 'chunks', 'label' => 'name', 'content_class' => 'modChunk'],
        'snippet' => ['class' => 'vxSnippet', 'processor' => 'snippets', 'label' => 'name', 'content_class' => 'modSnippet'],
        'template' => ['class' => 'vxTemplate', 'processor' => 'templates', 'label' => 'templatename', 'content_class' => 'modTemplate'],
        'plugin' => ['class' => 'vxPlugin', 'processor' => 'plugins', 'label' => 'name', 'content_class' => 'modPlugin'],
        'tv' => ['class' => 'vxTemplateVar', 'processor' => 'templatevars', 'label' => 'name', 'content_class' => 'modTemplateVar'],
    ];

    public function __construct(modX &$modx, array $config =[]) {
        $this->modx =& $modx;
        $corePath = $this->modx->getOption('modxmcp.core_path', $config, $this->modx->getOption('core_path') . 'components/modxmcp/');
        $this->config = array_merge(['corePath' => $corePath], $config);
    }

    public function processRequest($action, $elementType, $data =[]) {
        $serviceUserId = (int)$this->modx->getOption('modxmcp.service_user_id', null, 1);
        $serviceUser = $this->modx->getObject('modUser', ['id' => $serviceUserId]);
        if (!$serviceUser) {
            throw new ModxMCPClientException("Service user not found: {$serviceUserId}.");
        }
        if (!$serviceUser->get('active')) {
            throw new ModxMCPClientException("Service user is inactive: {$serviceUserId}.");
        }

        $this->modx->user = $serviceUser;
        $this->modx->user->set('sudo', 1);

        $this->assertCapabilityEnabled($action);

        // Dispatch is driven by actionRegistry() — the single source of truth that also
        // backs listSupportedActions(), the acl/context/workspace processor maps and the
        // capability enforcement. Element actions (and any unknown action) fall through to
        // the element-type path below.
        $spec = $this->resolveActionSpec($action);
        if ($spec !== null && empty($spec['element'])) {
            return $this->invokeActionSpec($action, $data, $spec);
        }

        if (!in_array($elementType, $this->allowedElementTypes, true)) {
            throw new ModxMCPClientException("Invalid element type: {$elementType}.");
        }

        $this->modx->lexicon->load('core:default', 'core:resource', 'core:element', 'core:tv', 'core:category', 'core:plugin');

        $processorPaths =[
            'chunk'    => 'element/chunk/',
            'snippet'  => 'element/snippet/',
            'template' => 'element/template/',
            'resource' => 'resource/',
            'tv'       => 'element/tv/',
            'category' => 'element/category/',
            'plugin'   => 'element/plugin/'
        ];

        $basePath = $processorPaths[$elementType];

        if (empty($data['id']) && !empty($data['name'])) {
            $data['id'] = $this->resolveIdByName($elementType, $data['name']);
        }

        // ==========================================
        // МАППИНГ ПОЛЕЙ ИМЕН И ЗНАЧЕНИЙ ПО УМОЛЧАНИЮ
        // ==========================================
        if (isset($data['type']) && $data['type'] === $elementType) {
            unset($data['type']);
        }
        if ($elementType === 'tv' && !empty($data['field_type'])) $data['type'] = $data['field_type'];
        
        // Преобразуем name в нужные поля БД
        if (isset($data['name'])) {
            if ($elementType === 'template' && !isset($data['templatename'])) $data['templatename'] = $data['name'];
            if ($elementType === 'resource' && !isset($data['pagetitle'])) $data['pagetitle'] = $data['name'];
            if ($elementType === 'category' && !isset($data['category'])) $data['category'] = $data['name'];
        }

        // Дефолтные значения для новых ресурсов
        if ($elementType === 'resource' && $action === 'create_element') {
            if (!isset($data['context_key'])) $data['context_key'] = 'web';
            if (!isset($data['parent'])) $data['parent'] = 0;
            if (!isset($data['published'])) $data['published'] = 1;
        }

        if (isset($data['content'])) {
            if (in_array($elementType, ['chunk', 'snippet'])) $data['snippet'] = $data['content'];
            if ($elementType === 'plugin') $data['plugincode'] = $data['content'];
        }
        // ==========================================

        $nameFieldMap =[
            'template' => 'templatename',
            'resource' => 'pagetitle',
            'category' => 'category'
        ];
        $nameField = isset($nameFieldMap[$elementType]) ? $nameFieldMap[$elementType] : 'name';

        switch ($action) {
            case 'list_elements':
                // Filter by name directly: the core element getlist processors ignore a `query`
                // property (they only honour `id`), so a name search has to be done here. limit
                // defaults to 100; 0 = all. start paginates.
                $limit = isset($data['limit']) ? max(0, (int) $data['limit']) : 100;
                $start = isset($data['start']) ? max(0, (int) $data['start']) : 0;
                $listClassMap = [
                    'chunk' => 'modChunk', 'snippet' => 'modSnippet', 'template' => 'modTemplate',
                    'resource' => 'modResource', 'tv' => 'modTemplateVar', 'category' => 'modCategory', 'plugin' => 'modPlugin',
                ];
                $listClass = $listClassMap[$elementType];
                $lc = $this->modx->newQuery($listClass);
                if (!empty($data['query'])) {
                    $q = '%' . trim((string) $data['query']) . '%';
                    if ($elementType === 'resource') {
                        $lc->where([['pagetitle:LIKE' => $q, 'OR:longtitle:LIKE' => $q, 'OR:alias:LIKE' => $q]]);
                    } else {
                        $lc->where([$nameField . ':LIKE' => $q]);
                    }
                }
                $lc->sortby($nameField, 'ASC');
                if ($limit > 0) { $lc->limit($limit, $start); }
                $list =[];
                foreach ($this->modx->getCollection($listClass, $lc) as $el) {
                    $list[] =[
                        'id' => (int) $el->get('id'),
                        'name' => $el->get($nameField),
                    ];
                }
                return $list;

            case 'get_element':
                if (empty($data['id'])) throw new ModxMCPClientException("{$elementType} not found by name or ID is missing.");
                $response = $this->modx->runProcessor($basePath . 'get',['id' => $data['id']]);
                if ($response->isError()) throw new ModxMCPClientException($this->formatProcessorErrors($response));
                
                $objData = $response->getObject();
                
                if ($elementType === 'tv') {
                    $objData['templates'] = $this->getTvTemplates($data['id']);
                    $objData['field_type'] = $objData['type'];
                }
                if ($elementType === 'plugin') {
                    $objData['events'] = $this->getPluginEvents($data['id']);
                }
                
                return $objData;

            case 'update_element':
                if (empty($data['id'])) throw new ModxMCPClientException("{$elementType} not found by name or ID is missing.");
                
                $currentResponse = $this->modx->runProcessor($basePath . 'get',['id' => $data['id']]);
                if ($currentResponse->isError()) throw new ModxMCPClientException($this->formatProcessorErrors($currentResponse));
                
                $currentData = $currentResponse->getObject();
                $updateData = array_merge($currentData, $data);
                
                unset($updateData['events'], $updateData['templates'], $updateData['input_properties'], $updateData['media_source'], $updateData['field_type']);
                $updateData = $this->filterProcessorData($elementType, $updateData);
                
                return $this->runWithTransaction(function () use ($basePath, $updateData, $elementType, $data) {
                    $response = $this->modx->runProcessor($basePath . 'update', $updateData);
                    if ($response->isError()) throw new ModxMCPClientException("Update failed: " . $this->formatProcessorErrors($response));
                    
                    if ($elementType === 'tv') $this->handleTvRelations($data['id'], $data);
                    if ($elementType === 'plugin') $this->handlePluginEvents($data['id'], $data);
                    if ($this->shouldAutoStatic($elementType)) { $this->makeElementStatic($elementType, (int) $data['id']); }
                    
                    $this->modx->cacheManager->refresh();
                    $this->logAudit('update_element', $elementType, ['id' => $data['id']]);
                    return "Successfully updated {$elementType} (ID: {$data['id']}).";
                });

            case 'create_element':
                $createData = $data;
                unset($createData['events'], $createData['templates'], $createData['input_properties'], $createData['media_source'], $createData['field_type']);
                $createData = $this->filterProcessorData($elementType, $createData);

                return $this->runWithTransaction(function () use ($basePath, $createData, $elementType, $data) {
                    $response = $this->modx->runProcessor($basePath . 'create', $createData);
                    if ($response->isError()) throw new ModxMCPClientException("Create failed: " . $this->formatProcessorErrors($response));
                    
                    $newObj = $response->getObject();
                    
                    if ($elementType === 'tv' && !empty($newObj['id'])) $this->handleTvRelations($newObj['id'], $data);
                    if ($elementType === 'plugin' && !empty($newObj['id'])) $this->handlePluginEvents($newObj['id'], $data);
                    if (!empty($newObj['id']) && $this->shouldAutoStatic($elementType)) { $this->makeElementStatic($elementType, (int) $newObj['id']); }
                    
                    $this->modx->cacheManager->refresh();
                    $this->logAudit('create_element', $elementType, ['id' => isset($newObj['id']) ? $newObj['id'] : null]);
                    return $newObj;
                });
                
            case 'delete_element':
                if (empty($data['id'])) throw new ModxMCPClientException("{$elementType} not found by name or ID is missing.");

                // Safety preview: dry_run reports what would be deleted + where it's still used,
                // WITHOUT deleting. Run this before a real delete.
                if (!empty($data['dry_run'])) {
                    return $this->previewDelete($elementType, (int) $data['id']);
                }

                $processorAction = ($elementType === 'resource') ? 'delete' : 'remove';
                $response = $this->modx->runProcessor($basePath . $processorAction, ['id' => $data['id']]);
                if ($response->isError()) throw new ModxMCPClientException("Delete failed: " . $this->formatProcessorErrors($response));
                
                $this->modx->cacheManager->refresh();
                $this->logAudit('delete_element', $elementType, ['id' => $data['id']]);
                return "Successfully deleted {$elementType} (ID: {$data['id']}).";

            default:
                throw new ModxMCPClientException("Unknown action: {$action}");
        }
    }

    /**
     * THE single source of truth for actions: group => [ action => dispatch spec ].
     * Everything else derives from this map — listSupportedActions(), the acl/context/
     * workspace processor maps, capability enforcement (via actionToGroup) and the client's
     * tool list. Keep an action in exactly one group.
     *
     * Dispatch spec forms:
     *   'methodName'                                   -> $this->methodName($data)
     *   ['m'=>'methodName','call'=>'bare']             -> $this->methodName()
     *   ['m'=>'methodName','call'=>'create'|'update']  -> $this->methodName($data, true|false)
     *   ['m'=>'methodName','call'=>'action']           -> $this->methodName($action, $data)
     *   ['m'=>'deleteVirtualPageObject','call'=>'vpdelete','vpclass'=>'vpEvent']
     *   ['proc'=>'core/processor','list'=>true,'via'=>'acl'|'context'|'workspace']
     *   ['element'=>true]                              -> handled by the element-type path
     */
    private function actionRegistry() {
        return array(
            'elements' => array(
                'list_elements'   => array('element' => true),
                'get_element'     => array('element' => true),
                'create_element'  => array('element' => true),
                'update_element'  => array('element' => true),
                'delete_element'  => array('element' => true),
                'make_static'     => 'makeStatic',
                'view_element'        => 'viewElementLines',
                'edit_element_lines'  => 'editElementLines',
                'bulk_resources'      => 'bulkResources',
                'duplicate_element'   => 'duplicateElement',
                'duplicate_resource'  => 'duplicateResource',
                'undelete_resource'   => 'undeleteResource',
                'empty_recycle_bin'   => 'emptyRecycleBin',
                'reorder_resources'   => 'reorderResources',
            ),
            'resource_tvs' => array(
                'get_resource_tvs'    => 'getResourceTvs',
                'update_resource_tvs' => 'updateResourceTvs',
            ),
            'tv_inputs' => array(
                'list_tv_input_types' => array('m' => 'listTvInputTypes', 'call' => 'bare'),
                'suggest_tv_type'     => 'suggestTvType',
            ),
            'system' => array(
                'list_system_settings'  => 'listSystemSettings',
                'get_system_setting'    => 'getSystemSetting',
                'create_system_setting' => 'createSystemSetting',
                'update_system_setting' => 'updateSystemSetting',
                'delete_system_setting' => 'deleteSystemSetting',
            ),
            'media' => array(
                'list_media_sources'      => array('m' => 'listMediaSources', 'call' => 'bare'),
                'get_media_source'        => 'getMediaSource',
                'list_media_source_files' => 'listMediaSourceFiles',
                'read_media_source_file'  => 'readMediaSourceFile',
                'create_media_source'     => array('m' => 'saveMediaSource', 'call' => 'create'),
                'update_media_source'     => array('m' => 'saveMediaSource', 'call' => 'update'),
                'delete_media_source'     => 'deleteMediaSource',
                'create_media_file'   => 'createMediaFile',
                'update_media_file'   => 'updateMediaFile',
                'delete_media_file'   => 'deleteMediaFile',
                'rename_media_file'   => 'renameMediaFile',
                'create_media_folder' => 'createMediaFolder',
                'delete_media_folder' => 'deleteMediaFolder',
            ),
            'components' => array(
                'list_installed_components' => array('m' => 'listInstalledComponents', 'call' => 'bare'),
                'get_component_files'       => 'getComponentFiles',
                'read_component_file'       => 'readComponentFile',
                'check_integrations'        => array('m' => 'getIntegrationsReport', 'call' => 'bare'),
            ),
            'code_search' => array(
                'search_code'    => 'searchCode',
                'find_usages'    => 'findUsages',
                'list_resources' => 'listResources',
                'replace_across' => 'replaceAcross',
            ),
            'versionx' => array(
                'versionx_list_versions'  => 'listVersionXVersions',
                'versionx_get_version'    => 'getVersionXVersion',
                'versionx_revert_version' => 'revertVersionXVersion',
            ),
            'virtualpage' => array(
                'virtualpage_list_events'    => 'listVirtualPageEvents',
                'virtualpage_get_event'      => 'getVirtualPageEvent',
                'virtualpage_create_event'   => 'createVirtualPageEvent',
                'virtualpage_update_event'   => 'updateVirtualPageEvent',
                'virtualpage_list_handlers'  => 'listVirtualPageHandlers',
                'virtualpage_get_handler'    => 'getVirtualPageHandler',
                'virtualpage_create_handler' => 'createVirtualPageHandler',
                'virtualpage_update_handler' => 'updateVirtualPageHandler',
                'virtualpage_list_routes'    => 'listVirtualPageRoutes',
                'virtualpage_get_route'      => 'getVirtualPageRoute',
                'virtualpage_create_route'   => 'createVirtualPageRoute',
                'virtualpage_update_route'   => 'updateVirtualPageRoute',
                'virtualpage_delete_event'   => array('m' => 'deleteVirtualPageObject', 'call' => 'vpdelete', 'vpclass' => 'vpEvent'),
                'virtualpage_delete_handler' => array('m' => 'deleteVirtualPageObject', 'call' => 'vpdelete', 'vpclass' => 'vpHandler'),
                'virtualpage_delete_route'   => array('m' => 'deleteVirtualPageObject', 'call' => 'vpdelete', 'vpclass' => 'vpRoute'),
                'virtualpage_resolve_route'  => 'resolveVirtualPageRoute',
                'virtualpage_clear_cache'    => array('m' => 'clearVirtualPageCache', 'call' => 'bare'),
            ),
            'minishop2' => array(
                'ms2_list_option_types'         => 'listMs2OptionTypes',
                'ms2_list_options'              => 'listMs2Options',
                'ms2_get_option'                => 'getMs2Option',
                'ms2_create_option'             => 'createMs2Option',
                'ms2_update_option'             => 'updateMs2Option',
                'ms2_assign_option_to_category' => 'assignMs2OptionToCategory',
                'ms2_get_product_options'       => 'getMs2ProductOptions',
                'ms2_update_product_options'    => 'updateMs2ProductOptions',
                'ms2_list_link_types'     => array('m' => 'ms2LinkAction', 'call' => 'action'),
                'ms2_get_link_type'       => array('m' => 'ms2LinkAction', 'call' => 'action'),
                'ms2_create_link_type'    => array('m' => 'ms2LinkAction', 'call' => 'action'),
                'ms2_update_link_type'    => array('m' => 'ms2LinkAction', 'call' => 'action'),
                'ms2_delete_link_type'    => array('m' => 'ms2LinkAction', 'call' => 'action'),
                'ms2_list_product_links'  => array('m' => 'ms2LinkAction', 'call' => 'action'),
                'ms2_create_product_link' => array('m' => 'ms2LinkAction', 'call' => 'action'),
                'ms2_delete_product_link' => array('m' => 'ms2LinkAction', 'call' => 'action'),
                'ms2_list_categories'     => array('m' => 'ms2LinkAction', 'call' => 'action'),
                'ms2_create_category'     => array('m' => 'ms2LinkAction', 'call' => 'action'),
                'ms2_update_category'     => array('m' => 'ms2LinkAction', 'call' => 'action'),
                'ms2_list_orders'         => array('m' => 'ms2LinkAction', 'call' => 'action'),
                'ms2_get_order'           => array('m' => 'ms2LinkAction', 'call' => 'action'),
                'ms2_update_order'        => array('m' => 'ms2LinkAction', 'call' => 'action'),
            ),
            'migx' => array(
                'migx_list_configs'  => 'listMigxConfigs',
                'migx_get_config'    => 'getMigxConfig',
                'migx_create_config' => array('m' => 'saveMigxConfig', 'call' => 'create'),
                'migx_update_config' => array('m' => 'saveMigxConfig', 'call' => 'update'),
                'migx_delete_config' => 'deleteMigxConfig',
            ),
            'access' => array(
                'list_users'   => array('proc' => 'security/user/getlist', 'list' => true, 'via' => 'acl'),
                'get_user'     => array('proc' => 'security/user/get', 'via' => 'acl'),
                'create_user'  => array('proc' => 'security/user/create', 'via' => 'acl'),
                'update_user'  => array('proc' => 'security/user/update', 'via' => 'acl'),
                'delete_user'  => array('proc' => 'security/user/delete', 'via' => 'acl'),
                'list_user_groups'        => array('proc' => 'security/group/getlist', 'list' => true, 'via' => 'acl'),
                'get_user_group'          => array('proc' => 'security/group/get', 'via' => 'acl'),
                'create_user_group'       => array('proc' => 'security/group/create', 'via' => 'acl'),
                'update_user_group'       => array('proc' => 'security/group/update', 'via' => 'acl'),
                'delete_user_group'       => array('proc' => 'security/group/remove', 'via' => 'acl'),
                'list_user_group_members' => array('proc' => 'security/group/user/getlist', 'list' => true, 'via' => 'acl'),
                'add_user_to_group'       => array('proc' => 'security/group/user/create', 'via' => 'acl'),
                'update_group_member'     => array('proc' => 'security/group/user/update', 'via' => 'acl'),
                'remove_user_from_group'  => array('proc' => 'security/group/user/remove', 'via' => 'acl'),
                'list_roles'  => array('proc' => 'security/role/getlist', 'list' => true, 'via' => 'acl'),
                'get_role'    => array('proc' => 'security/role/get', 'via' => 'acl'),
                'create_role' => array('proc' => 'security/role/create', 'via' => 'acl'),
                'update_role' => array('proc' => 'security/role/update', 'via' => 'acl'),
                'delete_role' => array('proc' => 'security/role/remove', 'via' => 'acl'),
                'list_access_policies'  => array('proc' => 'security/access/policy/getlist', 'list' => true, 'via' => 'acl'),
                'create_access_policy'  => array('proc' => 'security/access/policy/create', 'via' => 'acl'),
                'update_access_policy'  => array('proc' => 'security/access/policy/update', 'via' => 'acl'),
                'delete_access_policy'  => array('proc' => 'security/access/policy/remove', 'via' => 'acl'),
                'list_access_policy_templates'  => array('proc' => 'security/access/policy/template/getlist', 'list' => true, 'via' => 'acl'),
                'create_access_policy_template' => array('proc' => 'security/access/policy/template/create', 'via' => 'acl'),
                'update_access_policy_template' => array('proc' => 'security/access/policy/template/update', 'via' => 'acl'),
                'delete_access_policy_template' => array('proc' => 'security/access/policy/template/remove', 'via' => 'acl'),
                'list_access_permissions' => array('proc' => 'security/access/permission/getlist', 'list' => true, 'via' => 'acl'),
                'list_resource_groups'      => array('proc' => 'security/resourcegroup/getlist', 'list' => true, 'via' => 'acl'),
                'create_resource_group'     => array('proc' => 'security/resourcegroup/create', 'via' => 'acl'),
                'update_resource_group'     => array('proc' => 'security/resourcegroup/update', 'via' => 'acl'),
                'delete_resource_group'     => array('proc' => 'security/resourcegroup/remove', 'via' => 'acl'),
                'assign_resource_to_group'  => array('proc' => 'security/resourcegroup/updateresourcesin', 'via' => 'acl'),
                'remove_resource_from_group'=> array('proc' => 'security/resourcegroup/removeresource', 'via' => 'acl'),
                'list_context_access'   => array('proc' => 'security/access/usergroup/context/getlist', 'list' => true, 'via' => 'acl'),
                'grant_context_access'  => array('proc' => 'security/access/usergroup/context/create', 'via' => 'acl'),
                'update_context_access' => array('proc' => 'security/access/usergroup/context/update', 'via' => 'acl'),
                'revoke_context_access' => array('proc' => 'security/access/usergroup/context/remove', 'via' => 'acl'),
                'list_resourcegroup_access'   => array('proc' => 'security/access/usergroup/resourcegroup/getlist', 'list' => true, 'via' => 'acl'),
                'grant_resourcegroup_access'  => array('proc' => 'security/access/usergroup/resourcegroup/create', 'via' => 'acl'),
                'update_resourcegroup_access' => array('proc' => 'security/access/usergroup/resourcegroup/update', 'via' => 'acl'),
                'revoke_resourcegroup_access' => array('proc' => 'security/access/usergroup/resourcegroup/remove', 'via' => 'acl'),
                'flush_permissions' => array('m' => 'flushPermissions', 'call' => 'bare'),
            ),
            'property_sets' => array(
                'list_property_sets'   => 'listPropertySets',
                'get_property_set'     => 'getPropertySet',
                'create_property_set'  => array('m' => 'savePropertySet', 'call' => 'create'),
                'update_property_set'  => array('m' => 'savePropertySet', 'call' => 'update'),
                'delete_property_set'  => 'deletePropertySet',
                'assign_property_set'  => 'assignPropertySet',
                'unassign_property_set'=> 'unassignPropertySet',
            ),
            'contexts' => array(
                'list_contexts'          => array('proc' => 'context/getlist', 'list' => true, 'via' => 'context'),
                'get_context'            => array('proc' => 'context/get', 'via' => 'context'),
                'create_context'         => array('proc' => 'context/create', 'via' => 'context'),
                'update_context'         => array('proc' => 'context/update', 'via' => 'context'),
                'delete_context'         => array('proc' => 'context/remove', 'via' => 'context'),
                'list_context_settings'  => array('proc' => 'context/setting/getlist', 'list' => true, 'via' => 'context'),
                'get_context_setting'    => array('proc' => 'context/setting/get', 'via' => 'context'),
                'create_context_setting' => array('proc' => 'context/setting/create', 'via' => 'context'),
                'update_context_setting' => array('proc' => 'context/setting/update', 'via' => 'context'),
                'delete_context_setting' => array('proc' => 'context/setting/remove', 'via' => 'context'),
            ),
            'package_management' => array(
                'install_package'   => 'installPackage',
                'uninstall_package' => 'uninstallPackage',
                'list_providers'    => 'listProviders',
                'search_packages'   => 'searchPackages',
                'create_provider'   => array('m' => 'saveProvider', 'call' => 'create'),
                'update_provider'   => array('m' => 'saveProvider', 'call' => 'update'),
                'delete_provider'   => 'deleteProvider',
            ),
            'namespaces' => array(
                'list_namespaces'   => array('proc' => 'workspace/namespace/getlist', 'list' => true, 'via' => 'workspace'),
                'create_namespace'  => array('proc' => 'workspace/namespace/create', 'via' => 'workspace'),
                'update_namespace'  => array('proc' => 'workspace/namespace/update', 'via' => 'workspace'),
                'delete_namespace'  => array('proc' => 'workspace/namespace/remove', 'via' => 'workspace'),
            ),
            'lexicon' => array(
                'list_lexicon_entries' => array('proc' => 'workspace/lexicon/getlist', 'list' => true, 'via' => 'workspace'),
                'list_lexicon_topics'  => array('proc' => 'workspace/lexicon/topic/getlist', 'list' => true, 'via' => 'workspace'),
                'set_lexicon_entry'    => array('proc' => 'workspace/lexicon/create', 'via' => 'workspace'),
                'revert_lexicon_entry' => array('proc' => 'workspace/lexicon/revert', 'via' => 'workspace'),
            ),
            'ops' => array(
                'list_actions'     => array('m' => 'listSupportedActions', 'call' => 'bare'),
                'get_capabilities' => array('m' => 'getCapabilities', 'call' => 'bare'),
                'help'             => 'getHelp',
                'run_processor'    => 'runProcessorPassthrough',
                'clear_cache'      => 'clearCacheAction',
                'read_audit_log'   => 'readAuditLog',
                'regenerate_token' => array('m' => 'regenerateToken', 'call' => 'bare'),
                'describe_object'  => 'describeObject',
                'read_error_log'   => 'readErrorLog',
                'refresh_uris'     => 'refreshUris',
                'remove_locks'     => 'removeLocks',
                'system_info'      => 'systemInfo',
                'project_overview' => 'projectOverview',
            ),
        );
    }

    /** Flatten the registry to a memoized action => spec map and look one up (null if unknown). */
    private function resolveActionSpec($action) {
        if ($this->actionSpecsCache === null) {
            $flat = array();
            foreach ($this->actionRegistry() as $group => $actions) {
                foreach ($actions as $a => $spec) {
                    $flat[$a] = $spec;
                }
            }
            $this->actionSpecsCache = $flat;
        }
        return isset($this->actionSpecsCache[$action]) ? $this->actionSpecsCache[$action] : null;
    }

    /** Invoke a non-element action per its registry spec. */
    private function invokeActionSpec($action, $data, $spec) {
        if (is_string($spec)) {
            return $this->{$spec}($data);
        }
        if (isset($spec['proc'])) {
            $via = isset($spec['via']) ? $spec['via'] : '';
            if ($via === 'acl')       { return $this->runAclAction($action, $data); }
            if ($via === 'context')   { return $this->runContextAction($action, $data); }
            if ($via === 'workspace') { return $this->runWorkspaceAction($action, $data); }
            throw new ModxMCPClientException("Unhandled processor route for action '{$action}'.");
        }
        if (isset($spec['m'])) {
            $m = $spec['m'];
            $call = isset($spec['call']) ? $spec['call'] : 'data';
            switch ($call) {
                case 'bare':     return $this->$m();
                case 'create':   return $this->$m($data, true);
                case 'update':   return $this->$m($data, false);
                case 'action':   return $this->$m($action, $data);
                case 'vpdelete': return $this->$m($spec['vpclass'], $action, $data);
                case 'data':
                default:         return $this->$m($data);
            }
        }
        throw new ModxMCPClientException("Unhandled action spec for action '{$action}'.");
    }

    /** Derive an action => {processor,list} map for one dispatch route ('acl'|'context'|'workspace'). */
    private function procMapFor($via) {
        $out = array();
        foreach ($this->actionRegistry() as $group => $actions) {
            foreach ($actions as $a => $spec) {
                if (is_array($spec) && isset($spec['proc']) && isset($spec['via']) && $spec['via'] === $via) {
                    $cfg = array('processor' => $spec['proc']);
                    if (!empty($spec['list'])) { $cfg['list'] = true; }
                    $out[$a] = $cfg;
                }
            }
        }
        return $out;
    }

    // --- Обработчики связей ---

    private function handlePluginEvents($pluginId, $data) {
        if (empty($data['events'])) return;
        $events = is_string($data['events']) ? array_map('trim', explode(',', $data['events'])) : $data['events'];
        if (!is_array($events)) return;
        
        $pluginId = (int)$pluginId;
        $this->modx->removeCollection('modPluginEvent', ['pluginid' => $pluginId]);
        
        foreach ($events as $eventName) {
            $eventName = trim($eventName);
            if (empty($eventName)) continue;
            if ($this->modx->getObject('modEvent',['name' => $eventName])) {
                $pe = $this->modx->newObject('modPluginEvent');
                $pe->fromArray(['pluginid' => $pluginId, 'event' => $eventName, 'priority' => 0, 'propertyset' => 0], '', true, true);
                $pe->save();
            }
        }
    }

    private function getPluginEvents($pluginId) {
        $pes = $this->modx->getCollection('modPluginEvent',['pluginid' => $pluginId]);
        $res =[];
        foreach($pes as $p) $res[] = $p->get('event');
        return $res;
    }

    private function handleTvRelations($tvId, $data) {
        $tv = $this->modx->getObject('modTemplateVar', $tvId);
        if (!$tv) return;

        if (isset($data['templates']) && is_array($data['templates'])) {
            $this->modx->removeCollection('modTemplateVarTemplate', ['tmplvarid' => $tvId]);
            foreach ($data['templates'] as $tplId) {
                if (empty($tplId)) continue;
                $tvt = $this->modx->newObject('modTemplateVarTemplate');
                $tvt->fromArray(['tmplvarid' => $tvId, 'templateid' => $tplId], '', true, true);
                $tvt->save();
            }
        }

        if (isset($data['input_properties']) && is_array($data['input_properties'])) {
            $tv->set('input_properties', $data['input_properties']);
            $tv->save();
        }

        if (isset($data['media_source'])) {
            $sourceId = (int)$data['media_source'];
            $sourceEl = $this->modx->getObject('sources.modMediaSourceElement',[
                'object' => $tvId, 'object_class' => 'modTemplateVar', 'context_key' => 'web'
            ]);
            if (!$sourceEl) {
                $sourceEl = $this->modx->newObject('sources.modMediaSourceElement');
                $sourceEl->fromArray(['object' => $tvId, 'object_class' => 'modTemplateVar', 'context_key' => 'web'], '', true, true);
            }
            $sourceEl->set('source', $sourceId);
            $sourceEl->save();
        }
    }

    private function getTvTemplates($tvId) {
        $tvts = $this->modx->getCollection('modTemplateVarTemplate',['tmplvarid' => $tvId]);
        $res =[];
        foreach($tvts as $t) $res[] = $t->get('templateid');
        return $res;
    }

    private function resolveIdByName($elementType, $name) {
        $classMap =[
            'chunk'    =>['class' => 'modChunk', 'field' => 'name'],
            'snippet'  =>['class' => 'modSnippet', 'field' => 'name'],
            'template' =>['class' => 'modTemplate', 'field' => 'templatename'],
            'resource' =>['class' => 'modResource', 'field' => 'pagetitle'],
            'tv'       =>['class' => 'modTemplateVar', 'field' => 'name'],
            'category' =>['class' => 'modCategory', 'field' => 'category'],
            'plugin'   =>['class' => 'modPlugin', 'field' => 'name']
        ];
        if ($elementType === 'resource') {
            foreach (['alias', 'uri', 'pagetitle'] as $field) {
                $obj = $this->modx->getObject('modResource', [$field => $name]);
                if ($obj) {
                    return $obj->get('id');
                }
            }
            return null;
        }
        $obj = $this->modx->getObject($classMap[$elementType]['class'], [$classMap[$elementType]['field'] => $name]);
        return $obj ? $obj->get('id') : null;
    }

    private function formatProcessorErrors($response) {
        $error = $response->getMessage();
        if ($response->hasFieldErrors()) {
            foreach ($response->getFieldErrors() as $fError) {
                $error .= " | Field '{$fError->field}': {$fError->message}";
            }
        }
        return $error ?: "Unknown error.";
    }

    /**
     * Full-text search across element/resource content (and names).
     * Non-static elements are matched in the DB; static elements are matched by reading
     * their static file. data: query (required), types[] (chunk/snippet/template/plugin/tv/resource),
     * limit (default 50, max 200), case_sensitive (bool).
     */
    private function searchCode($data) {
        $query = isset($data['query']) ? trim((string) $data['query']) : '';
        if ($query === '') {
            throw new ModxMCPClientException('search_code: "query" is required.');
        }
        $limit = isset($data['limit']) ? (int) $data['limit'] : 50;
        if ($limit < 1) { $limit = 1; }
        if ($limit > 200) { $limit = 200; }
        $caseSensitive = !empty($data['case_sensitive']);

        $map = array(
            'chunk'    => array('class' => 'modChunk',       'content' => 'snippet',    'name' => 'name'),
            'snippet'  => array('class' => 'modSnippet',     'content' => 'snippet',    'name' => 'name'),
            'template' => array('class' => 'modTemplate',    'content' => 'content',    'name' => 'templatename'),
            'plugin'   => array('class' => 'modPlugin',      'content' => 'plugincode', 'name' => 'name'),
            'tv'       => array('class' => 'modTemplateVar', 'content' => 'default_text','name' => 'name'),
            'resource' => array('class' => 'modResource',    'content' => 'content',    'name' => 'pagetitle'),
        );

        $types = (isset($data['types']) && is_array($data['types']) && !empty($data['types']))
            ? $data['types']
            : array('chunk', 'snippet', 'template', 'plugin', 'tv', 'resource');

        $results = array();
        foreach ($types as $type) {
            if (!isset($map[$type]) || count($results) >= $limit) { continue; }
            $m = $map[$type];
            $isElement = ($type !== 'resource');

            // Non-static (DB LIKE on content OR name)
            $c = $this->modx->newQuery($m['class']);
            $c->where(array(
                array(
                    $m['content'] . ':LIKE' => '%' . $query . '%',
                    'OR:' . $m['name'] . ':LIKE' => '%' . $query . '%',
                ),
            ));
            if ($isElement) {
                $c->where(array('static' => 0));
            }
            $c->limit($limit);
            foreach ($this->modx->getCollection($m['class'], $c) as $o) {
                if (count($results) >= $limit) { break; }
                $results[] = $this->buildSearchHit($type, $o, $m, $query, (string) $o->get($m['content']), $caseSensitive, false);
            }

            // Static elements: match by reading the static file
            if ($isElement && count($results) < $limit) {
                $sc = $this->modx->newQuery($m['class']);
                $sc->where(array('static' => 1));
                $needle = $caseSensitive ? $query : strtolower($query);
                foreach ($this->modx->getCollection($m['class'], $sc) as $o) {
                    if (count($results) >= $limit) { break; }
                    $content = (string) $o->getContent();
                    $name = (string) $o->get($m['name']);
                    $hay = $caseSensitive ? ($content . "\n" . $name) : strtolower($content . "\n" . $name);
                    if (strpos($hay, $needle) !== false) {
                        $results[] = $this->buildSearchHit($type, $o, $m, $query, $content, $caseSensitive, true);
                    }
                }
            }
        }

        return array('query' => $query, 'count' => count($results), 'results' => $results);
    }

    private function buildSearchHit($type, $object, $map, $query, $content, $caseSensitive, $static) {
        $nameField = $map['name'];
        $name = (string) $object->get($nameField);
        $hayContent = $caseSensitive ? $content : strtolower($content);
        $needle = $caseSensitive ? $query : strtolower($query);
        $pos = strpos($hayContent, $needle);
        $field = ($pos !== false) ? $map['content'] : $nameField;
        $snippet = '';
        $line = null;
        $lineText = null;
        if ($pos !== false) {
            $start = max(0, $pos - 60);
            $snippet = substr($content, $start, strlen($query) + 120);
            $snippet = trim(preg_replace('/\s+/', ' ', $snippet));
            if ($start > 0) { $snippet = '…' . $snippet; }
            // 1-based line of the match + the exact line text (verbatim, EOL-normalised the same
            // way view_element/edit_element_lines do) so it can be reused directly as `expect`.
            $lineStart = strrpos(substr($content, 0, $pos), "\n");
            $lineStart = ($lineStart === false) ? 0 : $lineStart + 1;
            $lineEnd = strpos($content, "\n", $pos);
            if ($lineEnd === false) { $lineEnd = strlen($content); }
            $line = substr_count($content, "\n", 0, $lineStart) + 1;
            $lineText = rtrim(substr($content, $lineStart, $lineEnd - $lineStart), "\r");
        }
        return array(
            'type' => $type,
            'id' => (int) $object->get('id'),
            'name' => $name,
            'matched_field' => $field,
            'static' => $type !== 'resource' ? (bool) $object->get('static') : false,
            'line' => $line,
            'line_text' => $lineText,
            'snippet' => $snippet,
        );
    }

    /**
     * List resources, optionally by parent/context, with a pagetitle/alias/uri filter.
     * data: parent (int), context (string), query (string), limit (default 100, max 500), start (int).
     */
    private function listResources($data) {
        $limit = isset($data['limit']) ? (int) $data['limit'] : 100;
        if ($limit < 1) { $limit = 1; }
        if ($limit > 500) { $limit = 500; }
        $start = isset($data['start']) ? max(0, (int) $data['start']) : 0;

        $c = $this->modx->newQuery('modResource');
        $and = array();
        if (isset($data['parent']) && $data['parent'] !== '') { $and['parent'] = (int) $data['parent']; }
        if (!empty($data['context'])) { $and['context_key'] = (string) $data['context']; }
        if (!empty($and)) { $c->where($and); }
        if (!empty($data['query'])) {
            $q = trim((string) $data['query']);
            $c->where(array(array(
                'pagetitle:LIKE' => '%' . $q . '%',
                'OR:alias:LIKE' => '%' . $q . '%',
                'OR:uri:LIKE' => '%' . $q . '%',
            )));
        }
        $total = $this->modx->getCount('modResource', $c);
        $c->sortby('parent', 'ASC');
        $c->sortby('menuindex', 'ASC');
        $c->limit($limit, $start);

        $rows = array();
        foreach ($this->modx->getCollection('modResource', $c) as $r) {
            $rows[] = array(
                'id' => (int) $r->get('id'),
                'pagetitle' => $r->get('pagetitle'),
                'alias' => $r->get('alias'),
                'uri' => $r->get('uri'),
                'parent' => (int) $r->get('parent'),
                'template' => (int) $r->get('template'),
                'published' => (bool) $r->get('published'),
                'isfolder' => (bool) $r->get('isfolder'),
                'class_key' => $r->get('class_key'),
                'context_key' => $r->get('context_key'),
            );
        }
        return array('total' => (int) $total, 'count' => count($rows), 'start' => $start, 'results' => $rows);
    }

    /**
     * Find where an element is used. Runs a content search for the name across all code,
     * and — if a template with that name exists — also lists resources assigned to it.
     * data: name (required), limit (default 100).
     */
    private function findUsages($data) {
        $name = isset($data['name']) ? trim((string) $data['name']) : '';
        if ($name === '') {
            throw new ModxMCPClientException('find_usages: "name" is required.');
        }
        $limit = isset($data['limit']) ? (int) $data['limit'] : 100;

        $search = $this->searchCode(array('query' => $name, 'limit' => $limit));
        $usages = $search['results'];

        // Template usage: resources whose template is the one named $name.
        $templateResources = array();
        $tpl = $this->modx->getObject('modTemplate', array('templatename' => $name));
        if ($tpl) {
            $tid = (int) $tpl->get('id');
            $rc = $this->modx->newQuery('modResource', array('template' => $tid));
            $resTotal = $this->modx->getCount('modResource', $rc);
            $rc->limit($limit);
            foreach ($this->modx->getCollection('modResource', $rc) as $r) {
                $templateResources[] = array('id' => (int) $r->get('id'), 'pagetitle' => $r->get('pagetitle'), 'uri' => $r->get('uri'));
            }
            return array(
                'name' => $name,
                'content_matches' => $usages,
                'template_id' => $tid,
                'resources_using_template_total' => (int) $resTotal,
                'resources_using_template' => $templateResources,
            );
        }

        return array('name' => $name, 'content_matches' => $usages);
    }

    /**
     * Preview a delete without performing it: what the object is, and (for elements) where its
     * name is still referenced; (for resources) how many child resources it has.
     */
    private function previewDelete($elementType, $id) {
        if ($elementType === 'resource') {
            $r = $this->modx->getObject('modResource', $id);
            if (!$r) { throw new ModxMCPClientException("resource {$id} not found."); }
            $children = (int) $this->modx->getCount('modResource', array('parent' => $id));
            return array(
                'dry_run' => true,
                'would_delete' => array('type' => 'resource', 'id' => $id, 'pagetitle' => $r->get('pagetitle'), 'uri' => $r->get('uri')),
                'child_resources' => $children,
                'warning' => $children > 0 ? "Has {$children} child resource(s) that would be affected." : null,
            );
        }
        $classMap = array(
            'chunk' => array('modChunk', 'name'), 'snippet' => array('modSnippet', 'name'),
            'template' => array('modTemplate', 'templatename'), 'tv' => array('modTemplateVar', 'name'),
            'category' => array('modCategory', 'category'), 'plugin' => array('modPlugin', 'name'),
        );
        if (!isset($classMap[$elementType])) { throw new ModxMCPClientException("Cannot preview delete for type {$elementType}."); }
        list($class, $nameField) = $classMap[$elementType];
        $obj = $this->modx->getObject($class, $id);
        if (!$obj) { throw new ModxMCPClientException("{$elementType} {$id} not found."); }
        $name = (string) $obj->get($nameField);
        $usages = ($elementType === 'category') ? array('name' => $name) : $this->findUsages(array('name' => $name, 'limit' => 100));
        // Drop the element's own name-field self-match — that's not a usage.
        if (isset($usages['content_matches'])) {
            $usages['content_matches'] = array_values(array_filter($usages['content_matches'], function ($h) use ($id, $elementType) {
                return !((int) $h['id'] === (int) $id && $h['type'] === $elementType);
            }));
        }
        $matchCount = isset($usages['content_matches']) ? count($usages['content_matches']) : 0;
        return array(
            'dry_run' => true,
            'would_delete' => array('type' => $elementType, 'id' => $id, 'name' => $name),
            'usages' => $usages,
            'warning' => $matchCount > 0 ? "Name '{$name}' is referenced in {$matchCount} place(s) (and possibly more) — deleting may break them." : null,
        );
    }

    /**
     * Bulk resource operations. Select targets by explicit `ids` or by a parent/context/query
     * filter, then apply one operation to all of them. Operations: publish, unpublish,
     * set_template (needs `template`), move (needs `parent_to` and/or `context_to`), delete.
     * `dry_run` previews the change per resource without applying. Each change is routed through
     * the core resource processors (correct URI regeneration / events) via update/delete_element.
     */
    private function bulkResources($data) {
        $op = isset($data['operation']) ? (string) $data['operation'] : '';
        $ops = array('publish', 'unpublish', 'set_template', 'move', 'delete');
        if (!in_array($op, $ops, true)) {
            throw new ModxMCPClientException('bulk_resources: operation must be one of: ' . implode(', ', $ops) . '.');
        }
        $limit = isset($data['limit']) ? max(1, (int) $data['limit']) : 200;

        $ids = array();
        if (isset($data['ids']) && is_array($data['ids'])) {
            foreach ($data['ids'] as $i) { $i = (int) $i; if ($i > 0) { $ids[] = $i; } }
        } elseif (isset($data['parent']) || !empty($data['context']) || !empty($data['query'])) {
            $lr = $this->listResources(array(
                'parent'  => isset($data['parent']) ? $data['parent'] : '',
                'context' => isset($data['context']) ? $data['context'] : '',
                'query'   => isset($data['query']) ? $data['query'] : '',
                'limit'   => $limit,
            ));
            foreach ($lr['results'] as $r) { $ids[] = (int) $r['id']; }
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) { throw new ModxMCPClientException('bulk_resources: no targets — pass "ids" or a parent/context/query filter.'); }
        if (count($ids) > $limit) { $ids = array_slice($ids, 0, $limit); }

        if ($op === 'set_template' && empty($data['template'])) { throw new ModxMCPClientException('bulk_resources: set_template requires "template".'); }
        if ($op === 'move' && !isset($data['parent_to']) && empty($data['context_to'])) { throw new ModxMCPClientException('bulk_resources: move requires "parent_to" and/or "context_to".'); }

        $dry = !empty($data['dry_run']);
        $results = array();
        foreach ($ids as $id) {
            $r = $this->modx->getObject('modResource', $id);
            if (!$r) { $results[] = array('id' => $id, 'status' => 'not_found'); continue; }
            $row = array('id' => $id, 'pagetitle' => $r->get('pagetitle'));

            if ($dry) {
                if ($op === 'publish')      { $row['change'] = 'published: ' . (int) $r->get('published') . ' -> 1'; }
                elseif ($op === 'unpublish'){ $row['change'] = 'published: ' . (int) $r->get('published') . ' -> 0'; }
                elseif ($op === 'set_template') { $row['change'] = 'template: ' . (int) $r->get('template') . ' -> ' . (int) $data['template']; }
                elseif ($op === 'move')     { $row['change'] = 'parent: ' . (int) $r->get('parent') . (isset($data['parent_to']) ? ' -> ' . (int) $data['parent_to'] : '') . (!empty($data['context_to']) ? '; context -> ' . $data['context_to'] : ''); }
                elseif ($op === 'delete')   { $row['change'] = 'DELETE'; $row['child_resources'] = (int) $this->modx->getCount('modResource', array('parent' => $id)); }
                $results[] = $row;
                continue;
            }

            try {
                if ($op === 'delete') {
                    $this->processRequest('delete_element', 'resource', array('id' => $id));
                    $row['status'] = 'deleted';
                } else {
                    $upd = array('id' => $id);
                    if ($op === 'publish')          { $upd['published'] = 1; }
                    elseif ($op === 'unpublish')    { $upd['published'] = 0; }
                    elseif ($op === 'set_template') { $upd['template'] = (int) $data['template']; }
                    elseif ($op === 'move') {
                        if (isset($data['parent_to']))   { $upd['parent'] = (int) $data['parent_to']; }
                        if (!empty($data['context_to'])) { $upd['context_key'] = (string) $data['context_to']; }
                    }
                    $this->processRequest('update_element', 'resource', $upd);
                    $row['status'] = 'ok';
                }
            } catch (Exception $e) {
                $row['status'] = 'error';
                $row['error'] = $e->getMessage();
            }
            $results[] = $row;
        }

        if (!$dry) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('bulk_resources', 'resource', array('operation' => $op, 'count' => count($ids), 'dry_run' => $dry));
        return array('operation' => $op, 'dry_run' => $dry, 'count' => count($results), 'results' => $results);
    }

    // --- Media-source file & folder operations (modMediaSource API) ---

    private function initMediaSource($data) {
        $sel = array();
        if (isset($data['source']) && $data['source'] !== '') {
            if (is_numeric($data['source'])) { $sel['id'] = (int) $data['source']; }
            else { $sel['name'] = (string) $data['source']; }
        } elseif (!empty($data['id'])) {
            $sel['id'] = (int) $data['id'];
        } elseif (!empty($data['name'])) {
            $sel['name'] = (string) $data['name'];
        }
        $source = $this->resolveMediaSource($sel);
        if (!$source) { throw new ModxMCPClientException('Media source not found (provide "source" = id or name).'); }
        $source->initialize();
        return $source;
    }

    private function mediaSourceError($source, $fallback) {
        $errs = method_exists($source, 'getErrors') ? $source->getErrors() : array();
        if (is_array($errs) && $errs) {
            $parts = array();
            foreach ($errs as $k => $v) { $parts[] = is_string($k) ? "{$k}: {$v}" : (string) $v; }
            return implode('; ', $parts);
        }
        return $fallback;
    }

    private function createMediaFile($data) {
        $source = $this->initMediaSource($data);
        // createObject concatenates container + name, so the container must end with "/".
        $dir = isset($data['path']) ? trim((string) $data['path'], '/') : '';
        $dir = ($dir === '') ? '/' : $dir . '/';
        $name = isset($data['name']) ? (string) $data['name'] : '';
        if ($name === '') { throw new ModxMCPClientException('create_media_file: "name" is required.'); }
        $res = $source->createObject($dir, $name, isset($data['content']) ? (string) $data['content'] : '');
        if ($res === false) { throw new ModxMCPClientException('create_media_file failed: ' . $this->mediaSourceError($source, 'unknown error')); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('create_media_file', 'source', array('source' => (int) $source->get('id'), 'path' => $dir, 'name' => $name));
        return array('created' => true, 'path' => rtrim($dir, '/') . '/' . $name);
    }

    private function updateMediaFile($data) {
        $source = $this->initMediaSource($data);
        $path = isset($data['path']) ? (string) $data['path'] : '';
        if ($path === '') { throw new ModxMCPClientException('update_media_file: "path" (file) is required.'); }
        if (!array_key_exists('content', $data)) { throw new ModxMCPClientException('update_media_file: "content" is required.'); }
        $res = $source->updateObject($path, (string) $data['content']);
        if ($res === false) { throw new ModxMCPClientException('update_media_file failed: ' . $this->mediaSourceError($source, 'unknown error')); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('update_media_file', 'source', array('source' => (int) $source->get('id'), 'path' => $path));
        return array('updated' => true, 'path' => $path);
    }

    private function deleteMediaFile($data) {
        $source = $this->initMediaSource($data);
        $path = isset($data['path']) ? (string) $data['path'] : '';
        if ($path === '') { throw new ModxMCPClientException('delete_media_file: "path" is required.'); }
        $res = $source->removeObject($path);
        if ($res === false) { throw new ModxMCPClientException('delete_media_file failed: ' . $this->mediaSourceError($source, 'unknown error')); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('delete_media_file', 'source', array('source' => (int) $source->get('id'), 'path' => $path));
        return array('deleted' => true, 'path' => $path);
    }

    private function renameMediaFile($data) {
        $source = $this->initMediaSource($data);
        $path = isset($data['path']) ? (string) $data['path'] : '';
        $newName = isset($data['new_name']) ? (string) $data['new_name'] : '';
        if ($path === '' || $newName === '') { throw new ModxMCPClientException('rename_media_file: "path" and "new_name" are required.'); }
        $res = $source->renameObject($path, $newName);
        if ($res === false) { throw new ModxMCPClientException('rename_media_file failed: ' . $this->mediaSourceError($source, 'unknown error')); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('rename_media_file', 'source', array('source' => (int) $source->get('id'), 'path' => $path, 'new_name' => $newName));
        return array('renamed' => true, 'path' => $path, 'new_name' => $newName);
    }

    private function createMediaFolder($data) {
        $source = $this->initMediaSource($data);
        $parent = isset($data['parent']) ? (string) $data['parent'] : '/';
        $name = isset($data['name']) ? (string) $data['name'] : '';
        if ($name === '') { throw new ModxMCPClientException('create_media_folder: "name" is required.'); }
        $res = $source->createContainer($name, $parent);
        if ($res === false) { throw new ModxMCPClientException('create_media_folder failed: ' . $this->mediaSourceError($source, 'unknown error')); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('create_media_folder', 'source', array('source' => (int) $source->get('id'), 'parent' => $parent, 'name' => $name));
        return array('created' => true, 'parent' => $parent, 'name' => $name);
    }

    private function deleteMediaFolder($data) {
        $source = $this->initMediaSource($data);
        $rel = isset($data['path']) ? trim((string) $data['path'], '/') : '';
        if ($rel === '') { throw new ModxMCPClientException('delete_media_folder: "path" is required (refusing to remove the source root).'); }
        // MODX 2.x removeContainer() takes an ABSOLUTE path (unlike createContainer/removeObject).
        $path = rtrim($this->getMediaSourceRootPath($source), '/\\') . '/' . $rel;
        $res = $source->removeContainer($path);
        if ($res === false) { throw new ModxMCPClientException('delete_media_folder failed: ' . $this->mediaSourceError($source, 'unknown error')); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('delete_media_folder', 'source', array('source' => (int) $source->get('id'), 'path' => $path));
        return array('deleted' => true, 'path' => $path);
    }

    // --- Trash / duplicate / reorder (core resource & element processors) ---

    private function undeleteResource($data) {
        if (empty($data['id'])) { throw new ModxMCPClientException('undelete_resource: "id" is required.'); }
        $resp = $this->modx->runProcessor('resource/undelete', array('id' => (int) $data['id']));
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'undelete_resource: no response.'); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('undelete_resource', 'resource', array('id' => (int) $data['id']));
        return array('undeleted' => true, 'id' => (int) $data['id']);
    }

    private function emptyRecycleBin($data) {
        $resp = $this->modx->runProcessor('resource/emptyrecyclebin', array());
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'empty_recycle_bin: no response.'); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('empty_recycle_bin', 'resource', array());
        return $this->normalizeProcessorResponse($resp);
    }

    private function duplicateResource($data) {
        if (empty($data['id'])) { throw new ModxMCPClientException('duplicate_resource: "id" is required.'); }
        $props = array('id' => (int) $data['id']);
        if (!empty($data['name'])) { $props['name'] = (string) $data['name']; }
        if (isset($data['duplicate_children'])) { $props['duplicate_children'] = (bool) $data['duplicate_children']; }
        if (!empty($data['published_mode'])) { $props['published_mode'] = (string) $data['published_mode']; }
        $resp = $this->modx->runProcessor('resource/duplicate', $props);
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'duplicate_resource: no response.'); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('duplicate_resource', 'resource', array('id' => (int) $data['id']));
        return $this->normalizeProcessorResponse($resp);
    }

    private function duplicateElement($data) {
        $type = isset($data['type']) ? (string) $data['type'] : '';
        if (!in_array($type, array('chunk', 'snippet', 'template', 'plugin', 'tv'), true)) {
            throw new ModxMCPClientException('duplicate_element: type must be one of chunk, snippet, template, plugin, tv.');
        }
        if (empty($data['id'])) { throw new ModxMCPClientException('duplicate_element: "id" is required.'); }
        $props = array('id' => (int) $data['id']);
        if (!empty($data['name'])) { $props['name'] = (string) $data['name']; }
        $resp = $this->modx->runProcessor('element/' . $type . '/duplicate', $props);
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'duplicate_element: no response.'); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('duplicate_element', $type, array('id' => (int) $data['id']));
        return $this->normalizeProcessorResponse($resp);
    }

    private function reorderResources($data) {
        $items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : null;
        if (!$items) { throw new ModxMCPClientException('reorder_resources: "items" (list of {id, menuindex, parent?}) is required.'); }
        $results = array();
        foreach ($items as $it) {
            if (empty($it['id']) || !isset($it['menuindex'])) {
                $results[] = array('id' => isset($it['id']) ? (int) $it['id'] : null, 'status' => 'skipped (need id + menuindex)');
                continue;
            }
            $upd = array('id' => (int) $it['id'], 'menuindex' => (int) $it['menuindex']);
            if (isset($it['parent'])) { $upd['parent'] = (int) $it['parent']; }
            try {
                $this->processRequest('update_element', 'resource', $upd);
                $results[] = array('id' => (int) $it['id'], 'status' => 'ok', 'menuindex' => (int) $it['menuindex']);
            } catch (Exception $e) {
                $results[] = array('id' => (int) $it['id'], 'status' => 'error', 'error' => $e->getMessage());
            }
        }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('reorder_resources', 'resource', array('count' => count($results)));
        return array('count' => count($results), 'results' => $results);
    }

    // --- Diagnostics / maintenance ---

    private function readErrorLog($data) {
        $path = rtrim($this->modx->getOption('core_path'), '/') . '/cache/logs/error.log';
        if (!file_exists($path)) { return array('total' => 0, 'lines' => array(), 'path' => $path); }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) { $lines = array(); }
        $limit = isset($data['limit']) ? max(1, (int) $data['limit']) : 100;
        $tail = array_slice($lines, -$limit);
        return array('total' => count($tail), 'lines' => $tail);
    }

    private function refreshUris($data) {
        $resp = $this->modx->runProcessor('system/refreshuris', array());
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'refresh_uris: no response.'); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('refresh_uris', 'system', array());
        return array('refreshed' => true);
    }

    private function removeLocks($data) {
        $resp = $this->modx->runProcessor('system/remove_locks', array());
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'remove_locks: no response.'); }
        $this->logAudit('remove_locks', 'system', array());
        return $this->normalizeProcessorResponse($resp);
    }

    private function systemInfo($data) {
        $v = method_exists($this->modx, 'getVersionData') ? $this->modx->getVersionData() : array();
        return array(
            'modx_version'    => isset($v['full_version']) ? $v['full_version'] : (isset($v['version']) ? $v['version'] : null),
            'modxmcp_version' => self::VERSION,
            'php_version'     => PHP_VERSION,
            'dbtype'          => $this->modx->getOption('dbtype'),
            'base_path'       => $this->modx->getOption('base_path'),
            'core_path'       => $this->modx->getOption('core_path'),
        );
    }

    /**
     * Compact, token-safe project map so a model orients in ONE call. Scales with the site's
     * STRUCTURE, never its CONTENT: resources/products are COUNTS only; the resource tree is
     * roots + child counts (depth 1, capped); structural lists are capped with totals. A site
     * with 100k resources returns the same small payload as a tiny one. Per-item browsing stays
     * in the paginated tools (list_resources, list_elements). data: sections?[], max_tree_nodes?.
     */
    private function projectOverview($data) {
        $all = array('modx', 'counts', 'templates', 'tvs', 'resource_tree', 'resources_by_template', 'resources_by_context', 'element_categories', 'content_types', 'contexts', 'integrations');
        $req = (isset($data['sections']) && is_array($data['sections']) && $data['sections'])
            ? array_values(array_intersect($all, $data['sections']))
            : $all;
        $want = array_flip($req);
        $cap = 200;
        $maxTree = isset($data['max_tree_nodes']) ? max(1, (int) $data['max_tree_nodes']) : 50;
        $cnt = function ($class, $crit = null) { return (int) $this->modx->getCount($class, $crit); };
        $out = array();

        if (isset($want['modx'])) {
            $v = method_exists($this->modx, 'getVersionData') ? $this->modx->getVersionData() : array();
            $out['modx'] = array(
                'version'         => isset($v['full_version']) ? $v['full_version'] : (isset($v['version']) ? $v['version'] : null),
                'site_url'        => $this->modx->getOption('site_url'),
                'modxmcp_version' => self::VERSION,
            );
        }

        if (isset($want['counts'])) {
            $out['counts'] = array(
                'resources'           => $cnt('modResource'),
                'published_resources' => $cnt('modResource', array('published' => 1, 'deleted' => 0)),
                'deleted_resources'   => $cnt('modResource', array('deleted' => 1)),
                'templates'           => $cnt('modTemplate'),
                'tvs'                 => $cnt('modTemplateVar'),
                'chunks'              => $cnt('modChunk'),
                'snippets'            => $cnt('modSnippet'),
                'plugins'             => $cnt('modPlugin'),
                'categories'          => $cnt('modCategory'),
                'contexts'            => $cnt('modContext'),
                'users'               => $cnt('modUser'),
                'media_sources'       => $cnt('sources.modMediaSource'),
            );
            $products = $cnt('modResource', array('class_key' => 'msProduct'));
            if ($products > 0) { $out['counts']['ms2_products'] = $products; }
        }

        // Build the template list once (reused by templates + resources_by_template).
        $tplRows = null;
        if (isset($want['templates']) || isset($want['resources_by_template'])) {
            $tplRows = array();
            $q = $this->modx->newQuery('modTemplate');
            $q->select(array('id', 'templatename'));
            $q->sortby('templatename', 'ASC');
            $q->limit($cap);
            foreach ($this->modx->getCollection('modTemplate', $q) as $t) {
                $tplRows[(int) $t->get('id')] = $t->get('templatename');
            }
        }

        if (isset($want['templates'])) {
            $tplTotal = $cnt('modTemplate');
            // tv names + template→tv attachments (bounded by #templates × #tvs).
            $tvName = array();
            $tq = $this->modx->newQuery('modTemplateVar');
            $tq->select(array('id', 'name'));
            foreach ($this->modx->getCollection('modTemplateVar', $tq) as $tv) { $tvName[(int) $tv->get('id')] = $tv->get('name'); }
            $attach = array();
            $lq = $this->modx->newQuery('modTemplateVarTemplate');
            $lq->select(array('templateid', 'tmplvarid'));
            foreach ($this->modx->getCollection('modTemplateVarTemplate', $lq) as $l) {
                $attach[(int) $l->get('templateid')][] = (int) $l->get('tmplvarid');
            }
            $items = array();
            foreach ($tplRows as $tid => $name) {
                $tvids = isset($attach[$tid]) ? $attach[$tid] : array();
                $names = array();
                foreach ($tvids as $i) { if (isset($tvName[$i])) { $names[] = $tvName[$i]; } }
                $items[] = array('id' => $tid, 'name' => $name, 'tv_ids' => $tvids, 'tv_names' => $names, 'resource_count' => $cnt('modResource', array('template' => $tid)));
            }
            $out['templates'] = array('total' => $tplTotal, 'truncated' => $tplTotal > count($items), 'items' => $items);
        }

        if (isset($want['resources_by_template'])) {
            $rbt = array();
            foreach ($tplRows as $tid => $name) { $rbt[] = array('template_id' => $tid, 'template_name' => $name, 'count' => $cnt('modResource', array('template' => $tid))); }
            $out['resources_by_template'] = $rbt;
        }

        if (isset($want['tvs'])) {
            $tvTotal = $cnt('modTemplateVar');
            $items = array();
            $q = $this->modx->newQuery('modTemplateVar');
            $q->select(array('id', 'name', 'type', 'caption'));
            $q->sortby('name', 'ASC');
            $q->limit($cap);
            foreach ($this->modx->getCollection('modTemplateVar', $q) as $tv) {
                $items[] = array('id' => (int) $tv->get('id'), 'name' => $tv->get('name'), 'type' => $tv->get('type'), 'caption' => $tv->get('caption'));
            }
            $out['tvs'] = array('total' => $tvTotal, 'truncated' => $tvTotal > count($items), 'items' => $items);
        }

        if (isset($want['resource_tree'])) {
            $rootTotal = $cnt('modResource', array('parent' => 0, 'deleted' => 0));
            $q = $this->modx->newQuery('modResource', array('parent' => 0, 'deleted' => 0));
            $q->select(array('id', 'pagetitle', 'context_key', 'template', 'published', 'isfolder'));
            $q->sortby('context_key', 'ASC');
            $q->sortby('menuindex', 'ASC');
            $q->limit($maxTree);
            $roots = array();
            foreach ($this->modx->getCollection('modResource', $q) as $r) {
                $rid = (int) $r->get('id');
                $roots[] = array(
                    'id'          => $rid,
                    'pagetitle'   => $r->get('pagetitle'),
                    'context_key' => $r->get('context_key'),
                    'template'    => (int) $r->get('template'),
                    'published'   => (bool) $r->get('published'),
                    'isfolder'    => (bool) $r->get('isfolder'),
                    'child_count' => $cnt('modResource', array('parent' => $rid, 'deleted' => 0)),
                );
            }
            $out['resource_tree'] = array('depth' => 1, 'total_roots' => $rootTotal, 'truncated' => $rootTotal > count($roots), 'note' => 'Roots + child counts only. Drill down with list_resources(parent=...).', 'roots' => $roots);
        }

        if (isset($want['resources_by_context'])) {
            $rbc = array();
            $cq = $this->modx->newQuery('modContext');
            $cq->select(array('key'));
            foreach ($this->modx->getCollection('modContext', $cq) as $ctx) {
                $k = $ctx->get('key');
                $rbc[] = array('context' => $k, 'count' => $cnt('modResource', array('context_key' => $k, 'deleted' => 0)));
            }
            $out['resources_by_context'] = $rbc;
        }

        if (isset($want['element_categories'])) {
            $catTotal = $cnt('modCategory');
            $items = array();
            $q = $this->modx->newQuery('modCategory');
            $q->select(array('id', 'category'));
            $q->sortby('category', 'ASC');
            $q->limit($cap);
            foreach ($this->modx->getCollection('modCategory', $q) as $c) { $items[] = array('id' => (int) $c->get('id'), 'name' => $c->get('category')); }
            $out['element_categories'] = array('total' => $catTotal, 'truncated' => $catTotal > count($items), 'items' => $items);
        }

        if (isset($want['content_types'])) {
            $items = array();
            $q = $this->modx->newQuery('modContentType');
            $q->select(array('id', 'name', 'mime_type', 'file_extensions'));
            $q->limit($cap);
            foreach ($this->modx->getCollection('modContentType', $q) as $ct) {
                $items[] = array('id' => (int) $ct->get('id'), 'name' => $ct->get('name'), 'mime' => $ct->get('mime_type'), 'extensions' => $ct->get('file_extensions'));
            }
            $out['content_types'] = $items;
        }

        if (isset($want['contexts'])) {
            $items = array();
            $q = $this->modx->newQuery('modContext');
            $q->select(array('key', 'name'));
            foreach ($this->modx->getCollection('modContext', $q) as $ctx) { $items[] = array('key' => $ctx->get('key'), 'name' => $ctx->get('name')); }
            $out['contexts'] = $items;
        }

        if (isset($want['integrations'])) {
            $rep = $this->getIntegrationsReport();
            $out['integrations'] = isset($rep['integrations']) ? $rep['integrations'] : array();
        }

        return $out;
    }

    private function shouldAutoStatic($type) {
        if (!in_array($type, array('chunk', 'snippet', 'template', 'plugin'), true)) { return false; }
        return (bool) $this->modx->getOption('modxmcp.auto_static', null, false);
    }

    private function staticElementMap() {
        return array(
            'chunk'    => array('class' => 'modChunk',    'field' => 'snippet',    'dir' => 'chunks',    'ext' => 'tpl'),
            'snippet'  => array('class' => 'modSnippet',  'field' => 'snippet',    'dir' => 'snippets',  'ext' => 'php'),
            'template' => array('class' => 'modTemplate', 'field' => 'content',    'dir' => 'templates', 'ext' => 'tpl'),
            'plugin'   => array('class' => 'modPlugin',   'field' => 'plugincode', 'dir' => 'plugins',   'ext' => 'php'),
        );
    }

    /**
     * Convert one element to a static file: write its current DB content to
     * core/elements/<dir>/<slug>.<ext> and set static=1, static_file, source=1 (Filesystem).
     */
    private function makeElementStatic($type, $id) {
        $map = $this->staticElementMap();
        if (!isset($map[$type])) { throw new ModxMCPClientException("make_static: unsupported type '{$type}'."); }
        $m = $map[$type];
        $el = $this->modx->getObject($m['class'], (int) $id);
        if (!$el) { throw new ModxMCPClientException("make_static: {$type} {$id} not found."); }
        $nameField = ($type === 'template') ? 'templatename' : 'name';
        $name = (string) $el->get($nameField);
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $slug = trim($slug, '-._');
        if (strlen(preg_replace('/[^A-Za-z0-9]/', '', $slug)) < 3) { $slug = $type . '-' . $id; }
        $rel = 'core/elements/' . $m['dir'] . '/' . $slug . '.' . $m['ext'];
        $base = rtrim($this->modx->getOption('base_path'), '/') . '/';
        $abs = $base . $rel;
        if (file_exists($abs) && !(bool) $el->get('static')) {
            $rel = 'core/elements/' . $m['dir'] . '/' . $slug . '-' . $id . '.' . $m['ext'];
            $abs = $base . $rel;
        }
        $content = (string) $el->get($m['field']);
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { throw new ModxMCPClientException("make_static: cannot create directory {$dir}"); }
        if (@file_put_contents($abs, $content) === false) { throw new ModxMCPClientException("make_static: cannot write {$abs}"); }
        $el->set('static', true);
        $el->set('static_file', $rel);
        $el->set('source', 1);
        if (!$el->save()) { throw new ModxMCPClientException("make_static: save failed for {$type} {$id}"); }
        return array('type' => $type, 'id' => (int) $id, 'name' => $name, 'static_file' => $rel);
    }

    /**
     * Make one element static (data: type, id) or a batch (data: items=[{type,id}]).
     */
    private function makeStatic($data) {
        if (isset($data['items']) && is_array($data['items'])) {
            $out = array();
            foreach ($data['items'] as $it) {
                $t = isset($it['type']) ? $it['type'] : '';
                $i = isset($it['id']) ? (int) $it['id'] : 0;
                try { $out[] = $this->makeElementStatic($t, $i); }
                catch (Exception $e) { $out[] = array('type' => $t, 'id' => $i, 'error' => $e->getMessage()); }
            }
            $this->modx->getCacheManager()->refresh();
            $this->logAudit('make_static', 'batch', array('count' => count($out)));
            return array('count' => count($out), 'results' => $out);
        }
        $res = $this->makeElementStatic(isset($data['type']) ? $data['type'] : '', isset($data['id']) ? $data['id'] : 0);
        $this->modx->getCacheManager()->refresh();
        $this->logAudit('make_static', $res['type'], array('id' => $res['id']));
        return $res;
    }

    // --- Line-based viewing / editing (token-efficient partial edits) ---

    private function lineEditMap() {
        return array(
            'chunk'    => array('class' => 'modChunk',    'field' => 'snippet'),
            'snippet'  => array('class' => 'modSnippet',  'field' => 'snippet'),
            'template' => array('class' => 'modTemplate', 'field' => 'content'),
            'plugin'   => array('class' => 'modPlugin',   'field' => 'plugincode'),
        );
    }

    /** Resolve a chunk/snippet/template/plugin by type + id|name. Returns [el, type, id, mapEntry]. */
    private function resolveLineEditElement($data) {
        $type = isset($data['type']) ? (string) $data['type'] : '';
        $map = $this->lineEditMap();
        if (!isset($map[$type])) {
            throw new ModxMCPClientException('type must be one of: chunk, snippet, template, plugin.');
        }
        $id = !empty($data['id']) ? (int) $data['id'] : (int) $this->resolveIdByName($type, isset($data['name']) ? $data['name'] : '');
        if ($id <= 0) {
            throw new ModxMCPClientException('Element not found (provide id or name).');
        }
        $el = $this->modx->getObject($map[$type]['class'], $id);
        if (!$el) {
            throw new ModxMCPClientException("{$type} {$id} not found.");
        }
        return array($el, $type, $id, $map[$type]);
    }

    /** Read the element's EFFECTIVE content: from the static file if static, else the DB field.
     *  Returns [content, isStatic, staticAbsPath|null]. */
    private function readEffectiveContent($el, $m) {
        $isStatic = (bool) $el->get('static');
        $staticAbs = null;
        if ($isStatic) {
            $rel = (string) $el->get('static_file');
            if ($rel !== '') {
                $rel = $this->resolveModxPathPlaceholders($rel);
                $staticAbs = $this->isAbsolutePath($rel)
                    ? $rel
                    : rtrim($this->modx->getOption('base_path'), '/\\') . '/' . ltrim($rel, '/\\');
            }
            if ($staticAbs !== null && is_file($staticAbs)) {
                return array((string) file_get_contents($staticAbs), $isStatic, $staticAbs);
            }
        }
        return array((string) $el->get($m['field']), $isStatic, $staticAbs);
    }

    /** Write content back where it came from: static file first (source of truth), then DB field. */
    private function writeEffectiveContent($el, $m, $isStatic, $staticAbs, $content) {
        if ($isStatic && $staticAbs !== null) {
            $dir = dirname($staticAbs);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                throw new ModxMCPClientException("cannot create directory {$dir}");
            }
            if (@file_put_contents($staticAbs, $content) === false) {
                throw new ModxMCPClientException("cannot write static file {$staticAbs}");
            }
        }
        $el->set($m['field'], $content);
        if (!$el->save()) {
            throw new ModxMCPClientException('element save failed.');
        }
    }

    /** Split content into lines, reporting the dominant EOL so it can be rejoined unchanged. */
    private function splitLines($content, &$eol) {
        $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
        return explode("\n", str_replace("\r\n", "\n", $content));
    }

    /**
     * Return an element's content as numbered lines (like `cat -n`), optionally windowed by
     * start_line/end_line — so the model can target edits without pulling the whole file.
     * data: type, id|name, start_line?, end_line?.
     */
    private function viewElementLines($data) {
        list($el, $type, $id, $m) = $this->resolveLineEditElement($data);
        list($content, $isStatic, $staticAbs) = $this->readEffectiveContent($el, $m);
        $eol = "\n";
        $lines = $this->splitLines($content, $eol);
        $total = count($lines);
        $start = isset($data['start_line']) ? max(1, (int) $data['start_line']) : 1;
        $end = isset($data['end_line']) ? (int) $data['end_line'] : $total;
        if ($end > $total) { $end = $total; }
        if ($end < $start) { $end = $start; }
        $buf = array();
        for ($i = $start; $i <= $end && $i <= $total; $i++) {
            $buf[] = $i . "\t" . $lines[$i - 1];
        }
        return array(
            'type' => $type,
            'id' => $id,
            'name' => $el->get($type === 'template' ? 'templatename' : 'name'),
            'static' => $isStatic,
            'total_lines' => $total,
            'start_line' => $start,
            'end_line' => min($end, $total),
            'numbered' => implode("\n", $buf),
        );
    }

    /**
     * Resolve one edit's target range. With `expect` given, it is the anchor: verified at the
     * stated position, else relocated to its unique match anywhere in the file (so a small line
     * drift doesn't corrupt). Without `expect`, the literal range is used (must be in bounds).
     */
    private function locateEditRange($lines, $start, $end, $expect, $idx) {
        $total = count($lines);
        if ($expect === null) {
            if ($start < 1 || $end > $total) {
                throw new ModxMCPClientException("edit #{$idx}: lines {$start}-{$end} out of range (1..{$total}).");
            }
            return array($start, $end);
        }
        $expectLines = explode("\n", str_replace("\r\n", "\n", $expect));
        $len = count($expectLines);
        if ($start >= 1 && ($start + $len - 1) <= $total && array_slice($lines, $start - 1, $len) === $expectLines) {
            return array($start, $start + $len - 1);
        }
        $matches = array();
        for ($i = 0; $i + $len <= $total; $i++) {
            if (array_slice($lines, $i, $len) === $expectLines) { $matches[] = $i + 1; }
        }
        if (count($matches) === 1) { return array($matches[0], $matches[0] + $len - 1); }
        if (count($matches) === 0) {
            throw new ModxMCPClientException("edit #{$idx}: expected text not found (the lines have changed since you read them).");
        }
        throw new ModxMCPClientException("edit #{$idx}: expected text matches " . count($matches) . " places; make it more specific.");
    }

    /**
     * Apply line-based edits to a chunk/snippet/template/plugin. Only the changed lines travel —
     * no need to resend the whole element. data:
     *   type, id|name,
     *   edits: [ { start_line, end_line?, replacement?, expect? }, ... ]
     * Semantics (1-based, inclusive): replace [start_line..end_line] with `replacement`
     * (multi-line ok; "" = delete the lines). Insert = empty range (end_line = start_line - 1),
     * inserting `replacement` before start_line. `expect` (current text of the lines) is an
     * optional safety anchor — verified/relocated before applying; mismatch aborts the WHOLE
     * call (atomic). Writes back to the static file if static, else the DB; preserves EOL.
     */
    private function editElementLines($data) {
        list($el, $type, $id, $m) = $this->resolveLineEditElement($data);
        $edits = (isset($data['edits']) && is_array($data['edits'])) ? $data['edits'] : null;
        if (!$edits) {
            throw new ModxMCPClientException('edit_element_lines: a non-empty "edits" array is required.');
        }
        list($content, $isStatic, $staticAbs) = $this->readEffectiveContent($el, $m);
        $eol = "\n";
        $lines = $this->splitLines($content, $eol);
        $total = count($lines);

        // Resolve every edit against the ORIGINAL lines (so ranges/anchors are consistent).
        $resolved = array();
        foreach ($edits as $idx => $e) {
            if (!is_array($e) || !isset($e['start_line'])) {
                throw new ModxMCPClientException("edit #{$idx}: start_line is required.");
            }
            $s = (int) $e['start_line'];
            $en = isset($e['end_line']) ? (int) $e['end_line'] : $s;
            $isInsert = ($en === $s - 1);
            $replacement = isset($e['replacement']) ? (string) $e['replacement'] : '';
            $expect = array_key_exists('expect', $e) ? (string) $e['expect'] : null;

            if ($isInsert) {
                if ($s < 1 || $s > $total + 1) {
                    throw new ModxMCPClientException("edit #{$idx}: insert position {$s} out of range (1.." . ($total + 1) . ").");
                }
                $rs = $s; $re = $s - 1;
            } else {
                if ($en < $s) {
                    throw new ModxMCPClientException("edit #{$idx}: end_line < start_line.");
                }
                list($rs, $re) = $this->locateEditRange($lines, $s, $en, $expect, $idx);
            }
            $replLines = ($replacement === '') ? array() : explode("\n", str_replace("\r\n", "\n", $replacement));
            $resolved[] = array('s' => $rs, 'e' => $re, 'repl' => $replLines, 'insert' => $isInsert);
        }

        // Reject overlapping replace/delete ranges.
        $covered = array();
        foreach ($resolved as $r) {
            if ($r['insert']) { continue; }
            for ($i = $r['s']; $i <= $r['e']; $i++) {
                if (isset($covered[$i])) {
                    throw new ModxMCPClientException("edits overlap on line {$i}.");
                }
                $covered[$i] = true;
            }
        }

        // Apply bottom-up so earlier line indices stay valid.
        usort($resolved, function ($a, $b) { return $b['s'] - $a['s']; });
        foreach ($resolved as $r) {
            $offset = $r['s'] - 1;
            $length = $r['insert'] ? 0 : ($r['e'] - $r['s'] + 1);
            array_splice($lines, $offset, $length, $r['repl']);
        }

        $newContent = implode($eol, $lines);
        $totalAfter = count($lines);

        return $this->runWithTransaction(function () use ($el, $m, $isStatic, $staticAbs, $newContent, $type, $id, $edits, $total, $totalAfter) {
            $this->writeEffectiveContent($el, $m, $isStatic, $staticAbs, $newContent);
            $this->modx->cacheManager->refresh();
            $this->logAudit('edit_element_lines', $type, array('id' => $id, 'edits' => count($edits)));
            return array(
                'type' => $type,
                'id' => $id,
                'static' => $isStatic,
                'edits_applied' => count($edits),
                'total_lines_before' => $total,
                'total_lines_after' => $totalAfter,
            );
        });
    }

    /**
     * Site-wide search & replace across code elements (chunk/snippet/template/plugin). Finds
     * every element whose CONTENT contains `find` and replaces all occurrences with
     * `replacement`, in one call. Honours static files vs DB. Set `dry_run` to preview the
     * affected elements + occurrence counts without writing. data:
     *   find (required), replacement (required), types?[], case_sensitive?, dry_run?, limit?.
     */
    private function replaceAcross($data) {
        $find = isset($data['find']) ? (string) $data['find'] : '';
        if ($find === '') { throw new ModxMCPClientException('replace_across: "find" is required.'); }
        if (!array_key_exists('replacement', $data)) { throw new ModxMCPClientException('replace_across: "replacement" is required (use "" to delete the string).'); }
        $replacement = (string) $data['replacement'];
        // Default to case-SENSITIVE here (unlike search_code): a replacement is usually exact,
        // and a loose match across the whole site is a footgun.
        $cs = array_key_exists('case_sensitive', $data) ? !empty($data['case_sensitive']) : true;
        $dry = !empty($data['dry_run']);
        $allowed = $this->lineEditMap();
        $types = (isset($data['types']) && is_array($data['types']) && $data['types'])
            ? array_values(array_filter($data['types'], function ($t) use ($allowed) { return isset($allowed[$t]); }))
            : array('chunk', 'snippet', 'template', 'plugin');
        if (!$types) { throw new ModxMCPClientException('replace_across: types must be among chunk, snippet, template, plugin.'); }
        $limit = isset($data['limit']) ? max(1, (int) $data['limit']) : 200;

        // Reuse the tested search (incl. static-file scanning) to locate candidate elements.
        $hits = $this->searchCode(array('query' => $find, 'types' => $types, 'limit' => $limit, 'case_sensitive' => $cs));

        $results = array();
        $totalOcc = 0;
        foreach ($hits['results'] as $h) {
            $type = $h['type'];
            if (!isset($allowed[$type])) { continue; }
            $m = $allowed[$type];
            $el = $this->modx->getObject($m['class'], (int) $h['id']);
            if (!$el) { continue; }
            list($content, $isStatic, $staticAbs) = $this->readEffectiveContent($el, $m);
            $count = $cs ? substr_count($content, $find) : substr_count(strtolower($content), strtolower($find));
            if ($count === 0) { continue; } // name-only match — nothing to replace in content
            if (!$dry) {
                $new = $cs ? str_replace($find, $replacement, $content) : str_ireplace($find, $replacement, $content);
                $this->runWithTransaction(function () use ($el, $m, $isStatic, $staticAbs, $new) {
                    $this->writeEffectiveContent($el, $m, $isStatic, $staticAbs, $new);
                    return true;
                });
                $this->logAudit('replace_across', $type, array('id' => (int) $h['id'], 'occurrences' => $count));
            }
            $row = array('type' => $type, 'id' => (int) $h['id'], 'name' => $h['name'], 'occurrences' => $count, 'static' => $isStatic);
            if ($dry) {
                // Show the actual lines that would change (substring match — 'foo' also hits
                // 'footer'), so the preview is reviewable, not just a count.
                $eol = "\n";
                $ls = $this->splitLines($content, $eol);
                $needle = $cs ? $find : strtolower($find);
                $preview = array();
                foreach ($ls as $li => $lt) {
                    $hay = $cs ? $lt : strtolower($lt);
                    if (strpos($hay, $needle) !== false) {
                        $preview[] = array('line' => $li + 1, 'line_text' => $lt);
                        if (count($preview) >= 5) { break; }
                    }
                }
                $row['preview'] = $preview;
            }
            $results[] = $row;
            $totalOcc += $count;
        }
        if (!$dry && $results) { $this->modx->cacheManager->refresh(); }
        return array(
            'find' => $find,
            'replacement' => $replacement,
            'case_sensitive' => $cs,
            'dry_run' => $dry,
            'elements' => count($results),
            'total_occurrences' => $totalOcc,
            'capped_at' => $limit,
            'results' => $results,
        );
    }

    /**
     * Schema introspection: list an xPDO class's fields (name + php/db type, null, default) and
     * its primary key, so the model can use real field names instead of guessing. Accepts a
     * class name (modResource) or a friendly alias (resource/chunk/tv/user/...). data: class.
     */
    private function describeObject($data) {
        $class = isset($data['class']) ? trim((string) $data['class']) : '';
        if ($class === '') { throw new ModxMCPClientException('describe_object: "class" is required (e.g. modResource, or alias "resource").'); }
        $alias = array(
            'chunk' => 'modChunk', 'snippet' => 'modSnippet', 'template' => 'modTemplate',
            'plugin' => 'modPlugin', 'tv' => 'modTemplateVar', 'resource' => 'modResource',
            'category' => 'modCategory', 'user' => 'modUser', 'usergroup' => 'modUserGroup',
            'context' => 'modContext', 'setting' => 'modSystemSetting',
        );
        if (isset($alias[strtolower($class)])) { $class = $alias[strtolower($class)]; }
        $meta = $this->modx->getFieldMeta($class);
        if (empty($meta)) {
            if (!$this->modx->loadClass($class)) {
                throw new ModxMCPClientException("describe_object: unknown class '{$class}'. For add-on classes load the package first (e.g. via a known action).");
            }
            $meta = $this->modx->getFieldMeta($class);
        }
        if (empty($meta)) { throw new ModxMCPClientException("describe_object: no field metadata for '{$class}'."); }
        $fields = array();
        foreach ($meta as $name => $def) {
            $fields[] = array(
                'field'   => $name,
                'phptype' => isset($def['phptype']) ? $def['phptype'] : null,
                'dbtype'  => isset($def['dbtype']) ? $def['dbtype'] : null,
                'null'    => isset($def['null']) ? (bool) $def['null'] : null,
                'default' => array_key_exists('default', $def) ? $def['default'] : null,
            );
        }
        return array('class' => $class, 'primary_key' => $this->modx->getPK($class), 'fields' => $fields);
    }

    /**
     * miniShop2 product-link tooling: link TYPES (msLink: name + relation type +
     * description) and product-to-product LINKS (msProductLink: link + master + slave).
     * Relation types are: many_to_many, one_to_many, many_to_one, one_to_one.
     * Backed by miniShop2's own mgr processors via runMiniShop2Processor().
     */
    private function ms2LinkAction($action, $data) {
        $map = array(
            'ms2_list_link_types'     => array('p' => 'mgr/settings/link/getlist',     'list' => true),
            'ms2_get_link_type'       => array('p' => 'mgr/settings/link/get'),
            'ms2_create_link_type'    => array('p' => 'mgr/settings/link/create'),
            'ms2_update_link_type'    => array('p' => 'mgr/settings/link/update'),
            'ms2_delete_link_type'    => array('p' => 'mgr/settings/link/remove'),
            'ms2_list_product_links'  => array('p' => 'mgr/product/productlink/getlist', 'list' => true),
            'ms2_create_product_link' => array('p' => 'mgr/product/productlink/create'),
            'ms2_delete_product_link' => array('p' => 'mgr/product/productlink/remove'),
            'ms2_list_categories'     => array('p' => 'mgr/category/getlist', 'list' => true),
            'ms2_create_category'     => array('p' => 'mgr/category/create'),
            'ms2_update_category'     => array('p' => 'mgr/category/update'),
            'ms2_list_orders'         => array('p' => 'mgr/orders/getlist', 'list' => true),
            'ms2_get_order'           => array('p' => 'mgr/orders/get'),
            'ms2_update_order'        => array('p' => 'mgr/orders/update'),
        );
        if (!isset($map[$action])) { throw new ModxMCPClientException("Unknown miniShop2 link action: {$action}."); }
        $cfg = $map[$action];
        $props = is_array($data) ? $data : array();
        unset($props['action'], $props['elementType']);
        if (!empty($cfg['list']) && !isset($props['limit'])) { $props['limit'] = 0; }

        // A category is an msCategory resource. The ms2 category create/update processors extend
        // the resource processors with manager-only setup and don't run cleanly headless, so route
        // create/update through the core resource processors with class_key=msCategory instead.
        if ($action === 'ms2_create_category' || $action === 'ms2_update_category') {
            $props['class_key'] = 'msCategory';
            if ($action === 'ms2_create_category') {
                if (!isset($props['context_key'])) { $props['context_key'] = 'web'; }
                if (!isset($props['parent'])) { $props['parent'] = 0; }
                if (!isset($props['published'])) { $props['published'] = 1; }
                $resp = $this->modx->runProcessor('resource/create', $props);
            } else {
                $resp = $this->modx->runProcessor('resource/update', $props);
            }
            if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'ms2 category: no response.'); }
            if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
            $this->logAudit($action, 'ms2', array_intersect_key($props, array_flip(array('id', 'pagetitle', 'parent'))));
            return $this->normalizeProcessorResponse($resp);
        }

        $result = $this->runMiniShop2Processor($cfg['p'], $props);
        $this->logAudit($action, 'ms2', array_intersect_key($props, array_flip(array('id', 'link', 'master', 'slave', 'name', 'type'))));
        return $result;
    }

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
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
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
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('migx_delete_config', 'migx', array('id' => (int) $data['id'], 'name' => $name));
        return array('deleted' => true, 'id' => (int) $data['id'], 'name' => $name);
    }

    /**
     * Available TV input (widget) types. Core types are listed statically; any custom
     * types registered by other components (e.g. MIGX) are collected from OnTVInputRenderList,
     * exactly as the manager builds the TV 'Input Type' dropdown.
     */
    private function listTvInputTypes() {
        // Each core type carries `use` (when to pick it) + `requires` (extra create_element keys)
        // so a model can choose the right field_type without reading the docs. See help topic
        // tv_input_types for full examples.
        $g = function ($label, $use, $requires) { return array('label' => $label, 'use' => $use, 'requires' => $requires); };
        $core = array(
            'text'             => $g('Text', 'short single-line text (titles, labels, css class)', array()),
            'textarea'         => $g('Textarea', 'multi-line plain text (no editor)', array()),
            'textareamini'     => $g('Textarea (Mini)', 'a few lines of plain text', array()),
            'rawtext'          => $g('Text (No Filters)', 'single-line text stored raw (HTML/code, not output-filtered)', array()),
            'rawtextarea'      => $g('Textarea (No Filters)', 'multi-line raw text/HTML/code (not filtered)', array()),
            'richtext'         => $g('RichText', 'formatted body content via the WYSIWYG editor', array()),
            'date'             => $g('Date', 'a date / datetime value', array('input_properties.format? (e.g. %Y-%m-%d)')),
            'number'           => $g('Number', 'a numeric value', array('input_properties.min/max/step? (optional)')),
            'email'            => $g('Email', 'an email address', array()),
            'url'              => $g('URL', 'a URL', array()),
            'hidden'           => $g('Hidden', 'a value not shown in the edit form', array()),
            'checkbox'         => $g('Checkbox', 'one or more on/off options', array('elements (Label==value||...) for multiple', 'default_text?')),
            'listbox'          => $g('Listbox (Single-Select)', 'pick ONE value from a fixed list', array('elements (Label==value||...)', 'default_text?')),
            'listbox-multiple' => $g('Listbox (Multi-Select)', 'pick SEVERAL values from a list', array('elements (Label==value||...)')),
            'radio'            => $g('Radio Options', 'pick ONE value shown as radio buttons', array('elements (Label==value||...)', 'default_text?')),
            'image'            => $g('Image', 'pick/upload an image', array('media_source (id)')),
            'file'             => $g('File', 'pick/upload a file', array('media_source (id)')),
            'resourcelist'     => $g('Resource List', 'pick a MODX resource (page) by id', array('input_properties.parents? to limit the tree')),
            'tag'              => $g('Tag', 'comma-separated tags', array()),
            'autotag'          => $g('Auto-Tag', 'tags with auto-complete', array()),
        );
        $custom = array();
        // OnTVInputRenderList is a manager event; some add-ons (e.g. Ace) implement it assuming a
        // manager-controller context and fatal headlessly. A misbehaving plugin must not 500 this
        // action — catch it and just skip its custom types. (Two catches for PHP 5/7+ portability.)
        $results = null;
        try {
            $results = $this->modx->invokeEvent('OnTVInputRenderList');
        } catch (Exception $e) {
            $results = null;
        } catch (Throwable $e) {
            $results = null;
        }
        if (is_array($results)) {
            foreach ($results as $res) {
                if (is_array($res)) { $res = implode("\n", $res); }
                foreach (preg_split('/\r\n|\r|\n/', (string) $res) as $line) {
                    $line = trim($line);
                    if ($line === '') { continue; }
                    // Some add-ons (e.g. MIGX) return a render-templates directory path instead
                    // of a "key==Label" pair; skip those — they aren't usable type keys.
                    if (strpos($line, '==') !== false) {
                        list($k, $lbl) = explode('==', $line, 2);
                        $custom[trim($k)] = trim($lbl);
                    } elseif (strpos($line, '/') === false && strpos($line, '\\') === false) {
                        $custom[$line] = $line;
                    }
                }
            }
        }
        // Some add-ons register their input type via the OnTVInputRenderList event, which may
        // have been swallowed above if another plugin on it fataled. Add well-known ones
        // explicitly when their namespace is installed, so they're still reported.
        if ($this->modx->getObject('modNamespace', array('name' => 'migx'))) {
            if (!isset($custom['migx']))   { $custom['migx'] = 'MIGX'; }
            if (!isset($custom['migxdb'])) { $custom['migxdb'] = 'MIGXdb'; }
        }
        if (!isset($custom['colorpicker']) && $this->modx->getObject('modNamespace', array('name' => 'colorpicker'))) {
            $custom['colorpicker'] = 'ColorPicker';
        }
        return array('core' => $core, 'custom' => $custom);
    }

    /**
     * Suggest the right TV input type for a described need. Deterministic keyword rules
     * (English + Russian), ranked, with a ready-to-edit create_element skeleton for the top
     * pick. Complements the tv_input_types doc — helps a model that's unsure choose correctly.
     * data: description (required).
     */
    private function suggestTvType($data) {
        $desc = isset($data['description']) ? mb_strtolower((string) $data['description'], 'UTF-8') : '';
        if (trim($desc) === '') { throw new ModxMCPClientException('suggest_tv_type: "description" is required.'); }

        // Ordered most-specific-first so ties favour the more specific type. Keywords bilingual.
        $rules = array(
            array('type' => 'migx',         'why' => 'repeating structured rows (each with several fields)', 'kw' => array('migx', 'repeat', 'rows', 'gallery', 'slides', 'slider', 'carousel', 'list of items', 'blocks', 'multiple items', 'галере', 'слайд', 'слайдер', 'карусел', 'повторя', 'список блок', 'несколько элемент', 'список товар', 'список услуг')),
            array('type' => 'image',        'why' => 'a single image', 'kw' => array('image', 'photo', 'picture', 'logo', 'banner', 'icon', 'thumbnail', 'изображ', 'картинк', 'фото', 'логотип', 'баннер', 'иконк', 'превью')),
            array('type' => 'file',         'why' => 'a downloadable file', 'kw' => array('file', 'document', 'pdf', 'download', 'attachment', 'файл', 'документ', 'скачать', 'вложен')),
            array('type' => 'colorpicker',  'why' => 'a color value', 'kw' => array('color', 'colour', 'цвет', 'окрас')),
            array('type' => 'date',         'why' => 'a date / time value', 'kw' => array('date', 'time', 'datetime', 'deadline', 'schedule', 'дата', 'время', 'срок', 'расписан')),
            array('type' => 'number',       'why' => 'a numeric value', 'kw' => array('number', 'numeric', 'count', 'quantity', 'qty', 'amount', 'price', 'integer', 'weight', 'rating', 'число', 'количеств', 'цена', 'сумма', 'вес', 'рейтинг')),
            array('type' => 'email',        'why' => 'an email address', 'kw' => array('email', 'e-mail', 'почт', 'эл. адрес')),
            array('type' => 'resourcelist', 'why' => 'a link to another MODX resource (page)', 'kw' => array('related page', 'link to a page', 'resource', 'parent page', 'choose a page', 'связанн', 'ссылка на страниц', 'ресурс', 'выбрать страниц', 'родительск')),
            array('type' => 'url',          'why' => 'a URL / web link', 'kw' => array('url', 'website', 'web link', 'external link', 'href', 'ссылк', 'сайт', 'веб-адрес')),
            array('type' => 'richtext',     'why' => 'formatted content via the WYSIWYG editor', 'kw' => array('rich', 'wysiwyg', 'formatted', 'html content', 'article body', 'content body', 'editor', 'форматир', 'визуальн', 'редактор', 'статья', 'контент тела', 'вёрстк')),
            array('type' => 'listbox-multiple', 'why' => 'pick SEVERAL values from a list', 'kw' => array('multiple', 'several', 'multi-select', 'multiselect', 'many of', 'несколько из', 'мультивыбор', 'много значен')),
            array('type' => 'listbox',      'why' => 'pick ONE value from a fixed list', 'kw' => array('select', 'dropdown', 'choose one', 'option', 'status', 'choice', 'list of values', 'выбор', 'выпадающ', 'один из', 'опци', 'статус', 'вариант')),
            array('type' => 'radio',        'why' => 'pick ONE value as radio buttons', 'kw' => array('radio', 'радио', 'переключател')),
            array('type' => 'checkbox',     'why' => 'on/off toggle(s)', 'kw' => array('checkbox', 'yes/no', 'yes no', 'boolean', 'toggle', 'flag', 'on/off', 'enable', 'чекбокс', 'да/нет', 'да нет', 'флаг', 'вкл', 'переключ', 'булев')),
            array('type' => 'tag',          'why' => 'comma-separated tags', 'kw' => array('tag', 'tags', 'keywords', 'тег', 'метк', 'ключев слов')),
            array('type' => 'textarea',     'why' => 'multi-line plain text', 'kw' => array('textarea', 'multi-line', 'multiline', 'paragraph', 'description', 'note', 'многострочн', 'абзац', 'описани', 'заметк')),
            array('type' => 'rawtextarea',  'why' => 'raw HTML/code, not output-filtered', 'kw' => array('raw html', 'embed code', 'script', 'iframe', 'код', 'встроить код', 'сырой html')),
            array('type' => 'text',         'why' => 'short single-line text', 'kw' => array('title', 'name', 'label', 'heading', 'short text', 'css class', 'заголов', 'назван', 'короткий текст', 'css класс', 'метка')),
        );

        $scored = array();
        foreach ($rules as $i => $r) {
            $score = 0; $hits = array();
            foreach ($r['kw'] as $kw) {
                if (mb_strpos($desc, mb_strtolower($kw, 'UTF-8'), 0, 'UTF-8') !== false) { $score++; $hits[] = $kw; }
            }
            if ($score > 0) { $scored[] = array('type' => $r['type'], 'why' => $r['why'], 'score' => $score, 'matched' => $hits, 'order' => $i); }
        }
        // Sort by score desc, then by specificity (earlier rule wins ties).
        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) { return $b['score'] - $a['score']; }
            return $a['order'] - $b['order'];
        });

        // colorpicker only if the add-on is installed; else fall back to text with a note.
        $types = $this->listTvInputTypes();
        $colorAvailable = isset($types['custom']['colorpicker']);
        $note = null;
        foreach ($scored as $k => $c) {
            if ($c['type'] === 'colorpicker' && !$colorAvailable) {
                $scored[$k]['type'] = 'text';
                $scored[$k]['why'] = 'a color value (no colorpicker add-on installed — use text, or install one)';
                $note = 'No colorpicker input type is installed; suggesting text. Install a colorpicker add-on for a real picker.';
            }
        }

        if (!$scored) {
            $scored[] = array('type' => 'text', 'why' => 'no strong signal — short single-line text is the safe default', 'score' => 0, 'matched' => array());
        }

        // Skeleton for the top pick.
        $top = $scored[0]['type'];
        $reqMap = array(
            'image' => array('media_source' => '<media source id — see modx_list_media_sources>'),
            'file'  => array('media_source' => '<media source id — see modx_list_media_sources>'),
            'listbox' => array('elements' => 'Label==value||Label2==value2'),
            'listbox-multiple' => array('elements' => 'Label==value||Label2==value2'),
            'radio' => array('elements' => 'Label==value||Label2==value2'),
            'checkbox' => array('elements' => 'Yes==1'),
            'resourcelist' => array('input_properties' => array('parents' => '<optional parent id to limit the picker>')),
            'colorpicker' => array('input_properties' => array('format' => 'hex')),
            'migx' => array('input_properties' => array('configs' => '', 'formtabs' => '<see the migx help topic>', 'columns' => '<see the migx help topic>')),
        );
        $skel = array('name' => '<tv_name>', 'caption' => '<Caption>', 'field_type' => $top, 'templates' => array('<template id>'));
        if (isset($reqMap[$top])) { $skel = array_merge($skel, $reqMap[$top]); }

        return array(
            'description' => (string) $data['description'],
            'candidates'  => array_slice($scored, 0, 3),
            'recommended' => $top,
            'create_skeleton' => array('action' => 'create_element', 'type' => 'tv', 'data' => $skel),
            'note' => $note,
            'docs' => 'See the tv_input_types help topic (and migx for repeating rows).',
        );
    }

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
                'version'   => $version,
                'note'      => $def['note'],
            );
        }
        return array('integrations' => $out);
    }

    /**
     * Generate a fresh modxmcp.api_token, save it, and return it once.
     */
    private function flushPermissions() {
        $cm = $this->modx->getCacheManager();
        $ok = $cm ? $cm->flushPermissions() : false;
        $this->logAudit('flush_permissions', 'system', array());
        return array('flushed' => (bool) $ok);
    }

    /**
     * Media (file) sources: create/update via source/* core processors.
     * `properties` is a simple {name: value} map of source parameters (basePath, baseUrl, ...)
     * that is MERGED into the source's existing params (the rest are preserved).
     */
    private function saveMediaSource($data, $isCreate) {
        $props = is_array($data) ? $data : array();
        unset($props['action'], $props['elementType']);
        $userProps = (isset($props['properties']) && is_array($props['properties'])) ? $props['properties'] : null;
        unset($props['properties']);
        if ($isCreate) {
            if (empty($props['name'])) { throw new ModxMCPClientException('create_media_source: name is required.'); }
            if (empty($props['class_key'])) { $props['class_key'] = 'sources.modFileMediaSource'; }
            $resp = $this->modx->runProcessor('source/create', $props);
        } else {
            if (empty($props['id'])) { throw new ModxMCPClientException('update_media_source: id is required.'); }
            $resp = $this->modx->runProcessor('source/update', $props);
        }
        if (!$resp) { throw new ModxMCPClientException('media source: no response.'); }
        if ($resp->isError()) { throw new ModxMCPClientException($this->formatProcessorErrors($resp)); }
        $obj = $resp->getObject();
        $id = $isCreate ? ((is_array($obj) && isset($obj['id'])) ? (int) $obj['id'] : 0) : (int) $props['id'];
        if ($userProps !== null && $id > 0) { $this->mergeMediaSourceProperties($id, $userProps); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit($isCreate ? 'create_media_source' : 'update_media_source', 'source', array('id' => $id, 'name' => isset($props['name']) ? $props['name'] : null));
        return array('id' => $id, 'properties_set' => $userProps !== null ? array_keys($userProps) : array());
    }

    private function mergeMediaSourceProperties($id, array $map) {
        $source = $this->modx->getObject('sources.modMediaSource', (int) $id);
        if (!$source) { throw new ModxMCPClientException("media source {$id} not found for properties update."); }
        $current = $source->getProperties();
        if (!is_array($current)) { $current = array(); }
        foreach ($map as $k => $v) {
            if (isset($current[$k]) && is_array($current[$k])) {
                $current[$k]['value'] = $v;
            } else {
                $current[$k] = array('name' => $k, 'desc' => '', 'type' => 'textfield', 'options' => array(), 'value' => $v, 'area' => '');
            }
        }
        $source->setProperties($current);
        if (!$source->save()) { throw new ModxMCPClientException("Could not save media source {$id} properties."); }
    }

    private function deleteMediaSource($data) {
        if (empty($data['id'])) { throw new ModxMCPClientException('delete_media_source: id is required.'); }
        $resp = $this->modx->runProcessor('source/remove', array('id' => (int) $data['id']));
        if (!$resp) { throw new ModxMCPClientException('delete_media_source: no response.'); }
        if ($resp->isError()) { throw new ModxMCPClientException($this->formatProcessorErrors($resp)); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('delete_media_source', 'source', array('id' => (int) $data['id']));
        return array('deleted' => true, 'id' => (int) $data['id']);
    }

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
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
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

    private function filterProcessorData($elementType, array $data) {
        $allowed = [
            'chunk' => ['id', 'name', 'description', 'snippet', 'category', 'static', 'static_file', 'source', 'property_preprocess'],
            'snippet' => ['id', 'name', 'description', 'snippet', 'category', 'static', 'static_file', 'source', 'property_preprocess'],
            'template' => ['id', 'templatename', 'description', 'content', 'category', 'static', 'static_file', 'source'],
            'resource' => ['id', 'pagetitle', 'longtitle', 'description', 'alias', 'parent', 'template', 'content', 'published', 'context_key', 'class_key', 'isfolder', 'hidemenu', 'introtext', 'menutitle', 'menuindex', 'article', 'price', 'old_price', 'weight', 'remains', 'vendor', 'made_in', 'new', 'popular', 'favorite', 'tags', 'color', 'size'],
            'tv' => ['id', 'name', 'caption', 'description', 'category', 'type', 'elements', 'display', 'default_text', 'rank'],
            'category' => ['id', 'category', 'parent', 'rank'],
            'plugin' => ['id', 'name', 'description', 'plugincode', 'category', 'disabled', 'static', 'static_file', 'source', 'property_preprocess'],
        ];

        if (empty($allowed[$elementType])) {
            return $data;
        }

        return array_intersect_key($data, array_flip($allowed[$elementType]));
    }

    private function runWithTransaction(callable $callback) {
        $this->modx->beginTransaction();
        try {
            $result = $callback();
            $this->modx->commit();
            return $result;
        } catch (Exception $e) {
            $this->modx->rollback();
            throw $e;
        }
    }

    private function logAudit($action, $elementType, array $payload =[]) {
        $auditEnabled = (bool)$this->modx->getOption('modxmcp.audit_log', null, true);
        if (!$auditEnabled) {
            return;
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            sprintf(
                '[modxmcp] action=%s type=%s service_user=%s payload=%s',
                $action,
                $elementType,
                $this->modx->user ? $this->modx->user->get('id') : 'unknown',
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            )
        );

        $this->writeAuditFile($action, $elementType, $payload);
    }

    private function auditLogDir() {
        // Live under the component (not core/cache/, which MODX wipes on a cache refresh).
        return rtrim($this->modx->getOption('core_path'), '/') . '/components/modxmcp/logs';
    }

    private function auditLogPath() {
        return $this->auditLogDir() . '/audit.log';
    }

    private function writeAuditFile($action, $elementType, array $payload) {
        try {
            $dir = $this->auditLogDir();
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            $entry = array(
                'ts'      => date('c'),
                'action'  => $action,
                'type'    => $elementType,
                'user'    => $this->modx->user ? (int) $this->modx->user->get('id') : null,
                'payload' => $payload,
            );
            @file_put_contents($dir . '/audit.log', json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            /* auditing must never break the action */
        }
    }

    /**
     * Read back the modxMCP audit trail (last N entries, newest last), optionally
     * filtered to one action.
     */
    private function readAuditLog($data) {
        $path = $this->auditLogPath();
        if (!file_exists($path)) { return array('total' => 0, 'entries' => array()); }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) { $lines = array(); }
        $filterAction = isset($data['action']) ? (string) $data['action'] : '';
        $entries = array();
        foreach ($lines as $line) {
            $e = json_decode($line, true);
            if (!is_array($e)) { continue; }
            if ($filterAction !== '' && (!isset($e['action']) || $e['action'] !== $filterAction)) { continue; }
            $entries[] = $e;
        }
        $limit = isset($data['limit']) ? max(1, (int) $data['limit']) : 100;
        $entries = array_slice($entries, -$limit);
        return array('total' => count($entries), 'entries' => $entries);
    }

    /**
     * Refresh the MODX cache. Pass partitions (e.g. ['resource','context_settings'])
     * to refresh only those; omit for a full refresh.
     */
    private function clearCacheAction($data) {
        $partitions = (isset($data['partitions']) && is_array($data['partitions'])) ? $data['partitions'] : array();
        $cm = $this->modx->getCacheManager();
        if (!empty($partitions)) {
            $providers = array();
            foreach ($partitions as $p) { $providers[(string) $p] = array(); }
            $cm->refresh($providers);
        } else {
            $cm->refresh();
        }
        $this->logAudit('clear_cache', 'system', array('partitions' => $partitions));
        return array('cleared' => true, 'partitions' => !empty($partitions) ? $partitions : 'all');
    }

    /**
     * Generic passthrough to any MODX processor. High privilege — gated behind
     * modxmcp.allow_run_processor (off by default).
     */
    private function runProcessorPassthrough($data) {
        if (!$this->modx->getOption('modxmcp.allow_run_processor', null, false)) {
            throw new ModxMCPClientException('run_processor is disabled. Set modxmcp.allow_run_processor = Yes to enable it.');
        }
        $processor = isset($data['processor']) ? (string) $data['processor'] : '';
        if ($processor === '') { throw new ModxMCPClientException('run_processor: "processor" path is required.'); }
        $props = (isset($data['properties']) && is_array($data['properties'])) ? $data['properties'] : array();
        $options = array();
        if (!empty($data['processors_path'])) { $options['processors_path'] = (string) $data['processors_path']; }
        $response = $this->modx->runProcessor($processor, $props, $options);
        if (!$response) { throw new ModxMCPClientException('run_processor: no response (processor not found?).'); }
        if ($response->isError()) { throw new ModxMCPClientException($this->formatProcessorErrors($response)); }
        $this->logAudit('run_processor', 'system', array('processor' => $processor));
        $decoded = json_decode($response->getResponse(), true);
        return is_array($decoded) ? $decoded : $this->normalizeProcessorResponse($response);
    }

    /**
     * Introspection: the action names this server build supports, grouped. Lets a client
     * detect client/server version skew without reading the source.
     */
    private function listSupportedActions() {
        $out = array();
        foreach ($this->actionRegistry() as $group => $actions) {
            $out[$group] = array_keys($actions);
        }
        return $out;
    }

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
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('install_package', 'system', array('package' => $name, 'signature' => $signature));
        return array('status' => 'installed', 'package' => isset($chosen['name']) ? $chosen['name'] : $name, 'signature' => $signature, 'version' => isset($chosen['version']) ? $chosen['version'] : null);
    }

    private function uninstallPackage($data) {
        $sig = isset($data['signature']) ? (string) $data['signature'] : '';
        if ($sig === '') { throw new ModxMCPClientException('uninstall_package: "signature" is required (e.g. migx-2.13.0-pl).'); }
        $resp = $this->modx->runProcessor('workspace/packages/uninstall', array('signature' => $sig));
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'uninstall_package: no response.'); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
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
    }

    /**
     * Reports which capability groups exist, which are off, and the flat list of disabled
     * action names — the client uses this to hide disabled tools from its tool list.
     */
    /**
     * Built-in documentation (RAG-lite): returns a help topic's markdown on demand. No topic
     * (or 'index') returns the topic list. Docs live in core/components/modxmcp/docs/.
     */
    private function getHelp($data) {
        $dir = rtrim($this->modx->getOption('core_path'), '/') . '/components/modxmcp/docs/';
        $topic = isset($data['topic']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string) $data['topic'])) : '';
        $available = array();
        foreach ((array) glob($dir . '*.md') as $f) { $available[] = basename($f, '.md'); }
        sort($available);
        if ($topic === '' || $topic === 'index') {
            $idx = @file_get_contents($dir . 'index.md');
            return array('topics' => $available, 'index' => ($idx !== false) ? $idx : '');
        }
        $file = $dir . $topic . '.md';
        if (!file_exists($file)) {
            return array('error' => "Unknown help topic '{$topic}'.", 'topics' => $available);
        }
        return array('topic' => $topic, 'content' => (string) @file_get_contents($file));
    }

    public function getCapabilities() {
        $groups = $this->listSupportedActions();
        $toggle = $this->toggleableGroupKeys();
        $disabled = $this->disabledGroups();
        $disabledActions = array();
        foreach ($toggle as $g) {
            if (isset($disabled[$g]) && isset($groups[$g])) {
                foreach ($groups[$g] as $a) { $disabledActions[] = $a; }
            }
        }
        return array(
            'toggleable_groups' => $toggle,
            'disabled_groups'   => array_keys($disabled),
            'disabled_actions'  => $disabledActions,
            'fingerprint'       => $this->capabilitiesFingerprint(),
        );
    }

    /** Short fingerprint of the capability config; the client watches it on every response. */
    public function capabilitiesFingerprint() {
        return (string) $this->modx->getOption('modxmcp.disabled_groups', null, '');
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
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit($isCreate ? 'create_property_set' : 'update_property_set', 'element', array('id' => (int) $ps->get('id'), 'name' => $ps->get('name')));
        return array('id' => (int) $ps->get('id'), 'name' => $ps->get('name'));
    }

    private function deletePropertySet($data) {
        if (empty($data['id'])) { throw new ModxMCPClientException('delete_property_set: id is required.'); }
        $ps = $this->modx->getObject('modPropertySet', (int) $data['id']);
        if (!$ps) { throw new ModxMCPClientException('delete_property_set: property set ' . (int) $data['id'] . ' not found.'); }
        $name = $ps->get('name');
        if (!$ps->remove()) { throw new ModxMCPClientException('delete_property_set: remove failed.'); }
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
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
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
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
        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
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

        $this->modx->cacheManager->refresh();
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

        $this->modx->cacheManager->refresh();
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

        $this->modx->cacheManager->refresh();
        $this->logAudit('delete_system_setting', 'system_setting', ['key' => $key]);
        return "Successfully deleted system setting ({$key}).";
    }

    private function listMediaSources() {
        $sources = $this->modx->getCollection('sources.modMediaSource');
        $result = [];
        foreach ($sources as $source) {
            $result[] = $this->normalizeMediaSource($source, false);
        }
        return $result;
    }

    private function getMediaSource(array $data) {
        $source = $this->resolveMediaSource($data);
        if (!$source) {
            throw new ModxMCPClientException('Media source not found.');
        }
        return $this->normalizeMediaSource($source, true);
    }

    private function listMediaSourceFiles(array $data) {
        $source = $this->resolveMediaSource($data);
        if (!$source) {
            throw new ModxMCPClientException('Media source not found.');
        }
        $this->assertMediaSourceReadAllowed($source);

        $rootPath = $this->getMediaSourceRootPath($source);
        $relativePath = !empty($data['path']) ? $this->normalizeRelativePath($data['path']) : '';
        $absolutePath = $this->joinMediaSourcePath($rootPath, $relativePath);

        if (!is_dir($absolutePath)) {
            throw new ModxMCPClientException("Directory not found: {$relativePath}");
        }

        $entries = [];
        foreach (scandir($absolutePath) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $entryAbsolute = $absolutePath . DIRECTORY_SEPARATOR . $entry;
            $entryRelative = ltrim(str_replace('\\', '/', ($relativePath ? $relativePath . '/' : '') . $entry), '/');
            $entries[] = [
                'name' => $entry,
                'path' => $entryRelative,
                'is_dir' => is_dir($entryAbsolute),
                'size' => is_file($entryAbsolute) ? filesize($entryAbsolute) : null,
                'modified_on' => filemtime($entryAbsolute),
            ];
        }

        usort($entries, function ($a, $b) {
            if ($a['is_dir'] === $b['is_dir']) {
                return strcmp($a['name'], $b['name']);
            }
            return $a['is_dir'] ? -1 : 1;
        });

        return [
            'media_source' => $this->normalizeMediaSource($source, false),
            'path' => $relativePath,
            'entries' => $entries,
        ];
    }

    private function readMediaSourceFile(array $data) {
        $source = $this->resolveMediaSource($data);
        if (!$source) {
            throw new ModxMCPClientException('Media source not found.');
        }
        $this->assertMediaSourceReadAllowed($source);
        if (empty($data['path'])) {
            throw new ModxMCPClientException('File path is required.');
        }

        $rootPath = $this->getMediaSourceRootPath($source);
        $relativePath = $this->normalizeRelativePath($data['path']);
        $absolutePath = $this->joinMediaSourcePath($rootPath, $relativePath);

        if (!is_file($absolutePath)) {
            throw new ModxMCPClientException("File not found: {$relativePath}");
        }

        return array_merge([
            'media_source' => $this->normalizeMediaSource($source, false),
            'path' => $relativePath,
        ], $this->readFileBounded($absolutePath, $data));
    }

    /**
     * Read a file with a byte cap (modxmcp.max_read_bytes, default 256KB) and an optional
     * offset/bytes window, so a huge file can't blow up the client's context. Returns
     * mime/encoding/content plus size (total file size), offset, returned_bytes and a
     * `truncated` flag (true when more bytes remain past offset+returned).
     */
    private function readFileBounded($absolutePath, array $data) {
        $total = (int) filesize($absolutePath);
        $maxAllowed = (int) $this->modx->getOption('modxmcp.max_read_bytes', null, 262144);
        if ($maxAllowed <= 0) { $maxAllowed = 262144; }
        $offset = isset($data['offset']) ? max(0, (int) $data['offset']) : 0;
        if ($offset > $total) { $offset = $total; }
        $requested = isset($data['bytes']) ? (int) $data['bytes'] : (isset($data['length']) ? (int) $data['length'] : $maxAllowed);
        if ($requested <= 0 || $requested > $maxAllowed) { $requested = $maxAllowed; }

        $raw = ($total > 0 && $offset < $total)
            ? (string) file_get_contents($absolutePath, false, null, $offset, $requested)
            : '';
        $returned = strlen($raw);

        $mime = function_exists('mime_content_type') ? mime_content_type($absolutePath) : 'application/octet-stream';
        $isText = is_string($mime) && (
            strpos($mime, 'text/') === 0 ||
            strpos($mime, 'json') !== false ||
            strpos($mime, 'xml') !== false ||
            strpos($mime, 'javascript') !== false ||
            strpos($mime, 'svg') !== false ||
            strpos($mime, 'x-httpd-php') !== false
        );

        return [
            'mime' => $mime,
            'size' => $total,
            'offset' => $offset,
            'returned_bytes' => $returned,
            'truncated' => ($offset + $returned) < $total,
            'encoding' => $isText ? 'utf-8' : 'base64',
            'content' => $isText ? $raw : base64_encode($raw),
        ];
    }

    private function listInstalledComponents() {
        $components = [];

        foreach ($this->getComponentCodeRoots() as $scope => $rootPath) {
            if (!is_dir($rootPath)) {
                continue;
            }

            foreach (scandir($rootPath) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $componentPath = $rootPath . DIRECTORY_SEPARATOR . $entry;
                if (!is_dir($componentPath)) {
                    continue;
                }

                if (!isset($components[$entry])) {
                    $components[$entry] = [
                        'name' => $entry,
                        'scopes' => [],
                    ];
                }

                $components[$entry]['scopes'][$scope] = [
                    'path' => $this->normalizeFilesystemPath($componentPath),
                ];
            }
        }

        ksort($components, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($components);
    }

    private function getComponentFiles(array $data) {
        if (empty($data['name'])) {
            throw new ModxMCPClientException('Component name is required.');
        }

        $relativePath = !empty($data['path']) ? $this->normalizeRelativePath($data['path']) : '';
        $scopes = $this->resolveComponentScopes(isset($data['scope']) ? $data['scope'] : null);
        $results = [];

        foreach ($scopes as $scope) {
            $componentRoot = $this->getComponentRootPath($data['name'], $scope);
            if (!is_dir($componentRoot)) {
                continue;
            }

            $absolutePath = $this->joinMediaSourcePath($componentRoot, $relativePath);
            if (!is_dir($absolutePath)) {
                continue;
            }

            $entries = [];
            foreach (scandir($absolutePath) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $entryAbsolute = $absolutePath . DIRECTORY_SEPARATOR . $entry;
                $entryRelative = ltrim(str_replace('\\', '/', ($relativePath ? $relativePath . '/' : '') . $entry), '/');
                $entries[] = [
                    'name' => $entry,
                    'path' => $entryRelative,
                    'is_dir' => is_dir($entryAbsolute),
                    'size' => is_file($entryAbsolute) ? filesize($entryAbsolute) : null,
                    'modified_on' => filemtime($entryAbsolute),
                ];
            }

            usort($entries, function ($a, $b) {
                if ($a['is_dir'] === $b['is_dir']) {
                    return strcmp($a['name'], $b['name']);
                }
                return $a['is_dir'] ? -1 : 1;
            });

            $results[] = [
                'scope' => $scope,
                'component' => $data['name'],
                'root_path' => $componentRoot,
                'path' => $relativePath,
                'entries' => $entries,
            ];
        }

        if (empty($results)) {
            throw new ModxMCPClientException("Component not found or path unavailable: {$data['name']}.");
        }

        return count($results) === 1 ? $results[0] : $results;
    }

    private function readComponentFile(array $data) {
        if (empty($data['name'])) {
            throw new ModxMCPClientException('Component name is required.');
        }
        if (empty($data['path'])) {
            throw new ModxMCPClientException('Component file path is required.');
        }

        $relativePath = $this->normalizeRelativePath($data['path']);
        $scopes = $this->resolveComponentScopes(isset($data['scope']) ? $data['scope'] : null);

        foreach ($scopes as $scope) {
            $componentRoot = $this->getComponentRootPath($data['name'], $scope);
            if (!is_dir($componentRoot)) {
                continue;
            }

            $absolutePath = $this->joinMediaSourcePath($componentRoot, $relativePath);
            if (!is_file($absolutePath)) {
                continue;
            }

            return array_merge([
                'component' => $data['name'],
                'scope' => $scope,
                'root_path' => $componentRoot,
                'path' => $relativePath,
            ], $this->readFileBounded($absolutePath, $data));
        }

        throw new ModxMCPClientException("Component file not found: {$data['name']} / {$relativePath}.");
    }

    private function getResourceTvs(array $data) {
        $resourceId = !empty($data['resource_id']) ? (int)$data['resource_id'] : 0;
        if ($resourceId <= 0) {
            throw new ModxMCPClientException('resource_id is required.');
        }

        $resource = $this->modx->getObject('modResource', $resourceId);
        if (!$resource) {
            throw new ModxMCPClientException("Resource not found: {$resourceId}.");
        }

        $templateId = (int)$resource->get('template');
        $tvLinks = $this->modx->getCollection('modTemplateVarTemplate', ['templateid' => $templateId]);
        $result = [];

        foreach ($tvLinks as $link) {
            $tv = $this->modx->getObject('modTemplateVar', $link->get('tmplvarid'));
            if (!$tv) {
                continue;
            }
            $result[] = [
                'id' => $tv->get('id'),
                'name' => $tv->get('name'),
                'caption' => $tv->get('caption'),
                'type' => $tv->get('type'),
                'value' => $resource->getTVValue($tv->get('name')),
            ];
        }

        return [
            'resource_id' => $resourceId,
            'template_id' => $templateId,
            'tvs' => $result,
        ];
    }

    private function updateResourceTvs(array $data) {
        $resourceId = !empty($data['resource_id']) ? (int)$data['resource_id'] : 0;
        if ($resourceId <= 0) {
            throw new ModxMCPClientException('resource_id is required.');
        }
        if (empty($data['tvs']) || !is_array($data['tvs'])) {
            throw new ModxMCPClientException('tvs payload must be a non-empty object/array.');
        }

        $resource = $this->modx->getObject('modResource', $resourceId);
        if (!$resource) {
            throw new ModxMCPClientException("Resource not found: {$resourceId}.");
        }

        foreach ($data['tvs'] as $tvName => $tvValue) {
            $resource->setTVValue($tvName, $tvValue);
        }

        $this->modx->cacheManager->refresh();
        $this->logAudit('update_resource_tvs', 'resource_tv', ['resource_id' => $resourceId, 'tv_keys' => array_keys($data['tvs'])]);
        return $this->getResourceTvs(['resource_id' => $resourceId]);
    }

    /**
     * Delete a VirtualPage object (vpEvent / vpHandler / vpRoute) by id or name.
     * Mirrors the direct-xPDO style of the other VP methods; xPDO removes composite
     * children (e.g. an event's routes) per the VP schema.
     */
    private function deleteVirtualPageObject($class, $action, array $data) {
        $obj = $this->resolveVirtualPageObject($class, $data);
        $id = (int) $obj->get('id');
        $name = $obj->get('name');
        if (!$obj->remove()) {
            throw new ModxMCPClientException("Could not delete {$class} {$id}.");
        }
        $this->clearVirtualPageCache();
        $this->logAudit($action, 'virtualpage', ['id' => $id, 'name' => $name]);
        return ['deleted' => true, 'id' => $id, 'name' => $name];
    }

    private function listVirtualPageEvents(array $data = []) {
        $this->loadVirtualPagePackage();

        $query = $this->modx->newQuery('vpEvent');
        $this->applyVirtualPageListFilters($query, $data, ['id', 'name', 'active']);
        $query->sortby('rank', 'ASC');
        $query->sortby('id', 'ASC');
        $query->limit($this->getListLimit($data), $this->getListStart($data));

        $items = [];
        foreach ($this->modx->getCollection('vpEvent', $query) as $event) {
            $items[] = $this->normalizeVirtualPageEvent($event, !empty($data['include_routes']));
        }

        return [
            'count' => count($items),
            'events' => $items,
        ];
    }

    private function getVirtualPageEvent(array $data) {
        $event = $this->resolveVirtualPageObject('vpEvent', $data);
        return $this->normalizeVirtualPageEvent($event, true);
    }

    private function createVirtualPageEvent(array $data) {
        $this->loadVirtualPagePackage();
        $payload = $this->prepareVirtualPagePayload($data, ['name', 'description', 'rank', 'active']);
        if (empty($payload['name'])) {
            throw new ModxMCPClientException('name is required for VirtualPage event creation.');
        }
        if ($this->modx->getObject('vpEvent', ['name' => $payload['name']])) {
            throw new ModxMCPClientException("VirtualPage event already exists: {$payload['name']}.");
        }
        if (!array_key_exists('active', $payload)) {
            $payload['active'] = 1;
        }
        if (!array_key_exists('rank', $payload)) {
            $payload['rank'] = $this->modx->getCount('vpEvent');
        }

        $event = $this->modx->newObject('vpEvent');
        $event->fromArray($payload, '', true, true);
        if (!$event->save()) {
            throw new ModxMCPClientException('Could not save VirtualPage event.');
        }

        $this->clearVirtualPageCache();
        $this->logAudit('virtualpage_create_event', 'virtualpage_event', ['id' => $event->get('id'), 'name' => $event->get('name')]);
        return $this->normalizeVirtualPageEvent($event, true);
    }

    private function updateVirtualPageEvent(array $data) {
        $event = $this->resolveVirtualPageObject('vpEvent', $data);
        $payload = $this->prepareVirtualPagePayload($data, ['name', 'description', 'rank', 'active']);
        if (empty($payload)) {
            throw new ModxMCPClientException('No VirtualPage event fields to update.');
        }
        if (!empty($payload['name'])) {
            $duplicate = $this->modx->getObject('vpEvent', ['name' => $payload['name']]);
            if ($duplicate && (int)$duplicate->get('id') !== (int)$event->get('id')) {
                throw new ModxMCPClientException("VirtualPage event already exists: {$payload['name']}.");
            }
        }

        $event->fromArray($payload, '', true, true);
        if (!$event->save()) {
            throw new ModxMCPClientException('Could not update VirtualPage event.');
        }

        $this->ensureVirtualPagePluginEvent($event->get('name'));
        $this->clearVirtualPageCache();
        $this->logAudit('virtualpage_update_event', 'virtualpage_event', ['id' => $event->get('id')]);
        return $this->normalizeVirtualPageEvent($event, true);
    }

    private function listVirtualPageHandlers(array $data = []) {
        $this->loadVirtualPagePackage();

        $query = $this->modx->newQuery('vpHandler');
        $this->applyVirtualPageListFilters($query, $data, ['id', 'name', 'type', 'entry', 'active']);
        $query->sortby('rank', 'ASC');
        $query->sortby('id', 'ASC');
        $query->limit($this->getListLimit($data), $this->getListStart($data));

        $items = [];
        foreach ($this->modx->getCollection('vpHandler', $query) as $handler) {
            $items[] = $this->normalizeVirtualPageHandler($handler, !empty($data['include_routes']));
        }

        return [
            'count' => count($items),
            'handlers' => $items,
        ];
    }

    private function getVirtualPageHandler(array $data) {
        $handler = $this->resolveVirtualPageObject('vpHandler', $data);
        return $this->normalizeVirtualPageHandler($handler, true);
    }

    private function createVirtualPageHandler(array $data) {
        $this->loadVirtualPagePackage();
        $payload = $this->prepareVirtualPagePayload($data, ['name', 'type', 'entry', 'content', 'description', 'cache', 'rank', 'active']);
        if (empty($payload['name'])) {
            throw new ModxMCPClientException('name is required for VirtualPage handler creation.');
        }
        if ($this->modx->getObject('vpHandler', ['name' => $payload['name']])) {
            throw new ModxMCPClientException("VirtualPage handler already exists: {$payload['name']}.");
        }
        if (!array_key_exists('type', $payload)) {
            $payload['type'] = 3;
        }
        $payload['type'] = $this->normalizeVirtualPageHandlerType($payload['type']);
        if (!array_key_exists('entry', $payload)) {
            $payload['entry'] = 0;
        }
        $this->assertVirtualPageHandlerEntry($payload['type'], $payload['entry']);
        if (!array_key_exists('active', $payload)) {
            $payload['active'] = 1;
        }
        if (!array_key_exists('cache', $payload)) {
            $payload['cache'] = 0;
        }
        if (!array_key_exists('rank', $payload)) {
            $payload['rank'] = $this->modx->getCount('vpHandler');
        }

        $handler = $this->modx->newObject('vpHandler');
        $handler->fromArray($payload, '', true, true);
        if (!$handler->save()) {
            throw new ModxMCPClientException('Could not save VirtualPage handler.');
        }

        $this->clearVirtualPageCache();
        $this->logAudit('virtualpage_create_handler', 'virtualpage_handler', ['id' => $handler->get('id'), 'name' => $handler->get('name')]);
        return $this->normalizeVirtualPageHandler($handler, true);
    }

    private function updateVirtualPageHandler(array $data) {
        $handler = $this->resolveVirtualPageObject('vpHandler', $data);
        $payload = $this->prepareVirtualPagePayload($data, ['name', 'type', 'entry', 'content', 'description', 'cache', 'rank', 'active']);
        if (empty($payload)) {
            throw new ModxMCPClientException('No VirtualPage handler fields to update.');
        }
        if (!empty($payload['name'])) {
            $duplicate = $this->modx->getObject('vpHandler', ['name' => $payload['name']]);
            if ($duplicate && (int)$duplicate->get('id') !== (int)$handler->get('id')) {
                throw new ModxMCPClientException("VirtualPage handler already exists: {$payload['name']}.");
            }
        }
        if (array_key_exists('type', $payload)) {
            $payload['type'] = $this->normalizeVirtualPageHandlerType($payload['type']);
        }
        $type = array_key_exists('type', $payload) ? $payload['type'] : (int)$handler->get('type');
        $entry = array_key_exists('entry', $payload) ? $payload['entry'] : $handler->get('entry');
        $this->assertVirtualPageHandlerEntry($type, $entry);

        $handler->fromArray($payload, '', true, true);
        if (!$handler->save()) {
            throw new ModxMCPClientException('Could not update VirtualPage handler.');
        }

        $this->clearVirtualPageCache();
        $this->logAudit('virtualpage_update_handler', 'virtualpage_handler', ['id' => $handler->get('id')]);
        return $this->normalizeVirtualPageHandler($handler, true);
    }

    private function listVirtualPageRoutes(array $data = []) {
        $this->loadVirtualPagePackage();

        $query = $this->modx->newQuery('vpRoute');
        $query->leftJoin('vpEvent', 'Event', 'Event.id = vpRoute.event');
        $query->leftJoin('vpHandler', 'Handler', 'Handler.id = vpRoute.handler');
        $query->select($this->modx->getSelectColumns('vpRoute', 'vpRoute'));
        $query->select([
            'event_name' => 'Event.name',
            'handler_name' => 'Handler.name',
        ]);

        if (!empty($data['event_name'])) {
            $query->where(['Event.name' => (string)$data['event_name']]);
        }
        if (!empty($data['handler_name'])) {
            $query->where(['Handler.name' => (string)$data['handler_name']]);
        }
        $this->applyVirtualPageListFilters($query, $data, ['id', 'route', 'handler', 'event', 'active'], 'vpRoute');
        if (!empty($data['method'])) {
            $query->where(['vpRoute.metod:LIKE' => '%' . $this->normalizeVirtualPageMethod($data['method']) . '%']);
        }
        $query->sortby('vpRoute.rank', 'ASC');
        $query->sortby('vpRoute.id', 'ASC');
        $query->limit($this->getListLimit($data), $this->getListStart($data));

        $items = [];
        if ($query->prepare() && $query->stmt->execute()) {
            foreach ($query->stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = $this->normalizeVirtualPageRouteArray($row);
            }
        }

        return [
            'count' => count($items),
            'routes' => $items,
        ];
    }

    private function getVirtualPageRoute(array $data) {
        $route = $this->resolveVirtualPageObject('vpRoute', $data);
        return $this->normalizeVirtualPageRoute($route);
    }

    private function createVirtualPageRoute(array $data) {
        $this->loadVirtualPagePackage();
        $payload = $this->prepareVirtualPageRoutePayload($data, false);
        $this->assertUniqueVirtualPageRoute($payload['route'], $payload['metod']);
        if (!array_key_exists('rank', $payload)) {
            $payload['rank'] = $this->modx->getCount('vpRoute');
        }

        $route = $this->modx->newObject('vpRoute');
        $route->fromArray($payload, '', true, true);
        if (!$route->save()) {
            throw new ModxMCPClientException('Could not save VirtualPage route.');
        }

        if ($event = $route->getOne('Event')) {
            $this->ensureVirtualPagePluginEvent($event->get('name'));
        }
        $this->clearVirtualPageCache();
        $this->logAudit('virtualpage_create_route', 'virtualpage_route', ['id' => $route->get('id'), 'route' => $route->get('route')]);
        return $this->normalizeVirtualPageRoute($route);
    }

    private function updateVirtualPageRoute(array $data) {
        $route = $this->resolveVirtualPageObject('vpRoute', $data);
        $payload = $this->prepareVirtualPageRoutePayload($data, true);
        if (empty($payload)) {
            throw new ModxMCPClientException('No VirtualPage route fields to update.');
        }

        $routePath = array_key_exists('route', $payload) ? $payload['route'] : $route->get('route');
        $method = array_key_exists('metod', $payload) ? $payload['metod'] : $route->get('metod');
        $this->assertUniqueVirtualPageRoute($routePath, $method, (int)$route->get('id'));

        $route->fromArray($payload, '', true, true);
        if (!$route->save()) {
            throw new ModxMCPClientException('Could not update VirtualPage route.');
        }

        if ($event = $route->getOne('Event')) {
            $this->ensureVirtualPagePluginEvent($event->get('name'));
        }
        $this->clearVirtualPageCache();
        $this->logAudit('virtualpage_update_route', 'virtualpage_route', ['id' => $route->get('id')]);
        return $this->normalizeVirtualPageRoute($route);
    }

    private function resolveVirtualPageRoute(array $data) {
        $this->loadVirtualPagePackage();
        $path = !empty($data['path']) ? (string)$data['path'] : (!empty($data['uri']) ? (string)$data['uri'] : '');
        if ($path === '') {
            throw new ModxMCPClientException('path or uri is required.');
        }
        $path = '/' . trim($path, '/');
        $rawPath = array_key_exists('path', $data) ? (string)$data['path'] : '';
        $rawUri = array_key_exists('uri', $data) ? (string)$data['uri'] : '';
        if (substr($rawPath, -1) === '/' || substr($rawUri, -1) === '/') {
            $path .= '/';
        }
        $method = !empty($data['method']) ? $this->normalizeVirtualPageMethod($data['method']) : 'GET';

        $routes = $this->listVirtualPageRoutes(['active' => 1, 'limit' => 0]);
        foreach ($routes['routes'] as $route) {
            $methods = array_map('trim', explode(',', strtoupper($route['method'])));
            if (!in_array($method, $methods, true)) {
                continue;
            }
            $match = $this->matchVirtualPageRoutePattern($route['route'], $path);
            if ($match === false) {
                continue;
            }
            $properties = is_array($route['properties']) ? $route['properties'] : [];
            $placeholders = array_merge($match, $properties);
            $placeholders['uri'] = $path;

            return [
                'found' => true,
                'path' => $path,
                'method' => $method,
                'route' => $route,
                'placeholders' => $placeholders,
                'placeholder_prefix' => $this->modx->getOption('virtualpage_prefix_placeholder', null, 'vp.'),
            ];
        }

        return [
            'found' => false,
            'path' => $path,
            'method' => $method,
        ];
    }

    private function clearVirtualPageCache() {
        $this->loadVirtualPagePackage();
        $service = $this->loadVirtualPageService(false);
        if ($service && method_exists($service, 'clearCache')) {
            $service->clearCache(['cache_key' => 'event/']);
        }
        if ($this->modx->getCacheManager()) {
            $this->modx->cacheManager->clean(['cache_key' => 'default/virtualpage/']);
            $this->modx->cacheManager->refresh();
        }
        return ['cleared' => true];
    }

    private function loadVirtualPagePackage() {
        $corePath = $this->getVirtualPageCorePath();
        if (!is_dir($corePath)) {
            throw new ModxMCPClientException('Could not find VirtualPage component core path.');
        }
        $this->modx->addPackage('virtualpage', $corePath . 'model/');
        return true;
    }

    private function loadVirtualPageService($required = true) {
        $corePath = $this->getVirtualPageCorePath();
        $service = $this->modx->getService('virtualpage', 'virtualpage', $corePath . 'model/virtualpage/');
        if (!$service && $required) {
            throw new ModxMCPClientException('Could not load VirtualPage service. Is VirtualPage installed on this MODX site?');
        }
        return $service;
    }

    private function getVirtualPageCorePath() {
        return $this->modx->getOption('virtualpage_core_path', null, $this->modx->getOption('core_path') . 'components/virtualpage/');
    }

    private function resolveVirtualPageObject($classKey, array $data) {
        $this->loadVirtualPagePackage();
        if (!empty($data['id'])) {
            $object = $this->modx->getObject($classKey, (int)$data['id']);
        } elseif (!empty($data['name']) && in_array($classKey, ['vpEvent', 'vpHandler'], true)) {
            $object = $this->modx->getObject($classKey, ['name' => (string)$data['name']]);
        } elseif ($classKey === 'vpRoute' && !empty($data['route'])) {
            $criteria = ['route' => (string)$data['route']];
            if (!empty($data['method'])) {
                $criteria['metod'] = $this->normalizeVirtualPageMethod($data['method']);
            } elseif (!empty($data['metod'])) {
                $criteria['metod'] = $this->normalizeVirtualPageMethod($data['metod']);
            }
            $object = $this->modx->getObject($classKey, $criteria);
        } else {
            $object = null;
        }

        if (!$object) {
            throw new ModxMCPClientException("VirtualPage object not found: {$classKey}.");
        }
        return $object;
    }

    private function prepareVirtualPagePayload(array $data, array $allowedFields) {
        $payload = [];
        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (in_array($field, ['id', 'type', 'entry', 'rank', 'active', 'cache'], true)) {
                $value = (int)$value;
            }
            $payload[$field] = $value;
        }
        return $payload;
    }

    private function prepareVirtualPageRoutePayload(array $data, $isUpdate) {
        $payload = $this->prepareVirtualPagePayload($data, ['route', 'handler', 'event', 'description', 'rank', 'active', 'properties']);
        if (array_key_exists('method', $data)) {
            $payload['metod'] = $this->normalizeVirtualPageMethod($data['method']);
        } elseif (array_key_exists('metod', $data)) {
            $payload['metod'] = $this->normalizeVirtualPageMethod($data['metod']);
        }

        if (!empty($data['event_name'])) {
            $event = $this->modx->getObject('vpEvent', ['name' => (string)$data['event_name']]);
            if (!$event) {
                throw new ModxMCPClientException("VirtualPage event not found: {$data['event_name']}.");
            }
            $payload['event'] = (int)$event->get('id');
        }
        if (!empty($data['handler_name'])) {
            $handler = $this->modx->getObject('vpHandler', ['name' => (string)$data['handler_name']]);
            if (!$handler) {
                throw new ModxMCPClientException("VirtualPage handler not found: {$data['handler_name']}.");
            }
            $payload['handler'] = (int)$handler->get('id');
        }
        if (array_key_exists('properties', $payload) && is_string($payload['properties'])) {
            $decoded = json_decode($payload['properties'], true);
            if ($payload['properties'] !== '' && json_last_error() !== JSON_ERROR_NONE) {
                throw new ModxMCPClientException('properties must be a JSON object or an object payload.');
            }
            $payload['properties'] = is_array($decoded) ? $decoded : [];
        }
        if (array_key_exists('properties', $payload) && !is_array($payload['properties'])) {
            throw new ModxMCPClientException('properties must be an object.');
        }
        if (!empty($payload['route'])) {
            $payload['route'] = '/' . trim((string)$payload['route'], '/');
            if (!empty($data['route']) && substr((string)$data['route'], -1) === '/') {
                $payload['route'] .= '/';
            }
        }

        if (!$isUpdate) {
            foreach (['route', 'metod', 'handler', 'event'] as $field) {
                if (empty($payload[$field])) {
                    throw new ModxMCPClientException("{$field} is required for VirtualPage route creation.");
                }
            }
            if (!array_key_exists('active', $payload)) {
                $payload['active'] = 1;
            }
        }

        if (!empty($payload['handler']) && !$this->modx->getObject('vpHandler', (int)$payload['handler'])) {
            throw new ModxMCPClientException("VirtualPage handler not found: {$payload['handler']}.");
        }
        if (!empty($payload['event']) && !$this->modx->getObject('vpEvent', (int)$payload['event'])) {
            throw new ModxMCPClientException("VirtualPage event not found: {$payload['event']}.");
        }

        return $payload;
    }

    private function normalizeVirtualPageEvent(xPDOObject $event, $includeRoutes = false) {
        $result = $event->toArray();
        $result['id'] = (int)$result['id'];
        $result['rank'] = (int)$result['rank'];
        $result['active'] = (int)$result['active'];
        $result['route_count'] = $this->modx->getCount('vpRoute', ['event' => $event->get('id')]);
        if ($includeRoutes) {
            $routes = [];
            foreach ($event->getMany('Routes') as $route) {
                $routes[] = $this->normalizeVirtualPageRoute($route);
            }
            $result['routes'] = $routes;
        }
        return $result;
    }

    private function normalizeVirtualPageHandler(xPDOObject $handler, $includeRoutes = false) {
        $result = $handler->toArray();
        $result['id'] = (int)$result['id'];
        $result['type'] = (int)$result['type'];
        $result['entry'] = (int)$result['entry'];
        $result['rank'] = (int)$result['rank'];
        $result['active'] = (int)$result['active'];
        $result['cache'] = (int)$result['cache'];
        $result['type_name'] = $this->getVirtualPageHandlerTypeName($result['type']);
        $result['route_count'] = $this->modx->getCount('vpRoute', ['handler' => $handler->get('id')]);
        if ($includeRoutes) {
            $routes = [];
            foreach ($handler->getMany('Routes') as $route) {
                $routes[] = $this->normalizeVirtualPageRoute($route);
            }
            $result['routes'] = $routes;
        }
        return $result;
    }

    private function normalizeVirtualPageRoute(xPDOObject $route) {
        $result = $route->toArray();
        $event = $route->getOne('Event');
        $handler = $route->getOne('Handler');
        $result['event_name'] = $event ? $event->get('name') : null;
        $result['handler_name'] = $handler ? $handler->get('name') : null;
        return $this->normalizeVirtualPageRouteArray($result);
    }

    private function normalizeVirtualPageRouteArray(array $row) {
        $properties = isset($row['properties']) ? $row['properties'] : [];
        if (is_string($properties)) {
            $decoded = json_decode($properties, true);
            $properties = is_array($decoded) ? $decoded : [];
        }
        return [
            'id' => (int)$row['id'],
            'method' => isset($row['metod']) ? $row['metod'] : '',
            'metod' => isset($row['metod']) ? $row['metod'] : '',
            'route' => isset($row['route']) ? $row['route'] : '',
            'handler' => isset($row['handler']) ? (int)$row['handler'] : 0,
            'handler_name' => isset($row['handler_name']) ? $row['handler_name'] : null,
            'event' => isset($row['event']) ? (int)$row['event'] : 0,
            'event_name' => isset($row['event_name']) ? $row['event_name'] : null,
            'description' => isset($row['description']) ? $row['description'] : '',
            'rank' => isset($row['rank']) ? (int)$row['rank'] : 0,
            'active' => isset($row['active']) ? (int)$row['active'] : 0,
            'properties' => $properties,
        ];
    }

    private function normalizeVirtualPageMethod($method) {
        $parts = array_map('trim', explode(',', strtoupper((string)$method)));
        $parts = array_filter($parts);
        $allowed = ['GET', 'POST'];
        foreach ($parts as $part) {
            if (!in_array($part, $allowed, true)) {
                throw new ModxMCPClientException('VirtualPage method must be GET, POST, or GET,POST.');
            }
        }
        if (empty($parts)) {
            throw new ModxMCPClientException('VirtualPage method is required.');
        }
        return implode(',', array_values(array_unique($parts)));
    }

    private function normalizeVirtualPageHandlerType($type) {
        if (is_string($type) && !is_numeric($type)) {
            $map = [
                'resource' => 0,
                'snippet' => 1,
                'chunk' => 2,
                'dynamic_resource' => 3,
                'dynamic-resource' => 3,
                'template' => 3,
            ];
            $key = strtolower(trim($type));
            if (!array_key_exists($key, $map)) {
                throw new ModxMCPClientException('VirtualPage handler type must be 0, 1, 2, 3, resource, snippet, chunk, or dynamic_resource.');
            }
            return $map[$key];
        }
        $type = (int)$type;
        if (!in_array($type, [0, 1, 2, 3], true)) {
            throw new ModxMCPClientException('VirtualPage handler type must be one of: 0, 1, 2, 3.');
        }
        return $type;
    }

    private function getVirtualPageHandlerTypeName($type) {
        $map = [
            0 => 'resource_forward',
            1 => 'snippet',
            2 => 'chunk',
            3 => 'dynamic_resource',
        ];
        return isset($map[(int)$type]) ? $map[(int)$type] : 'unknown';
    }

    private function assertVirtualPageHandlerEntry($type, $entry) {
        $entry = (int)$entry;
        if ($entry <= 0) {
            return;
        }
        $map = [
            0 => 'modResource',
            1 => 'modSnippet',
            2 => 'modChunk',
            3 => 'modTemplate',
        ];
        if (!empty($map[$type]) && !$this->modx->getObject($map[$type], $entry)) {
            throw new ModxMCPClientException("VirtualPage handler entry not found for type {$type}: {$entry}.");
        }
    }

    private function assertUniqueVirtualPageRoute($route, $method, $excludeId = 0) {
        $query = $this->modx->newQuery('vpRoute');
        $query->where([
            'route' => $route,
            'metod' => $method,
        ]);
        if ($excludeId > 0) {
            $query->where(['id:!=' => $excludeId]);
        }
        if ($this->modx->getCount('vpRoute', $query) > 0) {
            throw new ModxMCPClientException("VirtualPage route already exists for {$method} {$route}.");
        }
    }

    private function ensureVirtualPagePluginEvent($eventName) {
        $service = $this->loadVirtualPageService(false);
        if ($service && method_exists($service, 'doEvent')) {
            return $service->doEvent('create', $eventName, 'vpEvent', 10);
        }

        $plugin = $this->modx->getObject('modPlugin', ['name' => 'vpEvent']);
        if (!$plugin) {
            return false;
        }
        $event = $this->modx->getObject('modPluginEvent', [
            'pluginid' => $plugin->get('id'),
            'event' => $eventName,
        ]);
        if (!$event) {
            $event = $this->modx->newObject('modPluginEvent');
            $event->set('pluginid', $plugin->get('id'));
            $event->set('event', $eventName);
        }
        $event->set('priority', 10);
        return $event->save();
    }

    private function matchVirtualPageRoutePattern($pattern, $path) {
        $regex = preg_quote($pattern, '#');
        $names = [];
        if (strpos($pattern, '{') !== false) {
            $quoted = '';
            $offset = 0;
            if (preg_match_all('/\{([^}:]+)(?::([^}]+))?\}/', $pattern, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $i => $match) {
                    $quoted .= preg_quote(substr($pattern, $offset, $match[1] - $offset), '#');
                    $names[] = $matches[1][$i][0];
                    $subPattern = isset($matches[2][$i][0]) && $matches[2][$i][0] !== '' ? $matches[2][$i][0] : '[^/]+';
                    $quoted .= '(' . $subPattern . ')';
                    $offset = $match[1] + strlen($match[0]);
                }
                $quoted .= preg_quote(substr($pattern, $offset), '#');
                $regex = $quoted;
            }
        }

        if (!preg_match('#^' . $regex . '$#', $path, $matches)) {
            return false;
        }
        array_shift($matches);

        $result = [];
        foreach ($names as $i => $name) {
            $result[$name] = isset($matches[$i]) ? $matches[$i] : '';
        }
        return $result;
    }

    private function applyVirtualPageListFilters(xPDOQuery $query, array $data, array $fields, $alias = '') {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                continue;
            }
            $column = $alias !== '' ? $alias . '.' . $field : $field;
            $key = in_array($field, ['id', 'active', 'type', 'entry', 'handler', 'event'], true)
                ? $column
                : $column . ':LIKE';
            $value = in_array($field, ['id', 'active', 'type', 'entry', 'handler', 'event'], true)
                ? (int)$data[$field]
                : '%' . (string)$data[$field] . '%';
            $query->where([$key => $value]);
        }
    }

    private function getListLimit(array $data) {
        if (array_key_exists('limit', $data)) {
            $limit = (int)$data['limit'];
            if ($limit === 0) {
                return 0;
            }
            return max(1, min($limit, 500));
        }
        return 100;
    }

    private function getListStart(array $data) {
        return !empty($data['start']) ? max(0, (int)$data['start']) : 0;
    }

    private function listMs2OptionTypes(array $data = []) {
        return $this->runMiniShop2Processor('mgr/settings/option/gettypes', $data);
    }

    private function listMs2Options(array $data = []) {
        $payload = [];
        foreach (['query', 'category', 'modcategory', 'limit', 'start'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }
        if (empty($payload['limit'])) {
            $payload['limit'] = 100;
        }
        return $this->runMiniShop2Processor('mgr/settings/option/getlist', $payload);
    }

    private function getMs2Option(array $data) {
        $id = $this->requirePositiveInt($data, 'id');
        return $this->runMiniShop2Processor('mgr/settings/option/get', ['id' => $id]);
    }

    private function createMs2Option(array $data) {
        $payload = $this->prepareMs2OptionPayload($data, false);
        $result = $this->runMiniShop2Processor('mgr/settings/option/create', $payload);
        $this->modx->cacheManager->refresh();
        $this->logAudit('ms2_create_option', 'ms2_option', ['key' => isset($payload['key']) ? $payload['key'] : null]);
        return $result;
    }

    private function updateMs2Option(array $data) {
        $payload = $this->prepareMs2OptionPayload($data, true);
        $result = $this->runMiniShop2Processor('mgr/settings/option/update', $payload);
        $this->modx->cacheManager->refresh();
        $this->logAudit('ms2_update_option', 'ms2_option', ['id' => $payload['id']]);
        return $result;
    }

    private function assignMs2OptionToCategory(array $data) {
        $payload = [
            'option_id' => $this->requirePositiveInt($data, 'option_id'),
            'category_id' => $this->requirePositiveInt($data, 'category_id'),
        ];
        $result = $this->runMiniShop2Processor('mgr/settings/option/assign', $payload);
        $this->modx->cacheManager->refresh();
        $this->logAudit('ms2_assign_option_to_category', 'ms2_option', $payload);
        return $result;
    }

    private function getMs2ProductOptions(array $data) {
        $productId = $this->requirePositiveInt($data, 'product_id');
        $this->assertMs2Product($productId);
        $this->loadMiniShop2Service();

        $query = $this->modx->newQuery('msProductOption');
        $query->leftJoin('msOption', 'Option', 'Option.key = msProductOption.key');
        $query->where(['msProductOption.product_id' => $productId]);
        $query->sortby('msProductOption.key', 'ASC');
        $query->select($this->modx->getSelectColumns('msProductOption', 'msProductOption'));
        $query->select([
            'caption' => 'Option.caption',
            'type' => 'Option.type',
            'measure_unit' => 'Option.measure_unit',
        ]);

        $rows = [];
        if ($query->prepare() && $query->stmt->execute()) {
            $rows = $query->stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'product_id' => $productId,
            'options' => $rows,
        ];
    }

    private function updateMs2ProductOptions(array $data) {
        $productId = $this->requirePositiveInt($data, 'product_id');
        $this->assertMs2Product($productId);

        if (empty($data['options']) || !is_array($data['options'])) {
            throw new ModxMCPClientException('options payload must be a non-empty object.');
        }

        $this->loadMiniShop2Service();
        $changed = [];

        return $this->runWithTransaction(function () use ($productId, $data, &$changed) {
            foreach ($data['options'] as $key => $value) {
                $key = trim((string)$key);
                if ($key === '') {
                    continue;
                }

                if (!$this->modx->getObject('msOption', ['key' => $key])) {
                    throw new ModxMCPClientException("miniShop2 option not found: {$key}.");
                }

                if ($value === null) {
                    $this->modx->removeCollection('msProductOption', [
                        'product_id' => $productId,
                        'key' => $key,
                    ]);
                    $changed[$key] = null;
                    continue;
                }

                if (is_array($value)) {
                    $value = implode('||', array_map('strval', $value));
                } elseif (is_bool($value)) {
                    $value = $value ? '1' : '0';
                } else {
                    $value = (string)$value;
                }

                $this->modx->removeCollection('msProductOption', [
                    'product_id' => $productId,
                    'key' => $key,
                ]);

                $option = $this->modx->newObject('msProductOption');
                $option->set('product_id', $productId);
                $option->set('key', $key);
                $option->set('value', $value);
                if (!$option->save()) {
                    throw new ModxMCPClientException("Could not save product option: {$key}.");
                }
                $changed[$key] = $value;
            }

            $this->modx->cacheManager->refresh();
            $this->logAudit('ms2_update_product_options', 'ms2_product_option', [
                'product_id' => $productId,
                'keys' => array_keys($changed),
            ]);

            return $this->getMs2ProductOptions(['product_id' => $productId]);
        });
    }

    private function assertMs2Product($productId) {
        $product = $this->modx->getObject('modResource', $productId);
        if (!$product) {
            throw new ModxMCPClientException("Product resource not found: {$productId}.");
        }
        if ($product->get('class_key') !== 'msProduct') {
            throw new ModxMCPClientException("Resource {$productId} is not an msProduct.");
        }
    }

    private function prepareMs2OptionPayload(array $data, $requireId) {
        $payload = [];
        if ($requireId) {
            $payload['id'] = $this->requirePositiveInt($data, 'id');
        }

        foreach (['key', 'caption', 'description', 'measure_unit', 'category', 'type'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (!$requireId) {
            foreach (['key', 'caption', 'type'] as $field) {
                if (!isset($payload[$field]) || $payload[$field] === '') {
                    throw new ModxMCPClientException("{$field} is required for miniShop2 option creation.");
                }
            }
            if (!array_key_exists('category', $payload)) {
                $payload['category'] = 0;
            }
        }

        if (array_key_exists('properties', $data)) {
            $payload['properties'] = is_array($data['properties'])
                ? json_encode($data['properties'], JSON_UNESCAPED_UNICODE)
                : $data['properties'];
        }

        if (!empty($data['category_ids']) && is_array($data['category_ids'])) {
            $categories = [];
            foreach ($data['category_ids'] as $categoryId) {
                $categoryId = (int)$categoryId;
                if ($categoryId > 0) {
                    $categories[$categoryId] = true;
                }
            }
            if (!empty($categories)) {
                $payload['categories'] = json_encode($categories);
            }
        }

        return $payload;
    }

    private function runMiniShop2Processor($action, array $payload = []) {
        $this->loadMiniShop2Service();
        $response = $this->modx->runProcessor($action, $payload, [
            'processors_path' => $this->getMiniShop2CorePath() . 'processors/',
        ]);

        if (!$response || $response->isError()) {
            throw new ModxMCPClientException('miniShop2 processor failed: ' . ($response ? $this->formatProcessorErrors($response) : 'No processor response.'));
        }

        return $this->normalizeProcessorResponse($response);
    }

    private function loadMiniShop2Service() {
        $corePath = $this->getMiniShop2CorePath();
        $service = $this->modx->getService('miniShop2', 'miniShop2', $corePath . 'model/minishop2/');
        if (!$service) {
            throw new ModxMCPClientException('Could not load miniShop2 service. Is miniShop2 installed on this MODX site?');
        }
        $service->initialize($this->modx->context ? $this->modx->context->get('key') : 'mgr');
        return $service;
    }

    private function getMiniShop2CorePath() {
        return $this->modx->getOption('minishop2.core_path', null, $this->modx->getOption('core_path') . 'components/minishop2/');
    }

    private function normalizeProcessorResponse($response) {
        $raw = $response->getResponse();
        if (is_array($raw)) { return $this->unwrapProcessorPayload($raw); }
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            return $this->unwrapProcessorPayload($decoded);
        }
        $object = $response->getObject();
        if (!empty($object)) {
            return $object;
        }
        return ['success' => true];
    }

    /**
     * Processor responses are wrapped as {success, message, total, errors, object}. The client
     * only needs the object, so strip the envelope to save tokens.
     */
    private function unwrapProcessorPayload($decoded) {
        if (is_array($decoded) && array_key_exists('object', $decoded) && array_key_exists('success', $decoded)) {
            $obj = $decoded['object'];
            if (!empty($obj)) { return $obj; }
            return array('success' => !empty($decoded['success']));
        }
        return $decoded;
    }

    /**
     * Drop never-useful / sensitive columns from list rows (e.g. user password hashes) to keep
     * responses small.
     */
    private function stripNoiseFields($rows) {
        if (!is_array($rows)) { return $rows; }
        $noise = array('password', 'cachepwd', 'salt', 'hash_class', 'remote_data', 'remote_key', 'session_stale', 'sudo');
        foreach ($rows as &$row) {
            if (is_array($row)) {
                foreach ($noise as $k) { unset($row[$k]); }
            }
        }
        unset($row);
        return $rows;
    }

    private function listVersionXVersions(array $data) {
        $meta = $this->resolveVersionXType($data);
        $contentId = $this->requirePositiveInt($data, 'content_id');
        $limit = !empty($data['limit']) ? max(1, min((int)$data['limit'], 100)) : 20;

        $this->loadVersionXService();

        $query = $this->modx->newQuery($meta['class']);
        $query->where(['content_id' => $contentId]);
        $query->sortby('saved', 'DESC');
        $query->sortby('version_id', 'DESC');
        $query->limit($limit);

        $versions = $this->modx->getCollection($meta['class'], $query);
        $items = [];
        foreach ($versions as $version) {
            $items[] = $this->normalizeVersionXVersion($version, $meta, false);
        }

        return [
            'type' => $data['type'],
            'content_id' => $contentId,
            'count' => count($items),
            'versions' => $items,
        ];
    }

    private function getVersionXVersion(array $data) {
        $meta = $this->resolveVersionXType($data);
        $contentId = $this->requirePositiveInt($data, 'content_id');
        $versionId = $this->requirePositiveInt($data, 'version_id');

        $this->loadVersionXService();
        $version = $this->modx->getObject($meta['class'], [
            'content_id' => $contentId,
            'version_id' => $versionId,
        ]);
        if (!$version) {
            throw new ModxMCPClientException("VersionX version not found: {$data['type']} content_id={$contentId} version_id={$versionId}.");
        }

        return $this->normalizeVersionXVersion($version, $meta, true);
    }

    private function revertVersionXVersion(array $data) {
        $meta = $this->resolveVersionXType($data);
        $contentId = $this->requirePositiveInt($data, 'content_id');
        $versionId = $this->requirePositiveInt($data, 'version_id');

        if (empty($data['confirm'])) {
            throw new ModxMCPClientException('confirm=true is required to revert a VersionX version.');
        }

        $this->loadVersionXService();

        $before = $this->modx->getObject($meta['content_class'], $contentId);
        $version = $this->modx->getObject($meta['class'], [
            'content_id' => $contentId,
            'version_id' => $versionId,
        ]);
        if (!$version) {
            throw new ModxMCPClientException("VersionX version not found: {$data['type']} content_id={$contentId} version_id={$versionId}.");
        }

        $processorPath = $this->getVersionXCorePath() . 'processors/mgr/';
        $response = $this->modx->runProcessor($meta['processor'] . '/revert', [
            'content_id' => $contentId,
            'version_id' => $versionId,
        ], [
            'processors_path' => $processorPath,
        ]);

        if (!$response || $response->isError()) {
            throw new ModxMCPClientException('VersionX revert failed: ' . ($response ? $this->formatProcessorErrors($response) : 'No processor response.'));
        }

        $after = $this->modx->getObject($meta['content_class'], $contentId);
        $this->logAudit('versionx_revert_version', $data['type'], [
            'content_id' => $contentId,
            'version_id' => $versionId,
        ]);

        return [
            'reverted' => true,
            'type' => $data['type'],
            'content_id' => $contentId,
            'version_id' => $versionId,
            'version' => $this->normalizeVersionXVersion($version, $meta, false),
            'before_exists' => (bool)$before,
            'after_exists' => (bool)$after,
            'after' => $after ? [
                'id' => $after->get('id'),
                'class_key' => $after->get('class_key'),
                'name' => $this->getLiveObjectLabel($after),
            ] : null,
        ];
    }

    private function resolveVersionXType(array $data) {
        $type = !empty($data['type']) ? strtolower((string)$data['type']) : '';
        if (empty($this->versionXTypes[$type])) {
            throw new ModxMCPClientException('VersionX type must be one of: ' . implode(', ', array_keys($this->versionXTypes)) . '.');
        }
        return $this->versionXTypes[$type];
    }

    private function requirePositiveInt(array $data, $key) {
        $value = !empty($data[$key]) ? (int)$data[$key] : 0;
        if ($value <= 0) {
            throw new ModxMCPClientException("{$key} is required and must be a positive integer.");
        }
        return $value;
    }

    private function loadVersionXService() {
        $corePath = $this->getVersionXCorePath();
        $service = $this->modx->getService('versionx', 'VersionX', $corePath . 'model/');
        if (!$service) {
            throw new ModxMCPClientException('Could not load VersionX service. Is VersionX installed on this MODX site?');
        }
        return $service;
    }

    private function getVersionXCorePath() {
        return $this->modx->getOption('versionx.core_path', null, $this->modx->getOption('core_path') . 'components/versionx/');
    }

    private function normalizeVersionXVersion(xPDOObject $version, array $meta, $includePayload = false) {
        $labelField = $meta['label'];
        $result = [
            'version_id' => (int)$version->get('version_id'),
            'content_id' => (int)$version->get('content_id'),
            'saved' => $version->get('saved'),
            'user' => (int)$version->get('user'),
            'mode' => $version->get('mode'),
            'marked' => (bool)$version->get('marked'),
            'label' => $version->get($labelField),
        ];

        if ($version->get('class') !== null) {
            $result['class'] = $version->get('class');
        }
        if ($version->get('context_key') !== null) {
            $result['context_key'] = $version->get('context_key');
        }

        $content = $this->getVersionXContent($version);
        if ($content !== null) {
            $result['content_length'] = strlen((string)$content);
            $result['content_preview'] = function_exists('mb_substr')
                ? mb_substr((string)$content, 0, 300, 'UTF-8')
                : substr((string)$content, 0, 300);
        }

        if ($includePayload) {
            $result['data'] = $version->toArray();
        }

        return $result;
    }

    private function getVersionXContent(xPDOObject $version) {
        foreach (['content', 'snippet', 'plugincode'] as $field) {
            $value = $version->get($field);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function getLiveObjectLabel(xPDOObject $object) {
        foreach (['pagetitle', 'name', 'templatename', 'caption'] as $field) {
            $value = $object->get($field);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return '';
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

    private function resolveMediaSource(array $data) {
        if (!empty($data['id'])) {
            return $this->modx->getObject('sources.modMediaSource', (int)$data['id']);
        }
        if (!empty($data['name'])) {
            return $this->modx->getObject('sources.modMediaSource', ['name' => $data['name']]);
        }
        return null;
    }

    private function normalizeMediaSource($source, $includeProperties = true) {
        $result = [
            'id' => $source->get('id'),
            'name' => $source->get('name'),
            'class_key' => $source->get('class_key'),
            'description' => $source->get('description'),
        ];

        if ($includeProperties) {
            $result['properties'] = $this->normalizeMediaSourceProperties($source->get('properties'));
            try {
                $result['root_path'] = $this->getMediaSourceRootPath($source);
            } catch (Exception $e) {
                $result['root_path'] = null;
                $result['root_path_error'] = $e->getMessage();
            }
        }

        return $result;
    }

    private function getMediaSourceRootPath($source) {
        $basePath = $this->extractMediaSourcePropertyValue($source->get('properties'), 'basePath');

        if (($basePath === '' || $basePath === null) && method_exists($source, 'initialize')) {
            try {
                $source->initialize();
            } catch (Exception $e) {
                // Ignore initialization failures and continue with fallback resolution.
            }
        }

        if (($basePath === '' || $basePath === null) && method_exists($source, 'getProperty')) {
            $basePath = $source->getProperty('basePath');
        }

        if (($basePath === '' || $basePath === null) && method_exists($source, 'getBasePath')) {
            $basePath = $source->getBasePath();
        }

        if (($basePath === '' || $basePath === null) && method_exists($source, 'getBases')) {
            $bases = $source->getBases('');
            if (is_array($bases) && !empty($bases['path'])) {
                $basePath = $bases['path'];
            }
        }

        if (($basePath === '' || $basePath === null) && $this->isFilesystemMediaSource($source)) {
            $basePath = $this->modx->getOption('assets_path');
        }

        if ($basePath === '' || $basePath === null) {
            throw new ModxMCPClientException("Media source {$source->get('id')} does not define basePath.");
        }

        $resolved = $this->resolveModxPathPlaceholders((string)$basePath);
        if ($resolved === '' && $this->isFilesystemMediaSource($source)) {
            $resolved = (string)$this->modx->getOption('assets_path');
        }

        if (!$this->isAbsolutePath($resolved)) {
            $resolved = rtrim($this->modx->getOption('base_path'), '/\\') . DIRECTORY_SEPARATOR . ltrim($resolved, '/\\');
        }

        return $this->normalizeFilesystemPath($resolved);
    }

    private function normalizeRelativePath($path) {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new ModxMCPClientException('Path traversal is not allowed.');
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function joinMediaSourcePath($rootPath, $relativePath) {
        $absolutePath = $rootPath;
        if ($relativePath !== '') {
            $absolutePath .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        }

        $rootReal = realpath($rootPath);
        if ($rootReal === false) {
            $rootReal = $this->normalizeFilesystemPath($rootPath);
        }

        $targetDir = file_exists($absolutePath)
            ? realpath($absolutePath)
            : realpath(dirname($absolutePath));
        if ($targetDir === false) {
            $targetDir = $this->normalizeFilesystemPath(dirname($absolutePath));
        }

        if (!$this->pathStartsWith($targetDir, $rootReal)) {
            throw new ModxMCPClientException('Resolved path is outside of media source root.');
        }

        return $this->normalizeFilesystemPath($absolutePath);
    }

    private function isAbsolutePath($path) {
        return preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) === 1;
    }

    private function normalizeMediaSourceProperties($properties) {
        if (!is_array($properties)) {
            return $properties;
        }

        $normalized = [];
        foreach ($properties as $key => $value) {
            if (is_array($value) && array_key_exists('value', $value) && count($value) === 1) {
                $normalized[$key] = $value['value'];
                continue;
            }

            $normalized[$key] = is_array($value)
                ? $this->normalizeMediaSourceProperties($value)
                : $value;
        }

        return $normalized;
    }

    private function extractMediaSourcePropertyValue($properties, $key) {
        if (!is_array($properties) || !array_key_exists($key, $properties)) {
            return '';
        }

        $value = $properties[$key];
        if (is_array($value) && array_key_exists('value', $value)) {
            return $value['value'];
        }

        return $value;
    }

    private function resolveModxPathPlaceholders($path) {
        $replacements = [
            '{base_path}' => $this->modx->getOption('base_path'),
            '{core_path}' => $this->modx->getOption('core_path'),
            '{assets_path}' => $this->modx->getOption('assets_path'),
            '[[++base_path]]' => $this->modx->getOption('base_path'),
            '[[++core_path]]' => $this->modx->getOption('core_path'),
            '[[++assets_path]]' => $this->modx->getOption('assets_path'),
        ];

        return strtr((string)$path, $replacements);
    }

    private function normalizeFilesystemPath($path) {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$path), DIRECTORY_SEPARATOR);
    }

    private function pathStartsWith($path, $rootPath) {
        $path = $this->normalizeFilesystemPath($path);
        $rootPath = $this->normalizeFilesystemPath($rootPath);

        if ($path === $rootPath) {
            return true;
        }

        return strpos($path, $rootPath . DIRECTORY_SEPARATOR) === 0;
    }

    private function isFilesystemMediaSource($source) {
        $classKey = (string)$source->get('class_key');
        $name = (string)$source->get('name');

        return stripos($classKey, 'File') !== false || strcasecmp($name, 'Filesystem') === 0;
    }

    private function assertMediaSourceReadAllowed($source) {
        if ($this->isFilesystemMediaSource($source)) {
            $allow = (bool)$this->modx->getOption('modxmcp.allow_root_filesystem_read', null, false);
            if (!$allow) {
                throw new ModxMCPClientException('Filesystem media source browsing is disabled by modxmcp.allow_root_filesystem_read. Use component read tools for installed package code.');
            }
        }
    }

    private function getComponentCodeRoots() {
        $configuredRoots = (string)$this->modx->getOption(
            'modxmcp.component_code_roots',
            null,
            'core/components,assets/components'
        );

        $scopePaths = [];
        foreach (explode(',', $configuredRoots) as $configuredRoot) {
            $configuredRoot = trim($configuredRoot);
            if ($configuredRoot === '') {
                continue;
            }

            $resolved = $this->resolveModxPathPlaceholders($configuredRoot);
            if (!$this->isAbsolutePath($resolved)) {
                $resolved = rtrim($this->modx->getOption('base_path'), '/\\') . DIRECTORY_SEPARATOR . ltrim($resolved, '/\\');
            }

            $normalized = $this->normalizeFilesystemPath($resolved);
            $scope = stripos(str_replace('\\', '/', $configuredRoot), 'assets/components') !== false ? 'assets' : 'core';
            $scopePaths[$scope] = $normalized;
        }

        return $scopePaths;
    }

    private function resolveComponentScopes($scope = null) {
        if ($scope === null || $scope === '' || $scope === 'all') {
            return ['core', 'assets'];
        }

        if (!in_array($scope, ['core', 'assets'], true)) {
            throw new ModxMCPClientException('Component scope must be one of: core, assets, all.');
        }

        return [$scope];
    }

    private function getComponentRootPath($componentName, $scope) {
        $roots = $this->getComponentCodeRoots();
        if (empty($roots[$scope])) {
            throw new ModxMCPClientException("Component code root is not configured for scope: {$scope}.");
        }

        $safeName = $this->normalizeRelativePath($componentName);
        if (strpos($safeName, '/') !== false) {
            throw new ModxMCPClientException('Component name must be a single directory name.');
        }

        return $this->joinMediaSourcePath($roots[$scope], $safeName);
    }
}
