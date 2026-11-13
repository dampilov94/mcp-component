<?php
/**
 * Manager processor: undo a changeset or everything after a checkpoint (plan without confirm). A thin wrapper around ModxMCP\Manager\HistoryService; needs the manager "settings" permission.
 */
class ModxmcpHistoryUndoProcessor extends modProcessor {
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
            $selector = array('changeset_id' => $this->getProperty('changeset_id', ''), 'to_checkpoint' => $this->getProperty('to_checkpoint', ''));
            $service = new \ModxMCP\Manager\HistoryService($this->modx, $this->modx->user);
            $result = $service->undo($selector, $this->flag('confirm'), $this->flag('force'));
        } catch (Throwable $e) {
            list($message, $object) = \ModxMCP\Manager\HistoryService::errorReport($e);
            return $this->failure($message, $object);
        }
        if (!empty($result['performed'])) {
            $this->modx->logManagerAction('modxmcp_undo', 'modxmcp_changeset', (int) $result['changeset_id']);
        }
        return $this->success('', $result);
    }
}
return 'ModxmcpHistoryUndoProcessor';
