<?php
namespace ModxMCP\Code;

use ModxMCP\Context;
use ModxMCP\Graph\IntegrationReferences;
use ModxMCP\Kind\Kind;

/** Shared, read-only textual and structural usage lookup. */
final class Usages {
    public static function supports(Kind $kind): bool {
        return in_array($kind->name(), array('chunk', 'snippet', 'tv', 'resource', 'template', 'category'), true);
    }
    private static function patterns($kind, $name, $id) {
        $n = preg_quote($name, '/');
        $end = '(?![A-Za-z0-9_.-])';
        $quoted = '(?:\'' . $n . '\'|"' . $n . '")';
        if ($kind === 'resource') {
            return array('tag' => '\[\[!?~' . $id . '(?![0-9])', 'api' => '\bmakeUrl\s*\(\s*' . $id . '(?![0-9])',
                'fenom' => '(?<![0-9])' . $id . '\s*\|\s*url\b');
        }
        if ($kind === 'tv') {
            return array('tag' => '\[\[!?\*' . $n . $end, 'api' => '\bgetTVValue\s*\(\s*' . $quoted,
                'fenom' => '\bresource\.' . $n . $end,
                'property' => '&(?:includeTVs|processTVs|tvFilters)\s*=\s*`[^`]*?(?<![A-Za-z0-9_.-])' . $n . $end . '[^`]*`');
        }
        if ($kind === 'chunk' || $kind === 'snippet') {
            // Fenom (pdoTools): {include 'x'}, {insert 'x'}, {extends 'x'}, {'chunk:x'}-style provider prefixes, 'x' | chunk, a 'tpl' => 'x' array value;
            // a snippet is also called as runSnippet('!x'), and parseChunk() takes a chunk like getChunk().
            $chunkQuoted = '(?:\'(?:chunk:)?' . $n . '\'|"(?:chunk:)?' . $n . '")';
            $snippetQuoted = '(?:\'!?' . $n . '\'|"!?' . $n . '")';
            return array('tag' => '\[\[!?' . ($kind === 'chunk' ? '\$' : '') . $n . $end,
                'api' => $kind === 'chunk' ? '\b(?:getChunk|parseChunk)\s*\(\s*' . $quoted : '\brunSnippet\s*\(\s*' . $snippetQuoted,
                'property' => '`' . $n . '`',
                'fenom' => $kind === 'chunk'
                    ? '\{(?:include|insert|extends)\s+' . $chunkQuoted . '|' . $quoted . '\s*\|\s*chunk\b|[\'"][A-Za-z0-9_]*(?:tpl|chunk|wrapper)[A-Za-z0-9_]*[\'"]\s*=>\s*' . $quoted
                    : $snippetQuoted . '\s*\|\s*snippet\b');
        }
        if ($kind === 'template') {
            return array('fenom' => '\{(?:include|extends)\s+(?:\'template:' . $n . '\'|"template:' . $n . '")');
        }
        return array();
    }
    private static function matches($code, array $patterns) {
        $code = str_replace(array("\r\n", "\r"), "\n", $code);
        $hits = array();
        foreach ($patterns as $via => $pattern) {
            preg_match_all('/' . $pattern . '/', $code, $found, PREG_OFFSET_CAPTURE);
            foreach ($found[0] as $match) { $hits[] = array('offset' => $match[1], 'via' => $via); }
        }
        usort($hits, function ($a, $b) { return $a['offset'] <=> $b['offset']; });
        $matches = array();
        foreach (array_slice($hits, 0, 5) as $hit) {
            $before = substr($code, 0, $hit['offset']);
            $last = strrpos($before, "\n");
            $start = $last === false ? 0 : $last + 1;
            $end = strpos($code, "\n", $hit['offset']);
            $line = $end === false ? substr($code, $start) : substr($code, $start, $end - $start);
            $matches[] = array('line' => substr_count($before, "\n") + 1,
                'text' => CodeSearch::excerpt($line, $hit['offset'] - $start), 'via' => $hit['via']);
        }
        return array(count($hits), $matches);
    }
    private static function ids($modx, $class, array $where, $column) {
        $ids = array();
        foreach ($modx->getCollection($class, $where) as $row) { $ids[] = (int) $row->get($column); }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
    private static function extra(Context $context, $kind, $id) {
        $modx = $context->modx;
        if ($kind === 'template') {
            $query = $modx->newQuery('modResource', array('template' => $id, 'deleted' => 0));
            $count = (int) $modx->getCount('modResource', $query);
            $query->sortby('id', 'ASC');
            $query->limit(20);
            $ids = array();
            foreach ($modx->getCollection('modResource', $query) as $row) { $ids[] = (int) $row->get('id'); }
            return array('resources' => $count, 'resource_ids' => $ids,
                'tvs' => self::ids($modx, 'modTemplateVarTemplate', array('templateid' => $id), 'tmplvarid'));
        }
        if ($kind === 'tv') {
            return array('templates' => self::ids($modx, 'modTemplateVarTemplate', array('tmplvarid' => $id), 'templateid'),
                'resources_with_value' => (int) $modx->getCount('modTemplateVarResource', array('tmplvarid' => $id)));
        }
        if ($kind === 'category') {
            $out = array();
            foreach (array('chunks' => 'modChunk', 'snippets' => 'modSnippet', 'templates' => 'modTemplate', 'plugins' => 'modPlugin', 'tvs' => 'modTemplateVar') as $key => $class) {
                $out[$key] = (int) $modx->getCount($class, array('category' => $id));
            }
            $out['subcategories'] = (int) $modx->getCount('modCategory', array('parent' => $id));
            return $out;
        }
        return $kind === 'resource' ? array('children' => (int) $modx->getCount('modResource', array('parent' => $id))) : new \stdClass();
    }
    public static function find(Context $context, Kind $targetKind, $target, $limit): array {
        $id = (int) $target->get('id');
        $name = (string) $targetKind->value($target, $targetKind->nameField());
        $patterns = self::patterns($targetKind->name(), $name, $id);
        $usages = array();
        $truncated = false;
        $structural = array();
        if (in_array($targetKind->name(), array('resource', 'snippet', 'chunk', 'template', 'tv'), true)) {
            foreach (IntegrationReferences::scan($context->modx) as $ref) {
                if ($ref['kind'] !== $targetKind->name() || (isset($ref['id']) ? $ref['id'] !== $id : $ref['name'] !== $name)) { continue; }
                $label = $ref['source'] . ' #' . $ref['source_id'];
                if (!isset($structural[$label])) {
                    if (count($structural) === $limit) { $truncated = true; break; }
                    $structural[$label] = array('kind' => $ref['source'], 'id' => $ref['source_id'], 'name' => $label,
                        'static' => false, 'match_count' => 0, 'matches' => array());
                }
                ++$structural[$label]['match_count'];
                if (count($structural[$label]['matches']) < 5) {
                    $structural[$label]['matches'][] = array('text' => $ref['field'], 'via' => $ref['source']);
                }
            }
            $usages = array_values($structural);
        }
        if ($patterns) {
            $kinds = CodeSearch::kinds($context, array('chunk', 'snippet', 'template', 'plugin', 'resource'));
            foreach ($kinds as $kind) {
                $hits = array();
                foreach (CodeSearch::objects($context, $kind, $targetKind->name() === 'resource' ? (string) $id : $name) as $entry) {
                    list($object, $content) = $entry;
                    if (($kind->name() === $targetKind->name() && (int) $object->get('id') === $id) || $content[3]) { continue; }
                    list($count, $matches) = self::matches($content[0], $patterns);
                    if (!$count) { continue; }
                    if (count($usages) + count($hits) === $limit) { $truncated = true; break; }
                    $hits[] = array('kind' => $kind->name(), 'id' => (int) $object->get('id'),
                        'name' => (string) $kind->value($object, $kind->nameField()), 'static' => $content[1], 'match_count' => $count, 'matches' => $matches);
                }
                usort($hits, function ($a, $b) { return strcmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']; });
                $usages = array_merge($usages, $hits);
                if ($truncated) { break; }
            }
        }
        return array('objects' => count($usages),
            'truncated' => $truncated, 'usages' => $usages, 'extra' => self::extra($context, $targetKind->name(), $id));
    }
}
