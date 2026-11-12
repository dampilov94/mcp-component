<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Code\Usages;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Kind\Objects;
use ModxMCP\Kind\StaticFiles;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class DeleteAction extends WriteAction {
    public function name() { return 'delete'; }

    public function destructive() { return true; }

    public function description() {
        return 'Delete one object. Run with dry_run first to see what would be removed and where it is still used. The object and its '
            . 'related rows are kept in the journal, so modx_undo restores it with the same id. A resource goes '
            . 'to the MODX trash together with its child resources.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'kind' => Schema::str('Object kind; see modx_describe.', array('pattern' => '^[a-z][a-z0-9_]{0,63}$')),
            'id'   => Schema::int(null, array('minimum' => 1)),
            'name' => Schema::str('Name, when the id is not known.', array('maxLength' => 255)),
            'key'  => self::keyArgument(),
        ) + $this->commonInput(), array('kind'));
    }

    public function run(array $args, Context $context) {
        $kind = Objects::kind($context, $args['kind'], true);
        $object = Objects::resolve($context, $kind, $args);
        $id = $kind->keyOf($object);
        $kind->assertObjectWritable($context->modx, $object, 'delete');
        $label = (string) $kind->value($object, $kind->nameField());
        $dry = $this->isDryRun($args, $context);

        list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $kind, $id, $label, $args, $dry) {
            $before = $tx->touch($kind, $id);
            if ($before === null) { throw new ApiException('not_found', "No {$kind->name()} with " . Objects::keyText($kind, $id) . '.'); }
            Objects::assertRevision($context, $kind, $id, $before, $args);
            $tx->keepDeleteExtra($kind, $id);
            foreach ($kind->deleteCascade($context->modx, $id) as $cascade) {
                $other = $context->kinds->get($cascade[0]);
                if ($other) { $tx->touch($other, $cascade[1]); }
            }
            if ($dry) {
                $related = array();
                foreach ($before['relations'] + Snapshot::captureDeleteExtra($context->modx, $kind, $id) as $name => $rows) {
                    if ($rows) { $related[$name] = count($rows); }
                }
                return array('result' => array(
                    'kind' => $kind->name(), $kind->keyField() => $id, 'revision' => Snapshot::revision($before),
                    'would_delete' => array('name' => $label, 'related_rows' => $related ? $related : new \stdClass()),
                    'reversible' => true,
                ) + $this->usagePlan($context, $kind, $id));
            }
            foreach ($kind->alsoAffected($context->modx, $id, array(), 'delete') as $other) { $tx->touch($kind, $other); }
            $staticPath = $kind->effectiveContent(Objects::resolveKey($context, $kind, $id))[2];
            Objects::process($context->modx, $kind->processorPath() . $kind->deleteProcessor(), $kind->processorKey($id));
            StaticFiles::removeManaged($context->modx, $staticPath);
            $kind->afterWrite($context->modx);
            return array('result' => array(), 'summary' => "delete {$kind->name()} {$id} ({$label})");
        });
        if ($dry) { return $this->plan($context, $result); }
        return $this->written($kind, $id, $info, array('deleted' => true));
    }
    private function usagePlan(Context $context, $kind, $id) {
        if (!Usages::supports($kind)) { return array(); }
        try {
            $found = Usages::find($context, $kind, Objects::resolveKey($context, $kind, $id), 10);
            $items = array();
            foreach ($found['usages'] as $item) { unset($item['matches']); $items[] = $item; }
            $out = array('usages' => array('objects' => $found['objects'], 'truncated' => $found['truncated'], 'items' => $items, 'extra' => $found['extra']));
            $warnings = array();
            if ($found['objects']) { $warnings[] = "Still used in {$found['objects']} object(s); deleting it will break them."; }
            $extra = (array) $found['extra'];
            if ($kind->name() === 'template' && !empty($extra['resources'])) { $warnings[] = "{$extra['resources']} resource(s) use this template."; }
            if ($kind->name() === 'tv' && !empty($extra['resources_with_value'])) { $warnings[] = "{$extra['resources_with_value']} resource(s) have a value for this TV."; }
            if ($kind->name() === 'category' && array_sum($extra) > 0) { $warnings[] = 'The category is not empty.'; }
            if ($kind->name() === 'resource' && !empty($extra['children'])) { $warnings[] = "{$extra['children']} child resource(s) go to the trash with it."; }
            if ($warnings) { $out['warning'] = implode(' ', $warnings); }
            return $out;
        } catch (\Throwable $e) {
            $context->warnings[] = 'Could not look up usages: ' . $e->getMessage();
            return array();
        }
    }

}
