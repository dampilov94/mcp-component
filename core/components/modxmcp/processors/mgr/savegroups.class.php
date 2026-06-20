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
        // Single source of truth for which groups are toggleable: the model itself. Hardcoding
        // a subset here silently re-enables the groups that are missing from the list on save.
        $allowed = array('versionx', 'virtualpage', 'minishop2', 'migx', 'access', 'property_sets', 'contexts', 'package_management', 'namespaces', 'lexicon');
        $modelFile = $this->modx->getOption('core_path') . 'components/modxmcp/model/modxmcp.class.php';
        if (file_exists($modelFile)) {
            require_once $modelFile;
            try {
                $caps = (new modxMCP($this->modx))->getCapabilities();
                if (!empty($caps['toggleable_groups']) && is_array($caps['toggleable_groups'])) {
                    $allowed = $caps['toggleable_groups'];
                }
            } catch (Exception $e) {
                /* fall back to the static list above */
            }
        }
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
