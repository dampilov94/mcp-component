<?php
/**
 * Manager processor: save the modxmcp.disabled_groups setting from the CMP capability toggles.
 * Accepts a CSV `disabled` of group keys; only known toggleable keys are stored.
 */
class ModxmcpSaveGroupsProcessor extends modProcessor {
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function process() {
        $allowed = array('versionx', 'virtualpage', 'minishop2', 'migx', 'access', 'property_sets', 'contexts');
        $raw = (string) $this->getProperty('disabled', '');
        $disabled = array();
        foreach (explode(',', $raw) as $g) {
            $g = trim($g);
            if ($g !== '' && in_array($g, $allowed, true)) {
                $disabled[$g] = $g;
            }
        }
        $value = implode(',', array_values($disabled));

        $setting = $this->modx->getObject('modSystemSetting', array('key' => 'modxmcp.disabled_groups'));
        if (!$setting) {
            $setting = $this->modx->newObject('modSystemSetting');
            $setting->fromArray(array(
                'key'       => 'modxmcp.disabled_groups',
                'namespace' => 'modxmcp',
                'area'      => 'modxmcp:main',
                'xtype'     => 'textfield',
            ), '', true, true);
        }
        $setting->set('value', $value);
        if (!$setting->save()) {
            return $this->failure('Could not save modxmcp.disabled_groups.');
        }

        $this->modx->reloadConfig();
        if ($this->modx->getCacheManager()) {
            $this->modx->getCacheManager()->refresh();
        }
        $this->modx->logManagerAction('modxmcp_save_groups', 'modSystemSetting', 'modxmcp.disabled_groups');

        return $this->success('', array('disabled_groups' => $value));
    }
}
return 'ModxmcpSaveGroupsProcessor';
