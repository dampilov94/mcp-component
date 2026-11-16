<?php
namespace ModxMCP\Manager;

use ModxMCP\Kernel;

/**
 * The overview of the component's main manager page: is it working, how many tokens are active, what happened
 * recently and which dangerous settings are on. Used by the page and by the getstatus processor.
 */
final class Overview {
    /** Settings that widen what a token can do or narrow who may call; shown read-only. Type: bool or text. */
    const DANGEROUS = array(
        'allow_run'             => 'bool',
        'allow_run_processor'   => 'bool',
        'allow_query_db'        => 'bool',
        'history_track_manager' => 'bool',
        'lint_php'              => 'bool',
        'require_https'         => 'bool',
        'allowed_ips'           => 'text',
    );
    const SOURCES = array('mcp', 'manager', 'external');

    private $modx;
    private $user;

    /** @param \modUser $user the manager user */
    public function __construct(\modX $modx, $user) {
        $this->modx = $modx;
        $this->user = $user;
    }

    public function data() {
        $tokens = new TokenService($this->modx, $this->user);
        $active = 0;
        try {
            foreach ($tokens->tokens() as $token) { if ($token['status'] === 'active') { $active++; } }
        } catch (\Throwable $e) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[modxmcp] Overview, tokens: ' . $e->getMessage());
        }
        $recent = array();
        $counts = array_fill_keys(self::SOURCES, 0);
        try {
            $history = new HistoryService($this->modx, $this->user);
            $recent = $history->changesets(array(), 5)['changesets'];
            foreach (self::SOURCES as $source) {
                $counts[$source] = (int) $history->changesets(array('source' => $source, 'since' => time() - 86400), 1)['total'];
            }
        } catch (\Throwable $e) {
            // File-only deployment without the journal tables: an empty list.
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[modxmcp] Overview, history: ' . $e->getMessage());
        }
        return array(
            'enabled'       => (bool) $this->modx->getOption('modxmcp.enabled'),
            'version'       => Kernel::VERSION,
            'endpoint'      => $tokens->endpoint(),
            'active_tokens' => $active,
            'auto_static'   => (bool) $this->modx->getOption('modxmcp.auto_static'),
            'audit_log'     => (bool) $this->modx->getOption('modxmcp.audit_log'),
            'recent'        => $recent,
            'counts_24h'    => $counts,
            'dangerous'     => $this->dangerous(),
        );
    }

    /** @return array[] key, type (bool|text), exists, on (bool settings) or value (text) */
    public function dangerous() {
        $out = array();
        foreach (self::DANGEROUS as $name => $type) {
            $value = $this->modx->getOption('modxmcp.' . $name, null, null);
            $row = array('key' => 'modxmcp.' . $name, 'type' => $type, 'exists' => $value !== null);
            if ($type === 'bool') {
                $row['on'] = $value !== null && (bool) $value && $value !== 'false' && $value !== '0';
            } else {
                $row['value'] = $value === null ? '' : trim((string) $value);
            }
            $out[] = $row;
        }
        return $out;
    }
}
