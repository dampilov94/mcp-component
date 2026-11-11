<?php
/**
 * Manager processor: return current modxMCP status for the CMP dashboard.
 */
class ModxmcpGetStatusProcessor extends modProcessor {
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function process() {
        $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';

        $active = 0;
        try {
            foreach ((new \ModxMCP\Auth\TokenStore($this->modx))->all() as $token) {
                $expired = $token['expires_at'] !== null && $token['expires_at'] <= time();
                if ($token['revoked_at'] === null && !$expired) { $active++; }
            }
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[modxmcp] Status: ' . $e->getMessage());
        }
        $siteUrl = rtrim((string) $this->modx->getOption('site_url'), '/');
        return $this->success('', array(
            'enabled'       => (bool) $this->modx->getOption('modxmcp.enabled'),
            'version'       => \ModxMCP\Kernel::VERSION,
            'active_tokens' => $active,
            'auto_static'   => (bool) $this->modx->getOption('modxmcp.auto_static'),
            'audit_log'     => (bool) $this->modx->getOption('modxmcp.audit_log'),
            'endpoint'      => $siteUrl . '/assets/components/modxmcp/api.php',
        ));
    }
}
return 'ModxmcpGetStatusProcessor';
