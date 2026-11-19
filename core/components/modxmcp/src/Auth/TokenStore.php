<?php
namespace ModxMCP\Auth;

/**
 * API tokens. Only the SHA-256 of a token is stored; the plain value is returned once by
 * create(). Tokens are 256-bit random values, so an unsalted hash lookup is sufficient.
 *
 * The table is created on package install and, as a fallback for file-only deployments,
 * on the first request that finds it missing.
 */
final class TokenStore {
    const TOKEN_PREFIX = 'mcp_';
    private const MAX_LABEL_LENGTH = 190;

    private $modx;

    public function __construct(\xPDO $modx) {
        $this->modx = $modx;
    }

    public static function hashToken($token) {
        return hash('sha256', (string) $token);
    }

    /** Syntactic check before any database work; legacy 1.x tokens are plain hex. */
    public static function looksLikeToken($token) {
        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{32,160}$/D', $token);
    }

    private function table() {
        return '`' . str_replace('`', '', (string) $this->modx->getOption('table_prefix', null, '')) . 'modxmcp_tokens`';
    }

    /** Create the table and import the single 1.x token, if one is still configured. */
    public function install() {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $this->table() . ' ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`label` VARCHAR(' . self::MAX_LABEL_LENGTH . ') NOT NULL DEFAULT \'\','
            . '`prefix` VARCHAR(16) NOT NULL DEFAULT \'\','
            . '`hash` CHAR(64) NOT NULL,'
            . '`scope` VARCHAR(16) NOT NULL,'
            . '`plan_only` TINYINT(1) NOT NULL DEFAULT 0,'
            . '`denied_groups` TEXT NULL,'
            . '`expires_at` INT UNSIGNED NULL,'
            . '`last_used_at` INT UNSIGNED NULL,'
            . '`created_by` INT UNSIGNED NOT NULL DEFAULT 0,'
            . '`created_at` INT UNSIGNED NOT NULL,'
            . '`revoked_at` INT UNSIGNED NULL,'
            . 'PRIMARY KEY (`id`), UNIQUE KEY `hash` (`hash`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        if ($this->modx->exec($sql) === false) {
            throw new \RuntimeException('Cannot create the modxMCP token table.');
        }
        $this->importLegacyToken();
    }

    /** 1.x kept one all-powerful token in a system setting; keep it working as an admin token. */
    private function importLegacyToken() {
        $setting = $this->modx->getObject('modSystemSetting', array('key' => 'modxmcp.api_token'));
        if (!$setting) { return; }
        $legacy = trim((string) $setting->get('value'));
        if (self::looksLikeToken($legacy) && !$this->findByHash(self::hashToken($legacy))) {
            $this->insert('Imported 1.x token', $legacy, Scope::ADMIN, false, array(), null, 0);
        }
        // The plain token must not stay readable in system settings once its hash is stored.
        if ($setting->remove()) {
            $this->modx->setOption('modxmcp.api_token', '');
            $cache = $this->modx->getCacheManager();
            if ($cache) { $cache->refresh(array('system_settings' => array())); }
        }
    }

    private function run($sql, array $params = array()) {
        // Code 1 marks "table does not exist" (SQLSTATE 42S02) in either PDO error mode.
        try {
            $statement = $this->modx->prepare($sql);
            if (!$statement || !$statement->execute($params)) {
                $code = $statement ? (string) $statement->errorCode() : '';
                throw new \RuntimeException('Token query failed.', $code === '42S02' ? 1 : 0);
            }
            return $statement;
        } catch (\PDOException $e) {
            throw new \RuntimeException('Token query failed.', (string) $e->getCode() === '42S02' ? 1 : 0);
        }
    }

    /** Run a query, creating the table once if it does not exist yet. */
    private function runInstalled($sql, array $params = array()) {
        try {
            return $this->run($sql, $params);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== 1) { throw $e; }
            $this->install();
            return $this->run($sql, $params);
        }
    }

    private function findByHash($hash) {
        $row = $this->run('SELECT * FROM ' . $this->table() . ' WHERE `hash` = ? LIMIT 1', array($hash))->fetch(\PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }

    private function insert($label, $token, $scope, $planOnly, array $deniedGroups, $expiresAt, $createdBy) {
        $this->run(
            'INSERT INTO ' . $this->table() . ' (`label`,`prefix`,`hash`,`scope`,`plan_only`,`denied_groups`,`expires_at`,`created_by`,`created_at`)'
            . ' VALUES (?,?,?,?,?,?,?,?,?)',
            array(
                $label, substr($token, 0, 8), self::hashToken($token), $scope,
                $planOnly ? 1 : 0, implode(',', $deniedGroups), $expiresAt, (int) $createdBy, time(),
            )
        );
        return (int) $this->modx->lastInsertId();
    }

    /**
     * Create a token. Returns its public record plus `token`, the plain value shown once.
     */
    public function create($label, $scope, $planOnly = false, array $deniedGroups = array(), $expiresAt = null, $createdBy = 0) {
        self::checkLabel($label);
        if (!Scope::valid($scope)) { throw new \InvalidArgumentException('Unknown token scope.'); }
        self::checkGroups($deniedGroups);
        if ($expiresAt !== null && (int) $expiresAt <= time()) { throw new \InvalidArgumentException('Expiry must be in the future.'); }
        $this->runInstalled('SELECT 1 FROM ' . $this->table() . ' LIMIT 1');
        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
        $id = $this->insert($label, $token, $scope, $planOnly, $deniedGroups, $expiresAt === null ? null : (int) $expiresAt, $createdBy);
        $record = $this->publicRecord($this->findByHash(self::hashToken($token)));
        $record['id'] = $id;
        $record['token'] = $token;
        return $record;
    }

    /** Resolve a presented token to its record, or null when unknown, revoked or expired. */
    public function authenticate($token) {
        if (!self::looksLikeToken($token)) { return null; }
        $hash = self::hashToken($token);
        $row = $this->runInstalled('SELECT * FROM ' . $this->table() . ' WHERE `hash` = ? LIMIT 1', array($hash))->fetch(\PDO::FETCH_ASSOC);
        if (!$row || !hash_equals($row['hash'], $hash)) { return null; }
        $now = time();
        if ($row['revoked_at'] !== null) { return null; }
        if ($row['expires_at'] !== null && (int) $row['expires_at'] <= $now) { return null; }
        if (!Scope::valid($row['scope'])) { return null; }
        if ($row['last_used_at'] === null || $now - (int) $row['last_used_at'] >= 60) {
            try {
                $this->run('UPDATE ' . $this->table() . ' SET `last_used_at` = ? WHERE `id` = ?', array($now, (int) $row['id']));
            } catch (\RuntimeException $e) {
                // Usage bookkeeping must not reject an otherwise valid request.
            }
        }
        return $this->publicRecord($row);
    }

    /** All tokens, newest first, without hashes. */
    public function all() {
        $rows = $this->runInstalled('SELECT * FROM ' . $this->table() . ' ORDER BY `id` DESC')->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(array($this, 'publicRecord'), $rows);
    }

    public function revoke($id) {
        $statement = $this->runInstalled(
            'UPDATE ' . $this->table() . ' SET `revoked_at` = ? WHERE `id` = ? AND `revoked_at` IS NULL',
            array(time(), (int) $id)
        );
        return $statement->rowCount() > 0;
    }

    /** One token's public record, or null. */
    public function get($id) {
        $row = $this->runInstalled('SELECT * FROM ' . $this->table() . ' WHERE `id` = ? LIMIT 1', array((int) $id))->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->publicRecord($row) : null;
    }

    /**
     * Change what a token may be used for besides its scope: label, plan_only, denied_groups, expires_at. The scope and
     * the hash never change (rights are raised or lowered by creating a new token); a revoked token cannot be changed.
     * @param array $changes label (string, up to 190 characters), plan_only (bool), denied_groups (string[] of toggleable groups),
     *                       expires_at (a future unix time, or null for no expiry)
     * @return array the public record after the change
     * @throws \InvalidArgumentException for an unknown key, an invalid value, an unknown or revoked token
     */
    public function update($id, array $changes) {
        $sets = array();
        $params = array();
        foreach ($changes as $key => $value) {
            if ($key === 'label') {
                self::checkLabel($value);
                $params[] = $value;
            } elseif ($key === 'plan_only') {
                if (!is_bool($value)) { throw new \InvalidArgumentException('plan_only must be true or false.'); }
                $params[] = $value ? 1 : 0;
            } elseif ($key === 'denied_groups') {
                self::checkGroups($value);
                $params[] = implode(',', array_values(array_unique($value)));
            } elseif ($key === 'expires_at') {
                if ($value !== null && (!is_int($value) || $value <= time())) { throw new \InvalidArgumentException('Expiry must be in the future.'); }
                $params[] = $value;
            } else {
                throw new \InvalidArgumentException('A token field cannot be changed: ' . $key);
            }
            $sets[] = '`' . $key . '` = ?';
        }
        $token = $this->get($id);
        if (!$token) { throw new \InvalidArgumentException('Token not found.'); }
        if ($token['revoked_at'] !== null) { throw new \InvalidArgumentException('A revoked token cannot be changed.'); }
        if ($sets) {
            $params[] = (int) $id;
            $this->runInstalled('UPDATE ' . $this->table() . ' SET ' . implode(', ', $sets) . ' WHERE `id` = ? AND `revoked_at` IS NULL', $params);
        }
        return $this->get($id);
    }

    private static function checkGroups($groups) {
        if (!is_array($groups)) { throw new \InvalidArgumentException('denied_groups must be a list of group names.'); }
        foreach ($groups as $group) {
            if (!is_string($group) || !in_array($group, \ModxMCP\Capabilities::TOGGLEABLE, true)) {
                throw new \InvalidArgumentException('Unknown group: ' . (is_scalar($group) ? $group : gettype($group)));
            }
        }
    }

    private static function checkLabel($label) {
        if (!is_string($label) || !mb_check_encoding($label, 'UTF-8') || mb_strlen($label, 'UTF-8') > self::MAX_LABEL_LENGTH) {
            throw new \InvalidArgumentException('The label must be a UTF-8 string of up to ' . self::MAX_LABEL_LENGTH . ' characters.');
        }
    }

    private function publicRecord(array $row) {
        $groups = array();
        foreach (explode(',', (string) $row['denied_groups']) as $group) {
            $group = trim($group);
            if ($group !== '') { $groups[] = $group; }
        }
        $int = function ($value) { return $value === null ? null : (int) $value; };
        return array(
            'id'            => (int) $row['id'],
            'label'         => (string) $row['label'],
            'prefix'        => (string) $row['prefix'],
            'scope'         => (string) $row['scope'],
            'plan_only'     => (bool) $row['plan_only'],
            'denied_groups' => $groups,
            'expires_at'    => $int($row['expires_at']),
            'last_used_at'  => $int($row['last_used_at']),
            'created_by'    => (int) $row['created_by'],
            'created_at'    => (int) $row['created_at'],
            'revoked_at'    => $int($row['revoked_at']),
        );
    }
}
