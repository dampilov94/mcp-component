<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class DeleteAction extends WriteAction {
    public function name() { return 'delete'; }

    public function destructive() { return true; }

    public function description() {
        return 'Delete one object. Run with dry_run first to see what would be removed. The object and its '
            . 'related rows are kept in the journal, so modx_undo restores it with the same id. A resource goes '
            . 'to the MODX trash together with its child resources.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'kind' => Schema::str('Object kind; see modx_describe.', array('pattern' => '^[a-z][a-z0-9_]{0,63}$')),
            'id'   => Schema::int(null, array('minimum' => 1)),
            'name' => Schema::str('Name, when the id is not known.', array('maxLength' => 255)),
        ) + $this->commonInput(), array('kind'));
    }

    public function run(array $args, Context $context) {
        $kind = Objects::kind($context, $args['kind'], true);
        $object = Objects::resolve($context, $kind, $args);
        $id = (int) $object->get('id');
        $label = (string) $kind->value($object, $kind->nameField());
        $dry = $this->isDryRun($args, $context);

        list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $kind, $id, $label, $args, $dry) {
            $before = $tx->touch($kind, $id);
            if ($before === null) { throw new ApiException('not_found', "No {$kind->name()} with id {$id}."); }
            Objects::assertRevision($context, $kind, $id, $before, $args);
            $tx->keepDeleteExtra($kind, $id);
            if ($dry) {
                $related = array();
                foreach ($before['relations'] + Snapshot::captureDeleteExtra($context->modx, $kind, $id) as $name => $rows) {
                    if ($rows) { $related[$name] = count($rows); }
                }
                return array('result' => array(
                    'kind' => $kind->name(), 'id' => $id, 'revision' => Snapshot::revision($before),
                    'would_delete' => array('name' => $label, 'related_rows' => $related ? $related : new \stdClass()),
                    'reversible' => true,
                ));
            }
            foreach ($kind->alsoAffected($context->modx, $id, array(), 'delete') as $other) { $tx->touch($kind, $other); }
            Objects::process($context->modx, $kind->processorPath() . $kind->deleteProcessor(), array('id' => $id));
            return array('result' => array(), 'summary' => "delete {$kind->name()} {$id} ({$label})");
        });
        if ($dry) { return $this->plan($context, $result); }
        return $this->written($kind->name(), $id, $info, array('deleted' => true));
    }
}
