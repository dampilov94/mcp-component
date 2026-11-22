<?php
/** Manager-only issuance of a short-lived draft preview. */
class ModxmcpDraftsShareProcessor extends modProcessor {
    public function checkPermissions() {
        return $this->modx->user && $this->modx->user->hasSessionContext('mgr') && $this->modx->hasPermission('settings');
    }

    public function process() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return $this->failure('POST required.'); }
        header('Cache-Control: no-store');
        $this->modx->lexicon->load('modxmcp:default');
        $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';
        try {
            $service = new \ModxMCP\Manager\DraftService($this->modx, $this->modx->user);
            $result = $service->share($this->getProperty('id'), $_SERVER);
        } catch (\ModxMCP\ApiException $e) {
            $key = $e->errorCode === 'https_required' ? 'share_https' : 'share_unavailable';
            return $this->failure($this->modx->lexicon('modxmcp_drafts_' . $key));
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[modxmcp] Draft preview issuance failed.');
            return $this->failure($this->modx->lexicon('modxmcp_drafts_share_unavailable'));
        }
        return $this->success('', $result);
    }
}
return 'ModxmcpDraftsShareProcessor';
