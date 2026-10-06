<?php
/** search operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPSearchTrait {
    /**
     * Full-text search across element/resource content (and names).
     * Non-static elements are matched in the DB; static elements are matched by reading
     * their static file. data: query (required), types[] (chunk/snippet/template/plugin/tv/resource),
     * limit (default 50, max 200), case_sensitive (bool).
     */
    private function searchCode($data) {
        $query = isset($data['query']) ? trim((string) $data['query']) : '';
        if ($query === '') {
            throw new ModxMCPClientException('search_code: "query" is required.');
        }
        $limit = isset($data['limit']) ? (int) $data['limit'] : 50;
        if ($limit < 1) { $limit = 1; }
        if ($limit > 200) { $limit = 200; }
        $caseSensitive = !empty($data['case_sensitive']);

        $map = array(
            'chunk'    => array('class' => 'modChunk',       'content' => 'snippet',    'name' => 'name'),
            'snippet'  => array('class' => 'modSnippet',     'content' => 'snippet',    'name' => 'name'),
            'template' => array('class' => 'modTemplate',    'content' => 'content',    'name' => 'templatename'),
            'plugin'   => array('class' => 'modPlugin',      'content' => 'plugincode', 'name' => 'name'),
            'tv'       => array('class' => 'modTemplateVar', 'content' => 'default_text','name' => 'name'),
            'resource' => array('class' => 'modResource',    'content' => 'content',    'name' => 'pagetitle'),
        );

        $types = (isset($data['types']) && is_array($data['types']) && !empty($data['types']))
            ? $data['types']
            : array('chunk', 'snippet', 'template', 'plugin', 'tv', 'resource');

        $results = array();
        foreach ($types as $type) {
            if (!isset($map[$type]) || count($results) >= $limit) { continue; }
            $m = $map[$type];
            $isElement = ($type !== 'resource');

            // Non-static (DB LIKE on content OR name)
            $c = $this->modx->newQuery($m['class']);
            $c->where(array(
                array(
                    $m['content'] . ':LIKE' => '%' . $query . '%',
                    'OR:' . $m['name'] . ':LIKE' => '%' . $query . '%',
                ),
            ));
            if ($isElement) {
                $c->where(array('static' => 0));
            }
            $c->limit($limit);
            foreach ($this->modx->getCollection($m['class'], $c) as $o) {
                if (count($results) >= $limit) { break; }
                $results[] = $this->buildSearchHit($type, $o, $m, $query, (string) $o->get($m['content']), $caseSensitive, false);
            }

            // Static elements: match by reading the static file
            if ($isElement && count($results) < $limit) {
                $sc = $this->modx->newQuery($m['class']);
                $sc->where(array('static' => 1));
                $needle = $caseSensitive ? $query : strtolower($query);
                foreach ($this->modx->getCollection($m['class'], $sc) as $o) {
                    if (count($results) >= $limit) { break; }
                    $content = (string) $o->getFileContent();
                    $name = (string) $o->get($m['name']);
                    $hay = $caseSensitive ? ($content . "\n" . $name) : strtolower($content . "\n" . $name);
                    if (strpos($hay, $needle) !== false) {
                        $results[] = $this->buildSearchHit($type, $o, $m, $query, $content, $caseSensitive, true);
                    }
                }
            }
        }

        return array('query' => $query, 'count' => count($results), 'results' => $results);
    }

    private function buildSearchHit($type, $object, $map, $query, $content, $caseSensitive, $static) {
        $nameField = $map['name'];
        $name = (string) $object->get($nameField);
        $hayContent = $caseSensitive ? $content : strtolower($content);
        $needle = $caseSensitive ? $query : strtolower($query);
        $pos = strpos($hayContent, $needle);
        $field = ($pos !== false) ? $map['content'] : $nameField;
        $snippet = '';
        $line = null;
        $lineText = null;
        if ($pos !== false) {
            $start = max(0, $pos - 60);
            $snippet = substr($content, $start, strlen($query) + 120);
            $snippet = trim(preg_replace('/\s+/', ' ', $snippet));
            if ($start > 0) { $snippet = '…' . $snippet; }
            // 1-based line of the match + the exact line text (verbatim, EOL-normalised the same
            // way view_element/edit_element_lines do) so it can be reused directly as `expect`.
            $lineStart = strrpos(substr($content, 0, $pos), "\n");
            $lineStart = ($lineStart === false) ? 0 : $lineStart + 1;
            $lineEnd = strpos($content, "\n", $pos);
            if ($lineEnd === false) { $lineEnd = strlen($content); }
            $line = substr_count($content, "\n", 0, $lineStart) + 1;
            $lineText = rtrim(substr($content, $lineStart, $lineEnd - $lineStart), "\r");
        }
        return array(
            'type' => $type,
            'id' => (int) $object->get('id'),
            'name' => $name,
            'matched_field' => $field,
            'static' => $type !== 'resource' ? (bool) $object->get('static') : false,
            'line' => $line,
            'line_text' => $lineText,
            'snippet' => $snippet,
        );
    }

    /**
     * Find where an element is used. Runs a content search for the name across all code,
     * and — if a template with that name exists — also lists resources assigned to it.
     * data: name (required), limit (default 100).
     */
    private function findUsages($data) {
        $name = isset($data['name']) ? trim((string) $data['name']) : '';
        if ($name === '') {
            throw new ModxMCPClientException('find_usages: "name" is required.');
        }
        $limit = isset($data['limit']) ? (int) $data['limit'] : 100;

        $search = $this->searchCode(array('query' => $name, 'limit' => $limit));
        $usages = $search['results'];

        // Template usage: resources whose template is the one named $name.
        $templateResources = array();
        $tpl = $this->modx->getObject('modTemplate', array('templatename' => $name));
        if ($tpl) {
            $tid = (int) $tpl->get('id');
            $rc = $this->modx->newQuery('modResource', array('template' => $tid));
            $resTotal = $this->modx->getCount('modResource', $rc);
            $rc->limit($limit);
            foreach ($this->modx->getCollection('modResource', $rc) as $r) {
                $templateResources[] = array('id' => (int) $r->get('id'), 'pagetitle' => $r->get('pagetitle'), 'uri' => $r->get('uri'));
            }
            return array(
                'name' => $name,
                'content_matches' => $usages,
                'template_id' => $tid,
                'resources_using_template_total' => (int) $resTotal,
                'resources_using_template' => $templateResources,
            );
        }

        return array('name' => $name, 'content_matches' => $usages);
    }
}
