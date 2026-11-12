<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Code\Usages;
use ModxMCP\Context;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;

final class FindUsagesAction extends Action {
    public function name() { return 'find_usages'; }
    public function group() { return 'code'; }
    public function description() {
        return 'Find where a chunk, snippet, TV, template, category or resource is used: tags, API calls, properties and Fenom, '
            . 'before renaming or deleting it. Matching is textual and can miss names built at run time.';
    }
    public function inputSchema() {
        return Schema::obj(array('kind' => Schema::str(), 'id' => Schema::int(null, array('minimum' => 1)),
            'name' => Schema::str(null, array('maxLength' => 255)),
            'limit' => Schema::int('Objects, default 50.', array('minimum' => 1, 'maximum' => 200))), array('kind'));
    }
    public function outputSchema() {
        return Schema::obj(array('kind' => Schema::str(), 'id' => Schema::int(), 'name' => Schema::str(),
            'objects' => Schema::int(), 'truncated' => Schema::bool(),
            'usages' => Schema::arr(Schema::obj(array(), array(), true)), 'extra' => Schema::obj(array(), array(), true)),
            array('kind', 'id', 'name', 'objects', 'truncated', 'usages', 'extra'));
    }
    public function run(array $args, Context $context) {
        $kind = Objects::kind($context, $args['kind']);
        if (!Usages::supports($kind)) {
            throw new ApiException('validation_failed', 'Supported targets: chunk, snippet, tv, resource, template, category.');
        }
        $object = Objects::resolve($context, $kind, $args);
        return array('kind' => $kind->name(), 'id' => (int) $object->get('id'),
            'name' => (string) $kind->value($object, $kind->nameField()))
            + Usages::find($context, $kind, $object, isset($args['limit']) ? $args['limit'] : 50);
    }
}
