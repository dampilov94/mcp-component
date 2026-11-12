<?php
namespace ModxMCP\Action;

use ModxMCP\Code\CodeSearch;
use ModxMCP\Context;
use ModxMCP\Schema;

final class SearchAction extends Action {
    public function name() { return 'search'; }
    public function group() { return 'code'; }
    public function description() {
        return 'Literal text search in element code (and resource content when kinds includes resource). '
            . 'Returns matching lines with numbers usable with modx_view_code / modx_edit_code.';
    }
    public function inputSchema() {
        return Schema::obj(array(
            'query' => Schema::str('Literal text.', array('minLength' => 2, 'maxLength' => 500)),
            'kinds' => Schema::arr(Schema::str(), 'Default: chunk, snippet, template, plugin.'),
            'case_sensitive' => Schema::bool('Default false.'),
            'limit' => Schema::int('Objects, default 50.', array('minimum' => 1, 'maximum' => 200)),
            'max_matches' => Schema::int('Lines per object, default 5.', array('minimum' => 1, 'maximum' => 50)),
        ), array('query'));
    }
    public function outputSchema() {
        return Schema::obj(array('query' => Schema::str(), 'case_sensitive' => Schema::bool(),
            'objects' => Schema::int(), 'truncated' => Schema::bool(),
            'results' => Schema::arr(Schema::obj(array(), array(), true))),
            array('query', 'case_sensitive', 'objects', 'truncated', 'results'));
    }
    public function run(array $args, Context $context) {
        $kinds = CodeSearch::kinds($context, isset($args['kinds']) ? $args['kinds'] : null);
        $sensitive = !empty($args['case_sensitive']);
        $limit = isset($args['limit']) ? $args['limit'] : 50;
        $max = isset($args['max_matches']) ? $args['max_matches'] : 5;
        $results = array();
        $truncated = false;
        foreach ($kinds as $kind) {
            $hits = array();
            foreach (CodeSearch::objects($context, $kind, $args['query']) as $entry) {
                list($object, $content) = $entry;
                $name = (string) $kind->value($object, $kind->nameField());
                $nameMatch = CodeSearch::position($name, $args['query'], $sensitive) !== false;
                $matches = array();
                $count = 0;
                foreach ($content[3] ? array() : preg_split('/\r\n|\r|\n/', $content[0]) as $i => $line) {
                    $pos = CodeSearch::position($line, $args['query'], $sensitive);
                    if ($pos === false) { continue; }
                    $count++;
                    if (count($matches) < $max) { $matches[] = array('line' => $i + 1, 'text' => CodeSearch::excerpt($line, $pos)); }
                }
                if (!$count && !$nameMatch) { continue; }
                if (count($results) + count($hits) === $limit) { $truncated = true; break; }
                $hits[] = array('kind' => $kind->name(), 'id' => (int) $object->get('id'), 'name' => $name,
                    'static' => $content[1], 'match_count' => $count, 'name_match' => $nameMatch, 'matches' => $matches);
            }
            usort($hits, function ($a, $b) { return strcmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']; });
            $results = array_merge($results, $hits);
            if ($truncated) { break; }
        }
        return array('query' => $args['query'], 'case_sensitive' => $sensitive, 'objects' => count($results),
            'truncated' => $truncated, 'results' => $results);
    }
}
