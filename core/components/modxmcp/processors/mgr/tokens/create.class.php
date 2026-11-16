<?php
/**
 * Manager processor: create an API token; the plain token and a ready client configuration are returned once. A thin wrapper around ModxMCP\Manager\TokenService; needs the manager "settings" permission.
 * Neither the token value (except once, from create) nor its hash is ever logged or put into an error message.
 */
class ModxmcpTokensCreateProcessor extends modProcessor {
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function process() {
        $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';
        try {
            $service = new \ModxMCP\Manager\TokenService($this->modx, $this->modx->user);
            return $this->success('', $service->create($this->getProperties()));
        } catch (Throwable $e) {
            list($message, $object) = \ModxMCP\Manager\HistoryService::errorReport($e);
            return $this->failure($message, $object);
        }
    }
}
return 'ModxmcpTokensCreateProcessor';
