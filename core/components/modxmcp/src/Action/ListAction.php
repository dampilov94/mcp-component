<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;

final class ListAction extends Action {
    public function name() { return 'list'; }

    public function group() { return 'objects'; }

    public function description() {
        return 'List objects of a kind with paging. `query` filters by name; `where` filters by exact field '
            . 'values. Returns the default list fields unless `fields` is given. Code is never listed; use modx_get.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'kind'   => Schema::str('Object kind; see modx_describe.', array('pattern' => '^[a-z][a-z0-9_]{0,63}$')),
            'query'  => Schema::str('Substring of the name.', array('maxLength' => 200)),
            'where'  => Schema::obj(array(), array(), true) + array('description' => 'Exact matches, e.g. {"category": 3}.'),
            'fields' => Schema::arr(Schema::str(), 'Fields to return.', array('maxItems' => 40)),
            'limit'  => Schema::int('Page size, default 50.', array('minimum' => 1, 'maximum' => 200)),
            'start'  => Schema::int('Offset, default 0.', array('minimum' => 0)),
        ), array('kind'));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'kind'       => Schema::str(),
            'total'      => Schema::int(),
            'items'      => Schema::arr(Schema::obj(array(), array(), true)),
            'next_start' => array('type' => array('integer', 'null')),
        ), array('kind', 'total', 'items', 'next_start'), true);
    }

    public function run(array $args, Context $context) {
        $kind = Objects::kind($context, $args['kind']);
        $definitions = $kind->fields();
        $modx = $context->modx;

        if (isset($args['fields'])) {
            $names = array_values(array_unique($args['fields']));
            foreach ($names as $name) {
                if (!isset($definitions[$name])) { throw new ApiException('validation_failed', "Unknown field '{$name}' for {$kind->name()}."); }
                if (!empty($definitions[$name]['code'])) { throw new ApiException('validation_failed', "Field '{$name}' holds code and cannot be listed; use modx_get or modx_view_code."); }
            }
        } else {
            $names = array();
            foreach ($definitions as $name => $definition) {
                if (!empty($definition['list'])) { $names[] = $name; }
            }
        }

        $criteria = array();
        if (isset($args['query']) && $args['query'] !== '') {
            $criteria[$kind->column($kind->nameField()) . ':LIKE'] = '%' . $args['query'] . '%';
        }
        foreach (isset($args['where']) ? $args['where'] : array() as $name => $value) {
            if (!isset($definitions[$name]) || !empty($definitions[$name]['code'])) {
                throw new ApiException('validation_failed', "Cannot filter {$kind->name()} by '{$name}'.");
            }
            if (!is_scalar($value)) { throw new ApiException('validation_failed', "Filter '{$name}' must be a scalar value."); }
            $criteria[$kind->column($name)] = is_bool($value) ? ($value ? 1 : 0) : $value;
        }

        $limit = isset($args['limit']) ? $args['limit'] : 50;
        $start = isset($args['start']) ? $args['start'] : 0;
        $total = (int) $modx->getCount($kind->modelClass(), $criteria);
        $query = $modx->newQuery($kind->modelClass(), $criteria);
        $query->sortby($kind->column($kind->nameField()), 'ASC');
        $query->sortby('id', 'ASC');
        $query->limit($limit, $start);

        $items = array();
        foreach ($modx->getCollection($kind->modelClass(), $query) as $object) {
            $item = array();
            foreach ($names as $name) { $item[$name] = $kind->value($object, $name); }
            $items[] = $item;
        }
        $next = $start + count($items);
        return array('kind' => $kind->name(), 'total' => $total, 'items' => $items, 'next_start' => $next < $total ? $next : null);
    }
}
