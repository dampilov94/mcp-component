<?php
namespace ModxMCP\Action;

use ModxMCP\Context;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;

/** The resource tree below a parent, bounded in depth and in total nodes. */
final class ResourceTreeAction extends Action {
    const NODE_LIMIT = 300;

    public function name() { return 'resource_tree'; }

    public function group() { return 'resources'; }

    public function description() {
        return 'Resource tree below a parent (default: the top level), up to `depth` levels and ' . self::NODE_LIMIT
            . ' nodes. Each node has id, title, alias, template, published/deleted flags and its child count, so a '
            . 'branch that was cut off can be expanded with another call. Use modx_list to search or filter resources.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'parent'  => Schema::int('Resource id to start below; 0 or omitted for the top level.', array('minimum' => 0)),
            'context' => Schema::str('Context key; default "web".', array('pattern' => '^[A-Za-z0-9_-]{1,100}$')),
            'depth'   => Schema::int('Levels to return, default 2.', array('minimum' => 1, 'maximum' => 5)),
        ));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'parent'    => Schema::int(),
            'context'   => Schema::str(),
            'nodes'     => Schema::arr(Schema::obj(array(), array(), true)),
            'returned'  => Schema::int(),
            'truncated' => Schema::bool(),
        ), array('parent', 'context', 'nodes', 'returned', 'truncated'), true);
    }

    public function run(array $args, Context $context) {
        $kind = Objects::kind($context, 'resource');
        $parent = isset($args['parent']) ? $args['parent'] : 0;
        $contextKey = isset($args['context']) ? $args['context'] : 'web';
        $depth = isset($args['depth']) ? $args['depth'] : 2;
        $count = 0;
        $truncated = false;
        $nodes = $this->level($context->modx, $kind, $parent, $contextKey, $depth, $count, $truncated);
        return array('parent' => $parent, 'context' => $contextKey, 'nodes' => $nodes, 'returned' => $count, 'truncated' => $truncated);
    }

    private function level(\modX $modx, $kind, $parent, $contextKey, $depth, &$count, &$truncated) {
        $query = $modx->newQuery('modResource');
        $query->where(array('parent' => $parent, 'context_key' => $contextKey));
        $query->sortby('menuindex', 'ASC');
        $query->sortby('id', 'ASC');
        $nodes = array();
        foreach ($modx->getCollection('modResource', $query) as $resource) {
            if ($count >= self::NODE_LIMIT) { $truncated = true; break; }
            $count++;
            $id = (int) $resource->get('id');
            $children = (int) $modx->getCount('modResource', array('parent' => $id));
            $node = array(
                'id' => $id, 'pagetitle' => (string) $resource->get('pagetitle'), 'alias' => (string) $resource->get('alias'),
                'template' => (int) $resource->get('template'), 'published' => (bool) $resource->get('published'),
                'children' => $children,
            );
            if ($resource->get('deleted')) { $node['deleted'] = true; }
            if ($resource->get('hidemenu')) { $node['hidemenu'] = true; }
            if ((string) $resource->get('class_key') !== 'modDocument') { $node['class_key'] = (string) $resource->get('class_key'); }
            if ($children > 0 && $depth > 1) {
                $node['nodes'] = $this->level($modx, $kind, $id, $contextKey, $depth - 1, $count, $truncated);
            }
            $nodes[] = $node;
        }
        return $nodes;
    }
}
