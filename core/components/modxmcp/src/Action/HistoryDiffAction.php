<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Code\Diff;
use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Schema;

final class HistoryDiffAction extends Action {
    /** Columns whose values are never shown in a diff. */
    const SECRET = '/pass|secret|token|salt|hash/i';

    public function name() { return 'history_diff'; }

    public function group() { return 'history'; }

    public function description() {
        return 'What one changeset changed: per object the operation, the changed fields with old and new values, '
            . 'and a line diff for code.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'changeset_id' => Schema::int(null, array('minimum' => 1)),
        ), array('changeset_id'));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'changeset_id' => Schema::int(),
            'objects'      => Schema::arr(Schema::obj(array(), array(), true)),
        ), array('changeset_id', 'objects'), true);
    }

    public function run(array $args, Context $context) {
        $changeset = $context->journal()->changeset($args['changeset_id']);
        if (!$changeset) { throw new ApiException('not_found', "No changeset {$args['changeset_id']}."); }
        $blobs = $context->blobs();
        $load = function ($revision) use ($blobs) {
            if ($revision === null) { return null; }
            $json = $blobs->get($revision);
            return $json === null ? false : json_decode($json, true);
        };

        $objects = array();
        foreach ($changeset['changes'] as $change) {
            $item = array('kind' => $change['kind'], 'id' => (int) $change['object_key'], 'name' => $change['label'], 'op' => $change['op']);
            $kind = $context->kinds->get($change['kind']);
            $before = $load($change['before_revision']);
            $after = $load($change['after_revision']);
            if (!$kind || $before === false || $after === false) {
                $item['note'] = 'The stored states of this change are not available.';
                $objects[] = $item;
                continue;
            }
            $fields = array();
            $codeColumn = $kind->codeField() !== null ? $kind->column($kind->codeField()) : null;
            foreach (Snapshot::changed($kind, $before, $after) as $name) {
                $definitions = $kind->fields();
                $column = isset($definitions[$name]) ? $kind->column($name) : $name;
                $old = $before !== null && array_key_exists($column, $before['fields']) ? $before['fields'][$column] : null;
                $new = $after !== null && array_key_exists($column, $after['fields']) ? $after['fields'][$column] : null;
                if ($before !== null && isset($before['relations'][$name]) || $after !== null && isset($after['relations'][$name])) {
                    $fields[$name] = array(
                        'old' => $before !== null && isset($before['relations'][$name]) ? $before['relations'][$name] : array(),
                        'new' => $after !== null && isset($after['relations'][$name]) ? $after['relations'][$name] : array(),
                    );
                } elseif ($column === $codeColumn) {
                    $fields[$name] = array('diff' => Diff::lines((string) $old, (string) $new));
                } elseif (preg_match(self::SECRET, $column)) {
                    $fields[$name] = array('old' => '[hidden]', 'new' => '[hidden]');
                } else {
                    $fields[$name] = array('old' => $old, 'new' => $new);
                }
            }
            $item['changed'] = $fields ? $fields : new \stdClass();
            if ($change['note'] !== '') { $item['note'] = $change['note']; }
            $objects[] = $item;
        }
        return array(
            'changeset_id' => $changeset['id'], 'at' => $changeset['created_at'], 'source' => $changeset['source'],
            'tool' => $changeset['tool'], 'summary' => $changeset['summary'], 'status' => $changeset['status'],
            'objects' => $objects,
        );
    }
}
