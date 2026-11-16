<?php
/**
 * Manager processor: throw a draft away (the plan without confirm). A thin wrapper around ModxMCP\Manager\DraftService, which runs
 * the same operation as the API; the change is journaled as the manager's. Needs the manager "settings" permission.
 */
class ModxmcpDraftsDiscardProcessor extends modProcessor {
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    /** Checkbox-like values arrive as the strings "true" / "false" / "1" / "0". */
    private function flag($name) {
        $value = $this->getProperty($name);
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    public function process() {
        $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';
        try {
            $service = new \ModxMCP\Manager\DraftService($this->modx, $this->modx->user);
            $result = $service->discard((int) $this->getProperty('id', 0), $this->flag('confirm'));
        } catch (Throwable $e) {
            list($message, $object) = \ModxMCP\Manager\HistoryService::errorReport($e);
            return $this->failure($message, $object);
        }
        if (!empty($result['discarded'])) {
            $this->modx->logManagerAction('modxmcp_discard_draft', 'modResource', (int) $result['draft_id']);
        }
        return $this->success('', $result);
    }
}
return 'ModxmcpDraftsDiscardProcessor';
