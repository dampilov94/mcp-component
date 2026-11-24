<?php
namespace ModxMCP\Journal;

use ModxMCP\Db;
use ModxMCP\DbException;

/**
 * The change journal: one changeset per write call, one change per object in it.
 * Snapshots themselves live in the BlobStore; a change refers to them by revision.
 */
final class Journal {
    private $modx;

    public function __construct(\xPDO $modx) {
        $this->modx = $modx;
    }

    private function t($name) {
        return Db::table($this->modx, $name);
    }

    /** Create the journal tables (MySQL). Safe to call repeatedly. */
    public function install() {
        $statements = array(
            'CREATE TABLE IF NOT EXISTS ' . $this->t('changesets') . ' ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`created_at` INT UNSIGNED NOT NULL,'
                . '`source` VARCHAR(16) NOT NULL,'
                . '`token_id` INT UNSIGNED NULL,'
                . '`user_id` INT UNSIGNED NULL,'
                . '`session_id` VARCHAR(128) NULL,'
                . '`request_id` VARCHAR(128) NULL,'
                . '`tool` VARCHAR(64) NOT NULL DEFAULT \'\','
                . '`summary` VARCHAR(255) NOT NULL DEFAULT \'\','
                . '`status` VARCHAR(16) NOT NULL,'
                . '`reversible` TINYINT(1) NOT NULL DEFAULT 1,'
                . '`undoes` TEXT NULL,'
                . '`undone_by` INT UNSIGNED NULL,'
                . 'PRIMARY KEY (`id`), KEY `created_at` (`created_at`), KEY `session_id` (`session_id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS ' . $this->t('changes') . ' ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`changeset_id` INT UNSIGNED NOT NULL,'
                . '`seq` INT UNSIGNED NOT NULL,'
                . '`kind` VARCHAR(64) NOT NULL,'
                . '`object_key` VARCHAR(190) NOT NULL,'
                . '`label` VARCHAR(255) NOT NULL DEFAULT \'\','
                . '`op` VARCHAR(16) NOT NULL,'
                . '`before_revision` CHAR(64) NULL,'
                . '`after_revision` CHAR(64) NULL,'
                . '`extra_blob` CHAR(64) NULL,'
                . '`reversible` TINYINT(1) NOT NULL DEFAULT 1,'
                . '`note` VARCHAR(255) NOT NULL DEFAULT \'\','
                . 'PRIMARY KEY (`id`), KEY `changeset_id` (`changeset_id`), KEY `object` (`kind`, `object_key`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS ' . $this->t('checkpoints') . ' ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`created_at` INT UNSIGNED NOT NULL,'
                . '`token_id` INT UNSIGNED NULL,'
                . '`session_id` VARCHAR(128) NULL,'
                . '`label` VARCHAR(255) NOT NULL DEFAULT \'\','
                . '`last_changeset_id` INT UNSIGNED NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY (`id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        foreach ($statements as $sql) {
            if ($this->modx->exec($sql) === false) { throw new \RuntimeException('Cannot create the modxMCP journal tables.'); }
        }
    }

    /** Run a query, creating the tables once if they do not exist yet. */
    private function run($sql, array $params = array()) {
        try {
            return Db::run($this->modx, $sql, $params);
        } catch (DbException $e) {
            if (!$e->tableMissing) { throw $e; }
            $this->install();
            return Db::run($this->modx, $sql, $params);
        }
    }

    /**
     * Record a changeset.
     * @param array $meta    source, token_id, user_id, session_id, request_id, tool, summary, undoes (int[])
     * @param array $changes each: kind, object_key, label, op, before_revision, after_revision, extra_blob, reversible, note.
     *                       May be empty: such a changeset only records that something happened (an installed package),
     *                       is never reversible and is skipped by the bulk undo selections.
     * @return int changeset id
     */
    public function commit(array $meta, array $changes) {
        $reversible = $changes ? 1 : 0;
        foreach ($changes as $change) {
            if (empty($change['reversible'])) { $reversible = 0; }
        }
        $get = function ($key, $default = null) use ($meta) { return isset($meta[$key]) ? $meta[$key] : $default; };
        $this->run(
            'INSERT INTO ' . $this->t('changesets')
            . ' (`created_at`,`source`,`token_id`,`user_id`,`session_id`,`request_id`,`tool`,`summary`,`status`,`reversible`,`undoes`)'
            . ' VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            array(
                time(), $get('source', 'mcp'), $get('token_id'), $get('user_id'), $get('session_id'), $get('request_id'),
                substr((string) $get('tool', ''), 0, 64), self::clip($get('summary', '')), 'committed', $reversible,
                $get('undoes') ? implode(',', array_map('intval', $get('undoes'))) : null,
            )
        );
        $id = (int) $this->modx->lastInsertId();
        $seq = 0;
        foreach ($changes as $change) {
            $this->run(
                'INSERT INTO ' . $this->t('changes')
                . ' (`changeset_id`,`seq`,`kind`,`object_key`,`label`,`op`,`before_revision`,`after_revision`,`extra_blob`,`reversible`,`note`)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                array(
                    $id, ++$seq, $change['kind'], (string) $change['object_key'], self::clip($change['label']), $change['op'],
                    $change['before_revision'], $change['after_revision'], isset($change['extra_blob']) ? $change['extra_blob'] : null,
                    empty($change['reversible']) ? 0 : 1, self::clip(isset($change['note']) ? $change['note'] : ''),
                )
            );
        }
        return $id;
    }

    private static function clip($text) {
        $text = (string) $text;
        return function_exists('mb_substr') ? mb_substr($text, 0, 255, 'UTF-8') : substr($text, 0, 255);
    }

    /**
     * Changesets, newest first.
     * @param array $filter session_id, source, kind, object_key, since / until (unix time, inclusive), after_id, ids (int[]), status,
     *                      tool, query (substring of the summary or of an object's name),
     *                      exclude_undos (skip changesets that are themselves undos),
     *                      hidden_kinds (exclude changesets that only contain these restricted kinds),
     *                      with_changes (skip changesets that record no change, e.g. a package install)
     */
    public function changesets(array $filter = array(), $limit = 20) {
        $page = $this->page($filter, $limit, 0, false);
        return $page['changesets'];
    }

    /**
     * One page of changesets, newest first, and how many match in all.
     * @return array ['changesets' => [...], 'total' => int]
     */
    public function page(array $filter, $limit, $start, $withTotal = true) {
        $built = $this->where($filter);
        if ($built === null) { return array('changesets' => array(), 'total' => 0); }
        list($where, $params) = $built;
        $rows = $this->run(
            'SELECT c.* FROM ' . $this->t('changesets') . ' c WHERE ' . implode(' AND ', $where)
            . ' ORDER BY c.`id` DESC LIMIT ' . max(1, min(500, (int) $limit)) . ' OFFSET ' . max(0, (int) $start),
            $params
        )->fetchAll(\PDO::FETCH_ASSOC);
        $total = $withTotal
            ? (int) $this->run('SELECT COUNT(*) FROM ' . $this->t('changesets') . ' c WHERE ' . implode(' AND ', $where), $params)->fetchColumn()
            : 0;
        return array('changesets' => array_map(array($this, 'changesetRow'), $rows), 'total' => $total);
    }

    /** @return array|null [conditions, parameters]; null when the filter can match nothing */
    private function where(array $filter) {
        $where = array('1=1');
        $params = array();
        foreach (array('session_id', 'source', 'status', 'tool') as $column) {
            if (isset($filter[$column])) { $where[] = "c.`{$column}` = ?"; $params[] = $filter[$column]; }
        }
        if (!empty($filter['exclude_undos'])) { $where[] = 'c.`undoes` IS NULL'; }
        if (!empty($filter['with_changes'])) { $where[] = 'EXISTS (SELECT 1 FROM ' . $this->t('changes') . ' w WHERE w.`changeset_id` = c.`id`)'; }
        if (isset($filter['since'])) { $where[] = 'c.`created_at` >= ?'; $params[] = (int) $filter['since']; }
        if (isset($filter['until'])) { $where[] = 'c.`created_at` <= ?'; $params[] = (int) $filter['until']; }
        if (isset($filter['after_id'])) { $where[] = 'c.`id` > ?'; $params[] = (int) $filter['after_id']; }
        if (isset($filter['ids'])) {
            if (!$filter['ids']) { return null; }
            $where[] = 'c.`id` IN (' . implode(',', array_map('intval', $filter['ids'])) . ')';
        }
        $hidden = $filter['hidden_kinds'] ?? (array) ($filter['hidden_kind'] ?? array());
        if ($hidden) {
            if (in_array($filter['kind'] ?? null, $hidden, true)) { return null; }
            $holders = implode(',', array_fill(0, count($hidden), '?'));
            $changes = $this->t('changes');
            $where[] = '(NOT EXISTS (SELECT 1 FROM ' . $changes . ' h WHERE h.`changeset_id` = c.`id` AND h.`kind` IN (' . $holders . '))'
                . ' OR EXISTS (SELECT 1 FROM ' . $changes . ' v WHERE v.`changeset_id` = c.`id` AND v.`kind` NOT IN (' . $holders . ')))';
            $params = array_merge($params, $hidden, $hidden);
        }
        if (isset($filter['kind']) || isset($filter['object_key'])) {
            $sub = 'SELECT 1 FROM ' . $this->t('changes') . ' x WHERE x.`changeset_id` = c.`id`';
            if (isset($filter['kind'])) { $sub .= ' AND x.`kind` = ?'; $params[] = $filter['kind']; }
            if (isset($filter['object_key'])) { $sub .= ' AND x.`object_key` = ?'; $params[] = (string) $filter['object_key']; }
            if ($hidden) { $sub .= ' AND x.`kind` NOT IN (' . $holders . ')'; $params = array_merge($params, $hidden); }
            $where[] = 'EXISTS (' . $sub . ')';
        }
        if (isset($filter['query']) && (string) $filter['query'] !== '') {
            // "!" escapes the LIKE wildcards; the comparison is case-insensitive in MySQL's utf8mb4 collations.
            $like = '%' . str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), (string) $filter['query']) . '%';
            $where[] = '(c.`summary` LIKE ? ESCAPE \'!\' OR EXISTS (SELECT 1 FROM ' . $this->t('changes') . ' q WHERE q.`changeset_id` = c.`id` AND q.`label` LIKE ? ESCAPE \'!\'))';
            $params[] = $like;
            $params[] = $like;
        }
        return array($where, $params);
    }

    /** Checkpoints, newest first. */
    public function checkpoints($limit = 20) {
        $rows = $this->run('SELECT * FROM ' . $this->t('checkpoints') . ' ORDER BY `id` DESC LIMIT ' . max(1, min(200, (int) $limit)))->fetchAll(\PDO::FETCH_ASSOC);
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'id' => (int) $row['id'], 'created_at' => (int) $row['created_at'], 'label' => (string) $row['label'],
                'token_id' => $row['token_id'] === null ? null : (int) $row['token_id'], 'last_changeset_id' => (int) $row['last_changeset_id'],
            );
        }
        return $out;
    }

    /** One changeset with its changes (in recording order), or null. */
    public function changeset($id) {
        $rows = $this->changesets(array('ids' => array((int) $id)), 1);
        if (!$rows) { return null; }
        $rows[0]['changes'] = $this->changes(array((int) $id));
        return $rows[0];
    }

    /** Changes of the given changesets, ordered by changeset then recording order. */
    public function changes(array $changesetIds) {
        if (!$changesetIds) { return array(); }
        $rows = $this->run(
            'SELECT * FROM ' . $this->t('changes') . ' WHERE `changeset_id` IN (' . implode(',', array_map('intval', $changesetIds)) . ')'
            . ' ORDER BY `changeset_id` ASC, `seq` ASC'
        )->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(array($this, 'changeRow'), $rows);
    }

    /** Most recent recorded change of one object, or null: tells who last touched it. */
    public function lastChange($kind, $key) {
        $row = $this->run(
            'SELECT x.*, c.`created_at`, c.`source`, c.`token_id`, c.`user_id`, c.`tool` FROM ' . $this->t('changes') . ' x'
            . ' JOIN ' . $this->t('changesets') . ' c ON c.`id` = x.`changeset_id`'
            . ' WHERE x.`kind` = ? AND x.`object_key` = ? ORDER BY x.`id` DESC LIMIT 1',
            array($kind, (string) $key)
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$row) { return null; }
        return $this->changeRow($row) + array(
            'created_at' => (int) $row['created_at'], 'source' => $row['source'], 'tool' => $row['tool'],
            'token_id' => $row['token_id'] === null ? null : (int) $row['token_id'],
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
        );
    }

    /** Mark changesets undone by another changeset, or (with null) put them back to committed. */
    public function setUndone(array $ids, $undoneBy) {
        if (!$ids) { return; }
        $this->run(
            'UPDATE ' . $this->t('changesets') . ' SET `status` = ?, `undone_by` = ? WHERE `id` IN (' . implode(',', array_map('intval', $ids)) . ')',
            array($undoneBy === null ? 'committed' : 'undone', $undoneBy)
        );
    }

    public function lastChangesetId() {
        return (int) $this->run('SELECT MAX(`id`) FROM ' . $this->t('changesets'))->fetchColumn();
    }

    /** Changesets strictly before the cut-off, oldest first, with stable ordering for ties. */
    public function oldestChangesetIds($olderThan, $limit) {
        return array_map('intval', $this->run(
            'SELECT `id` FROM ' . $this->t('changesets') . ' WHERE `created_at` < ?'
            . ' ORDER BY `created_at` ASC, `id` ASC LIMIT ' . max(1, min(500, (int) $limit)),
            array((int) $olderThan)
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Delete journal rows; an optional cut-off keeps retention's supplied clock consistent. */
    public function deleteChangesets(array $ids, $olderThan = null) {
        if (!$ids) { return 0; }
        $in = implode(',', array_map('intval', $ids));
        $this->run('DELETE FROM ' . $this->t('changes') . ' WHERE `changeset_id` IN (' . $in . ')');
        $count = $this->run('DELETE FROM ' . $this->t('changesets') . ' WHERE `id` IN (' . $in . ')')->rowCount();
        if ($olderThan === null) {
            $days = max(0, (int) $this->modx->getOption('modxmcp.history_retention_days', null, 30));
            $olderThan = $days > 0 ? time() - $days * 86400 : 0;
        }
        if ($olderThan > 0) {
            $first = $this->run('SELECT MIN(`id`) FROM ' . $this->t('changesets'))->fetchColumn();
            // With no changesets left, no checkpoint can anchor retained history.
            $this->run('DELETE FROM ' . $this->t('checkpoints') . ' WHERE `created_at` < ?'
                . ($first === null ? '' : ' AND `last_changeset_id` < ' . (int) $first), array((int) $olderThan));
        }
        return (int) $count;
    }

    /** Set of snapshot hashes still referenced by any change, including delete-only data. */
    public function referencedBlobs() {
        $selects = array();
        foreach (array('before_revision', 'after_revision', 'extra_blob') as $column) {
            $selects[] = 'SELECT `' . $column . '` FROM ' . $this->t('changes') . ' WHERE `' . $column . '` IS NOT NULL';
        }
        return array_fill_keys($this->run(implode(' UNION ', $selects))->fetchAll(\PDO::FETCH_COLUMN), true);
    }

    public function addCheckpoint($label, $tokenId, $sessionId) {
        $last = $this->lastChangesetId();
        $this->run(
            'INSERT INTO ' . $this->t('checkpoints') . ' (`created_at`,`token_id`,`session_id`,`label`,`last_changeset_id`) VALUES (?,?,?,?,?)',
            array(time(), $tokenId, $sessionId, self::clip($label), $last)
        );
        return array('id' => (int) $this->modx->lastInsertId(), 'label' => self::clip($label), 'last_changeset_id' => $last);
    }

    public function checkpoint($id) {
        $row = $this->run('SELECT * FROM ' . $this->t('checkpoints') . ' WHERE `id` = ?', array((int) $id))->fetch(\PDO::FETCH_ASSOC);
        if (!$row) { return null; }
        return array(
            'id' => (int) $row['id'], 'created_at' => (int) $row['created_at'], 'label' => (string) $row['label'],
            'last_changeset_id' => (int) $row['last_changeset_id'],
        );
    }

    private function changesetRow(array $row) {
        $int = function ($value) { return $value === null ? null : (int) $value; };
        $undoes = array();
        foreach (explode(',', (string) $row['undoes']) as $item) {
            if ($item !== '') { $undoes[] = (int) $item; }
        }
        return array(
            'id' => (int) $row['id'], 'created_at' => (int) $row['created_at'], 'source' => (string) $row['source'],
            'token_id' => $int($row['token_id']), 'user_id' => $int($row['user_id']),
            'session_id' => $row['session_id'], 'request_id' => $row['request_id'],
            'tool' => (string) $row['tool'], 'summary' => (string) $row['summary'], 'status' => (string) $row['status'],
            'reversible' => (bool) $row['reversible'], 'undoes' => $undoes, 'undone_by' => $int($row['undone_by']),
        );
    }

    private function changeRow(array $row) {
        return array(
            'id' => (int) $row['id'], 'changeset_id' => (int) $row['changeset_id'], 'seq' => (int) $row['seq'],
            'kind' => (string) $row['kind'], 'object_key' => (string) $row['object_key'], 'label' => (string) $row['label'],
            'op' => (string) $row['op'], 'before_revision' => $row['before_revision'], 'after_revision' => $row['after_revision'],
            'extra_blob' => $row['extra_blob'], 'reversible' => (bool) $row['reversible'], 'note' => (string) $row['note'],
        );
    }
}
