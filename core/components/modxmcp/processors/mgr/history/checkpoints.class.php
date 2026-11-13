<?php
/**
 * Manager processor: the checkpoints, newest first. A thin wrapper around ModxMCP\Manager\HistoryService; needs the manager "settings" permission.
 */
class ModxmcpHistoryCheckpointsProcessor extends modProcessor {
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function process() {
        $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';
        try {
            $service = new \ModxMCP\Manager\HistoryService($this->modx, $this->modx->user);
            $result = $service->checkpoints(20);
        } catch (Throwable $e) {
            list($message, $object) = \ModxMCP\Manager\HistoryService::errorReport($e);
            return $this->failure($message, $object);
        }
        return $this->success('', $result);
    }
}
return 'ModxmcpHistoryCheckpointsProcessor';
