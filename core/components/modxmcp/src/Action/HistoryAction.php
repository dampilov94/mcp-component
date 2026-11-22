<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Journal\Authors;
use ModxMCP\Journal\Subjects;
use ModxMCP\Kind\ClientConfigSettingKind;
use ModxMCP\Schema;

final class HistoryAction extends Action {
    const RESTRICTED_SUMMARY = 'Changeset includes restricted entries.';
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
            'id'           => Schema::int('With kind: only this numeric object ID.', array('minimum' => 1)),
            'key'          => Schema::str('With kind: only this object key. Use instead of id.', array('minLength' => 1, 'maxLength' => 255)),
            'since'        => Schema::int('Unix time; only newer changesets.', array('minimum' => 0)),
            'limit'        => Schema::int('Default 20.', array('minimum' => 1, 'maximum' => 100)),
        ));
    }

    public function outputSchema() {
        return Schema::obj(array('changesets' => Schema::arr(Schema::obj(array(), array(), true))), array('changesets'), true);
    }

    public function run(array $args, Context $context) {
        if (isset($args['key']) && (!isset($args['kind']) || isset($args['id']))) {
            throw new ApiException('validation_failed', 'Use key with kind and without id.');
        }
        $filter = array();
        if (!empty($args['this_session'])) { $filter['session_id'] = (string) $context->sessionId; }
        foreach (array('source', 'kind', 'since') as $key) {
            if (isset($args[$key])) { $filter[$key] = $args[$key]; }
        }
        if (!self::changeVisible($context, array('kind' => ClientConfigSettingKind::NAME))) {
            $filter['hidden_kind'] = ClientConfigSettingKind::NAME;
        }
        if (isset($args['key'])) { $filter['object_key'] = $args['key']; }
        elseif (isset($args['kind'], $args['id'])) { $filter['object_key'] = (string) $args['id']; }
        $changesets = $context->journal()->changesets($filter, isset($args['limit']) ? $args['limit'] : 20);
        return array('changesets' => self::entries($context, $changesets));
    }

    /** Journal changesets in the shape this tool returns them (also used by the manager's history page). */
    public static function entries(Context $context, array $changesets) {
        $byChangeset = array();
        $private = array();
        foreach ($context->journal()->changes(array_column($changesets, 'id')) as $change) {
            if (!self::changeVisible($context, $change)) {
                $private[$change['changeset_id']] = true;
                continue;
            }
            $byChangeset[$change['changeset_id']][] = array(
                'kind' => $change['kind'], Subjects::keyField($context, $change['kind']) => Subjects::publicKey($context, $change['kind'], $change['object_key']),
                'name' => $change['label'], 'op' => $change['op'],
            );
        }
        $authors = new Authors($context->modx);
        $out = array();
        foreach ($changesets as $changeset) {
            if (isset($private[$changeset['id']]) && empty($byChangeset[$changeset['id']])) { continue; }
            $out[] = array(
                'changeset_id' => $changeset['id'],
                'at'           => $changeset['created_at'],
                'time'         => date('c', $changeset['created_at']),
                'source'       => $changeset['source'],
                'author'       => $authors->name($changeset),
                'tool'         => $changeset['tool'],
                'summary'      => isset($private[$changeset['id']]) ? self::RESTRICTED_SUMMARY : $changeset['summary'],
                'status'       => $changeset['status'],
                'reversible'   => $changeset['reversible'],
                'this_session' => $context->sessionId !== null && $changeset['session_id'] === $context->sessionId,
                'objects'      => isset($byChangeset[$changeset['id']]) ? $byChangeset[$changeset['id']] : array(),
            ) + ($changeset['undoes'] ? array('undoes' => $changeset['undoes']) : array())
              + ($changeset['undone_by'] !== null ? array('undone_by' => $changeset['undone_by']) : array());
        }
        return $out;
    }

    /** ClientConfig metadata shares the setting kind's admin-only visibility. */
    public static function changeVisible(Context $context, array $change) {
        if ($change['kind'] !== ClientConfigSettingKind::NAME) { return true; }
        $kind = $context->kinds->get(ClientConfigSettingKind::NAME);
        return $context->kinds->blockReason($kind, $context) === null;
    }
}
