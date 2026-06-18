<?php
/**
 * Manager processor: return current modxMCP status for the CMP dashboard.
 */
class ModxmcpGetStatusProcessor extends modProcessor {
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function process() {
        $token = (string) $this->modx->getOption('modxmcp.api_token');
        $siteUrl = rtrim((string) $this->modx->getOption('site_url'), '/');
        return $this->success('', array(
            'enabled'       => (bool) $this->modx->getOption('modxmcp.enabled'),
            'token_set'     => $token !== '',
            'token_preview' => $token !== '' ? (substr($token, 0, 6) . '…' . substr($token, -4)) : '',
            'auto_static'   => (bool) $this->modx->getOption('modxmcp.auto_static'),
            'audit_log'     => (bool) $this->modx->getOption('modxmcp.audit_log'),
            'endpoint'      => $siteUrl . '/assets/components/modxmcp/api.php',
        ));
    }
}
return 'ModxmcpGetStatusProcessor';
