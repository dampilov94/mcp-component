<?php
if (!class_exists("ModxMCPClientException")) {
    /** Expected/validation error whose message is safe to return to the client. */
    class ModxMCPClientException extends Exception {}
}
require_once __DIR__ . '/logreader.class.php';
require_once __DIR__ . '/traits/administration.trait.php';
require_once __DIR__ . '/traits/capabilities.trait.php';
require_once __DIR__ . '/traits/diagnostics.trait.php';
require_once __DIR__ . '/traits/elements.trait.php';
require_once __DIR__ . '/traits/files.trait.php';
require_once __DIR__ . '/traits/graph.trait.php';
require_once __DIR__ . '/traits/migx.trait.php';
require_once __DIR__ . '/traits/minishop2.trait.php';
require_once __DIR__ . '/traits/packages.trait.php';
require_once __DIR__ . '/traits/registry.trait.php';
require_once __DIR__ . '/traits/resources.trait.php';
require_once __DIR__ . '/traits/runtime.trait.php';
require_once __DIR__ . '/traits/search.trait.php';
require_once __DIR__ . '/traits/tvinputs.trait.php';
require_once __DIR__ . '/traits/versionx.trait.php';
require_once __DIR__ . '/traits/virtualpage.trait.php';

class modxMCP {
    use ModxMCPAdministrationTrait;
    use ModxMCPCapabilitiesTrait;
    use ModxMCPDiagnosticsTrait;
    use ModxMCPElementsTrait;
    use ModxMCPFilesTrait;
    use ModxMCPGraphTrait;
    use ModxMCPMigxTrait;
    use ModxMCPMinishop2Trait;
    use ModxMCPPackagesTrait;
    use ModxMCPRegistryTrait;
    use ModxMCPResourcesTrait;
    use ModxMCPRuntimeTrait;
    use ModxMCPSearchTrait;
    use ModxMCPTvinputsTrait;
    use ModxMCPVersionxTrait;
    use ModxMCPVirtualpageTrait;

    const VERSION = '1.10.0';
    public $modx;
    public $config =[];
    private $actionSpecsCache = null;
    private $transactionalContentTables = array();
    private $integrationAvailabilityCache = null;
    private $cacheDeferralDepth = 0;
    private $cacheRefreshPending = false;
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
                $codeMap = $this->lineEditMap();
                if (isset($codeMap[$elementType])) {
                    $el = $this->modx->getObject($codeMap[$elementType]['class'], (int) $data['id'], false);
                    if (!$el) { throw new ModxMCPClientException('Element no longer exists.'); }
                    list($effective) = $this->readEffectiveContent($el, $codeMap[$elementType]);
                    $field = $codeMap[$elementType]['field'];
                    $oldCode = isset($objData[$field]) ? $objData[$field] : null;
                    $objData[$field] = $effective;
                    $objData['revision'] = $this->contentRevision($effective);
                    // Keep one canonical code field if the processor also exposes its alias.
                    if ($field !== 'content' && array_key_exists('content', $objData)
                        && ($objData['content'] === $oldCode || $objData['content'] === $effective)) { unset($objData['content']); }
                }

                
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
                if ($elementType === 'resource' && $this->cacheDeferralDepth > 0) {
                    $updateData['syncsite'] = 0;
                    $updateData['clearCache'] = false;
                }
                
                return $this->runWithTransaction(function () use ($basePath, $updateData, $elementType, $data) {
                    $response = $this->modx->runProcessor($basePath . 'update', $updateData);
                    if ($response->isError()) throw new ModxMCPClientException("Update failed: " . $this->formatProcessorErrors($response));
                    
                    if ($elementType === 'tv') $this->handleTvRelations($data['id'], $data);
                    if ($elementType === 'plugin') $this->handlePluginEvents($data['id'], $data);
                    if ($this->shouldAutoStatic($elementType)) { $this->makeElementStatic($elementType, (int) $data['id']); }
                    
                    $this->refreshContentCache();
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
                    if (!empty($newObj['id']) && $this->shouldAutoStatic($elementType)) {
                        $staticInfo = $this->makeElementStatic($elementType, (int) $newObj['id']);
                        $newObj['static'] = 1;
                        $newObj['static_file'] = $staticInfo['static_file'];
                        $newObj['source'] = $staticInfo['source'];
                    }
                    
                    $this->refreshContentCache();
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
                $deleteData = array('id' => $data['id']);
                if ($elementType === 'resource' && $this->cacheDeferralDepth > 0) {
                    $deleteData['syncsite'] = 0;
                    $deleteData['clearCache'] = false;
                }
                $response = $this->modx->runProcessor($basePath . $processorAction, $deleteData);
                if ($response->isError()) throw new ModxMCPClientException("Delete failed: " . $this->formatProcessorErrors($response));
                
                $this->refreshContentCache();
                $this->logAudit('delete_element', $elementType, ['id' => $data['id']]);
                return "Successfully deleted {$elementType} (ID: {$data['id']}).";

            default:
                throw new ModxMCPClientException("Unknown action: {$action}");
        }
    }
}
