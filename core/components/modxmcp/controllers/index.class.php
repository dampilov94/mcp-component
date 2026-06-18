<?php
/**
 * modxMCP — manager CMP controller (Components > modxMCP).
 *
 * Read-only status dashboard plus a "regenerate token" button. The class name
 * MUST be Modxmcp + Index + ManagerController for MODX 2.3+ namespaced controller
 * autoloading (namespace "modxmcp", action "index").
 */
class ModxmcpIndexManagerController extends modExtraManagerController {

    public function getPageTitle() {
        return $this->modx->lexicon('modxmcp');
    }

    public function getLanguageTopics() {
        return array('modxmcp:default');
    }

    public function loadCustomCssJs() {
        $assetsUrl = $this->modx->getOption(
            'modxmcp.assets_url',
            null,
            $this->modx->getOption('assets_url') . 'components/modxmcp/'
        );
        $this->addHtml('<script type="text/javascript">var Modxmcp = { connector_url: "' . $assetsUrl . 'connector.php" };</script>');
        $this->addJavascript($assetsUrl . 'js/home.js');
    }

    public function process(array $scriptProperties = array()) {
        $token = (string) $this->modx->getOption('modxmcp.api_token');
        $siteUrl = rtrim((string) $this->modx->getOption('site_url'), '/');

        $integrations = array();
        $modelFile = $this->modx->getOption('core_path') . 'components/modxmcp/model/modxmcp.class.php';
        if (file_exists($modelFile)) {
            require_once $modelFile;
            try {
                $mcp = new modxMCP($this->modx);
                $report = $mcp->getIntegrationsReport();
                $integrations = isset($report['integrations']) ? $report['integrations'] : array();
            } catch (Exception $e) {
                $integrations = array();
            }
        }

        $this->setPlaceholders(array(
            'enabled'         => $this->modx->getOption('modxmcp.enabled') ? 1 : 0,
            'token_set'       => $token !== '' ? 1 : 0,
            'token_preview'   => $token !== '' ? (substr($token, 0, 6) . '…' . substr($token, -4)) : '—',
            'auto_static'     => $this->modx->getOption('modxmcp.auto_static') ? 1 : 0,
            'audit_log'       => $this->modx->getOption('modxmcp.audit_log') ? 1 : 0,
            'package_install' => $this->modx->getOption('modxmcp.allow_package_install') ? 1 : 0,
            'endpoint'        => $siteUrl . '/assets/components/modxmcp/api.php',
            'integrations'    => $integrations,
        ));
    }

    public function getTemplateFile() {
        return $this->modx->getOption('core_path') . 'components/modxmcp/templates/home.tpl';
    }
}
