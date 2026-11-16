<?php
/**
 * modxMCP — change history screen (Components > modxMCP > История).
 *
 * Everything changed through the API, in the manager or outside the journal, with an undo. The page
 * is built in the browser (history.js) from the processors in processors/mgr/history/; every text comes
 * from the lexicon topic modxmcp:default, keys modxmcp_history_*.
 *
 * Class name MUST be Modxmcp + History + ManagerController for MODX 2.3+ namespaced controller
 * autoloading (namespace "modxmcp", action "history").
 */
class ModxmcpHistoryManagerController extends modExtraManagerController {

    /** The history can undo any change: the same permission as the rest of the component. */
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function getPageTitle() {
        $this->modx->lexicon->load('modxmcp:default');
        return $this->modx->lexicon('modxmcp') . ' — ' . $this->modx->lexicon('modxmcp_history_title');
    }

    public function getLanguageTopics() {
        return array('modxmcp:default');
    }

    public function loadCustomCssJs() {
        $this->modx->lexicon->load('modxmcp:default');
        $assetsUrl = $this->modx->getOption(
            'modxmcp.assets_url',
            null,
            $this->modx->getOption('assets_url') . 'components/modxmcp/'
        );
        $cfg = json_encode(array(
            'connector_url' => $assetsUrl . 'connector.php',
            'l'             => $this->modx->lexicon->fetch('modxmcp_history_', true),
        ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $this->addHtml('<script type="text/javascript">var ModxmcpHistory = ' . $cfg . ';</script>');
        // Cache-bust by mtime so the browser always gets the current build.
        $jsFile = $this->modx->getOption('assets_path') . 'components/modxmcp/js/history.js';
        $ver = file_exists($jsFile) ? filemtime($jsFile) : '1';
        $this->addJavascript($assetsUrl . 'js/history.js?v=' . $ver);
    }

    public function process(array $scriptProperties = array()) {
        $this->modx->lexicon->load('modxmcp:default');
    }

    public function getTemplateFile() {
        return $this->modx->getOption('core_path') . 'components/modxmcp/templates/history.tpl';
    }
}
