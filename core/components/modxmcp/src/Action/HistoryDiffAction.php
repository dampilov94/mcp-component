<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Journal\Subjects;
use ModxMCP\Schema;

final class HistoryDiffAction extends Action {
    public function name() { return 'history_diff'; }

    public function group() { return 'history'; }

    public function description() {
        return 'What one changeset changed: per object the operation and the changed fields. A field shows '
            . '{old, new}; code shows {diff} with removed (-) and added (+) lines instead.';
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
            $item = array('kind' => $change['kind'], 'id' => ctype_digit($change['object_key']) ? (int) $change['object_key'] : $change['object_key'], 'name' => $change['label'], 'op' => $change['op']);
            $before = $load($change['before_revision']);
            $after = $load($change['after_revision']);
            try {
                $subject = Subjects::forChange($context, $change['kind'], $change['object_key'], false);
            } catch (ApiException $e) {
                $subject = null;   // e.g. the media source is gone
            }
            if (!$subject || $before === false || $after === false) {
                $item['note'] = 'The stored states of this change are not available.';
                $objects[] = $item;
                continue;
            }
            $fields = $subject->describeChange($before, $after);
            $item['changed'] = $fields ? $fields : new \stdClass();
            if ($change['note'] !== '') { $item['note'] = $change['note']; }
            $objects[] = $item;
        }
        return array(
            'changeset_id' => $changeset['id'], 'at' => $changeset['created_at'], 'time' => date('c', $changeset['created_at']), 'source' => $changeset['source'],
            'tool' => $changeset['tool'], 'summary' => $changeset['summary'], 'status' => $changeset['status'],
            'objects' => $objects,
        );
    }
}
