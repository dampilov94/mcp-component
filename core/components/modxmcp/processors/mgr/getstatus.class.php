<?php
/**
 * Manager processor: the overview of the component for the main page (status, active tokens, recent
 * changes, dangerous settings). A thin wrapper around ModxMCP\Manager\Overview.
 */
class ModxmcpGetStatusProcessor extends modProcessor {
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function process() {
        $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';
        try {
            return $this->success('', (new \ModxMCP\Manager\Overview($this->modx, $this->modx->user))->data());
        } catch (Throwable $e) {
            list($message, $object) = \ModxMCP\Manager\HistoryService::errorReport($e);
            return $this->failure($message, $object);
        }
    }
}
return 'ModxmcpGetStatusProcessor';
