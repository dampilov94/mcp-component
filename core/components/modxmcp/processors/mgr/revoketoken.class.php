<?php
/**
 * Manager processor: revoke an API token. Revocation is permanent; clients using the token
 * are rejected from the next request. Requires the manager "settings" permission.
 */
class ModxmcpRevokeTokenProcessor extends modProcessor {
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function process() {
        $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';

        $id = (int) $this->getProperty('id', 0);
        if ($id < 1) {
            return $this->failure('Token id is required.');
        }
        try {
            $revoked = (new \ModxMCP\Auth\TokenStore($this->modx))->revoke($id);
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[modxmcp] Token revocation failed: ' . $e->getMessage());
            return $this->failure('Could not revoke the token.');
        }
        if (!$revoked) {
            return $this->failure('Token not found or already revoked.');
        }
        $this->modx->logManagerAction('modxmcp_revoke_token', 'modxmcp_token', $id);

        return $this->success('', array('id' => $id));
    }
}
return 'ModxmcpRevokeTokenProcessor';
