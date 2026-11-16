<?php
namespace ModxMCP\Manager;

use ModxMCP\Action\HistoryAction;
use ModxMCP\Action\HistoryDiffAction;
use ModxMCP\Action\UndoAction;
use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Journal\Authors;
use ModxMCP\Schema;

/**
 * What the manager's "History" page needs, shaped like the results of the API tools. It runs the
 * same actions as the API (the undo is UndoAction itself), so every guard of the API applies to a
 * person in the manager too. Changes made here are journaled as source "manager".
 */
final class HistoryService {
    const SOURCES = array('mcp', 'manager', 'external');

    private $modx;
    private $user;
    private $context = null;

    /** @param \modUser $user the manager user */
    public function __construct(\modX $modx, $user) {
        $this->modx = $modx;
        $this->user = $user;
    }

    /** The context this service acts in (the manager user's; its changes are journaled as the manager's). */
    public function context() {
        if ($this->context === null) {
            $corePath = $this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/');
            $this->context = Context::forManager($this->modx, $this->user, $corePath);
        }
        return $this->context;
    }

    /**
     * Changesets, newest first.
     * @param array $filter source, kind, id / key (an object of that kind or, without kind, of any), tool, status, since, until, query
     * @return array the entries of modx_history plus `total` (all matches) and `author`
     */
    public function changesets(array $filter, $limit = 50, $start = 0) {
        $where = array();
        foreach (array('source', 'kind', 'tool', 'status', 'query') as $key) {
            if (isset($filter[$key]) && (string) $filter[$key] !== '') { $where[$key] = (string) $filter[$key]; }
        }
        foreach (array('since', 'until') as $key) {
            if (isset($filter[$key]) && $filter[$key] !== '' && (int) $filter[$key] > 0) { $where[$key] = (int) $filter[$key]; }
        }
        foreach (array('id', 'key') as $key) {
            if (isset($filter[$key]) && (string) $filter[$key] !== '') { $where['object_key'] = (string) $filter[$key]; }
        }
        if (isset($where['source']) && !in_array($where['source'], self::SOURCES, true)) {
            throw new ApiException('validation_failed', 'Unknown source.');
        }
        if (isset($where['status']) && !in_array($where['status'], array('committed', 'undone'), true)) {
            throw new ApiException('validation_failed', 'Unknown status.');
        }
        $context = $this->context();
        $page = $context->journal()->page($where, max(1, min(100, (int) $limit)), max(0, (int) $start));
        return array('changesets' => HistoryAction::entries($context, $page['changesets']), 'total' => $page['total']);
    }

    /**
     * What one changeset changed: the result of modx_history_diff plus `author`, `reversible`, `undone_by`
     * and, when it cannot be undone, `not_reversible` (no_changes | blocked) with the `notes` of the journal.
     */
    public function changeset($id) {
        $context = $this->context();
        $diff = (new HistoryDiffAction())->run(array('changeset_id' => (int) $id), $context);
        $changeset = $context->journal()->changeset((int) $id);
        $diff['author'] = (new Authors($this->modx))->name($changeset);
        $diff['reversible'] = $changeset['reversible'];
        $diff['undone_by'] = $changeset['undone_by'];
        if ($changeset['undoes']) { $diff['undoes'] = $changeset['undoes']; }
        if (!$changeset['reversible']) {
            $notes = array();
            foreach ($changeset['changes'] as $change) {
                if (!$change['reversible'] && $change['note'] !== '') { $notes[] = $change['kind'] . ' ' . $change['label'] . ': ' . $change['note']; }
            }
            $diff['not_reversible'] = $changeset['changes'] ? 'blocked' : 'no_changes';
            $diff['notes'] = $notes;
        }
        return $diff;
    }

    /**
     * The behaviour of modx_undo for one changeset or for everything after a checkpoint.
     * @param array $selector changeset_id or to_checkpoint
     */
    public function undo(array $selector, $confirm = false, $force = false) {
        $args = array();
        foreach (array('changeset_id', 'to_checkpoint') as $key) {
            if (isset($selector[$key]) && $selector[$key] !== '') { $args[$key] = (int) $selector[$key]; }
        }
        if (count($args) !== 1) { throw new ApiException('validation_failed', 'Give exactly one of: changeset_id, to_checkpoint.'); }
        if ($confirm) { $args['confirm'] = true; }
        if ($force) { $args['force'] = true; }
        $action = new UndoAction();
        $invalid = Schema::validate($action->inputSchema(), json_decode(json_encode($args)));
        if ($invalid !== null) { throw new ApiException('validation_failed', $invalid); }
        return $action->run($args, $this->context());
    }

    /**
     * Checkpoints, newest first. `after` counts the MCP changesets recorded after each one, `undoable_after`
     * those a rollback to it would undo (still committed, not undos themselves, with changes).
     */
    public function checkpoints($limit = 20) {
        $context = $this->context();
        $journal = $context->journal();
        $authors = new Authors($this->modx);
        $out = array();
        foreach ($journal->checkpoints($limit) as $checkpoint) {
            $after = array('source' => 'mcp', 'after_id' => $checkpoint['last_changeset_id']);
            $out[] = array(
                'checkpoint_id' => $checkpoint['id'], 'at' => $checkpoint['created_at'], 'time' => date('c', $checkpoint['created_at']),
                'label' => $checkpoint['label'], 'author' => $authors->token($checkpoint['token_id']),
                'after' => $journal->page($after, 1, 0)['total'],
                'undoable_after' => $journal->page($after + array('status' => 'committed', 'exclude_undos' => true, 'with_changes' => true), 1, 0)['total'],
            );
        }
        return array('checkpoints' => $out);
    }

    /**
     * An error for the page: its message and, for an API error, the code and details (a refused undo lists
     * the conflicting objects in `details`). Other errors are reported with their message only.
     * @return array [message, object]
     */
    public static function errorReport(\Throwable $e) {
        if ($e instanceof ApiException) {
            return array($e->getMessage(), array('code' => $e->errorCode, 'details' => $e->details ? $e->details : new \stdClass()));
        }
        return array($e->getMessage(), array('code' => 'internal_error', 'details' => new \stdClass()));
    }
}
