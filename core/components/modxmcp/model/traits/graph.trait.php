<?php
/** graph operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPGraphTrait {
    /**
     * Dependency graph of the site's ELEMENTS — the structural counterpart to find_usages:
     * which template pulls which chunk/snippet/TV, which snippet renders which chunk, where
     * each TV is attached. Read-only; also powers the visual graph in the CMP.
     *
     * TOKEN-SAFETY (same hard rule as project_overview): the payload scales with the site's
     * STRUCTURE (element count), never with its CONTENT. Resources are NOT nodes by default —
     * templates carry a `resources` count instead — so a 100k-resource site returns the same
     * small graph as a tiny one. `include_resources` only adds resources that elements actually
     * link to via [[~id]], which is bounded by element content.
     *
     * data: format ('graph'|'summary'), types (subset of template/chunk/snippet/tv/plugin),
     *       focus ('name' or 'type:name'), depth (hops around focus, default 1),
     *       direction ('out'|'in'|'both'), include_resources (bool), max_nodes (default 1500).
     */
    private function dependencyGraph($data) {
        return $this->buildDependencyGraph(is_array($data) ? $data : array());
    }

    /** Public so the manager CMP can render the same graph without going through the API. */
    public function buildDependencyGraph(array $data = array()) {
        $format   = (isset($data['format']) && $data['format'] === 'summary') ? 'summary' : 'graph';
        $maxNodes = isset($data['max_nodes']) ? max(10, (int) $data['max_nodes']) : 1500;
        $withRes  = !empty($data['include_resources']);

        $elementTypes = array(
            'template' => array('class' => 'modTemplate',    'name' => 'templatename', 'content' => 'content'),
            'chunk'    => array('class' => 'modChunk',       'name' => 'name',         'content' => 'snippet'),
            'snippet'  => array('class' => 'modSnippet',     'name' => 'name',         'content' => 'snippet'),
            'tv'       => array('class' => 'modTemplateVar', 'name' => 'name',         'content' => 'default_text'),
            'plugin'   => array('class' => 'modPlugin',      'name' => 'name',         'content' => 'plugincode'),
        );

        $categories = array();
        $catParent  = array();
        $cq = $this->modx->newQuery('modCategory');
        $cq->select(array('id', 'category', 'parent'));
        foreach ($this->modx->getCollection('modCategory', $cq) as $c) {
            $categories[(int) $c->get('id')] = (string) $c->get('category');
            $catParent[(int) $c->get('id')] = (int) $c->get('parent');
        }

        // Which elements came from an installed add-on rather than from this site's own build.
        // MODX has no element→package link, but add-ons name their element category after their
        // namespace (MIGX, pdoTools, miniShop2 …), so match categories — and their descendants —
        // against installed namespaces. Lets the UI hide vendor noise; nothing is filtered here.
        $nsNames = array();
        foreach ($this->modx->getCollection('modNamespace') as $ns) {
            $k = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $ns->get('name')));
            if ($k !== '' && $k !== 'core') { $nsNames[$k] = true; }
        }
        $vendorCat = array();
        foreach ($categories as $cid => $cname) {
            $cur = $cid; $seen = array(); $isVendor = false;
            while ($cur && isset($categories[$cur]) && !isset($seen[$cur])) {
                $seen[$cur] = true;
                $k = strtolower(preg_replace('/[^a-z0-9]/i', '', $categories[$cur]));
                if (isset($nsNames[$k])) { $isVendor = true; break; }
                $cur = isset($catParent[$cur]) ? $catParent[$cur] : 0;
            }
            $vendorCat[$cid] = $isVendor;
        }

        $nodes = array();   // position === node index
        $index = array();   // type => name => node index
        $nodeByTypeId = array(); // type => element id => node index
        $edges = array();   // "from|to|kind" => array(from, to, kind)
        $missing = array(); // "from|kind|name" => row
        $sources = array(); // node index => list of refs found in its source

        // --- Nodes: every element is a node (structure, not content). ---
        foreach ($elementTypes as $type => $m) {
            foreach ($this->modx->getCollection($m['class']) as $o) {
                $name = (string) $o->get($m['name']);
                if ($name === '') { continue; }
                if (isset($index[$type][$name])) { continue; } // duplicate name: first wins
                $cat = (int) $o->get('category');
                $node = array(
                    'i'        => count($nodes),
                    'type'     => $type,
                    'id'       => (int) $o->get('id'),
                    'name'     => $name,
                    'category' => $cat && isset($categories[$cat]) ? $categories[$cat] : null,
                    'static'   => (bool) $o->get('static'),
                );
                if ($type === 'tv')     { $node['tv_type'] = (string) $o->get('type'); }
                if ($type === 'plugin') { $node['disabled'] = (bool) $o->get('disabled'); }
                // Vendor by category, or — for add-on elements left uncategorised, like the Ace
                // and VersionX plugins — by the element name matching an installed namespace.
                $nameKey = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
                if (($cat && !empty($vendorCat[$cat])) || isset($nsNames[$nameKey])) { $node['vendor'] = true; }
                $i = $node['i'];
                $nodes[$i] = $node;
                $index[$type][$name] = $i;
                $nodeByTypeId[$type][(int) $o->get('id')] = $i;

                // Static elements: read the file directly. (getContent() would re-save a stale
                // element as a side effect — this action must stay read-only.)
                $raw = '';
                if ($o->get('static')) {
                    try { $raw = (string) $o->getFileContent(); } catch (Exception $e) { $raw = ''; }
                    if ($raw === '') { $raw = (string) $o->get($m['content']); }
                } else {
                    $raw = (string) $o->get($m['content']);
                }
                $refs = array_merge($this->scanModxTags($raw), $this->scanPhpElementCalls($raw));
                // An element's DEFAULT properties are a first-class wiring mechanism: miniShop2's
                // msProducts points &tpl at tpl.msProducts.row there, not in any content. Without
                // this, those chunks look like orphans and the snippet→chunk link is invisible.
                $refs = array_merge($refs, $this->scanPropertyRefs($o->get('properties')));
                if ($type === 'tv') { $refs = array_merge($refs, $this->scanTvInputRefs($o)); }
                $sources[$i] = $refs;
            }
        }

        // --- Edges from the scanned references. ---
        $linkedResources = array();
        foreach ($sources as $from => $refs) {
            foreach ($refs as $ref) {
                $kind = $ref['kind'];
                $name = $ref['name'];
                if ($kind === 'resource') {
                    if ($withRes) { $linkedResources[(int) $name][] = $from; }
                    continue;
                }
                if (isset($index[$kind][$name])) {
                    $to = $index[$kind][$name];
                    if ($to === $from) { continue; } // self-reference (e.g. a recursive chunk)
                    $edges[$from . '|' . $to . '|' . $ref['via']] = array($from, $to, $ref['via']);
                    continue;
                }
                // Unresolved. [[*x]] is usually a resource FIELD, and property/php refs are
                // heuristic — only explicit [[$chunk]] / [[snippet]] tags are reported as broken.
                if ($ref['via'] === 'tag' && ($kind === 'chunk' || $kind === 'snippet')) {
                    $k = $from . '|' . $kind . '|' . $name;
                    $missing[$k] = array(
                        'from'      => $nodes[$from]['type'] . ':' . $nodes[$from]['name'],
                        'from_id'   => $nodes[$from]['id'],
                        'kind'      => $kind,
                        'name'      => $name,
                    );
                }
            }
        }

        // --- Relational edges: TVs attached to templates (the real, authoritative link). ---
        $tvById = array();
        foreach ($nodes as $n) { if ($n['type'] === 'tv') { $tvById[$n['id']] = $n['i']; } }
        $tplById = array();
        foreach ($nodes as $n) { if ($n['type'] === 'template') { $tplById[$n['id']] = $n['i']; } }
        $lq = $this->modx->newQuery('modTemplateVarTemplate');
        $lq->select(array('templateid', 'tmplvarid'));
        foreach ($this->modx->getCollection('modTemplateVarTemplate', $lq) as $l) {
            $t = (int) $l->get('templateid');
            $v = (int) $l->get('tmplvarid');
            if (isset($tplById[$t]) && isset($tvById[$v])) {
                $edges[$tplById[$t] . '|' . $tvById[$v] . '|attached'] = array($tplById[$t], $tvById[$v], 'attached');
            }
        }

        // --- Named property sets: [[msProducts@mySet]] can re-point &tpl at another chunk. ---
        $setRefs = array();
        foreach ($this->modx->getCollection('modPropertySet') as $ps) {
            $r = $this->scanPropertyRefs($ps->get('properties'));
            if ($r) { $setRefs[(int) $ps->get('id')] = $r; }
        }
        if ($setRefs) {
            $classToType = array(
                'modTemplate' => 'template', 'modChunk' => 'chunk', 'modSnippet' => 'snippet',
                'modTemplateVar' => 'tv', 'modPlugin' => 'plugin',
            );
            foreach ($this->modx->getCollection('modElementPropertySet') as $link) {
                $cls  = (string) $link->get('element_class');
                $pset = (int) $link->get('property_set');
                if (!isset($classToType[$cls]) || !isset($setRefs[$pset])) { continue; }
                $t = $classToType[$cls];
                $eid = (int) $link->get('element');
                if (!isset($nodeByTypeId[$t][$eid])) { continue; }
                $from = $nodeByTypeId[$t][$eid];
                foreach ($setRefs[$pset] as $ref) {
                    if (!isset($index['chunk'][$ref['name']])) { continue; }
                    $to = $index['chunk'][$ref['name']];
                    if ($to === $from) { continue; }
                    $edges[$from . '|' . $to . '|property'] = array($from, $to, 'property');
                }
            }
        }

        // --- Chunks named in SYSTEM SETTINGS (miniShop2 keeps its email templates there, MODX
        //     keeps error/unauthorized pages). No node — a setting is not an element — but such a
        //     chunk is genuinely in use and must never be reported as a safe-to-delete orphan. ---
        $settingUse = array();
        $sq = $this->modx->newQuery('modSystemSetting');
        $sq->select(array('key', 'value'));
        foreach ($this->modx->getCollection('modSystemSetting', $sq) as $s) {
            $k = (string) $s->get('key');
            if (!preg_match('/tpl|chunk|template/i', $k)) { continue; }
            $v = trim((string) $s->get('value'));
            if ($v === '' || $v[0] === '@') { continue; }
            $n = $this->tagName($v);
            if ($n !== '' && $n === $v && isset($index['chunk'][$n]) && !isset($settingUse[$n])) {
                $settingUse[$n] = $k;
                $nodes[$index['chunk'][$n]]['used_by'] = 'system setting ' . $k;
            }
        }

        // --- miniShop2 order statuses point at their e-mail chunks BY ID (ms2_order_statuses
        //     .body_user / .body_manager), so those chunks carry no textual reference anywhere
        //     and would be reported as dead code. Guarded — a no-op without miniShop2. ---
        $ms2Core = $this->getMiniShop2CorePath();
        if (!empty($nodeByTypeId['chunk']) && is_dir($ms2Core . 'model/')) {
            try {
                $this->modx->addPackage('minishop2', $ms2Core . 'model/');
                $osq = $this->modx->newQuery('msOrderStatus');
                $osq->select(array('id', 'name', 'body_user', 'body_manager'));
                foreach ($this->modx->getCollection('msOrderStatus', $osq) as $st) {
                    foreach (array('body_user', 'body_manager') as $f) {
                        $cid = (int) $st->get($f);
                        if ($cid > 0 && isset($nodeByTypeId['chunk'][$cid])) {
                            $nodes[$nodeByTypeId['chunk'][$cid]]['used_by'] =
                                'miniShop2 order status "' . $st->get('name') . '" (' . $f . ')';
                        }
                    }
                }
            } catch (Exception $e) {
                // Accuracy nicety only — never let an add-on's model break the graph.
            } catch (Throwable $e) {
            }
        }

        // --- Template resource counts (O(#templates) COUNTs — never a resource listing). ---
        foreach ($tplById as $tid => $i) {
            $nodes[$i]['resources'] = (int) $this->modx->getCount('modResource', array('template' => $tid, 'deleted' => 0));
        }

        // --- Plugin events (what triggers each plugin) as a node field, not as edges. ---
        $pluginById = array();
        foreach ($nodes as $n) { if ($n['type'] === 'plugin') { $pluginById[$n['id']] = $n['i']; } }
        if ($pluginById) {
            $eq = $this->modx->newQuery('modPluginEvent');
            $eq->select(array('pluginid', 'event'));
            foreach ($this->modx->getCollection('modPluginEvent', $eq) as $pe) {
                $pid = (int) $pe->get('pluginid');
                if (!isset($pluginById[$pid])) { continue; }
                $i = $pluginById[$pid];
                if (!isset($nodes[$i]['events'])) { $nodes[$i]['events'] = array(); }
                if (count($nodes[$i]['events']) < 12) { $nodes[$i]['events'][] = (string) $pe->get('event'); }
            }
        }

        // --- Optional resource nodes: only those elements actually link to via [[~id]]. ---
        if ($withRes && $linkedResources) {
            $ids = array_slice(array_keys($linkedResources), 0, max(0, $maxNodes - count($nodes)));
            if ($ids) {
                $rq = $this->modx->newQuery('modResource', array('id:IN' => $ids));
                $rq->select(array('id', 'pagetitle', 'template', 'published'));
                foreach ($this->modx->getCollection('modResource', $rq) as $r) {
                    $rid = (int) $r->get('id');
                    $i = count($nodes);
                    $nodes[$i] = array(
                        'i' => $i, 'type' => 'resource', 'id' => $rid,
                        'name' => (string) $r->get('pagetitle'),
                        'category' => null, 'static' => false,
                        'published' => (bool) $r->get('published'),
                    );
                    foreach ($linkedResources[$rid] as $from) {
                        $edges[$from . '|' . $i . '|link'] = array($from, $i, 'link');
                    }
                    $tpl = (int) $r->get('template');
                    if (isset($tplById[$tpl])) {
                        $edges[$i . '|' . $tplById[$tpl] . '|template'] = array($i, $tplById[$tpl], 'template');
                    }
                }
            }
        }

        $edges = array_values($edges);

        // --- Degrees over the WHOLE graph (kept even in a focused view: "used in N places"). ---
        foreach ($nodes as $i => $n) { $nodes[$i]['in'] = 0; $nodes[$i]['out'] = 0; }
        foreach ($edges as $e) { $nodes[$e[0]]['out']++; $nodes[$e[1]]['in']++; }

        // --- Orphans: reachable by nothing. Templates are entry points (judged by resource use),
        //     plugins are triggered by events — neither is an orphan for lack of references. ---
        $orphans = array();
        $candidates = array();
        foreach ($nodes as $n) {
            if ($n['in'] === 0 && ($n['type'] === 'chunk' || $n['type'] === 'snippet' || $n['type'] === 'tv')) {
                if (isset($n['used_by'])) { continue; }   // wired via a system setting
                $candidates[] = $n;
            } elseif ($n['type'] === 'template' && isset($n['resources']) && $n['resources'] === 0) {
                $orphans[] = array('type' => 'template', 'id' => $n['id'], 'name' => $n['name'], 'reason' => 'no resources use it');
            }
        }
        // A chunk pasted straight into a RESOURCE's content ([[$cta]] inside a page body) has no
        // element referencing it and would look orphaned. Verify each candidate with ONE targeted
        // COUNT — bounded by the number of candidates, not by site size. Auto-skipped on very
        // large sites (where a content LIKE scan is too costly); `verify_orphans` forces it.
        $resTotal = (int) $this->modx->getCount('modResource');
        $verified = isset($data['verify_orphans']) ? !empty($data['verify_orphans']) : ($resTotal <= 5000);
        foreach ($candidates as $n) {
            if ($verified) {
                $prefix = ($n['type'] === 'chunk') ? '[[$' : (($n['type'] === 'tv') ? '[[*' : '[[');
                $unc    = ($n['type'] === 'chunk') ? '[[!$' : (($n['type'] === 'tv') ? '[[!*' : '[[!');
                $q = $this->modx->newQuery('modResource');
                $q->where(array(array(
                    'content:LIKE'    => '%' . $prefix . $n['name'] . '%',
                    'OR:content:LIKE' => '%' . $unc . $n['name'] . '%',
                )));
                if ((int) $this->modx->getCount('modResource', $q) > 0) { continue; }
            }
            $orphans[] = array(
                'type'   => $n['type'],
                'id'     => $n['id'],
                'name'   => $n['name'],
                'reason' => ($n['type'] === 'tv') ? 'not attached to any template and never referenced' : 'never referenced',
            );
        }
        $missing = array_values($missing);

        $byType = array();
        $vendorCount = 0;
        foreach ($nodes as $n) {
            $byType[$n['type']] = isset($byType[$n['type']]) ? $byType[$n['type']] + 1 : 1;
            if (!empty($n['vendor'])) { $vendorCount++; }
        }
        $stats = array(
            'nodes'   => count($nodes),
            'edges'   => count($edges),
            'by_type' => $byType,
            'missing' => count($missing),
            'orphans' => count($orphans),
            // Elements whose category matches an installed add-on namespace — vendor code, not
            // this site's own. Useful to filter out; never filtered server-side.
            'vendor'  => $vendorCount,
            // false => orphans were NOT cross-checked against resource content, so the list may
            // include chunks/TVs that are only used inside pages. Re-run with verify_orphans:true.
            'orphans_verified' => $verified,
        );

        // Orphans are candidates, not proof: an add-on may reference an element from its own
        // tables. Say so, so nothing deletes on this list alone.
        $orphanNote = 'Orphans are CANDIDATES, not proof. Checked: element content, element default'
            . ' properties, named property sets, system settings, miniShop2 order-status e-mails,'
            . ' template↔TV attachment' . ($verified ? ', resource content' : '')
            . '. An add-on can still reference an element from its own tables, and dynamic names'
            . ' ($modx->getChunk($var)) are invisible. Confirm with delete_element {dry_run:true}.';

        if ($format === 'summary') {
            return array('stats' => $stats, 'missing' => $missing, 'orphans' => $orphans,
                'orphans_note' => $orphanNote,
                'note' => 'Health summary only. Call again without format:"summary" for the nodes/edges graph.');
        }

        // --- Optional focus: keep only what is within `depth` hops of one element. ---
        $focused = null;
        if (!empty($data['focus'])) {
            $focused = $this->focusSubgraph($nodes, $edges, $index, (string) $data['focus'],
                isset($data['depth']) ? (int) $data['depth'] : 1,
                isset($data['direction']) ? (string) $data['direction'] : 'both');
            $nodes = $focused['nodes'];
            $edges = $focused['edges'];
        }

        // --- Optional type filter (applied after focus so a focused view stays connected). ---
        if (isset($data['types']) && is_array($data['types']) && $data['types']) {
            $keep = array_flip($data['types']);
            $kept = array();
            foreach ($nodes as $n) { if (isset($keep[$n['type']])) { $kept[$n['i']] = $n; } }
            $nodes = $kept;
            $edges = array_values(array_filter($edges, function ($e) use ($kept) {
                return isset($kept[$e[0]]) && isset($kept[$e[1]]);
            }));
        }

        // --- Cap, then renumber so `i` and the edge endpoints are contiguous array indices. ---
        $truncated = false;
        if (count($nodes) > $maxNodes) {
            $nodes = array_slice($nodes, 0, $maxNodes, true);
            $truncated = true;
        }
        $remap = array();
        $out = array();
        foreach ($nodes as $n) { $remap[$n['i']] = count($out); $n['i'] = count($out); $out[] = $n; }
        $outEdges = array();
        foreach ($edges as $e) {
            if (isset($remap[$e[0]]) && isset($remap[$e[1]])) { $outEdges[] = array($remap[$e[0]], $remap[$e[1]], $e[2]); }
        }

        $result = array(
            'stats'     => $stats,
            'returned'  => array('nodes' => count($out), 'edges' => count($outEdges)),
            'truncated' => $truncated,
            'legend'    => array(
                'nodes' => 'i=index, type, id, name, category, in/out = reference degree across the WHOLE site.',
                'edges' => '[from_i, to_i, kind] — from USES to. kind: tag ([[$chunk]]/[[snippet]]/[[*tv]]), attached (TV on template), property (&tpl=`chunk`, an element default property, or a named property set), php ($modx->getChunk/runSnippet), migx (inputTV/renderchunktpl), binding (@CHUNK), link ([[~id]]), template (resource uses template).',
            ),
            'nodes'     => $out,
            'edges'     => $outEdges,
        );
        if ($focused !== null) {
            $result['focus'] = $focused['focus'];
            if ($focused['focus'] === null) { $result['error'] = 'focus target not found; returning an empty subgraph.'; }
        } else {
            $result['missing'] = $missing;
            $result['orphans'] = $orphans;
            if ($orphans) { $result['orphans_note'] = $orphanNote; }
        }
        return $result;
    }

    /** BFS `depth` hops out of / into a focus node. `focus` is "name" or "type:name". */
    private function focusSubgraph($nodes, $edges, $index, $focus, $depth, $direction) {
        $depth = ($depth < 1) ? 1 : (($depth > 5) ? 5 : $depth);
        if (!in_array($direction, array('out', 'in', 'both'), true)) { $direction = 'both'; }

        $start = null;
        if (strpos($focus, ':') !== false) {
            list($t, $n) = explode(':', $focus, 2);
            if (isset($index[$t][$n])) { $start = $index[$t][$n]; }
        }
        if ($start === null) {
            foreach ($index as $t => $names) { if (isset($names[$focus])) { $start = $names[$focus]; break; } }
        }
        if ($start === null) {
            return array('nodes' => array(), 'edges' => array(), 'focus' => null);
        }

        $adj = array();
        foreach ($edges as $e) {
            if ($direction === 'out' || $direction === 'both') { $adj[$e[0]][] = $e[1]; }
            if ($direction === 'in'  || $direction === 'both') { $adj[$e[1]][] = $e[0]; }
        }
        $seen = array($start => true);
        $frontier = array($start);
        for ($d = 0; $d < $depth && $frontier; $d++) {
            $next = array();
            foreach ($frontier as $cur) {
                if (!isset($adj[$cur])) { continue; }
                foreach ($adj[$cur] as $nb) {
                    if (!isset($seen[$nb])) { $seen[$nb] = true; $next[] = $nb; }
                }
            }
            $frontier = $next;
        }

        $keptNodes = array();
        foreach ($nodes as $n) { if (isset($seen[$n['i']])) { $keptNodes[$n['i']] = $n; } }
        $keptEdges = array();
        foreach ($edges as $e) { if (isset($seen[$e[0]]) && isset($seen[$e[1]])) { $keptEdges[] = $e; } }
        return array(
            'nodes' => $keptNodes,
            'edges' => $keptEdges,
            'focus' => array('node' => $nodes[$start]['type'] . ':' . $nodes[$start]['name'], 'depth' => $depth, 'direction' => $direction),
        );
    }

    /**
     * Extract element references from MODX tags in a source string. Peels innermost tags
     * outward so nested tags ([[$wrap? &in=`[[$inner]]`]]) are all seen.
     * Returns rows: kind (chunk|snippet|tv|resource), name, via (tag|property|link).
     */
    private function scanModxTags($content) {
        $refs = array();
        if (!is_string($content) || $content === '' || strpos($content, '[[') === false) { return $refs; }
        $s = $content;
        for ($pass = 0; $pass < 12; $pass++) {
            if (!preg_match_all('/\[\[([^\[\]]*)\]\]/s', $s, $m, PREG_SET_ORDER)) { break; }
            foreach ($m as $hit) {
                foreach ($this->parseModxTag($hit[1]) as $r) { $refs[] = $r; }
            }
            $cnt = 0;
            $s = preg_replace('/\[\[([^\[\]]*)\]\]/s', ' ', $s, -1, $cnt);
            if (!$cnt) { break; }
        }
        return $refs;
    }

    /** Parse one tag body (without the [[ ]]) into element references. */
    private function parseModxTag($token) {
        $refs = array();
        $t = ltrim((string) $token);
        $t = ltrim($t, '!');           // uncached
        if ($t === '') { return $refs; }
        $c = $t[0];
        // Placeholders [[+x]], settings [[++x]], lexicon [[%x]], comments [[-x]] reference no element.
        if ($c === '+' || $c === '%' || $c === '-' || $c === '#' || $c === ':') { return $refs; }

        if ($c === '$') {
            $n = $this->tagName(substr($t, 1));
            if ($n !== '') { $refs[] = array('kind' => 'chunk', 'name' => $n, 'via' => 'tag'); }
        } elseif ($c === '*') {
            $n = $this->tagName(substr($t, 1));
            if ($n !== '') { $refs[] = array('kind' => 'tv', 'name' => $n, 'via' => 'tag'); }
        } elseif ($c === '~') {
            $n = $this->tagName(substr($t, 1));
            if ($n !== '' && ctype_digit($n)) { $refs[] = array('kind' => 'resource', 'name' => $n, 'via' => 'link'); }
            return $refs;
        } else {
            $n = $this->tagName($t);
            if ($n !== '') { $refs[] = array('kind' => 'snippet', 'name' => $n, 'via' => 'tag'); }
        }

        // Chunk-valued properties: &tpl=`chunkName`, &tplWrapper=`x`, &rowChunk=`y`. Heuristic —
        // emitted as edges only when the value resolves to a real chunk, never as "missing".
        if (strpos($t, '&') !== false && preg_match_all('/&([A-Za-z0-9_\-\.]+)\s*=\s*`([^`]*)`/s', $t, $pm, PREG_SET_ORDER)) {
            foreach ($pm as $p) {
                if (!preg_match('/tpl|chunk|wrapper/i', $p[1])) { continue; }
                $v = trim($p[2]);
                // @INLINE / @FILE / @CODE bindings are literal templates, not chunk names.
                if ($v === '' || $v[0] === '@') { continue; }
                $n = $this->tagName($v);
                if ($n !== '' && $n === $v) { $refs[] = array('kind' => 'chunk', 'name' => $n, 'via' => 'property'); }
            }
        }
        return $refs;
    }

    /** Leading element-name characters of a tag body ("header? &x=`1`" -> "header"). */
    private function tagName($s) {
        return preg_match('/^([A-Za-z0-9_\-\.\/]+)/', ltrim((string) $s), $m) ? $m[1] : '';
    }

    /**
     * Chunk references inside a properties blob — an element's default `properties` or a named
     * modPropertySet. Handles both storage shapes: the modern definition list
     * ([{name, value, type, ...}, ...]) and a plain name => value map.
     * Only property NAMES that look template-ish are considered, and only values that resolve to
     * a real chunk become edges (never reported as missing) — same rule as inline &tpl=`x`.
     */
    private function scanPropertyRefs($props) {
        $refs = array();
        if (!is_array($props)) { return $refs; }
        foreach ($props as $key => $p) {
            if (is_array($p)) {
                $name  = isset($p['name']) ? $p['name'] : (is_string($key) ? $key : null);
                $value = isset($p['value']) ? $p['value'] : null;
            } else {
                $name  = is_string($key) ? $key : null;
                $value = $p;
            }
            if ($name === null || $value === null || !is_scalar($value)) { continue; }
            if (!preg_match('/tpl|chunk|wrapper/i', (string) $name)) { continue; }
            $v = trim((string) $value);
            if ($v === '' || $v[0] === '@') { continue; }   // @INLINE/@FILE are literal templates
            $n = $this->tagName($v);
            if ($n !== '' && $n === $v) {
                $refs[] = array('kind' => 'chunk', 'name' => $n, 'via' => 'property');
            }
        }
        return $refs;
    }

    /** $modx->getChunk('x') / parseChunk('x') / runSnippet('x') inside snippet & plugin code. */
    private function scanPhpElementCalls($content) {
        $refs = array();
        if (!is_string($content) || strpos($content, '->') === false) { return $refs; }
        if (preg_match_all('/->\s*(?:getChunk|parseChunk)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $h) { if ($h[1] !== '' && $h[1][0] !== '@') { $refs[] = array('kind' => 'chunk', 'name' => $h[1], 'via' => 'php'); } }
        }
        if (preg_match_all('/->\s*runSnippet\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $h) { if ($h[1] !== '') { $refs[] = array('kind' => 'snippet', 'name' => $h[1], 'via' => 'php'); } }
        }
        return $refs;
    }

    /**
     * References hiding in a TV's input wiring: MIGX `inputTV` (a helper TV used as a field's
     * input — the ...ForMigx convention) and `renderchunktpl`/`chunk` column renderers, plus
     * @CHUNK bindings in `elements` / `default_text`.
     */
    private function scanTvInputRefs($tv) {
        $refs = array();
        $props = $tv->get('input_properties');
        if (is_array($props) && $props) {
            // MIGX keeps formtabs/columns as JSON *strings* inside input_properties, so the
            // encoded blob has escaped quotes (and nested configs escape them twice). Unescape
            // layer by layer, matching on each pass. Duplicate hits are harmless (edges dedupe).
            $hay = json_encode($props);
            for ($pass = 0; $pass < 3 && is_string($hay) && $hay !== ''; $pass++) {
                if (preg_match_all('/"(inputTV|renderchunktpl|chunk|formtabs_chunk)"\s*:\s*"([^"]+)"/', $hay, $m, PREG_SET_ORDER)) {
                    foreach ($m as $h) {
                        $v = trim($h[2]);
                        if ($v === '' || $v[0] === '@') { continue; }
                        $refs[] = array('kind' => ($h[1] === 'inputTV') ? 'tv' : 'chunk', 'name' => $v, 'via' => 'migx');
                    }
                }
                if (strpos($hay, '\\') === false) { break; }
                $hay = stripcslashes($hay);
            }
        }
        foreach (array('elements', 'default_text') as $f) {
            $v = (string) $tv->get($f);
            if ($v !== '' && preg_match('/@CHUNK\s+([A-Za-z0-9_\-\.\/]+)/i', $v, $m)) {
                $refs[] = array('kind' => 'chunk', 'name' => $m[1], 'via' => 'binding');
            }
        }
        return $refs;
    }
}
