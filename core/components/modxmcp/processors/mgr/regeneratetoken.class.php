<?php
/**
 * Manager processor: generate a fresh modxmcp.api_token and save it.
 * Returns the new token ONCE so the operator can copy it into their client config.
 * Requires the manager "settings" permission.
 */
class ModxmcpRegenerateTokenProcessor extends modProcessor {
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function process() {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $token = md5(uniqid('modxmcp', true)) . md5(uniqid('token', true));
        }

        $setting = $this->modx->getObject('modSystemSetting', array('key' => 'modxmcp.api_token'));
        if (!$setting) {
            $setting = $this->modx->newObject('modSystemSetting');
            $setting->fromArray(array(
                'key'       => 'modxmcp.api_token',
                'namespace' => 'modxmcp',
                'area'      => 'modxmcp:main',
                'xtype'     => 'textfield',
            ), '', true, true);
        }
        $setting->set('value', $token);
        if (!$setting->save()) {
            return $this->failure('Could not save the new token.');
        }

        $this->modx->reloadConfig();
        if ($this->modx->getCacheManager()) {
            $this->modx->getCacheManager()->refresh();
        }
        $this->modx->logManagerAction('modxmcp_regenerate_token', 'modSystemSetting', 'modxmcp.api_token');

        return $this->success('', array('token' => $token));
    }
}
return 'ModxmcpRegenerateTokenProcessor';
