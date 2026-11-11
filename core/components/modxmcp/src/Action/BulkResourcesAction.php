<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

/** One operation on many resources, as a single changeset: all of it happens, or none. */
final class BulkResourcesAction extends WriteAction {
    const OPERATIONS = array('publish', 'unpublish', 'set_template', 'move', 'reorder', 'delete', 'undelete');

    public function name() { return 'bulk_resources'; }

    public function group() { return 'resources'; }

    public function destructive() { return true; }

    public function description() {
        return 'Apply one operation to several resources in a single changeset (one modx_undo reverts all): '
            . 'publish, unpublish, set_template (needs `template`), move (needs `parent`), reorder (menu order = the '
            . 'order of `ids`), delete (to trash, with children), undelete. If any resource fails, nothing is changed. '
            . 'Find the ids with modx_list or modx_resource_tree first.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'operation' => array('type' => 'string', 'enum' => self::OPERATIONS),
            'ids'       => Schema::arr(Schema::int(null, array('minimum' => 1)), 'Resource ids.', array('maxItems' => 200)),
            'template'  => Schema::int('For set_template: template id, 0 for none.', array('minimum' => 0)),
            'parent'    => Schema::int('For move: new parent id, 0 for the top level.', array('minimum' => 0)),
            'dry_run'   => Schema::bool('Report what would change without changing anything.'),
        ), array('operation', 'ids'));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'operation'    => Schema::str(),
            'count'        => Schema::int(),
            'objects'      => Schema::arr(Schema::obj(array(), array(), true)),
            'changeset_id' => array('type' => array('integer', 'null')),
            'dry_run'      => Schema::bool(),
        ), array('operation', 'count', 'objects'), true);
    }

    public function run(array $args, Context $context) {
        $kind = Objects::kind($context, 'resource', true);
        $operation = $args['operation'];
        $ids = array_values(array_unique($args['ids']));
        if (!$ids) { throw new ApiException('validation_failed', 'ids must not be empty.'); }
        if ($operation === 'set_template' && !isset($args['template'])) { throw new ApiException('validation_failed', 'set_template needs `template`.'); }
        if ($operation === 'move' && !isset($args['parent'])) { throw new ApiException('validation_failed', 'move needs `parent`.'); }
        $dry = $this->isDryRun($args, $context);
        $modx = $context->modx;

        list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $modx, $kind, $operation, $ids, $args, $dry) {
            $plan = array();
            foreach ($ids as $position => $id) {
                $before = $tx->touch($kind, $id);
                if ($before === null) { throw new ApiException('not_found', "No resource with id {$id}. Nothing was changed."); }
                $fields = $before['fields'];
                $columns = array();
                switch ($operation) {
                    case 'publish':      $columns = array('published' => 1); break;
                    case 'unpublish':    $columns = array('published' => 0); break;
                    case 'set_template': $columns = array('template' => $args['template']); break;
                    case 'move':         $columns = array('parent' => $args['parent']); break;
                    case 'reorder':      $columns = array('menuindex' => $position); break;
                }
                $would = array();
                foreach ($columns as $column => $value) {
                    if ((string) $fields[$column] !== (string) $value) { $would[] = $column; }
                }
                if ($operation === 'delete' && $fields['deleted'] !== '1') { $would[] = 'deleted'; }
                if ($operation === 'undelete' && $fields['deleted'] === '1') { $would[] = 'deleted'; }
                $plan[] = array('id' => $id, 'pagetitle' => $fields['pagetitle'], 'would_change' => $would);
                if ($dry || !$would) { continue; }

                if ($operation === 'delete' || $operation === 'undelete') {
                    foreach ($kind->alsoAffected($modx, $id, array(), $operation) as $other) { $tx->touch($kind, $other); }
                    Objects::process($modx, 'resource/' . $operation, array('id' => $id));
                } else {
                    Objects::save($context, $tx, $kind, $id, $columns, array());
                }
            }
            return array('result' => $plan, 'summary' => "{$operation} " . count($ids) . ' resources');
        });

        if ($dry) {
            return $this->plan($context, array('operation' => $operation, 'count' => count($result), 'objects' => $result, 'reversible' => true));
        }
        $objects = array();
        foreach ($info['changes'] as $change) {
            $objects[] = array('id' => $change['id'], 'changed' => $change['changed'], 'revision' => $change['revision']);
        }
        return array('operation' => $operation, 'count' => count($objects), 'objects' => $objects, 'changeset_id' => $info['changeset_id']);
    }
}
