<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;

final class GetAction extends Action {
    const CODE_LIMIT = 20000;

    public function name() { return 'get'; }

    public function group() { return 'objects'; }

    public function description() {
        return 'Get one object by id or name: its fields, relations and revision. Long code is cut at '
            . self::CODE_LIMIT . ' bytes unless `full` is true; modx_view_code reads code by line range.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'kind'   => Schema::str('Object kind; see modx_describe.', array('pattern' => '^[a-z][a-z0-9_]{0,63}$')),
            'id'     => Schema::int(null, array('minimum' => 1)),
            'name'   => Schema::str('Name, when the id is not known.', array('maxLength' => 255)),
            'key'    => self::keyArgument(),
            'fields' => Schema::arr(Schema::str(), 'Fields to return; default all.', array('maxItems' => 40)),
            'full'   => Schema::bool('Return code in full.'),
        ), array('kind'));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'kind'      => Schema::str(),
            'id'        => Schema::int(),
            'key'       => Schema::str(),
            'revision'  => Schema::str(),
            'fields'    => Schema::obj(array(), array(), true),
            'relations' => Schema::obj(array(), array(), true),
            'truncated' => Schema::obj(array(), array(), true),
        ), array('kind', 'revision', 'fields', 'relations'), true);
    }

    public function run(array $args, Context $context) {
        $kind = Objects::kind($context, $args['kind']);
        $object = Objects::resolve($context, $kind, $args);
        $definitions = $kind->fields();
        $names = isset($args['fields']) ? array_values(array_unique($args['fields'])) : array_keys($definitions);

        $fields = array();
        $truncated = array();
        foreach ($names as $name) {
            if (!isset($definitions[$name])) { throw new ApiException('validation_failed', "Unknown field '{$name}' for {$kind->name()}."); }
            $value = $kind->value($object, $name);
            if (!empty($definitions[$name]['code']) && empty($args['full']) && strlen($value) > self::CODE_LIMIT) {
                $truncated[$name] = strlen($value);
                $value = function_exists('mb_strcut') ? mb_strcut($value, 0, self::CODE_LIMIT, 'UTF-8') : substr($value, 0, self::CODE_LIMIT);
            }
            $fields[$name] = $value;
        }
        $relations = array();
        foreach ($kind->relationSpecs() as $name => $spec) {
            if (empty($spec['delete_only'])) { $relations[$name] = $kind->presentRelation($context->modx, $object, $name); }
        }
        $id = $kind->keyOf($object);
        $result = array(
            'kind'      => $kind->name(),
            $kind->keyField() => $id,
            'revision'  => Snapshot::revision(Snapshot::capture($context->modx, $kind, $id)),
            'fields'    => $fields,
            'relations' => $relations ? $relations : new \stdClass(),
        );
        if ($truncated) { $result['truncated'] = $truncated; }
        return $result;
    }
}
