<?php
namespace ModxMCP\Manager;

/**
 * The pause button of the main page: switches the system setting modxmcp.enabled. While it is off every API request is
 * answered "modxMCP is disabled"; the manager pages do not look at it and keep working.
 */
final class ApiSwitch {
    const KEY = 'modxmcp.enabled';

    private $modx;

    public function __construct(\modX $modx) {
        $this->modx = $modx;
    }

    public function enabled() {
        return (bool) $this->modx->getOption(self::KEY, null, false);
    }

    /**
     * Switch to the given state, or to the opposite one when $enabled is null. A call that asks for the state the API is
     * already in changes nothing (a double click must not undo itself).
     * @return array enabled (the state now), changed (whether it was switched by this call)
     */
    public function set($enabled = null) {
        $before = $this->enabled();
        $target = $enabled === null ? !$before : (bool) $enabled;
        if ($target === $before) { return array('enabled' => $before, 'changed' => false); }

        $setting = $this->modx->getObject('modSystemSetting', array('key' => self::KEY));
        if (!$setting) {
            $setting = $this->modx->newObject('modSystemSetting');
            $setting->fromArray(array('key' => self::KEY, 'namespace' => 'modxmcp', 'area' => 'modxmcp:main', 'xtype' => 'combo-boolean'), '', true, true);
        }
        $setting->set('value', $target ? '1' : '0');
        if (!$setting->save()) { throw new \RuntimeException('Could not save ' . self::KEY . '.'); }
        $this->modx->reloadConfig();
        $cache = $this->modx->getCacheManager();
        if ($cache) { $cache->refresh(); }
        $this->modx->logManagerAction($target ? 'modxmcp_resume_api' : 'modxmcp_pause_api', 'modSystemSetting', self::KEY);
        return array('enabled' => $target, 'changed' => true);
    }
}
