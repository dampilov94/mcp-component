<?php
namespace ModxMCP\Draft;

use ModxMCP\Db;
use ModxMCP\DbException;
use ModxMCP\Context;

/**
 * The links between a resource and its draft (modxmcp_drafts), in plain SQL like the journal.
 *
 * The table is not journaled. It is kept consistent with the resources, which are: a link whose draft (or original) is gone is
 * dropped by reconcile(), and when the draft was removed by a journaled change (apply, discard, an undo), the link is kept in
 * modxmcp_draft_archive under the changeset that removed it. Undoing that changeset brings the draft resource back, and
 * reconcile() then moves the link back from the archive. Every lookup checks that both resources exist, so a link that has not
 * been reconciled yet is never believed.
 */
final class DraftStore {
    private $modx;

    public function __construct(\xPDO $modx) {
        $this->modx = $modx;
    }

    private function t($name) {
        return Db::table($this->modx, $name);
    }

    /** Create the tables (MySQL). Safe to call repeatedly. */
    public function install() {
        $statements = array(
            'CREATE TABLE IF NOT EXISTS ' . $this->t('drafts') . ' ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`original_id` INT UNSIGNED NOT NULL,'
                . '`draft_id` INT UNSIGNED NOT NULL,'
                . '`created_at` INT UNSIGNED NOT NULL,'
                . '`token_id` INT UNSIGNED NULL,'
                . '`user_id` INT UNSIGNED NULL,'
                . '`base_revision` CHAR(64) NOT NULL,'
                . '`base_state` MEDIUMTEXT NULL,'
                . 'PRIMARY KEY (`id`), UNIQUE KEY `draft_id` (`draft_id`), KEY `original_id` (`original_id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS ' . $this->t('draft_archive') . ' ('
                . '`changeset_id` INT UNSIGNED NOT NULL,'
                . '`draft_id` INT UNSIGNED NOT NULL,'
                . '`original_id` INT UNSIGNED NOT NULL,'
                . '`created_at` INT UNSIGNED NOT NULL,'
                . '`token_id` INT UNSIGNED NULL,'
                . '`user_id` INT UNSIGNED NULL,'
                . '`base_revision` CHAR(64) NOT NULL,'
                . '`base_state` MEDIUMTEXT NULL,'
                . 'PRIMARY KEY (`changeset_id`, `draft_id`), KEY `draft_id` (`draft_id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        foreach ($statements as $sql) {
            if ($this->modx->exec($sql) === false) { throw new \RuntimeException('Cannot create the modxMCP draft tables.'); }
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

    private function exists($id) {
        return $this->modx->getCount('modResource', array('id' => (int) $id)) > 0;
    }

    private static function row(array $row) {
        return array(
            'original_id' => (int) $row['original_id'], 'draft_id' => (int) $row['draft_id'], 'created_at' => (int) $row['created_at'],
            'token_id' => $row['token_id'] === null ? null : (int) $row['token_id'],
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
            'base_revision' => (string) $row['base_revision'],
            'base_state' => $row['base_state'] === null || $row['base_state'] === '' ? null : json_decode($row['base_state'], true),
        );
    }

    /** Every open draft (both resources exist), oldest first. */
    public function all($tokenId = null) {
        $out = array();
        $where = $tokenId === null ? '' : ' WHERE `token_id` = ? AND `user_id` IS NULL';
        $params = $tokenId === null ? array() : array((int) $tokenId);
        foreach ($this->run('SELECT * FROM ' . $this->t('drafts') . $where . ' ORDER BY `id` ASC', $params)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($this->exists($row['draft_id']) && $this->exists($row['original_id'])) { $out[] = self::row($row); }
        }
        return $out;
    }

    /** The open draft of an original, or null. */
    public function ofOriginal($originalId) {
        foreach ($this->run('SELECT * FROM ' . $this->t('drafts') . ' WHERE `original_id` = ? ORDER BY `id` ASC', array((int) $originalId))->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($this->exists($row['draft_id']) && $this->exists($row['original_id'])) { return self::row($row); }
        }
        return null;
    }

    /** The link of a draft resource, or null. */
    public function ofDraft($draftId) {
        $row = $this->run('SELECT * FROM ' . $this->t('drafts') . ' WHERE `draft_id` = ?', array((int) $draftId))->fetch(\PDO::FETCH_ASSOC);
        return $row && $this->exists($row['draft_id']) && $this->exists($row['original_id']) ? self::row($row) : null;
    }

    /** The link a resource takes part in, as the original or as the draft, or null. */
    public function linkOf($resourceId) {
        $link = $this->ofDraft($resourceId);
        return $link ? $link : $this->ofOriginal($resourceId);
    }

    public function isDraft($resourceId) {
        return $this->ofDraft($resourceId) !== null;
    }

    /** @return array [id => ['draft_of' => original id] | ['draft_id' => draft id]] for every open draft */
    public function marks() {
        $marks = array();
        foreach ($this->all() as $link) {
            $marks[$link['draft_id']] = array('draft_of' => $link['original_id']);
            $marks[$link['original_id']] = array('draft_id' => $link['draft_id']);
        }
        return $marks;
    }

    /** Ids of the draft resources. */
    public function draftIds() {
        $ids = array();
        foreach ($this->all() as $link) { $ids[$link['draft_id']] = true; }
        return $ids;
    }

    public function add($originalId, $draftId, $createdAt, $tokenId, $userId, $baseRevision, array $baseState = null) {
        $this->run('DELETE FROM ' . $this->t('drafts') . ' WHERE `draft_id` = ?', array((int) $draftId));
        // The id is the draft's from now on, whatever an older resource of that id left behind.
        $this->run('DELETE FROM ' . $this->t('draft_archive') . ' WHERE `draft_id` = ?', array((int) $draftId));
        $this->run(
            'INSERT INTO ' . $this->t('drafts') . ' (`original_id`,`draft_id`,`created_at`,`token_id`,`user_id`,`base_revision`,`base_state`) VALUES (?,?,?,?,?,?,?)',
            array((int) $originalId, (int) $draftId, (int) $createdAt, $tokenId, $userId, (string) $baseRevision,
                $baseState === null ? null : json_encode($baseState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        );
    }

    /**
     * Bring the table in line with the resources. Call after anything that can add or remove a draft resource (apply, discard,
     * an undo). Failures are logged and never thrown: the change itself is done.
     */
    public function reconcile(Context $context) {
        try {
            $this->dropGone($context);
            $this->restoreBack($context);
            $this->pruneArchive($context);
        } catch (\Throwable $e) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[modxmcp] Could not reconcile the draft links: ' . $e->getMessage());
        }
    }

    /** A link whose draft is gone is archived under the changeset that removed the draft (when the journal knows it), then dropped. */
    private function dropGone(Context $context) {
        foreach ($this->run('SELECT * FROM ' . $this->t('drafts'))->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $draftGone = !$this->exists($row['draft_id']);
            if ($this->exists($row['original_id']) && !$draftGone) { continue; }
            if ($draftGone) {
                $last = $context->journal()->lastChange('resource', (string) (int) $row['draft_id']);
                if ($last && $last['op'] === 'delete') {
                    $this->run('DELETE FROM ' . $this->t('draft_archive') . ' WHERE `changeset_id` = ? AND `draft_id` = ?', array($last['changeset_id'], (int) $row['draft_id']));
                    $this->run(
                        'INSERT INTO ' . $this->t('draft_archive') . ' (`changeset_id`,`draft_id`,`original_id`,`created_at`,`token_id`,`user_id`,`base_revision`,`base_state`) VALUES (?,?,?,?,?,?,?,?)',
                        array($last['changeset_id'], (int) $row['draft_id'], (int) $row['original_id'], (int) $row['created_at'], $row['token_id'], $row['user_id'], $row['base_revision'], $row['base_state'])
                    );
                }
            }
            $this->run('DELETE FROM ' . $this->t('drafts') . ' WHERE `id` = ?', array((int) $row['id']));
        }
    }

    /**
     * A draft resource that exists again because an undo undid the changeset that removed it gets its link from the archive,
     * unless the original has another draft by now. A changeset that has not been undone does not count: the id of a removed
     * resource can be given to a new one.
     */
    private function restoreBack(Context $context) {
        $rows = $this->run('SELECT * FROM ' . $this->t('draft_archive') . ' ORDER BY `changeset_id` DESC')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (!$this->exists($row['draft_id'])) { continue; }
            if (!$this->exists($row['original_id'])) { continue; }
            $changeset = $context->journal()->changesets(array('ids' => array((int) $row['changeset_id'])), 1);
            if (!$changeset || $changeset[0]['status'] !== 'undone') { continue; }
            if ($this->ofDraft($row['draft_id']) !== null || $this->ofOriginal($row['original_id']) !== null) { continue; }
            $this->add($row['original_id'], $row['draft_id'], $row['created_at'], $row['token_id'], $row['user_id'], $row['base_revision'],
                $row['base_state'] === null || $row['base_state'] === '' ? null : json_decode($row['base_state'], true));
            $this->run('DELETE FROM ' . $this->t('draft_archive') . ' WHERE `draft_id` = ?', array((int) $row['draft_id']));
        }
    }

    /** Archived links whose changeset the retention has removed can never be restored. */
    private function pruneArchive(Context $context) {
        $ids = array_map('intval', $this->run('SELECT DISTINCT `changeset_id` FROM ' . $this->t('draft_archive'))->fetchAll(\PDO::FETCH_COLUMN));
        if (!$ids) { return; }
        $alive = array_column($context->journal()->changesets(array('ids' => $ids), count($ids)), 'id');
        foreach (array_diff($ids, array_map('intval', $alive)) as $gone) {
            $this->run('DELETE FROM ' . $this->t('draft_archive') . ' WHERE `changeset_id` = ?', array($gone));
        }
    }
}
