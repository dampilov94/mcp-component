<?php
/**
 * modxMCP — manager CMP controller (Components > modxMCP).
 *
 * Read-only status dashboard plus a "regenerate token" button. The class name
 * MUST be Modxmcp + Index + ManagerController for MODX 2.3+ namespaced controller
 * autoloading (namespace "modxmcp", action "index").
 *
 * UI strings are lexicon-driven (en + ru), so the panel follows the manager language.
 */
class ModxmcpIndexManagerController extends modExtraManagerController {

    public function getPageTitle() {
        return $this->modx->lexicon('modxmcp');
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
            'connector_url'      => $assetsUrl . 'connector.php',
            'confirm_regenerate' => $this->modx->lexicon('modxmcp_cmp_confirm_regenerate'),
            'regenerate_failed'  => $this->modx->lexicon('modxmcp_cmp_regenerate_failed'),
        ), JSON_UNESCAPED_UNICODE);
        $this->addHtml('<script type="text/javascript">var Modxmcp = ' . $cfg . ';</script>');
        $this->addJavascript($assetsUrl . 'js/home.js');
    }

    public function process(array $scriptProperties = array()) {
        $this->modx->lexicon->load('modxmcp:default');
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

        $labelKeys = array(
            'intro', 'endpoint', 'enabled', 'yes', 'no', 'enable_hint', 'token', 'token_set',
            'token_notset', 'auto_static', 'audit_log', 'package_install', 'on', 'off',
            'regenerate', 'new_token', 'settings_hint', 'integrations', 'integrations_intro',
        );
        $l = array();
        foreach ($labelKeys as $k) {
            $l[$k] = $this->modx->lexicon('modxmcp_cmp_' . $k);
        }

        $this->setPlaceholders(array(
            'l'               => $l,
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
