<?php
/**
 * Manager processor: the list of journal changesets (grid form). A thin wrapper around ModxMCP\Manager\HistoryService; needs the manager "settings" permission.
 */
class ModxmcpHistoryGetListProcessor extends modProcessor {
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function process() {
        $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';
        try {
            $filter = array();
            foreach (array('source', 'kind', 'id', 'key', 'tool', 'status', 'since', 'until', 'query') as $key) {
                $filter[$key] = $this->getProperty($key, '');
            }
            $limit = max(1, min(100, (int) $this->getProperty('limit', 50)));
            $service = new \ModxMCP\Manager\HistoryService($this->modx, $this->modx->user);
            $result = $service->changesets($filter, $limit, (int) $this->getProperty('start', 0));
        } catch (Throwable $e) {
            list($message, $object) = \ModxMCP\Manager\HistoryService::errorReport($e);
            return $this->failure($message, $object);
        }
        return $this->outputArray($result['changesets'], $result['total']);
    }
}
return 'ModxmcpHistoryGetListProcessor';
