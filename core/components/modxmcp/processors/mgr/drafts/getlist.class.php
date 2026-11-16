<?php
/**
 * Manager processor: the open drafts of resources (the Drafts block of the component page). A thin wrapper around ModxMCP\Manager\DraftService; needs the manager "settings" permission.
 */
class ModxmcpDraftsGetListProcessor extends modProcessor {
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function process() {
        $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';
        try {
            $service = new \ModxMCP\Manager\DraftService($this->modx, $this->modx->user);
            return $this->success('', array('drafts' => $service->drafts()));
        } catch (Throwable $e) {
            list($message, $object) = \ModxMCP\Manager\HistoryService::errorReport($e);
            return $this->failure($message, $object);
        }
    }
}
return 'ModxmcpDraftsGetListProcessor';
