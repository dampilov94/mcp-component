<?php
namespace ModxMCP\Action;

use ModxMCP\Context;
use ModxMCP\Schema;

final class HistoryAction extends Action {
    public function name() { return 'history'; }

    public function group() { return 'history'; }

    public function description() {
        return 'Journal of changes made to the site, newest first. Each changeset is one write call and can be '
            . 'inspected with modx_history_diff or reverted with modx_undo. Filter by session, kind, object or time.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'this_session' => Schema::bool('Only changes made since this MCP client was started.'),
            'source'       => array('type' => 'string', 'enum' => array('mcp', 'manager', 'external'), 'description' => 'Who made the change.'),
            'kind'         => Schema::str('Only changesets touching this kind.', array('pattern' => '^[a-z][a-z0-9_]{0,63}$')),
            'id'           => Schema::int('With kind: only this object.', array('minimum' => 1)),
            'since'        => Schema::int('Unix time; only newer changesets.', array('minimum' => 0)),
            'limit'        => Schema::int('Default 20.', array('minimum' => 1, 'maximum' => 100)),
        ));
    }

    public function outputSchema() {
        return Schema::obj(array('changesets' => Schema::arr(Schema::obj(array(), array(), true))), array('changesets'), true);
    }

    public function run(array $args, Context $context) {
        $filter = array();
        if (!empty($args['this_session'])) { $filter['session_id'] = (string) $context->sessionId; }
        foreach (array('source', 'kind', 'since') as $key) {
            if (isset($args[$key])) { $filter[$key] = $args[$key]; }
        }
        if (isset($args['kind'], $args['id'])) { $filter['object_key'] = (string) $args['id']; }
        $journal = $context->journal();
        $changesets = $journal->changesets($filter, isset($args['limit']) ? $args['limit'] : 20);
        $byChangeset = array();
        foreach ($journal->changes(array_column($changesets, 'id')) as $change) {
            $byChangeset[$change['changeset_id']][] = array(
                'kind' => $change['kind'], 'id' => (int) $change['object_key'], 'name' => $change['label'], 'op' => $change['op'],
            );
        }
        $out = array();
        foreach ($changesets as $changeset) {
            $out[] = array(
                'changeset_id' => $changeset['id'],
                'at'           => $changeset['created_at'],
                'time'         => date('c', $changeset['created_at']),
                'source'       => $changeset['source'],
                'tool'         => $changeset['tool'],
                'summary'      => $changeset['summary'],
                'status'       => $changeset['status'],
                'reversible'   => $changeset['reversible'],
                'this_session' => $context->sessionId !== null && $changeset['session_id'] === $context->sessionId,
                'objects'      => isset($byChangeset[$changeset['id']]) ? $byChangeset[$changeset['id']] : array(),
            ) + ($changeset['undoes'] ? array('undoes' => $changeset['undoes']) : array())
              + ($changeset['undone_by'] !== null ? array('undone_by' => $changeset['undone_by']) : array());
        }
        return array('changesets' => $out);
    }
}
