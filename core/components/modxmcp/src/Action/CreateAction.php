<?php
namespace ModxMCP\Action;

use ModxMCP\Context;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class CreateAction extends WriteAction {
    public function name() { return 'create'; }

    public function description() {
        return 'Create an object of a kind. `fields` holds its values (see modx_describe for the kind); '
            . 'PHP code of snippets and plugins must pass a syntax check. Reversible with modx_undo.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'kind'      => Schema::str('Object kind; see modx_describe.', array('pattern' => '^[a-z][a-z0-9_]{0,63}$')),
            'fields'    => Schema::obj(array(), array(), true) + array('description' => 'Field values.'),
            'relations' => Schema::obj(array(), array(), true) + array('description' => 'Writable relations, e.g. {"events": ["OnLoadWebDocument"]}.'),
            'skip_lint' => Schema::bool('Save PHP code even if it does not parse.'),
            'dry_run'   => Schema::bool('Validate and report without creating anything.'),
        ), array('kind', 'fields'));
    }

    public function run(array $args, Context $context) {
        $kind = Objects::kind($context, $args['kind'], true);
        Objects::assertGenericWrites($kind);
        $columns = Objects::normalize($kind, $args['fields'], true);
        $relations = isset($args['relations']) ? $args['relations'] : array();
        Objects::assertWritableRelations($kind, $relations);
        Objects::lint($context, $kind, $columns, !empty($args['skip_lint']));
        if ($this->isDryRun($args, $context)) {
            return $this->plan($context, array('kind' => $kind->name(), 'would_create' => array_keys($args['fields']), 'reversible' => true));
        }
        $id = null;
        list(, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $kind, $columns, $relations, &$id) {
            $id = Objects::save($context, $tx, $kind, null, $columns, $relations);
            return array('result' => array(), 'summary' => "create {$kind->name()} {$id}");
        });
        return $this->written($kind, $id, $info);
    }
}
