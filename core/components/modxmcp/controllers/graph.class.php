<?php
/**
 * modxMCP — dependency graph screen (Components > modxMCP > Граф связей).
 *
 * Its own manager action so the graph gets the full width and height of the content region —
 * on the settings page it competed with the dashboard and pushed it out of view.
 *
 * Class name MUST be Modxmcp + Graph + ManagerController for MODX 2.3+ namespaced controller
 * autoloading (namespace "modxmcp", action "graph").
 */
class ModxmcpGraphManagerController extends modExtraManagerController {

    /** The component exposes administrative tools and the API token. */
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function getPageTitle() {
        // getPageTitle() runs before getLanguageTopics() is applied, so load the topic ourselves
        // or the raw lexicon key ends up in the browser title.
        $this->modx->lexicon->load('modxmcp:default');
        return $this->modx->lexicon('modxmcp') . ' — ' . $this->modx->lexicon('modxmcp_graph_title');
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
            'manager_url'   => $this->modx->getOption('manager_url'),
        ), JSON_UNESCAPED_UNICODE);
        $this->addHtml('<script type="text/javascript">var ModxmcpGraph = ' . $cfg . ';</script>');
        // Cache-bust by mtime so the browser always gets the current build.
        $jsFile = $this->modx->getOption('assets_path') . 'components/modxmcp/js/graph.js';
        $ver = file_exists($jsFile) ? filemtime($jsFile) : '1';
        $this->addJavascript($assetsUrl . 'js/graph.js?v=' . $ver);
    }

    public function process(array $scriptProperties = array()) {
        $this->modx->lexicon->load('modxmcp:default');
        $this->setPlaceholders(array(
            'settings_url' => '?a=index&namespace=modxmcp',
        ));
    }

    public function getTemplateFile() {
        return $this->modx->getOption('core_path') . 'components/modxmcp/templates/graph.tpl';
    }
}
