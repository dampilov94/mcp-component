<?php
namespace ModxMCP\Write;

use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Kind\Kind;

/**
 * One write call. Every object the call may change is touched first: that takes its lock and
 * records its state. On commit the states are compared and each real change goes to the
 * journal; on failure the touched objects are put back as they were.
 */
final class Transaction {
    private $context;
    private $tool;
    private $lock;
    private $touched = array();

    public function __construct(Context $context, $tool) {
        $this->context = $context;
        $this->tool = $tool;
        $this->lock = new Lock($context->dataDir() . '/locks');
    }

    /** Lock an object and remember its current state. Returns that state (null if absent). */
    public function touch(Kind $kind, $key) {
        $id = $kind->name() . ':' . (int) $key;
        if (!isset($this->touched[$id])) {
            $this->lock->acquire($id);
            $before = Snapshot::capture($this->context->modx, $kind, $key);
            $this->recordOutsideChange($kind, (int) $key, $before);
            $this->touched[$id] = array('kind' => $kind, 'key' => (int) $key, 'extra' => array(), 'before' => $before);
        }
        return $this->touched[$id]['before'];
    }

    /**
     * The journal knows the state it last left an object in. If the object is in another
     * state now, somebody changed it without the journal (a manager edit with no save event,
     * a static file edited over FTP, a direct database query). Record that as a changeset of
     * its own, so it shows in the history and can be undone like any other.
     */
    private function recordOutsideChange(Kind $kind, $key, $current) {
        $journal = $this->context->journal();
        $last = $journal->lastChange($kind->name(), $key);
        $revision = Snapshot::revision($current);
        if (!$last || $last['after_revision'] === $revision) { return; }
        $blobs = $this->context->blobs();
        $reversible = $last['after_revision'] === null || $blobs->has($last['after_revision']);
        if ($current !== null && $blobs->put(Snapshot::encode($current)) === null) { $reversible = false; }
        $nameColumn = $kind->column($kind->nameField());
        $journal->commit(array(
            'source' => 'external', 'tool' => '', 'summary' => "{$kind->name()} {$key} was changed outside the journal",
        ), array(array(
            'kind' => $kind->name(), 'object_key' => $key,
            'label' => $current !== null && isset($current['fields'][$nameColumn]) ? $current['fields'][$nameColumn] : $last['label'],
            'op' => $last['after_revision'] === null ? 'create' : ($current === null ? 'delete' : 'update'),
            'before_revision' => $last['after_revision'], 'after_revision' => $revision,
            'reversible' => $reversible,
            'note' => 'Detected at the next MCP write; author and exact time are unknown.',
        )));
    }

    /** Register an object that this call has just created. */
    public function created(Kind $kind, $key) {
        $id = $kind->name() . ':' . (int) $key;
        if (!isset($this->touched[$id])) {
            $this->lock->acquire($id);
            $this->touched[$id] = array('kind' => $kind, 'key' => (int) $key, 'extra' => array(), 'before' => null);
        }
    }

    /** Before a delete: keep the rows that would be destroyed with the object. */
    public function keepDeleteExtra(Kind $kind, $key) {
        $this->touch($kind, $key);
        $this->touched[$kind->name() . ':' . (int) $key]['extra'] = Snapshot::captureDeleteExtra($this->context->modx, $kind, $key);
    }

    /**
     * Compare states and journal the differences.
     * @param int[] $undoes changesets this call undoes
     * @return array ['changeset_id' => int|null, 'changes' => [...]]; no changeset when nothing changed
     */
    public function commit($summary, array $undoes = array()) {
        $modx = $this->context->modx;
        $blobs = $this->context->blobs();
        $rows = array();
        $changes = array();
        foreach ($this->touched as $entry) {
            /** @var Kind $kind */
            $kind = $entry['kind'];
            $before = $entry['before'];
            $after = Snapshot::capture($modx, $kind, $entry['key']);
            $beforeRevision = Snapshot::revision($before);
            $afterRevision = Snapshot::revision($after);
            if ($beforeRevision === $afterRevision) { continue; }

            $reversible = true;
            $note = '';
            foreach (array($before, $after) as $state) {
                if ($state !== null && $blobs->put(Snapshot::encode($state)) === null) {
                    $reversible = false;
                    $note = 'Snapshot is larger than modxmcp.history_max_blob_bytes; this change cannot be undone.';
                }
            }
            $extraBlob = null;
            if ($after === null && $entry['extra']) {
                $extraBlob = $blobs->put(json_encode($entry['extra'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                if ($extraBlob === null) {
                    $reversible = false;
                    $note = 'Related data is larger than modxmcp.history_max_blob_bytes; this delete cannot be fully undone.';
                }
            }
            $named = $after !== null ? $after : $before;
            $nameColumn = $kind->column($kind->nameField());
            $op = $before === null ? 'create' : ($after === null ? 'delete' : 'update');
            $rows[] = array(
                'kind' => $kind->name(), 'object_key' => $entry['key'], 'op' => $op,
                'label' => isset($named['fields'][$nameColumn]) ? $named['fields'][$nameColumn] : '',
                'before_revision' => $beforeRevision, 'after_revision' => $afterRevision,
                'extra_blob' => $extraBlob, 'reversible' => $reversible, 'note' => $note,
            );
            $changes[] = array(
                'kind' => $kind->name(), 'id' => $entry['key'], 'op' => $op,
                'revision' => $afterRevision, 'before_revision' => $beforeRevision,
                'changed' => Snapshot::changed($kind, $before, $after), 'reversible' => $reversible,
            );
            if ($note !== '') { $this->context->warnings[] = "{$kind->name()} {$entry['key']}: {$note}"; }
        }
        if (!$rows) { return array('changeset_id' => null, 'changes' => array()); }

        $journal = $this->context->journal();
        $id = $journal->commit(array(
            'source' => 'mcp', 'token_id' => $this->context->token['id'], 'session_id' => $this->context->sessionId,
            'request_id' => $this->context->requestId, 'tool' => $this->tool, 'summary' => $summary, 'undoes' => $undoes,
        ), $rows);
        if ($undoes) { $journal->setUndone($undoes, $id); }
        return array('changeset_id' => $id, 'changes' => $changes);
    }

    /** Put every touched object back into the state it had when it was touched. */
    public function rollback() {
        $modx = $this->context->modx;
        foreach (array_reverse($this->touched) as $entry) {
            try {
                $current = Snapshot::capture($modx, $entry['kind'], $entry['key']);
                if (Snapshot::revision($current) !== Snapshot::revision($entry['before'])) {
                    Snapshot::restore($modx, $entry['kind'], $entry['key'], $entry['before'], $entry['extra']);
                }
            } catch (\Throwable $e) {
                $modx->log(\modX::LOG_LEVEL_ERROR, '[modxmcp] Rollback of ' . $entry['kind']->name() . ' ' . $entry['key'] . ' failed: ' . $e->getMessage());
            }
        }
    }

    public function release() {
        $this->lock->releaseAll();
    }
}
