<?php
if (!class_exists("ModxMCPClientException")) {
    /** Expected/validation error whose message is safe to return to the client. */
    class ModxMCPClientException extends Exception {}
}
class modxMCP {
    public $modx;
    public $config =[];
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

        switch ($action) {
            case 'list_system_settings':
                return $this->listSystemSettings($data);
            case 'get_system_setting':
                return $this->getSystemSetting($data);
            case 'create_system_setting':
                return $this->createSystemSetting($data);
            case 'update_system_setting':
                return $this->updateSystemSetting($data);
            case 'delete_system_setting':
                return $this->deleteSystemSetting($data);
            case 'list_media_sources':
                return $this->listMediaSources();
            case 'get_media_source':
                return $this->getMediaSource($data);
            case 'list_media_source_files':
                return $this->listMediaSourceFiles($data);
            case 'read_media_source_file':
                return $this->readMediaSourceFile($data);
            case 'list_installed_components':
                return $this->listInstalledComponents();
            case 'get_component_files':
                return $this->getComponentFiles($data);
            case 'read_component_file':
                return $this->readComponentFile($data);
            case 'get_resource_tvs':
                return $this->getResourceTvs($data);
            case 'update_resource_tvs':
                return $this->updateResourceTvs($data);
            case 'versionx_list_versions':
                return $this->listVersionXVersions($data);
            case 'versionx_get_version':
                return $this->getVersionXVersion($data);
            case 'versionx_revert_version':
                return $this->revertVersionXVersion($data);
            case 'ms2_list_option_types':
                return $this->listMs2OptionTypes($data);
            case 'ms2_list_options':
                return $this->listMs2Options($data);
            case 'ms2_get_option':
                return $this->getMs2Option($data);
            case 'ms2_create_option':
                return $this->createMs2Option($data);
            case 'ms2_update_option':
                return $this->updateMs2Option($data);
            case 'ms2_assign_option_to_category':
                return $this->assignMs2OptionToCategory($data);
            case 'ms2_get_product_options':
                return $this->getMs2ProductOptions($data);
            case 'ms2_update_product_options':
                return $this->updateMs2ProductOptions($data);
            case 'virtualpage_list_events':
                return $this->listVirtualPageEvents($data);
            case 'virtualpage_get_event':
                return $this->getVirtualPageEvent($data);
            case 'virtualpage_create_event':
                return $this->createVirtualPageEvent($data);
            case 'virtualpage_update_event':
                return $this->updateVirtualPageEvent($data);
            case 'virtualpage_list_handlers':
                return $this->listVirtualPageHandlers($data);
            case 'virtualpage_get_handler':
                return $this->getVirtualPageHandler($data);
            case 'virtualpage_create_handler':
                return $this->createVirtualPageHandler($data);
            case 'virtualpage_update_handler':
                return $this->updateVirtualPageHandler($data);
            case 'virtualpage_list_routes':
                return $this->listVirtualPageRoutes($data);
            case 'virtualpage_get_route':
                return $this->getVirtualPageRoute($data);
            case 'virtualpage_create_route':
                return $this->createVirtualPageRoute($data);
            case 'virtualpage_update_route':
                return $this->updateVirtualPageRoute($data);
            case 'virtualpage_resolve_route':
                return $this->resolveVirtualPageRoute($data);
            case 'virtualpage_delete_event':
                return $this->deleteVirtualPageObject('vpEvent', 'virtualpage_delete_event', $data);
            case 'virtualpage_delete_handler':
                return $this->deleteVirtualPageObject('vpHandler', 'virtualpage_delete_handler', $data);
            case 'virtualpage_delete_route':
                return $this->deleteVirtualPageObject('vpRoute', 'virtualpage_delete_route', $data);
            case 'virtualpage_clear_cache':
                return $this->clearVirtualPageCache();
            case 'search_code':
                return $this->searchCode($data);
            case 'list_resources':
                return $this->listResources($data);
            case 'find_usages':
                return $this->findUsages($data);
            case 'make_static':
                return $this->makeStatic($data);
            case 'regenerate_token':
                return $this->regenerateToken();
            case 'list_tv_input_types':
                return $this->listTvInputTypes();
            case 'check_integrations':
                return $this->getIntegrationsReport();
            case 'install_package':
                return $this->installPackage($data);
            case 'ms2_list_link_types':
            case 'ms2_get_link_type':
            case 'ms2_create_link_type':
            case 'ms2_update_link_type':
            case 'ms2_delete_link_type':
            case 'ms2_list_product_links':
            case 'ms2_create_product_link':
            case 'ms2_delete_product_link':
                return $this->ms2LinkAction($action, $data);
            case 'migx_list_configs':
                return $this->listMigxConfigs($data);
            case 'migx_get_config':
                return $this->getMigxConfig($data);
            case 'migx_create_config':
                return $this->saveMigxConfig($data, true);
            case 'migx_update_config':
                return $this->saveMigxConfig($data, false);
            case 'migx_delete_config':
                return $this->deleteMigxConfig($data);
            case 'ms2_list_categories':
            case 'ms2_create_category':
            case 'ms2_update_category':
            case 'ms2_list_orders':
            case 'ms2_get_order':
            case 'ms2_update_order':
                return $this->ms2LinkAction($action, $data);
            case 'list_actions':
                return $this->listSupportedActions();
            case 'run_processor':
                return $this->runProcessorPassthrough($data);
            case 'clear_cache':
                return $this->clearCacheAction($data);
            case 'read_audit_log':
                return $this->readAuditLog($data);
            case 'list_property_sets':
                return $this->listPropertySets($data);
            case 'get_property_set':
                return $this->getPropertySet($data);
            case 'create_property_set':
                return $this->savePropertySet($data, true);
            case 'update_property_set':
                return $this->savePropertySet($data, false);
            case 'delete_property_set':
                return $this->deletePropertySet($data);
            case 'assign_property_set':
                return $this->assignPropertySet($data);
            case 'unassign_property_set':
                return $this->unassignPropertySet($data);
        }

        if (array_key_exists($action, $this->aclActionMap())) {
            return $this->runAclAction($action, $data);
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
                $listOptions = array('limit' => isset($data['limit']) ? max(0, (int) $data['limit']) : 0);
                if (!empty($data['query'])) { $listOptions['query'] = (string) $data['query']; }
                if (!empty($data['start'])) { $listOptions['start'] = (int) $data['start']; }
                $response = $this->modx->runProcessor($basePath . 'getlist', $listOptions);
                if ($response->isError()) throw new ModxMCPClientException($this->formatProcessorErrors($response));
                
                $results = json_decode($response->getResponse(), true);
                $list =[];
                foreach ($results['results'] as $el) {
                    $list[] =[
                        'id' => $el['id'],
                        'name' => isset($el[$nameField]) ? $el[$nameField] : (isset($el['name']) ? $el['name'] : 'Unknown')
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
        if ($pos !== false) {
            $start = max(0, $pos - 60);
            $snippet = substr($content, $start, strlen($query) + 120);
            $snippet = trim(preg_replace('/\s+/', ' ', $snippet));
            if ($start > 0) { $snippet = '…' . $snippet; }
        }
        return array(
            'type' => $type,
            'id' => (int) $object->get('id'),
            'name' => $name,
            'matched_field' => $field,
            'static' => $type !== 'resource' ? (bool) $object->get('static') : false,
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
        $core = array(
            'text' => 'Text', 'textarea' => 'Textarea', 'textareamini' => 'Textarea (Mini)',
            'rawtext' => 'Text (No Filters)', 'rawtextarea' => 'Textarea (No Filters)',
            'richtext' => 'RichText', 'date' => 'Date', 'number' => 'Number',
            'email' => 'Email', 'url' => 'URL', 'hidden' => 'Hidden',
            'checkbox' => 'Checkbox', 'listbox' => 'Listbox (Single-Select)',
            'listbox-multiple' => 'Listbox (Multi-Select)', 'radio' => 'Radio Options',
            'image' => 'Image', 'file' => 'File', 'resourcelist' => 'Resource List',
            'tag' => 'Tag', 'autotag' => 'Auto-Tag',
        );
        $custom = array();
        $results = $this->modx->invokeEvent('OnTVInputRenderList');
        if (is_array($results)) {
            foreach ($results as $res) {
                if (is_array($res)) { $res = implode("\n", $res); }
                foreach (preg_split('/\r\n|\r|\n/', (string) $res) as $line) {
                    $line = trim($line);
                    if ($line === '') { continue; }
                    if (strpos($line, '==') !== false) {
                        list($k, $lbl) = explode('==', $line, 2);
                        $custom[trim($k)] = trim($lbl);
                    } else {
                        $custom[$line] = $line;
                    }
                }
            }
        }
        return array('core' => $core, 'custom' => $custom);
    }

    /**
     * Catalogue of popular MODX add-ons modxMCP is aware of. 'ns' = namespace key,
     * optional 'snippet' = a snippet name to fall back on when no namespace is registered.
     */
    private function knownIntegrations() {
        return array(
            array('ns' => 'minishop2', 'label' => 'miniShop2',  'note' => 'Deep: ms2 option tools + msProduct fields. Orders/deliveries/payments not exposed.'),
            array('ns' => 'migx',      'label' => 'MIGX',       'note' => 'Can create MIGX TVs (type=migx); the MIGX config (custom table) has no dedicated tool yet.'),
            array('ns' => 'pdotools',  'label' => 'pdoTools',   'note' => 'Full: Fenom / pdo* snippets edited as chunks/snippets/templates.'),
            array('ns' => 'tickets',   'label' => 'Tickets',    'note' => 'Content as resources (set class_key) + settings; no dedicated tool.'),
            array('ns' => 'collections','label' => 'Collections','note' => 'Collection containers manageable as resources; no dedicated tool.'),
            array('ns' => 'formit',    'label' => 'FormIt',     'note' => 'Full: form snippet + hooks configured via chunks/snippets.'),
            array('ns' => 'msearch2',  'label' => 'mSearch2',   'note' => 'Edit snippet calls + settings; the search index rebuild is not exposed.'),
            array('ns' => 'mfilter2',  'label' => 'mFilter2',   'note' => 'Edit mFilter2 calls via chunks/snippets + TVs/settings.'),
            array('ns' => 'seosuite',  'label' => 'SEO Suite',  'note' => 'Resource SEO TVs/fields; redirect/meta tables have no dedicated tool.'),
            array('ns' => 'versionx',  'label' => 'VersionX',   'note' => 'Deep: element/resource history + rollback (versionx_* tools).'),
            array('ns' => 'office',    'label' => 'Office',     'note' => 'Frontend account snippets/chunks/settings (miniShop2 ecosystem).'),
            array('ns' => 'login',     'label' => 'Login',      'note' => 'Frontend login/registration snippets + chunks.'),
            array('ns' => 'getresources', 'label' => 'getResources', 'note' => 'Full: resource-listing snippet edited as elements.', 'snippet' => 'getResources'),
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
     * Install a transport package from a provider (default modx.com) by name.
     * Gated behind modxmcp.allow_package_install (off by default) because it pulls
     * code from the network and runs the package installer.
     */
    private function installPackage($data) {
        if (!$this->modx->getOption('modxmcp.allow_package_install', null, false)) {
            throw new ModxMCPClientException('install_package is disabled. Set modxmcp.allow_package_install = Yes to enable it.');
        }
        $name = isset($data['package']) ? trim((string) $data['package']) : '';
        if ($name === '') { throw new ModxMCPClientException('install_package: "package" (name) is required.'); }

        $providerId = isset($data['provider']) ? (int) $data['provider'] : 0;
        if (!$providerId) {
            $c = $this->modx->newQuery('transport.modTransportProvider');
            $c->where(array('name:=' => 'modx.com', 'OR:name:=' => 'modxcms.com'));
            $provider = $this->modx->getObject('transport.modTransportProvider', $c);
            if (!$provider) { $provider = $this->modx->getObject('transport.modTransportProvider', array('id:>' => 0)); }
            if (!$provider) { throw new ModxMCPClientException('install_package: no transport provider is configured.'); }
            $providerId = (int) $provider->get('id');
        }

        $existing = $this->modx->getObject('transport.modTransportPackage', array('package_name' => $name, 'installed:!=' => null));
        if ($existing) {
            return array('status' => 'already_installed', 'package' => $name, 'signature' => $existing->get('signature'));
        }

        $listResp = $this->modx->runProcessor('workspace/packages/rest/getlist', array('provider' => $providerId, 'query' => $name, 'limit' => 20));
        if ($listResp->isError()) { throw new ModxMCPClientException('install_package: provider search failed: ' . $this->formatProcessorErrors($listResp)); }
        $listData = json_decode($listResp->getResponse(), true);
        $rows = isset($listData['results']) ? $listData['results'] : array();
        if (empty($rows)) { throw new ModxMCPClientException("install_package: no package named '{$name}' found on the provider."); }

        $chosen = null;
        foreach ($rows as $row) {
            if (isset($row['name']) && strcasecmp($row['name'], $name) === 0) { $chosen = $row; break; }
        }
        if (!$chosen) { $chosen = $rows[0]; }
        if (empty($chosen['location']) || empty($chosen['signature'])) {
            throw new ModxMCPClientException('install_package: provider result is missing location/signature.');
        }

        $dlResp = $this->modx->runProcessor('workspace/packages/rest/download', array(
            'info'     => $chosen['location'] . '::' . $chosen['signature'],
            'provider' => $providerId,
        ));
        if ($dlResp->isError()) { throw new ModxMCPClientException('install_package: download failed: ' . $this->formatProcessorErrors($dlResp)); }
        $dlObj = $dlResp->getObject();
        $signature = (is_array($dlObj) && !empty($dlObj['signature'])) ? $dlObj['signature'] : $chosen['signature'];

        $instResp = $this->modx->runProcessor('workspace/packages/install', array('signature' => $signature));
        if ($instResp->isError()) { throw new ModxMCPClientException('install_package: install failed: ' . $this->formatProcessorErrors($instResp)); }

        if ($this->modx->getCacheManager()) { $this->modx->getCacheManager()->refresh(); }
        $this->logAudit('install_package', 'system', array('package' => $name, 'signature' => $signature));
        return array(
            'status'    => 'installed',
            'package'   => isset($chosen['name']) ? $chosen['name'] : $name,
            'signature' => $signature,
            'version'   => isset($chosen['version']) ? $chosen['version'] : null,
        );
    }

    /**
     * Generate a fresh modxmcp.api_token, save it, and return it once.
     */
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
                'xtype'     => 'text-password',
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
     * Map of Access-Control actions to the core MODX security processor that backs them.
     * 'list' => true means the processor returns a getlist {total,results} payload.
     */
    private function aclActionMap() {
        return array(
            // --- user groups (modUserGroup) ---
            'list_user_groups'        => array('processor' => 'security/group/getlist', 'list' => true),
            'get_user_group'          => array('processor' => 'security/group/get'),
            'create_user_group'       => array('processor' => 'security/group/create'),
            'update_user_group'       => array('processor' => 'security/group/update'),
            'delete_user_group'       => array('processor' => 'security/group/remove'),
            // --- user group membership (modUserGroupMember) ---
            'list_user_group_members' => array('processor' => 'security/group/user/getlist', 'list' => true),
            'add_user_to_group'       => array('processor' => 'security/group/user/create'),
            'update_group_member'     => array('processor' => 'security/group/user/update'),
            'remove_user_from_group'  => array('processor' => 'security/group/user/remove'),
            // --- users (modUser) ---
            'list_users'   => array('processor' => 'security/user/getlist', 'list' => true),
            'get_user'     => array('processor' => 'security/user/get'),
            'create_user'  => array('processor' => 'security/user/create'),
            'update_user'  => array('processor' => 'security/user/update'),
            'delete_user'  => array('processor' => 'security/user/delete'),
            // --- roles (modUserGroupRole) ---
            'list_roles'              => array('processor' => 'security/role/getlist', 'list' => true),
            'get_role'                => array('processor' => 'security/role/get'),
            'create_role'             => array('processor' => 'security/role/create'),
            'update_role'             => array('processor' => 'security/role/update'),
            'delete_role'             => array('processor' => 'security/role/remove'),
            // --- access policies (modAccessPolicy) ---
            'list_access_policies'         => array('processor' => 'security/access/policy/getlist', 'list' => true),
            'create_access_policy'         => array('processor' => 'security/access/policy/create'),
            'update_access_policy'         => array('processor' => 'security/access/policy/update'),
            'delete_access_policy'         => array('processor' => 'security/access/policy/remove'),
            // --- access policy templates (modAccessPolicyTemplate) ---
            'list_access_policy_templates' => array('processor' => 'security/access/policy/template/getlist', 'list' => true),
            'create_access_policy_template'=> array('processor' => 'security/access/policy/template/create'),
            'update_access_policy_template'=> array('processor' => 'security/access/policy/template/update'),
            'delete_access_policy_template'=> array('processor' => 'security/access/policy/template/remove'),
            // --- permissions (read-only catalogue) ---
            'list_access_permissions'      => array('processor' => 'security/access/permission/getlist', 'list' => true),
            // --- resource groups (modResourceGroup) ---
            'list_resource_groups'      => array('processor' => 'security/resourcegroup/getlist', 'list' => true),
            'create_resource_group'     => array('processor' => 'security/resourcegroup/create'),
            'update_resource_group'     => array('processor' => 'security/resourcegroup/update'),
            'delete_resource_group'     => array('processor' => 'security/resourcegroup/remove'),
            'assign_resource_to_group'  => array('processor' => 'security/resourcegroup/updateresourcesin'),
            'remove_resource_from_group'=> array('processor' => 'security/resourcegroup/removeresource'),
            // --- context access for a user group (modAccessContext) ---
            'list_context_access'    => array('processor' => 'security/access/usergroup/context/getlist', 'list' => true),
            'grant_context_access'   => array('processor' => 'security/access/usergroup/context/create'),
            'update_context_access'  => array('processor' => 'security/access/usergroup/context/update'),
            'revoke_context_access'  => array('processor' => 'security/access/usergroup/context/remove'),
            // --- resource-group access for a user group (modAccessResourceGroup) ---
            'list_resourcegroup_access'   => array('processor' => 'security/access/usergroup/resourcegroup/getlist', 'list' => true),
            'grant_resourcegroup_access'  => array('processor' => 'security/access/usergroup/resourcegroup/create'),
            'update_resourcegroup_access' => array('processor' => 'security/access/usergroup/resourcegroup/update'),
            'revoke_resourcegroup_access' => array('processor' => 'security/access/usergroup/resourcegroup/remove'),
        );
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

        $logFields = array_intersect_key($props, array_flip(array('id', 'usergroup', 'user', 'target', 'principal', 'resource', 'resourceGroup', 'name')));
        $this->logAudit($action, 'acl', $logFields);

        if ($isList) {
            $decoded = json_decode($response->getResponse(), true);
            return array(
                'total'   => isset($decoded['total']) ? (int) $decoded['total'] : 0,
                'results' => isset($decoded['results']) ? $decoded['results'] : array(),
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

    private function auditLogPath() {
        return rtrim($this->modx->getOption('core_path'), '/') . '/cache/modxmcp/audit.log';
    }

    private function writeAuditFile($action, $elementType, array $payload) {
        try {
            $dir = rtrim($this->modx->getOption('core_path'), '/') . '/cache/modxmcp';
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
        return array(
            'elements'      => array('list_elements', 'get_element', 'create_element', 'update_element', 'delete_element', 'make_static'),
            'resource_tvs'  => array('get_resource_tvs', 'update_resource_tvs'),
            'tv_inputs'     => array('list_tv_input_types'),
            'system'        => array('list_system_settings', 'get_system_setting', 'create_system_setting', 'update_system_setting', 'delete_system_setting'),
            'media'         => array('list_media_sources', 'get_media_source', 'list_media_source_files', 'read_media_source_file'),
            'components'    => array('list_installed_components', 'get_component_files', 'read_component_file', 'check_integrations', 'install_package'),
            'code_search'   => array('search_code', 'find_usages', 'list_resources'),
            'versionx'      => array('versionx_list_versions', 'versionx_get_version', 'versionx_revert_version'),
            'virtualpage'   => array('virtualpage_list_events', 'virtualpage_get_event', 'virtualpage_create_event', 'virtualpage_update_event', 'virtualpage_list_handlers', 'virtualpage_get_handler', 'virtualpage_create_handler', 'virtualpage_update_handler', 'virtualpage_list_routes', 'virtualpage_get_route', 'virtualpage_create_route', 'virtualpage_update_route', 'virtualpage_delete_event', 'virtualpage_delete_handler', 'virtualpage_delete_route', 'virtualpage_resolve_route', 'virtualpage_clear_cache'),
            'minishop2'     => array('ms2_list_option_types', 'ms2_list_options', 'ms2_get_option', 'ms2_create_option', 'ms2_update_option', 'ms2_assign_option_to_category', 'ms2_get_product_options', 'ms2_update_product_options', 'ms2_list_link_types', 'ms2_get_link_type', 'ms2_create_link_type', 'ms2_update_link_type', 'ms2_delete_link_type', 'ms2_list_product_links', 'ms2_create_product_link', 'ms2_delete_product_link', 'ms2_list_categories', 'ms2_create_category', 'ms2_update_category', 'ms2_list_orders', 'ms2_get_order', 'ms2_update_order'),
            'migx'          => array('migx_list_configs', 'migx_get_config', 'migx_create_config', 'migx_update_config', 'migx_delete_config'),
            'access'        => array('list_users', 'get_user', 'create_user', 'update_user', 'delete_user', 'list_user_groups', 'get_user_group', 'create_user_group', 'update_user_group', 'delete_user_group', 'list_user_group_members', 'add_user_to_group', 'update_group_member', 'remove_user_from_group', 'list_roles', 'get_role', 'create_role', 'update_role', 'delete_role', 'list_access_policies', 'create_access_policy', 'update_access_policy', 'delete_access_policy', 'list_access_policy_templates', 'create_access_policy_template', 'update_access_policy_template', 'delete_access_policy_template', 'list_access_permissions', 'list_resource_groups', 'create_resource_group', 'update_resource_group', 'delete_resource_group', 'assign_resource_to_group', 'remove_resource_from_group', 'list_context_access', 'grant_context_access', 'update_context_access', 'revoke_context_access', 'list_resourcegroup_access', 'grant_resourcegroup_access', 'update_resourcegroup_access', 'revoke_resourcegroup_access'),
            'property_sets' => array('list_property_sets', 'get_property_set', 'create_property_set', 'update_property_set', 'delete_property_set', 'assign_property_set', 'unassign_property_set'),
            'ops'           => array('list_actions', 'run_processor', 'clear_cache', 'read_audit_log', 'regenerate_token'),
        );
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

        $content = file_get_contents($absolutePath);
        $mime = function_exists('mime_content_type') ? mime_content_type($absolutePath) : 'application/octet-stream';
        $isText = is_string($mime) && (
            strpos($mime, 'text/') === 0 ||
            strpos($mime, 'json') !== false ||
            strpos($mime, 'xml') !== false ||
            strpos($mime, 'javascript') !== false ||
            strpos($mime, 'svg') !== false
        );

        return [
            'media_source' => $this->normalizeMediaSource($source, false),
            'path' => $relativePath,
            'mime' => $mime,
            'size' => filesize($absolutePath),
            'encoding' => $isText ? 'utf-8' : 'base64',
            'content' => $isText ? $content : base64_encode($content),
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

            $content = file_get_contents($absolutePath);
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
                'component' => $data['name'],
                'scope' => $scope,
                'root_path' => $componentRoot,
                'path' => $relativePath,
                'mime' => $mime,
                'size' => filesize($absolutePath),
                'encoding' => $isText ? 'utf-8' : 'base64',
                'content' => $isText ? $content : base64_encode($content),
            ];
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
        if (is_array($raw)) { return $raw; }
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $object = $response->getObject();
        if (!empty($object)) {
            return $object;
        }
        return ['success' => true, 'message' => $response->getMessage()];
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
