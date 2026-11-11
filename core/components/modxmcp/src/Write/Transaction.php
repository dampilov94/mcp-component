<?php
namespace ModxMCP\Write;

use ModxMCP\Context;
use ModxMCP\Journal\ObjectSubject;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Journal\Subject;
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
        return $this->touchSubject(new ObjectSubject($this->context->modx, $kind, $key));
    }

    /** Lock any subject and remember its current state. Returns that state (null if absent). */
    public function touchSubject(Subject $subject) {
        $id = $subject->kindName() . ':' . $subject->key();
        if (!isset($this->touched[$id])) {
            $this->lock->acquire($id);
            $before = $subject->capture();
            $this->recordOutsideChange($subject, $before);
            $this->touched[$id] = array('subject' => $subject, 'extra' => array(), 'before' => $before);
        }
        return $this->touched[$id]['before'];
    }

    /**
     * The journal knows the state it last left a subject in. If it is in another state now,
     * somebody changed it without the journal (a manager edit with no save event, a file
     * edited over FTP, a direct database query). Record that as a changeset of its own, so
     * it shows in the history and can be undone like any other.
     */
    private function recordOutsideChange(Subject $subject, $current) {
        $journal = $this->context->journal();
        $last = $journal->lastChange($subject->kindName(), $subject->key());
        $revision = Snapshot::revision($current);
        if (!$last || $last['after_revision'] === $revision) { return; }
        $blobs = $this->context->blobs();
        $reversible = $last['after_revision'] === null || $blobs->has($last['after_revision']);
        if ($current !== null && $blobs->put(Snapshot::encode($current)) === null) { $reversible = false; }
        $journal->commit(array(
            'source' => 'external', 'tool' => '', 'summary' => "{$subject->kindName()} {$subject->key()} was changed outside the journal",
        ), array(array(
            'kind' => $subject->kindName(), 'object_key' => $subject->key(),
            'label' => $current !== null ? $subject->label($current) : $last['label'],
            'op' => $last['after_revision'] === null ? 'create' : ($current === null ? 'delete' : 'update'),
            'before_revision' => $last['after_revision'], 'after_revision' => $revision,
            'reversible' => $reversible,
            'note' => 'Detected at the next MCP write; author and exact time are unknown.',
        )));
    }

    /** Register an object that this call has just created. */
    public function created(Kind $kind, $key) {
        $subject = new ObjectSubject($this->context->modx, $kind, $key);
        $id = $subject->kindName() . ':' . $subject->key();
        if (!isset($this->touched[$id])) {
            $this->lock->acquire($id);
            $this->touched[$id] = array('subject' => $subject, 'extra' => array(), 'before' => null);
        }
    }

    /** Before a delete: keep the rows that would be destroyed with the object. */
    public function keepDeleteExtra(Kind $kind, $key) {
        $this->touch($kind, $key);
        $subject = new ObjectSubject($this->context->modx, $kind, $key);
        $this->touched[$subject->kindName() . ':' . $subject->key()]['extra'] = $subject->captureDeleteExtra();
    }

    /**
     * Compare states and journal the differences.
     * @param int[] $undoes changesets this call undoes
     * @return array ['changeset_id' => int|null, 'changes' => [...]]; no changeset when nothing changed
     */
    public function commit($summary, array $undoes = array()) {
        $blobs = $this->context->blobs();
        $rows = array();
        $changes = array();
        foreach ($this->touched as $entry) {
            /** @var Subject $subject */
            $subject = $entry['subject'];
            $before = $entry['before'];
            $after = $subject->capture();
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
            $op = $before === null ? 'create' : ($after === null ? 'delete' : 'update');
            $key = $subject->key();
            $rows[] = array(
                'kind' => $subject->kindName(), 'object_key' => $key, 'op' => $op,
                'label' => $subject->label($after !== null ? $after : $before),
                'before_revision' => $beforeRevision, 'after_revision' => $afterRevision,
                'extra_blob' => $extraBlob, 'reversible' => $reversible, 'note' => $note,
            );
            $changes[] = array(
                'kind' => $subject->kindName(), 'id' => ctype_digit($key) ? (int) $key : $key, 'op' => $op,
                'revision' => $afterRevision, 'before_revision' => $beforeRevision,
                'changed' => $subject->changed($before, $after), 'reversible' => $reversible,
            );
            if ($note !== '') { $this->context->warnings[] = "{$subject->kindName()} {$key}: {$note}"; }
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
        foreach (array_reverse($this->touched) as $entry) {
            $subject = $entry['subject'];
            try {
                if (Snapshot::revision($subject->capture()) !== Snapshot::revision($entry['before'])) {
                    $subject->restore($entry['before'], $entry['extra']);
                }
            } catch (\Throwable $e) {
                $this->context->modx->log(\modX::LOG_LEVEL_ERROR, '[modxmcp] Rollback of ' . $subject->kindName() . ' ' . $subject->key() . ' failed: ' . $e->getMessage());
            }
        }
    }

    public function release() {
        $this->lock->releaseAll();
    }
}
