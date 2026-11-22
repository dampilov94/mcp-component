<?php
/**
 * modxMCP — manager CMP controller (Components > modxMCP).
 *
 * Overview: status, recent changes, where to go, capability groups, integrations and the dangerous settings. The class name
 * MUST be Modxmcp + Index + ManagerController for MODX 2.3+ namespaced controller
 * autoloading (namespace "modxmcp", action "index").
 *
 * UI strings are lexicon-driven (en + ru), so the panel follows the manager language.
 */
class ModxmcpIndexManagerController extends modExtraManagerController {

    /** @var array the open drafts of resources, read in process() for the page's script block */
    private $drafts = array();
    private $sharePreview = false;

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
            'connector_url' => $assetsUrl . 'connector.php',
            'drafts'        => $this->drafts,
            'share_preview' => $this->sharePreview,
            'dl'            => $this->modx->lexicon->fetch('modxmcp_drafts_', true),
            'l'             => array(
                'copied'          => $this->modx->lexicon('modxmcp_cmp_copied'),
                'copy_failed'     => $this->modx->lexicon('modxmcp_cmp_copy_failed'),
                'features_saving' => $this->modx->lexicon('modxmcp_cmp_features_saving'),
                'features_saved'  => $this->modx->lexicon('modxmcp_cmp_features_saved'),
                'features_failed' => $this->modx->lexicon('modxmcp_cmp_features_failed'),
            ),
        ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $this->addHtml('<script type="text/javascript">var Modxmcp = ' . $cfg . ';</script>');
        // Cache-bust home.js by its mtime so browsers always load the current version.
        $jsFile = $this->modx->getOption('assets_path') . 'components/modxmcp/js/home.js';
        $ver = file_exists($jsFile) ? filemtime($jsFile) : '1';
        $this->addJavascript($assetsUrl . 'js/home.js?v=' . $ver);
    }

    public function process(array $scriptProperties = array()) {
        $this->modx->lexicon->load('modxmcp:default');
        $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
        require_once rtrim($corePath, '/\\') . '/src/autoload.php';
        $lex = function ($key) { return $this->modx->lexicon($key); };

        $overview = array(
            'enabled' => (bool) $this->modx->getOption('modxmcp.enabled'), 'version' => \ModxMCP\Kernel::VERSION, 'endpoint' => '',
            'active_tokens' => 0, 'auto_static' => (bool) $this->modx->getOption('modxmcp.auto_static'),
            'recent' => array(), 'counts_24h' => array('mcp' => 0, 'manager' => 0, 'external' => 0), 'dangerous' => array(),
        );
        $integrations = array();
        try {
            $this->drafts = (new \ModxMCP\Manager\DraftService($this->modx, $this->modx->user))->drafts();
            $this->sharePreview = \ModxMCP\Manager\DraftService::shareEndpoint($this->modx, $_SERVER) !== null;
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[modxmcp] Manager page, drafts: ' . $e->getMessage());
        }
        $components = array();
        $features = array();
        try {
            $overview = (new \ModxMCP\Manager\Overview($this->modx, $this->modx->user))->data();
            $capabilities = new \ModxMCP\Capabilities($this->modx);
            $integrations = $capabilities->integrationsReport();

            // Groups the admin can switch off for the whole site, split into add-ons vs core MODX.
            $disabled = array_flip($capabilities->disabledGroups());
            $addons = array('virtualpage', 'minishop2', 'migx');
            foreach (\ModxMCP\Capabilities::TOGGLEABLE as $g) {
                $row = array('key' => $g, 'label' => $lex('modxmcp_group_' . $g), 'enabled' => isset($disabled[$g]) ? 0 : 1);
                if (in_array($g, $addons, true)) { $components[] = $row; } else { $features[] = $row; }
            }
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[modxmcp] Manager page: ' . $e->getMessage());
        }

        $recent = array();
        foreach ($overview['recent'] as $c) {
            $recent[] = array(
                'id'      => $c['changeset_id'],
                'time'    => date('Y-m-d H:i', $c['at']),
                'source'  => $c['source'],
                'label'   => $lex('modxmcp_history_source_' . $c['source']),
                'author'  => $c['author'],
                'summary' => $c['summary'],
            );
        }
        $counts = array();
        foreach ($overview['counts_24h'] as $source => $n) {
            $counts[] = array('source' => $source, 'label' => $lex('modxmcp_history_source_' . $source), 'n' => (int) $n);
        }
        $dangerous = array();
        foreach ($overview['dangerous'] as $d) {
            $name = substr($d['key'], strlen('modxmcp.'));
            if (!$d['exists']) { $state = $lex('modxmcp_cmp_danger_missing'); $class = 'missing'; }
            elseif ($d['type'] === 'bool') { $state = $lex($d['on'] ? 'modxmcp_cmp_danger_on' : 'modxmcp_cmp_danger_off'); $class = $d['on'] ? 'on' : 'off'; }
            else { $state = $d['value'] !== '' ? $d['value'] : $lex('modxmcp_cmp_danger_any_address'); $class = $d['value'] !== '' ? 'on' : 'off'; }
            $dangerous[] = array('key' => $d['key'], 'state' => $state, 'class' => $class, 'desc' => $lex('modxmcp_cmp_danger_' . $name));
        }

        $labelKeys = array(
            'intro', 'endpoint', 'enabled', 'yes', 'no', 'enable_hint', 'auto_static',
            'on', 'off', 'settings_hint', 'integrations', 'integrations_intro',
            'heading_status', 'version', 'active_tokens', 'no_tokens', 'tokens_link', 'copy',
            'recent_title', 'recent_empty', 'recent_24h', 'recent_all',
            'nav_tokens_desc', 'nav_history_desc', 'nav_graph_desc', 'nav_open',
            'features_title', 'features_intro', 'features_components', 'features_modx', 'features_save',
            'integration_available', 'integration_files_missing', 'integration_not_installed',
            'danger_title', 'danger_intro',
        );
        $l = array();
        foreach ($labelKeys as $k) {
            $l[$k] = $this->modx->lexicon('modxmcp_cmp_' . $k);
        }
        $l['tokens_count'] = str_replace('{n}', (string) $overview['active_tokens'], $this->modx->lexicon('modxmcp_cmp_tokens_count'));
        $l['nav_tokens'] = $this->modx->lexicon('modxmcp_tokens');
        $l['nav_history'] = $this->modx->lexicon('modxmcp_history');
        $l['nav_graph'] = $this->modx->lexicon('modxmcp_graph');
        $l['drafts_title'] = $this->modx->lexicon('modxmcp_drafts_title');

        $this->setPlaceholders(array(
            'l'               => $l,
            'overview'        => $overview,
            'recent'          => $recent,
            'counts'          => $counts,
            'dangerous'       => $dangerous,
            'integrations'    => $integrations,
            'components'      => $components,
            'features'        => $features,
        ));
    }

    public function getTemplateFile() {
        return $this->modx->getOption('core_path') . 'components/modxmcp/templates/home.tpl';
    }
}
