<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class DuplicateAction extends WriteAction {
    public function name() { return 'duplicate'; }

    public function description() {
        return 'Copy one object including its writable relations. A resource copy is unpublished and has no children; '
            . 'a static element copy is not static.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'kind'    => Schema::str('Object kind; see modx_describe.', array('pattern' => '^[a-z][a-z0-9_]{0,63}$')),
            'id'      => Schema::int(null, array('minimum' => 1)),
            'name'    => Schema::str('Copy name when id is given; otherwise the source name.', array('maxLength' => 255)),
            'fields'  => Schema::obj(array(), array(), true) + array('description' => 'Overrides for the copied fields.'),
            'dry_run' => Schema::bool('Validate and report without creating anything.'),
        ), array('kind'));
    }

    public function run(array $args, Context $context) {
        $kind = Objects::kind($context, $args['kind'], true);
        $source = Objects::resolve($context, $kind, $args);
        $sourceId = (int) $source->get('id');
        $overrides = isset($args['fields']) ? $args['fields'] : array();
        Objects::normalize($kind, $overrides, false);
        $values = array();
        foreach ($kind->fields() as $field => $definition) {
            if (empty($definition['read_only']) && empty($definition['derived'])) {
                $values[$field] = $kind->value($source, $field);
            }
        }
        $nameField = $kind->nameField();
        // Without an id, name belongs to the source reference, just as in update.
        $explicitName = isset($args['id'], $args['name']);
        $baseName = $values[$nameField] . ' copy';
        $newName = $explicitName ? $args['name'] : $baseName;
        if ($kind->uniqueNames() && !$explicitName) {
            for ($number = 2; $number <= 20 && $kind->find($context->modx, array('name' => $newName)); $number++) {
                $newName = $baseName . ' ' . $number;
            }
        }
        $values[$nameField] = $newName;
        $values = array_replace($values, $kind->duplicateOverrides($source), $overrides);
        $newName = $values[$nameField];
        $columns = Objects::normalize($kind, $values, true);
        if ($kind->uniqueNames() && $kind->find($context->modx, array('name' => $newName))) {
            throw new ApiException('validation_failed', "A {$kind->name()} named '{$newName}' already exists.");
        }

        $relations = array();
        $notCopied = array();
        foreach ($kind->relationSpecs() as $name => $spec) {
            if (!empty($spec['writable'])) {
                $relations[$name] = !empty($spec['map'])
                    ? (array) $kind->storedRelation($context->modx, $source, $name)
                    : $kind->presentRelation($context->modx, $source, $name);
            } else {
                $criteria = array($spec['fk'] => $sourceId) + (isset($spec['where']) ? $spec['where'] : array());
                if ($context->modx->getCount($spec['class'], $criteria)) { $notCopied[] = $name; }
            }
        }
        if ($notCopied) { $context->warnings[] = 'Not copied: ' . implode(', ', $notCopied); }
        Objects::assertWritableRelations($kind, $relations);
        Objects::lint($context, $kind, $columns);
        if ($this->isDryRun($args, $context)) {
            return $this->plan($context, array(
                'kind' => $kind->name(), 'source_id' => $sourceId, 'would_create' => array('name' => $newName),
                'relations' => array_keys($relations), 'reversible' => true,
            ));
        }
        $id = null;
        list(, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $kind, $columns, $relations, $sourceId, &$id) {
            $id = Objects::save($context, $tx, $kind, null, $columns, $relations);
            return array('result' => array(), 'summary' => "duplicate {$kind->name()} {$sourceId} as {$id}");
        });
        return $this->written($kind->name(), $id, $info, array('source_id' => $sourceId, 'name' => $newName));
    }
}
