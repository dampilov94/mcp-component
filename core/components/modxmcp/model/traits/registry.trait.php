<?php
/** registry operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPRegistryTrait {
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
                'dependency_graph' => 'dependencyGraph',
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
                'get_request_status' => 'getRequestStatus',
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
}
