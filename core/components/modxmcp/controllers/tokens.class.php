<?php
/**
 * modxMCP — API tokens screen (Components > modxMCP > Токены).
 *
 * Who uses which access: the list of tokens, creating one with a ready client configuration, changing and
 * revoking. The page is built in the browser (tokens.js) from the processors in processors/mgr/tokens/; every
 * text comes from the lexicon topic modxmcp:default, keys modxmcp_tokens_* and modxmcp_group_*.
 *
 * Class name MUST be Modxmcp + Tokens + ManagerController for MODX 2.3+ namespaced controller
 * autoloading (namespace "modxmcp", action "tokens").
 */
class ModxmcpTokensManagerController extends modExtraManagerController {

    /** Tokens give access to the whole site: the same permission as the rest of the component. */
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function getPageTitle() {
        $this->modx->lexicon->load('modxmcp:default');
        return $this->modx->lexicon('modxmcp') . ' — ' . $this->modx->lexicon('modxmcp_tokens_title');
    }

    public function getLanguageTopics() {
        return array('modxmcp:default');
    }

    public function loadCustomCssJs() {
        $this->modx->lexicon->load('modxmcp:default');
        $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';
        $assetsUrl = $this->modx->getOption(
            'modxmcp.assets_url',
            null,
            $this->modx->getOption('assets_url') . 'components/modxmcp/'
        );
        $l = $this->modx->lexicon->fetch('modxmcp_tokens_', true);
        foreach ($this->modx->lexicon->fetch('modxmcp_group_', true) as $key => $text) {
            $l['group_' . $key] = $text;
        }
        $cfg = json_encode(array(
            'connector_url' => $assetsUrl . 'connector.php',
            'groups'        => \ModxMCP\Capabilities::TOGGLEABLE,
            'scopes'        => array_keys(\ModxMCP\Auth\Scope::LEVELS),
            'l'             => $l,
        ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $this->addHtml('<script type="text/javascript">var ModxmcpTokens = ' . $cfg . ';</script>');
        // Cache-bust by mtime so the browser always gets the current build.
        $jsFile = $this->modx->getOption('assets_path') . 'components/modxmcp/js/tokens.js';
        $ver = file_exists($jsFile) ? filemtime($jsFile) : '1';
        $this->addJavascript($assetsUrl . 'js/tokens.js?v=' . $ver);
    }

    public function process(array $scriptProperties = array()) {
        $this->modx->lexicon->load('modxmcp:default');
    }

    public function getTemplateFile() {
        return $this->modx->getOption('core_path') . 'components/modxmcp/templates/tokens.tpl';
    }
}
