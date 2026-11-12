<?php
namespace ModxMCP\Action;

use ModxMCP\Context;
use ModxMCP\Graph\GraphBuilder;
use ModxMCP\Schema;

final class DependencyGraphAction extends Action {
    public function name() { return 'dependency_graph'; }
    public function group() { return 'code'; }
    public function description() {
        return 'Structural dependency graph: what a template pulls in, what uses a chunk, and which references are broken or elements may be unused.';
    }
    public function inputSchema() {
        return Schema::obj(array(
            'format' => Schema::str('Default graph; summary returns health counts and lists only.'),
            'types' => Schema::arr(Schema::str(), 'Default all element types; optional resource nodes when include_resources is true.'),
            'focus' => Schema::str('Default none; element name or type:name.'),
            'depth' => Schema::int('Default 1; clamped to 1..5 hops around focus.'),
            'direction' => Schema::str('out, in or both (default; also fallback for unknown values).'),
            'include_resources' => Schema::bool('Default false; only resources linked from elements are added.'),
            'max_nodes' => Schema::int('Default 1500; clamped to at least 10, no legacy upper limit.'),
            'verify_orphans' => Schema::bool('Default true for sites with at most 5000 resources.'),
        ));
    }
    public function outputSchema() {
        $open = Schema::obj(array(), array(), true);
        return Schema::obj(array('stats' => $open, 'returned' => $open, 'truncated' => Schema::bool(), 'legend' => $open,
            'nodes' => Schema::arr($open), 'edges' => Schema::arr(Schema::arr(array())), 'missing' => Schema::arr($open),
            'orphans' => Schema::arr($open), 'orphans_note' => Schema::str(), 'note' => Schema::str(),
            'focus' => array('type' => array('object', 'null')), 'error' => Schema::str()), array('stats'), true);
    }
    public function run(array $args, Context $context) {
        return (new GraphBuilder($context->modx))->build($args);
    }
}
