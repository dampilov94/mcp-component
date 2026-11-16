<?php
namespace ModxMCP\Manager;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Auth\TokenStore;
use ModxMCP\Db;
use ModxMCP\DbException;

/**
 * What the manager's "Tokens" page needs. The processors pass its arrays through; every invalid input is an
 * ApiException('validation_failed'). Writes are logged as manager actions with the token id, never with the
 * token value or its hash.
 */
final class TokenService {
    private $modx;
    private $user;
    private $store;

    /** @param \modUser $user the manager user */
    public function __construct(\modX $modx, $user) {
        $this->modx = $modx;
        $this->user = $user;
        $this->store = new TokenStore($modx);
    }

    /** Every token, newest first: the public record plus created_by_name, status and changesets. */
    public function tokens() {
        $counts = $this->changesetCounts();
        $names = array();
        $now = time();
        $out = array();
        foreach ($this->store->all() as $token) {
            $by = $token['created_by'];
            if ($by < 1) {
                $name = '—';
            } else {
                if (!isset($names[$by])) {
                    $user = $this->modx->getObject('modUser', array('id' => $by));
                    $names[$by] = $user ? (string) $user->get('username') : '(deleted user #' . $by . ')';
                }
                $name = $names[$by];
            }
            if ($token['revoked_at'] !== null) { $status = 'revoked'; }
            elseif ($token['expires_at'] !== null && $token['expires_at'] <= $now) { $status = 'expired'; }
            else { $status = 'active'; }
            $out[] = $token + array(
                'created_by_name' => $name,
                'status'          => $status,
                'changesets'      => isset($counts[$token['id']]) ? $counts[$token['id']] : 0,
            );
        }
        return $out;
    }

    /** Journal changesets per token, in one query. A site without journal tables has none. */
    private function changesetCounts() {
        try {
            $rows = Db::run($this->modx, 'SELECT `token_id`, COUNT(*) AS n FROM ' . Db::table($this->modx, 'changesets')
                . ' WHERE `token_id` IS NOT NULL GROUP BY `token_id`')->fetchAll(\PDO::FETCH_ASSOC);
        } catch (DbException $e) {
            if (!$e->tableMissing) { throw $e; }
            return array();
        }
        $counts = array();
        foreach ($rows as $row) { $counts[(int) $row['token_id']] = (int) $row['n']; }
        return $counts;
    }

    /**
     * Create a token.
     * @param array $input label, scope, plan_only, expires_days (0 = never, up to 3650), denied_groups (list or comma-separated)
     * @return array the public record plus `token` (shown once) and `config` (a ready .mcp.json text)
     */
    public function create(array $input) {
        $label = $this->label(isset($input['label']) ? $input['label'] : '');
        $scope = isset($input['scope']) ? (string) $input['scope'] : '';
        if (!Scope::valid($scope)) { throw new ApiException('validation_failed', 'Unknown scope.'); }
        $planOnly = self::flag(isset($input['plan_only']) ? $input['plan_only'] : false);
        $days = self::days(isset($input['expires_days']) ? $input['expires_days'] : 0);
        $groups = self::groups(isset($input['denied_groups']) ? $input['denied_groups'] : array());
        try {
            $record = $this->store->create(
                $label, $scope, $planOnly, $groups, $days > 0 ? time() + $days * 86400 : null,
                $this->user ? (int) $this->user->get('id') : 0
            );
        } catch (\InvalidArgumentException $e) {
            throw new ApiException('validation_failed', $e->getMessage());
        }
        $this->modx->logManagerAction('modxmcp_create_token', 'modxmcp_token', $record['id']);
        $record['config'] = $this->config($record['token']);
        return $record;
    }

    /**
     * Change a token. Only the given keys change; an empty expires_days keeps the expiry, 0 removes it.
     * @param array $input label, plan_only, expires_days, denied_groups (list or comma-separated; empty = none)
     * @return array the public record
     */
    public function update($id, array $input) {
        $changes = array();
        if (array_key_exists('label', $input)) { $changes['label'] = $this->label($input['label']); }
        if (array_key_exists('plan_only', $input)) { $changes['plan_only'] = self::flag($input['plan_only']); }
        if (array_key_exists('denied_groups', $input)) { $changes['denied_groups'] = self::groups($input['denied_groups']); }
        if (array_key_exists('expires_days', $input) && $input['expires_days'] !== '' && $input['expires_days'] !== null) {
            $days = self::days($input['expires_days']);
            $changes['expires_at'] = $days > 0 ? time() + $days * 86400 : null;
        }
        try {
            $record = $this->store->update((int) $id, $changes);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException('validation_failed', $e->getMessage());
        }
        $this->modx->logManagerAction('modxmcp_update_token', 'modxmcp_token', $record['id']);
        return $record;
    }

    public function revoke($id) {
        if (!$this->store->revoke((int) $id)) { throw new ApiException('validation_failed', 'Token not found or already revoked.'); }
        $this->modx->logManagerAction('modxmcp_revoke_token', 'modxmcp_token', (int) $id);
        return array('id' => (int) $id);
    }

    /** The address of the API of this site, as a client has to be configured with it. */
    public function endpoint() {
        $siteUrl = (string) $this->modx->getOption('site_url');
        $assets = (string) $this->modx->getOption('modxmcp.assets_url', null, $this->modx->getOption('assets_url') . 'components/modxmcp/');
        if (preg_match('#^https?://#i', $assets)) {
            $base = $assets;
        } elseif ($assets !== '' && $assets[0] === '/') {
            // assets_url is relative to the host: it already contains the site's own folder, if any.
            $parts = parse_url($siteUrl);
            $origin = isset($parts['host']) ? (isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') : rtrim($siteUrl, '/');
            $base = $origin . $assets;
        } else {
            $base = rtrim($siteUrl, '/') . '/' . $assets;
        }
        return rtrim($base, '/') . '/api.php';
    }

    /** A .mcp.json text for a client of this site. */
    public function config($token) {
        $config = array('mcpServers' => array('modx' => array(
            'command' => 'npx',
            'args'    => array('-y', 'github:dampilov94/mcp-component'),
            'env'     => array('MODX_MCP_SITE_URL' => $this->endpoint(), 'MODX_MCP_TOKEN' => (string) $token),
        )));
        $text = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // json_encode indents with four spaces; a .mcp.json is usually written with two. Strings never contain a line break.
        return preg_replace_callback('/^( {4})+/m', function ($m) { return str_repeat('  ', strlen($m[0]) / 4); }, $text);
    }

    private function label($value) {
        $label = trim((string) $value);
        if ($label === '') { throw new ApiException('validation_failed', 'The token needs a label.'); }
        if (mb_strlen($label, 'UTF-8') > 190) { throw new ApiException('validation_failed', 'The label is longer than 190 characters.'); }
        return $label;
    }

    /** Checkbox values arrive as the strings "true"/"false"; "false" is truthy in PHP. */
    private static function flag($value) {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }

    private static function days($value) {
        if (is_string($value)) { $value = trim($value); }
        if ($value === '' || $value === null) { return 0; }
        if (!(is_int($value) || (is_string($value) && preg_match('/^\d+$/D', $value))) || (int) $value < 0 || (int) $value > 3650) {
            throw new ApiException('validation_failed', 'expires_days must be a whole number from 0 to 3650.');
        }
        return (int) $value;
    }

    private static function groups($value) {
        if (is_string($value)) {
            $value = array_values(array_filter(array_map('trim', explode(',', $value)), function ($group) { return $group !== ''; }));
        }
        if (!is_array($value)) { throw new ApiException('validation_failed', 'denied_groups must be a list of group names.'); }
        return $value;
    }
}
