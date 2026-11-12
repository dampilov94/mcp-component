<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class UpdateAction extends WriteAction {
    public function name() { return 'update'; }

    public function description() {
        return 'Change fields and/or writable relations of one object. Only the given fields change. '
            . 'For edits inside long code prefer modx_edit_code. Reversible with modx_undo.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'kind'      => Schema::str('Object kind; see modx_describe.', array('pattern' => '^[a-z][a-z0-9_]{0,63}$')),
            'id'        => Schema::int(null, array('minimum' => 1)),
            'name'      => Schema::str('Current name, when the id is not known.', array('maxLength' => 255)),
            'key'       => self::keyArgument(),
            'fields'    => Schema::obj(array(), array(), true) + array('description' => 'Fields to change.'),
            'relations' => Schema::obj(array(), array(), true) + array('description' => 'Writable relations to replace.'),
            'skip_lint' => Schema::bool('Save PHP code even if it does not parse.'),
        ) + $this->commonInput(), array('kind'));
    }

    public function run(array $args, Context $context) {
        $kind = Objects::kind($context, $args['kind'], true);
        Objects::assertGenericWrites($kind);
        $object = Objects::resolve($context, $kind, $args);
        $id = $kind->keyOf($object);
        $columns = Objects::normalize($kind, isset($args['fields']) ? $args['fields'] : array(), false);
        Objects::assertImmutable($kind, $object, $columns);
        $kind->assertObjectWritable($context->modx, $object, 'update');
        $kind->assertUpdatable($context->modx, $object, $columns);
        $kind->assertKeepsSecret($context, $object, $columns, 'update');
        $relations = isset($args['relations']) ? $args['relations'] : array();
        Objects::assertWritableRelations($kind, $relations);
        if (!$columns && !$relations) { throw new ApiException('validation_failed', 'Nothing to change: give fields or relations.'); }
        $dry = $this->isDryRun($args, $context);

        list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $kind, $id, $columns, $relations, $args, $dry) {
            $before = $tx->touch($kind, $id);
            if ($before === null) { throw new ApiException('not_found', "No {$kind->name()} with " . Objects::keyText($kind, $id) . '.'); }
            Objects::assertRevision($context, $kind, $id, $before, $args);
            Objects::lint($context, $kind, $columns, !empty($args['skip_lint']));
            if ($dry) {
                $would = array();
                foreach (isset($args['fields']) ? $args['fields'] : array() as $name => $value) {
                    $current = Snapshot::stateValue($kind, $before, $name);
                    if (!Objects::sameValue($kind, $name, $current, $value)) { $would[] = $name; }
                }
                return array('result' => array(
                    'kind' => $kind->name(), $kind->keyField() => $id, 'revision' => Snapshot::revision($before),
                    'would_change' => array_merge($would, array_keys($relations)), 'reversible' => true,
                ));
            }
            Objects::save($context, $tx, $kind, $id, $columns, $relations);
            return array('result' => array(), 'summary' => "update {$kind->name()} {$id}");
        });
        if ($dry) { return $this->plan($context, $result); }
        return $this->written($kind, $id, $info, array('revision' => Snapshot::revision(Snapshot::capture($context->modx, $kind, $id))));
    }
}
