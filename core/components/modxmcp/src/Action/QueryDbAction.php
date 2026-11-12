<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Schema;

/**
 * Escape hatch: a read-only SELECT against the site database. The checks in guard() are a net for accidents;
 * the boundary is the read-only transaction the statement runs in. Credential tables are refused as a whole,
 * because a query can alias any column to any name.
 */
final class QueryDbAction extends Action {
    const MAX_CELL = 2000;
    const TIMEOUT_MS = 10000;

    /** Unquoted words that never belong in a read-only query (outside string literals). */
    const BANNED = array(
        'insert', 'update', 'delete', 'into', 'merge', 'truncate', 'drop', 'alter', 'create', 'grant', 'revoke', 'rename', 'load',
        'handler', 'lock', 'outfile', 'dumpfile', 'call', 'attach', 'detach', 'pragma', 'vacuum',
    );
    /** Functions with side effects or that wait or read files; refused quoted or not. */
    const FUNCTIONS = array(
        'load_file', 'sleep', 'benchmark', 'get_lock', 'release_lock', 'release_all_locks', 'is_free_lock', 'is_used_lock',
        'master_pos_wait', 'source_pos_wait', 'wait_for_executed_gtid_set', 'wait_until_sql_thread_after_gtids',
        'load_extension', 'readfile', 'writefile', 'pg_sleep',
    );
    /** Tables with credentials or sessions (names without the prefix): refused for every token below admin. */
    const CREDENTIAL_TABLES = array(
        'users', 'user_attributes', 'user_settings', 'system_settings', 'context_settings', 'transport_providers', 'session', 'media_sources',
    );
    /** Refused for every token. */
    const SECRET_TABLES = array('modxmcp_tokens');
    /** Schemas that expose other accounts and other sessions' statements. */
    const SYSTEM_SCHEMAS_ALL = array('mysql', 'performance_schema', 'sys');
    const SYSTEM_SCHEMAS_BELOW_ADMIN = array('information_schema');

    public function name() { return 'query_db'; }
    public function group() { return 'maintenance'; }
    public function scope() { return Scope::DEVELOP; }

    public function unavailableReason(Context $context) {
        return $context->capabilities->settingEnabled('allow_query_db') ? null : 'disabled_by_setting';
    }

    public function description() {
        return 'Escape hatch: run one read-only SELECT (or WITH ... SELECT) against the site database; disabled unless the administrator turns it on. '
            . 'Write {prefix} for the table prefix and use ? with params. Rows are cut at limit (default 100, max 1000); long values are shortened. '
            . 'Tables with credentials are refused (all of them below admin scope). Prefer modx_list / modx_search when they can answer.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'sql'    => Schema::str('One SELECT or WITH ... SELECT statement. {prefix} stands for the table prefix.', array('minLength' => 1, 'maxLength' => 20000)),
            'params' => Schema::arr(array('type' => array('string', 'number', 'boolean', 'null')), 'Values for the ? placeholders, in order.', array('maxItems' => 100)),
            'limit'  => Schema::int('Rows to return (default 100).', array('minimum' => 1, 'maximum' => 1000)),
        ), array('sql'));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'columns'   => Schema::arr(Schema::str()),
            'rows'      => Schema::arr(Schema::arr(array())),
            'returned'  => Schema::int(),
            'truncated' => Schema::bool(),
            'time_ms'   => Schema::int(),
        ), array('columns', 'rows', 'returned', 'truncated', 'time_ms'), true);
    }

    /**
     * Split SQL into tokens, leaving out whitespace and comments. Each token is [type, lowercase value, paren depth] with the
     * types word, qword (backtick), dq (double quotes), str, sym. Refuses what cannot be read with certainty: executable comments, hints,
     * unterminated strings or comments.
     * @param bool $backslash whether a backslash escapes inside string literals (MySQL default; off with NO_BACKSLASH_ESCAPES)
     */
    public static function tokens($sql, $backslash) {
        $tokens = array();
        $length = strlen($sql);
        $depth = 0;
        $i = 0;
        while ($i < $length) {
            $c = $sql[$i];
            $o = ord($c);
            if ($o <= 32) { $i++; continue; }
            if ($c === "'" || $c === '"') {
                $j = $i + 1;
                while (true) {
                    if ($j >= $length) { throw new ApiException('validation_failed', 'The query has an unterminated string literal.'); }
                    if ($backslash && $sql[$j] === '\\') { $j += 2; continue; }
                    if ($sql[$j] === $c) {
                        if ($j + 1 < $length && $sql[$j + 1] === $c) { $j += 2; continue; }
                        break;
                    }
                    $j++;
                }
                // A double-quoted text is an identifier when the server runs in ANSI_QUOTES mode: its content is checked as a name too.
                $tokens[] = $c === '"' ? array('dq', mb_strtolower(trim(str_replace(array('""', '\\'), array('"', ''), substr($sql, $i + 1, $j - $i - 1))), 'UTF-8'), $depth) : array('str', '', $depth);
                $i = $j + 1;
                continue;
            }
            if ($c === '`') {
                $j = $i + 1;
                $name = '';
                while (true) {
                    if ($j >= $length) { throw new ApiException('validation_failed', 'The query has an unterminated quoted name.'); }
                    if ($sql[$j] === '`') {
                        if ($j + 1 < $length && $sql[$j + 1] === '`') { $name .= '`'; $j += 2; continue; }
                        break;
                    }
                    $name .= $sql[$j];
                    $j++;
                }
                $tokens[] = array('qword', mb_strtolower(trim($name), 'UTF-8'), $depth);
                $i = $j + 1;
                continue;
            }
            if ($c === '#' || ($c === '-' && $i + 1 < $length && $sql[$i + 1] === '-' && ($i + 2 >= $length || ord($sql[$i + 2]) <= 32))) {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($c === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                $next = $i + 2 < $length ? $sql[$i + 2] : '';
                if ($next === '!' || $next === '+' || ($next === 'M' && substr($sql, $i + 3, 1) === '!')) {
                    throw new ApiException('validation_failed', 'Executable comments (/*! */) and optimizer hints (/*+ */) are not allowed in the query.');
                }
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) { throw new ApiException('validation_failed', 'The query has an unterminated comment.'); }
                $i = $end + 2;
                continue;
            }
            if (preg_match('/[A-Za-z0-9_$]/', $c) || $o >= 128) {
                $j = $i;
                while ($j < $length && (preg_match('/[A-Za-z0-9_$]/', $sql[$j]) || ord($sql[$j]) >= 128)) { $j++; }
                $tokens[] = array('word', mb_strtolower(substr($sql, $i, $j - $i), 'UTF-8'), $depth);
                $i = $j;
                continue;
            }
            if ($c === '(') { $tokens[] = array('sym', '(', $depth); $depth++; }
            elseif ($c === ')') { $depth = max(0, $depth - 1); $tokens[] = array('sym', ')', $depth); }
            else { $tokens[] = array('sym', $c, $depth); }
            $i++;
        }
        return $tokens;
    }

    /**
     * Check a statement and return its tokens without a final semicolon. Throws validation_failed for what is not one
     * read-only SELECT, and forbidden_scope for credential tables. Runs twice, with and without backslash escapes, and
     * refuses what either reading refuses (the server's SQL mode is not known here).
     * @param string $prefix table prefix, to recognise tables written with or without it
     * @param bool $admin whether the token has the admin scope
     */
    public static function guard($sql, $prefix, $admin) {
        if (strpos($sql, "\0") !== false || !mb_check_encoding($sql, 'UTF-8')) { throw new ApiException('validation_failed', 'The query must be valid UTF-8 text.'); }
        $result = null;
        foreach (array(true, false) as $backslash) {
            $tokens = self::tokens($sql, $backslash);
            $last = count($tokens) - 1;
            foreach ($tokens as $index => $token) {
                if ($token[0] === 'sym' && $token[1] === ';') {
                    if ($index !== $last) { throw new ApiException('validation_failed', 'Only one statement is allowed: a ; may only end the query.'); }
                    array_pop($tokens);
                }
            }
            if (!$tokens || $tokens[0][0] !== 'word' || !in_array($tokens[0][1], array('select', 'with'), true)) {
                throw new ApiException('validation_failed', 'Only a SELECT (or WITH ... SELECT) statement is allowed.');
            }
            $topSelect = false;
            foreach ($tokens as $index => $token) {
                list($type, $value, $depth) = $token;
                $next = isset($tokens[$index + 1]) ? $tokens[$index + 1] : array('', '', 0);
                if ($type === 'word') {
                    if (in_array($value, self::BANNED, true)) { throw new ApiException('validation_failed', "'" . strtoupper($value) . "' is not allowed in a read-only query."); }
                    if ($value === 'for' && $next[0] === 'word' && in_array($next[1], array('share', 'update', 'no', 'key'), true)) {
                        throw new ApiException('validation_failed', 'Locking reads (FOR UPDATE, FOR SHARE) are not allowed.');
                    }
                    if ($value === 'replace' && !($next[0] === 'sym' && $next[1] === '(')) { throw new ApiException('validation_failed', "'REPLACE' is not allowed in a read-only query."); }
                    if ($value === 'select' && $depth === 0) { $topSelect = true; }
                }
                if (($type === 'word' || $type === 'qword') && in_array($value, self::FUNCTIONS, true)) {
                    throw new ApiException('validation_failed', strtoupper($value) . ' is not allowed in the query.');
                }
                if ($type === 'word' || $type === 'qword' || $type === 'dq') {
                    // A quoted name may hold a dotted path ("`db.table`"), so test its parts too.
                    $names = array($value);
                    if ($type !== 'word' && strpos($value, '.') !== false) { $names = array_merge($names, explode('.', $value)); }
                    foreach ($names as $name) { self::checkName($name, $prefix, $admin); }
                }
            }
            if (!$topSelect) { throw new ApiException('validation_failed', 'Only a SELECT (or WITH ... SELECT) statement is allowed.'); }
            $result = $tokens;
        }
        return $result;
    }

    /** Credential tables and system schemas. */
    private static function checkName($name, $prefix, $admin) {
        $prefixes = array_unique(array('', mb_strtolower($prefix, 'UTF-8'), 'modx_'));
        foreach (self::SECRET_TABLES as $table) {
            foreach ($prefixes as $p) {
                if ($name === $p . $table) { throw new ApiException('forbidden_scope', "The table {$table} holds API token hashes; no token may query it."); }
            }
        }
        if (in_array($name, self::SYSTEM_SCHEMAS_ALL, true)) { throw new ApiException('forbidden_scope', "The schema {$name} is not available to modx_query_db."); }
        if ($admin) { return; }
        if (in_array($name, self::SYSTEM_SCHEMAS_BELOW_ADMIN, true)) { throw new ApiException('forbidden_scope', "The schema {$name} needs the admin scope."); }
        foreach (self::CREDENTIAL_TABLES as $table) {
            foreach ($prefixes as $p) {
                if ($name === $p . $table) { throw new ApiException('forbidden_scope', "The table {$p}{$table} holds credentials, sessions or settings that may be secret; querying it needs the admin scope."); }
            }
        }
    }

    private static function cell($value) {
        if ($value === null || is_bool($value) || is_int($value)) { return $value; }
        if (is_float($value)) { return is_finite($value) ? $value : (string) $value; }
        if (is_resource($value)) { $value = stream_get_contents($value); }
        $text = (string) $value;
        if (!mb_check_encoding($text, 'UTF-8')) { return '[binary ' . strlen($text) . ' bytes]'; }
        if (mb_strlen($text, 'UTF-8') > self::MAX_CELL) { return mb_substr($text, 0, self::MAX_CELL, 'UTF-8') . '…'; }
        return $text;
    }

    private static function message($info, $default) {
        return is_array($info) && isset($info[2]) && (string) $info[2] !== '' ? (string) $info[2] : $default;
    }

    /** Run a statement that is not part of the query itself (session setup); a failure is returned, not thrown. */
    private static function attempt(\xPDO $modx, $sql) {
        try { return $modx->exec($sql) !== false; }
        catch (\Throwable $e) { return false; }
    }

    public function run(array $args, Context $context) {
        $modx = $context->modx;
        $prefix = (string) $modx->getOption('table_prefix', null, '');
        if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) { throw new ApiException('validation_failed', 'The table prefix of this site has unusual characters; modx_query_db cannot be used.'); }
        $sql = str_replace('{prefix}', $prefix, (string) $args['sql']);
        $admin = Scope::allows($context->token['scope'], Scope::ADMIN);
        $tokens = self::guard($sql, $prefix, $admin);
        $sql = rtrim(rtrim($sql), ';');  // the guard allowed at most one ; and only at the very end (comments may follow it)
        $params = isset($args['params']) ? array_values($args['params']) : array();
        $placeholders = count(array_filter($tokens, function ($token) { return $token[0] === 'sym' && $token[1] === '?'; }));
        if ($placeholders !== count($params)) {
            throw new ApiException('validation_failed', "The query has {$placeholders} ? placeholder" . ($placeholders === 1 ? '' : 's') . ' but params has ' . count($params) . ' value' . (count($params) === 1 ? '' : 's') . '.');
        }
        $limit = isset($args['limit']) ? (int) $args['limit'] : 100;
        $budget = max(1024, (int) ($modx->getOption('modxmcp.max_response_bytes', null, 4194304) / 2));

        $pdo = isset($modx->pdo) ? $modx->pdo : null;
        $driver = 'unknown';
        try { if ($pdo) { $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME); } } catch (\Throwable $e) { /* unknown */ }
        $mysql = $driver === 'mysql';
        $inTransaction = false;
        $readOnly = false;
        $timeout = null;
        $buffered = null;
        $statement = null;
        $started = microtime(true);
        try {
            if ($mysql) {
                // The boundary: whatever the statement is, the server refuses to write in a read-only transaction.
                if (!self::attempt($modx, 'SET SESSION TRANSACTION READ ONLY')) { throw new ApiException('validation_failed', 'Cannot start a read-only transaction; the query was not run.'); }
                $readOnly = true;
                if (!self::attempt($modx, 'START TRANSACTION')) { throw new ApiException('validation_failed', 'Cannot start a read-only transaction; the query was not run.'); }
                $inTransaction = true;
                try {
                    $row = $modx->query('SELECT @@SESSION.max_execution_time');
                    $current = $row ? $row->fetchColumn() : false;
                    if ($current !== false && self::attempt($modx, 'SET SESSION max_execution_time = ' . self::TIMEOUT_MS)) { $timeout = (int) $current; }
                } catch (\Throwable $e) { /* a server without max_execution_time (MariaDB, MySQL < 5.7.8) runs without it */ }
                if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                    // Rows are read one by one, so a huge result is not held in memory.
                    $buffered = $pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
                    $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                }
            }
            // xPDO connections are usually in silent error mode: a failure is a false return, with the message in errorInfo().
            $statement = $modx->prepare($sql);
            if (!$statement) { throw new \PDOException(self::message($pdo ? $pdo->errorInfo() : array(), 'The statement could not be prepared.')); }
            foreach ($params as $position => $value) {
                if ($value === null) { $bound = $statement->bindValue($position + 1, null, \PDO::PARAM_NULL); }
                elseif (is_bool($value) || is_int($value)) { $bound = $statement->bindValue($position + 1, (int) $value, \PDO::PARAM_INT); }
                else { $bound = $statement->bindValue($position + 1, (string) $value, \PDO::PARAM_STR); }
                if (!$bound) { throw new \PDOException(self::message($statement->errorInfo(), 'There are more params than ? placeholders.')); }
            }
            if (!$statement->execute()) { throw new \PDOException(self::message($statement->errorInfo(), 'The statement failed (are there as many params as ? placeholders?).')); }
            $columns = array();
            for ($i = 0, $n = $statement->columnCount(); $i < $n; $i++) {
                $meta = $statement->getColumnMeta($i);
                $columns[] = is_array($meta) && isset($meta['name']) ? (string) $meta['name'] : (string) $i;
            }
            $rows = array();
            $truncated = false;
            $bytes = 0;
            while (($row = $statement->fetch(\PDO::FETCH_NUM)) !== false) {
                if (count($rows) >= $limit) { $truncated = true; break; }
                $row = array_map(array(self::class, 'cell'), $row);
                $bytes += strlen((string) json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
                if ($bytes > $budget && $rows) { $truncated = true; break; }
                $rows[] = $row;
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\PDOException $e) {
            throw new ApiException('validation_failed', 'Database error: ' . trim($e->getMessage()));
        } finally {
            if ($statement) { try { $statement->closeCursor(); } catch (\Throwable $e) { /* nothing to close */ } }
            if ($mysql) {
                if ($inTransaction) { self::attempt($modx, 'ROLLBACK'); }
                if ($readOnly) { self::attempt($modx, 'SET SESSION TRANSACTION READ WRITE'); }
                if ($timeout !== null) { self::attempt($modx, 'SET SESSION max_execution_time = ' . $timeout); }
                if ($buffered !== null) { try { $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered); } catch (\Throwable $e) { /* keep going */ } }
            }
        }
        if ($truncated) { $context->warnings[] = 'The result was cut; narrow the query or raise limit (at most 1000).'; }
        return array('columns' => $columns, 'rows' => $rows, 'returned' => count($rows), 'truncated' => $truncated,
            'time_ms' => (int) round((microtime(true) - $started) * 1000));
    }
}
