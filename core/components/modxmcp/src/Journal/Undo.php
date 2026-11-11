<?php
namespace ModxMCP\Journal;

use ModxMCP\ApiException;
use ModxMCP\Context;

/**
 * Plans an undo of one or more changesets. For every object the plan gives the state to go
 * back to (the state before the oldest selected change) and says whether the object is still
 * in the state the newest selected change left it in.
 */
final class Undo {
    /**
     * @param array $changesets journal changesets (any order) with their changes loaded
     * @return array ['items' => [...], 'conflicts' => int]; each item has subject (Subject), key,
     *               label, action (restore|recreate|remove), target (state|null), extra, conflict
     */
    public static function plan(Context $context, array $changesets) {
        usort($changesets, function ($a, $b) { return $b['id'] - $a['id']; });
        $objects = array();
        foreach ($changesets as $changeset) {
            if ($changeset['status'] !== 'committed') {
                throw new ApiException('not_reversible', "Changeset {$changeset['id']} is already undone (status {$changeset['status']}).");
            }
            foreach (array_reverse($changeset['changes']) as $change) {
                if (!$change['reversible']) {
                    throw new ApiException('not_reversible', "Changeset {$changeset['id']} cannot be undone: {$change['kind']} {$change['object_key']} — " . ($change['note'] !== '' ? $change['note'] : 'not reversible.'));
                }
                $id = $change['kind'] . ':' . $change['object_key'];
                if (!isset($objects[$id])) {
                    $objects[$id] = array('newest' => $change, 'oldest' => $change, 'broken' => null);
                } else {
                    // Walking from new to old: this change must have produced the state the next one started from.
                    if ($objects[$id]['oldest']['before_revision'] !== $change['after_revision'] && $objects[$id]['broken'] === null) {
                        $objects[$id]['broken'] = $change['changeset_id'];
                    }
                    $objects[$id]['oldest'] = $change;
                }
            }
        }

        $blobs = $context->blobs();
        $journal = $context->journal();
        $items = array();
        $conflicts = 0;
        foreach ($objects as $entry) {
            $newest = $entry['newest'];
            $oldest = $entry['oldest'];
            $subject = Subjects::forChange($context, $newest['kind'], $newest['object_key'], true);
            $key = $newest['object_key'];
            $current = Snapshot::revision($subject->capture());

            $conflict = null;
            if ($entry['broken'] !== null) {
                $conflict = "another change between the selected changesets also modified it (after changeset {$entry['broken']})";
            } elseif ($current !== $newest['after_revision']) {
                $last = $journal->lastChange($subject->kindName(), $key);
                $conflict = $last && $last['changeset_id'] > $newest['changeset_id']
                    ? "changed later by changeset {$last['changeset_id']} ({$last['source']}, {$last['tool']})"
                    : 'changed outside the journal since this change';
            }
            if ($conflict !== null) { $conflicts++; }

            $target = null;
            if ($oldest['before_revision'] !== null) {
                $json = $blobs->get($oldest['before_revision']);
                if ($json === null) {
                    throw new ApiException('not_reversible', "The stored state of {$subject->kindName()} {$key} is no longer available.");
                }
                $target = json_decode($json, true);
            }
            $extra = array();
            if ($oldest['extra_blob'] !== null) {
                $json = $blobs->get($oldest['extra_blob']);
                if ($json !== null) { $extra = json_decode($json, true); }
            }
            $items[] = array(
                'subject' => $subject, 'key' => $key, 'label' => $newest['label'],
                'action' => $target === null ? 'remove' : ($current === null ? 'recreate' : 'restore'),
                'target' => $target, 'extra' => $extra, 'conflict' => $conflict,
            );
        }
        return array('items' => $items, 'conflicts' => $conflicts);
    }
}
