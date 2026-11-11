<?php
/**
 * Manager processor: create an API token. The plain token is returned ONCE so the operator
 * can copy it into the client configuration; only its hash is stored.
 * Requires the manager "settings" permission.
 */
class ModxmcpCreateTokenProcessor extends modProcessor {
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function process() {
        $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';

        $label = trim((string) $this->getProperty('label', ''));
        $scope = (string) $this->getProperty('scope', '');
        if (!\ModxMCP\Auth\Scope::valid($scope)) {
            return $this->failure('Unknown scope.');
        }
        // Checkbox values arrive as the STRINGS "true"/"false" — "false" is truthy in PHP.
        $flag = $this->getProperty('plan_only');
        $planOnly = ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true');
        $days = (int) $this->getProperty('expires_days', 0);
        $expiresAt = $days > 0 ? time() + min($days, 3650) * 86400 : null;

        try {
            $record = (new \ModxMCP\Auth\TokenStore($this->modx))->create(
                $label, $scope, $planOnly, array(), $expiresAt, $this->modx->user ? (int) $this->modx->user->get('id') : 0
            );
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[modxmcp] Token creation failed: ' . $e->getMessage());
            return $this->failure('Could not create the token.');
        }
        $this->modx->logManagerAction('modxmcp_create_token', 'modxmcp_token', $record['id']);

        return $this->success('', array('id' => $record['id'], 'token' => $record['token']));
    }
}
return 'ModxmcpCreateTokenProcessor';
