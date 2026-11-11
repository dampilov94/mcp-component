<?php
/**
 * modxMCP — manager CMP controller (Components > modxMCP).
 *
 * Status dashboard: API tokens, capability groups and integrations. The class name
 * MUST be Modxmcp + Index + ManagerController for MODX 2.3+ namespaced controller
 * autoloading (namespace "modxmcp", action "index").
 *
 * UI strings are lexicon-driven (en + ru), so the panel follows the manager language.
 */
class ModxmcpIndexManagerController extends modExtraManagerController {

    /** The component exposes administrative tools and the API token. */
    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

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
        ), JSON_UNESCAPED_UNICODE);
        $this->addHtml('<script type="text/javascript">var Modxmcp = ' . $cfg . ';</script>');
        // Cache-bust home.js by its mtime so browsers always load the current version.
        $jsFile = $this->modx->getOption('assets_path') . 'components/modxmcp/js/home.js';
        $ver = file_exists($jsFile) ? filemtime($jsFile) : '1';
        $this->addJavascript($assetsUrl . 'js/home.js?v=' . $ver);
    }

    public function process(array $scriptProperties = array()) {
        $this->modx->lexicon->load('modxmcp:default');
        $siteUrl = rtrim((string) $this->modx->getOption('site_url'), '/');
        $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';

        $tokens = array();
        $integrations = array();
        $components = array();
        $features = array();
        try {
            $date = function ($time) { return $time ? date('Y-m-d H:i', $time) : ''; };
            foreach ((new \ModxMCP\Auth\TokenStore($this->modx))->all() as $token) {
                $tokens[] = array(
                    'id'        => $token['id'],
                    'label'     => $token['label'] !== '' ? $token['label'] : '—',
                    'prefix'    => $token['prefix'],
                    'scope'     => $token['scope'],
                    'plan_only' => $token['plan_only'] ? 1 : 0,
                    'created'   => $date($token['created_at']),
                    'last_used' => $date($token['last_used_at']),
                    'expires'   => $date($token['expires_at']),
                    'revoked'   => $token['revoked_at'] !== null ? 1 : 0,
                    'expired'   => ($token['expires_at'] !== null && $token['expires_at'] <= time()) ? 1 : 0,
                );
            }

            $capabilities = new \ModxMCP\Capabilities($this->modx);
            $integrations = $capabilities->integrationsReport();

            // Groups the admin can switch off for the whole site, split into add-ons vs core MODX.
            $disabled = array_flip($capabilities->disabledGroups());
            $meta = array(
                'virtualpage'        => array('VirtualPage (роуты / события)', 'component'),
                'minishop2'          => array('miniShop2 (товары / опции / связи / категории / заказы)', 'component'),
                'migx'               => array('MIGX (конфигурации)', 'component'),
                'access'             => array('Контроль доступа (пользователи, группы, роли, политики)', 'feature'),
                'contexts'           => array('Контексты', 'feature'),
                'property_sets'      => array('Наборы свойств элементов', 'feature'),
                'namespaces'         => array('Пространства имён', 'feature'),
                'lexicon'            => array('Управление словарями', 'feature'),
                'package_management' => array('Менеджер пакетов (установка / провайдеры)', 'feature'),
            );
            foreach (\ModxMCP\Capabilities::TOGGLEABLE as $g) {
                $label = isset($meta[$g]) ? $meta[$g][0] : $g;
                $kind = isset($meta[$g]) ? $meta[$g][1] : 'feature';
                $row = array('key' => $g, 'label' => $label, 'enabled' => isset($disabled[$g]) ? 0 : 1);
                if ($kind === 'component') { $components[] = $row; } else { $features[] = $row; }
            }
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[modxmcp] Manager page: ' . $e->getMessage());
        }

        $labelKeys = array(
            'intro', 'endpoint', 'enabled', 'yes', 'no', 'enable_hint', 'auto_static', 'audit_log',
            'on', 'off', 'settings_hint', 'integrations', 'integrations_intro',
        );
        $l = array();
        foreach ($labelKeys as $k) {
            $l[$k] = $this->modx->lexicon('modxmcp_cmp_' . $k);
        }

        $this->setPlaceholders(array(
            'l'               => $l,
            'enabled'         => $this->modx->getOption('modxmcp.enabled') ? 1 : 0,
            'auto_static'     => $this->modx->getOption('modxmcp.auto_static') ? 1 : 0,
            'audit_log'       => $this->modx->getOption('modxmcp.audit_log') ? 1 : 0,
            'endpoint'        => $siteUrl . '/assets/components/modxmcp/api.php',
            'tokens'          => $tokens,
            'integrations'    => $integrations,
            'components'      => $components,
            'features'        => $features,
        ));
    }

    public function getTemplateFile() {
        return $this->modx->getOption('core_path') . 'components/modxmcp/templates/home.tpl';
    }
}
