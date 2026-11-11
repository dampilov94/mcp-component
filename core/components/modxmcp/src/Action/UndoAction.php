<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Journal\Undo;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class UndoAction extends WriteAction {
    public function name() { return 'undo'; }

    public function group() { return 'history'; }

    public function destructive() { return true; }

    public function description() {
        return 'Revert changes recorded in the journal: one changeset, the last N, everything from this session, '
            . 'or everything after a checkpoint. Without confirm:true it only returns the plan. An object changed '
            . 'since (e.g. by a person in the manager) is a conflict and stops the undo unless force:true. '
            . 'The undo is itself a changeset, so undoing it re-applies the changes.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'changeset_id'  => Schema::int('Undo this changeset.', array('minimum' => 1)),
            'last'          => Schema::int('Undo the last N changesets made through MCP.', array('minimum' => 1, 'maximum' => 100)),
            'this_session'  => Schema::bool('Undo everything this client session changed.'),
            'to_checkpoint' => Schema::int('Undo everything done through MCP after this checkpoint.', array('minimum' => 1)),
            'confirm'       => Schema::bool('Actually perform the undo. Without it the plan is returned.'),
            'force'         => Schema::bool('Undo even over later changes. Their current state is journaled first.'),
        ));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'changesets'   => Schema::arr(Schema::int()),
            'objects'      => Schema::arr(Schema::obj(array(), array(), true)),
            'conflicts'    => Schema::int(),
            'performed'    => Schema::bool(),
            'changeset_id' => array('type' => array('integer', 'null')),
        ), array('changesets', 'objects', 'conflicts', 'performed'), true);
    }

    public function run(array $args, Context $context) {
        $selectors = array_intersect(array('changeset_id', 'last', 'this_session', 'to_checkpoint'), array_keys(array_filter($args)));
        if (count($selectors) !== 1) {
            throw new ApiException('validation_failed', 'Give exactly one of: changeset_id, last, this_session, to_checkpoint.');
        }
        $journal = $context->journal();
        // Bulk selections never pick up an undo itself; to re-apply, undo that changeset by id.
        $open = array('status' => 'committed', 'exclude_undos' => true);
        if (isset($args['changeset_id'])) {
            $changesets = $journal->changesets(array('ids' => array($args['changeset_id'])), 1);
            if (!$changesets) { throw new ApiException('not_found', "No changeset {$args['changeset_id']}."); }
        } elseif (isset($args['last'])) {
            $changesets = $journal->changesets($open + array('source' => 'mcp'), $args['last']);
        } elseif (!empty($args['this_session'])) {
            $changesets = $journal->changesets($open + array('session_id' => (string) $context->sessionId), 500);
        } else {
            $checkpoint = $journal->checkpoint($args['to_checkpoint']);
            if (!$checkpoint) { throw new ApiException('not_found', "No checkpoint {$args['to_checkpoint']}."); }
            $changesets = $journal->changesets($open + array('source' => 'mcp', 'after_id' => $checkpoint['last_changeset_id']), 500);
        }
        $ids = array_column($changesets, 'id');
        if (!$ids) {
            return array('changesets' => array(), 'objects' => array(), 'conflicts' => 0, 'performed' => false, 'note' => 'Nothing to undo.');
        }
        $byChangeset = array();
        foreach ($journal->changes($ids) as $change) { $byChangeset[$change['changeset_id']][] = $change; }
        foreach ($changesets as $index => $changeset) {
            $changesets[$index]['changes'] = isset($byChangeset[$changeset['id']]) ? $byChangeset[$changeset['id']] : array();
        }

        $plan = Undo::plan($context, $changesets);
        $objects = array();
        foreach ($plan['items'] as $item) {
            $objects[] = array('kind' => $item['kind']->name(), 'id' => $item['key'], 'name' => $item['label'], 'action' => $item['action'])
                + ($item['conflict'] !== null ? array('conflict' => $item['conflict']) : array());
        }
        $summary = array('changesets' => $ids, 'objects' => $objects, 'conflicts' => $plan['conflicts']);

        if (empty($args['confirm']) || !empty($context->token['plan_only'])) {
            return $summary + array('performed' => false, 'note' => $plan['conflicts']
                ? 'Conflicts found. Review them; confirm:true with force:true undoes over the later changes.'
                : 'Plan only. Repeat with confirm:true to perform the undo.');
        }
        if ($plan['conflicts'] && empty($args['force'])) {
            throw new ApiException('undo_conflict', 'Some objects changed after the selected changesets. Nothing was undone.', $summary);
        }

        list(, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $plan, $ids) {
            foreach ($plan['items'] as $item) {
                $tx->touch($item['kind'], $item['key']);
                Snapshot::restore($context->modx, $item['kind'], $item['key'], $item['target'], $item['extra']);
            }
            $cache = $context->modx->getCacheManager();
            if ($cache) { $cache->refresh(); }
            return array('result' => array(), 'summary' => 'undo changesets ' . implode(', ', $ids), 'undoes' => $ids);
        });
        // Undoing an undo re-applies the original changes, so those are live again.
        foreach ($changesets as $changeset) {
            if ($changeset['undoes']) { $journal->setUndone($changeset['undoes'], null); }
        }
        return $summary + array('performed' => true, 'changeset_id' => $info['changeset_id']);
    }
}
