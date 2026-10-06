<?php
/** diagnostics operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPDiagnosticsTrait {
    // --- Diagnostics / maintenance ---

    private function readErrorLog($data) {
        $path = rtrim($this->modx->getOption('core_path'), '/') . '/cache/logs/error.log';
        $tail = ModxMCPLogReader::tail($path, isset($data['limit']) ? $data['limit'] : 100,
            $this->modx->getOption('modxmcp.max_read_bytes', null, 262144), function ($line) { return $line; });
        $lines = $tail['items'];
        unset($tail['items']);
        return array_merge(array('total' => count($lines), 'lines' => $lines), $tail);
    }

    private function refreshUris($data) {
        $resp = $this->modx->runProcessor('system/refreshuris', array());
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'refresh_uris: no response.'); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('refresh_uris', 'system', array());
        return array('refreshed' => true);
    }

    private function removeLocks($data) {
        $resp = $this->modx->runProcessor('system/remove_locks', array());
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'remove_locks: no response.'); }
        $this->logAudit('remove_locks', 'system', array());
        return $this->normalizeProcessorResponse($resp);
    }

    private function systemInfo($data) {
        $v = method_exists($this->modx, 'getVersionData') ? $this->modx->getVersionData() : array();
        return array(
            'modx_version'    => isset($v['full_version']) ? $v['full_version'] : (isset($v['version']) ? $v['version'] : null),
            'modxmcp_version' => self::VERSION,
            'php_version'     => PHP_VERSION,
            'dbtype'          => $this->modx->getOption('dbtype'),
            'base_path'       => $this->modx->getOption('base_path'),
            'core_path'       => $this->modx->getOption('core_path'),
        );
    }

    /**
     * Compact, token-safe project map so a model orients in ONE call. Scales with the site's
     * STRUCTURE, never its CONTENT: resources/products are COUNTS only; the resource tree is
     * roots + child counts (depth 1, capped); structural lists are capped with totals. A site
     * with 100k resources returns the same small payload as a tiny one. Per-item browsing stays
     * in the paginated tools (list_resources, list_elements). data: sections?[], max_tree_nodes?.
     */
    private function projectOverview($data) {
        $all = array('modx', 'counts', 'templates', 'tvs', 'resource_tree', 'resources_by_template', 'resources_by_context', 'element_categories', 'content_types', 'contexts', 'integrations');
        $req = (isset($data['sections']) && is_array($data['sections']) && $data['sections'])
            ? array_values(array_intersect($all, $data['sections']))
            : $all;
        $want = array_flip($req);
        $cap = 200;
        $maxTree = isset($data['max_tree_nodes']) ? min(500, max(1, (int) $data['max_tree_nodes'])) : 50;
        $cnt = function ($class, $crit = null) { return (int) $this->modx->getCount($class, $crit); };
        $out = array();

        if (isset($want['modx'])) {
            $v = method_exists($this->modx, 'getVersionData') ? $this->modx->getVersionData() : array();
            $out['modx'] = array(
                'version'         => isset($v['full_version']) ? $v['full_version'] : (isset($v['version']) ? $v['version'] : null),
                'site_url'        => $this->modx->getOption('site_url'),
                'modxmcp_version' => self::VERSION,
            );
        }

        if (isset($want['counts'])) {
            $out['counts'] = array(
                'resources'           => $cnt('modResource'),
                'published_resources' => $cnt('modResource', array('published' => 1, 'deleted' => 0)),
                'deleted_resources'   => $cnt('modResource', array('deleted' => 1)),
                'templates'           => $cnt('modTemplate'),
                'tvs'                 => $cnt('modTemplateVar'),
                'chunks'              => $cnt('modChunk'),
                'snippets'            => $cnt('modSnippet'),
                'plugins'             => $cnt('modPlugin'),
                'categories'          => $cnt('modCategory'),
                'contexts'            => $cnt('modContext'),
                'users'               => $cnt('modUser'),
                'media_sources'       => $cnt('sources.modMediaSource'),
            );
            $products = $cnt('modResource', array('class_key' => 'msProduct'));
            if ($products > 0) { $out['counts']['ms2_products'] = $products; }
        }

        // Build the template list once (reused by templates + resources_by_template).
        $tplRows = null;
        if (isset($want['templates']) || isset($want['resources_by_template'])) {
            $tplRows = array();
            $q = $this->modx->newQuery('modTemplate');
            $q->select(array('id', 'templatename'));
            $q->sortby('templatename', 'ASC');
            $q->limit($cap);
            foreach ($this->modx->getCollection('modTemplate', $q) as $t) {
                $tplRows[(int) $t->get('id')] = $t->get('templatename');
            }
        }

        $templateCounts = $tplRows !== null ? $this->resourceCountsBy('template', array_keys($tplRows)) : array();

        if (isset($want['templates'])) {
            $tplTotal = $cnt('modTemplate');
            // tv names + template→tv attachments (bounded by #templates × #tvs).
            $tvName = array();
            $attach = array();
            if ($tplRows) {
                $lq = $this->modx->newQuery('modTemplateVarTemplate');
                $lq->leftJoin('modTemplateVar', 'Tv', 'Tv.id = modTemplateVarTemplate.tmplvarid');
                $lq->select($this->modx->getSelectColumns('modTemplateVarTemplate', 'modTemplateVarTemplate', '', array('templateid', 'tmplvarid')));
                $lq->select('Tv.name AS tv_name');
                $lq->where(array('templateid:IN' => array_keys($tplRows)));
                $lq->sortby('templateid', 'ASC');
                $lq->sortby('tmplvarid', 'ASC');
                if (!$lq->prepare() || !$lq->stmt->execute()) { throw new Exception('Cannot list template TV attachments.'); }
                while ($link = $lq->stmt->fetch(PDO::FETCH_ASSOC)) {
                    $tvId = (int) $link['tmplvarid'];
                    $attach[(int) $link['templateid']][] = $tvId;
                    if ($link['tv_name'] !== null) { $tvName[$tvId] = $link['tv_name']; }
                }
            }
            $items = array();
            foreach ($tplRows as $tid => $name) {
                $tvids = isset($attach[$tid]) ? $attach[$tid] : array();
                $names = array();
                foreach ($tvids as $i) { if (isset($tvName[$i])) { $names[] = $tvName[$i]; } }
                $items[] = array('id' => $tid, 'name' => $name, 'tv_ids' => $tvids, 'tv_names' => $names, 'resource_count' => isset($templateCounts[$tid]) ? $templateCounts[$tid] : 0);
            }
            $out['templates'] = array('total' => $tplTotal, 'truncated' => $tplTotal > count($items), 'items' => $items);
        }

        if (isset($want['resources_by_template'])) {
            $rbt = array();
            foreach ($tplRows as $tid => $name) { $rbt[] = array('template_id' => $tid, 'template_name' => $name, 'count' => isset($templateCounts[$tid]) ? $templateCounts[$tid] : 0); }
            $out['resources_by_template'] = $rbt;
        }

        if (isset($want['tvs'])) {
            $tvTotal = $cnt('modTemplateVar');
            $items = array();
            $q = $this->modx->newQuery('modTemplateVar');
            $q->select(array('id', 'name', 'type', 'caption'));
            $q->sortby('name', 'ASC');
            $q->limit($cap);
            foreach ($this->modx->getCollection('modTemplateVar', $q) as $tv) {
                $items[] = array('id' => (int) $tv->get('id'), 'name' => $tv->get('name'), 'type' => $tv->get('type'), 'caption' => $tv->get('caption'));
            }
            $out['tvs'] = array('total' => $tvTotal, 'truncated' => $tvTotal > count($items), 'items' => $items);
        }

        if (isset($want['resource_tree'])) {
            $rootTotal = $cnt('modResource', array('parent' => 0, 'deleted' => 0));
            $q = $this->modx->newQuery('modResource', array('parent' => 0, 'deleted' => 0));
            $q->select(array('id', 'pagetitle', 'context_key', 'template', 'published', 'isfolder'));
            $q->sortby('context_key', 'ASC');
            $q->sortby('menuindex', 'ASC');
            $q->limit($maxTree);
            $roots = array();
            foreach ($this->modx->getCollection('modResource', $q) as $r) {
                $rid = (int) $r->get('id');
                $roots[] = array(
                    'id'          => $rid,
                    'pagetitle'   => $r->get('pagetitle'),
                    'context_key' => $r->get('context_key'),
                    'template'    => (int) $r->get('template'),
                    'published'   => (bool) $r->get('published'),
                    'isfolder'    => (bool) $r->get('isfolder'),
                    'child_count' => 0,
                );
            }
            $rootIds = array();
            foreach ($roots as $node) { $rootIds[] = $node['id']; }
            $childCounts = $this->resourceCountsBy('parent', $rootIds, array('deleted' => 0));
            foreach ($roots as &$node) { $node['child_count'] = isset($childCounts[$node['id']]) ? $childCounts[$node['id']] : 0; }
            unset($node);
            $out['resource_tree'] = array('depth' => 1, 'total_roots' => $rootTotal, 'truncated' => $rootTotal > count($roots), 'note' => 'Roots + child counts only. Drill down with list_resources(parent=...).', 'roots' => $roots);
        }

        $contextRows = null;
        if (isset($want['resources_by_context']) || isset($want['contexts'])) {
            $contextRows = array();
            $cq = $this->modx->newQuery('modContext');
            $cq->select(array('key', 'name'));
            foreach ($this->modx->getCollection('modContext', $cq) as $ctx) { $contextRows[$ctx->get('key')] = $ctx->get('name'); }
        }
        if (isset($want['resources_by_context'])) {
            $rbc = array();
            $contextCounts = $this->resourceCountsBy('context_key', array_keys($contextRows), array('deleted' => 0));
            foreach ($contextRows as $key => $name) {
                $rbc[] = array('context' => $key, 'count' => isset($contextCounts[$key]) ? $contextCounts[$key] : 0);
            }
            $out['resources_by_context'] = $rbc;
        }

        if (isset($want['element_categories'])) {
            $catTotal = $cnt('modCategory');
            $items = array();
            $q = $this->modx->newQuery('modCategory');
            $q->select(array('id', 'category'));
            $q->sortby('category', 'ASC');
            $q->limit($cap);
            foreach ($this->modx->getCollection('modCategory', $q) as $c) { $items[] = array('id' => (int) $c->get('id'), 'name' => $c->get('category')); }
            $out['element_categories'] = array('total' => $catTotal, 'truncated' => $catTotal > count($items), 'items' => $items);
        }

        if (isset($want['content_types'])) {
            $items = array();
            $q = $this->modx->newQuery('modContentType');
            $q->select(array('id', 'name', 'mime_type', 'file_extensions'));
            $q->limit($cap);
            foreach ($this->modx->getCollection('modContentType', $q) as $ct) {
                $items[] = array('id' => (int) $ct->get('id'), 'name' => $ct->get('name'), 'mime' => $ct->get('mime_type'), 'extensions' => $ct->get('file_extensions'));
            }
            $out['content_types'] = $items;
        }

        if (isset($want['contexts'])) {
            $items = array();
            foreach ($contextRows as $key => $name) { $items[] = array('key' => $key, 'name' => $name); }
            $out['contexts'] = $items;
        }

        if (isset($want['integrations'])) {
            $rep = $this->getIntegrationsReport();
            $out['integrations'] = isset($rep['integrations']) ? $rep['integrations'] : array();
        }

        return $out;
    }

    /**
     * Generate a fresh modxmcp.api_token, save it, and return it once.
     */
    private function flushPermissions() {
        $cm = $this->modx->getCacheManager();
        $ok = $cm ? $cm->flushPermissions() : false;
        $this->logAudit('flush_permissions', 'system', array());
        return array('flushed' => (bool) $ok);
    }

    private function logAudit($action, $elementType, array $payload =[]) {
        $auditEnabled = (bool)$this->modx->getOption('modxmcp.audit_log', null, true);
        if (!$auditEnabled) {
            return;
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            sprintf(
                '[modxmcp] action=%s type=%s service_user=%s payload=%s',
                $action,
                $elementType,
                $this->modx->user ? $this->modx->user->get('id') : 'unknown',
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            )
        );

        $this->writeAuditFile($action, $elementType, $payload);
    }

    private function auditLogDir() {
        // Live under the component (not core/cache/, which MODX wipes on a cache refresh).
        return rtrim($this->modx->getOption('core_path'), '/') . '/components/modxmcp/logs';
    }

    private function auditLogPath() {
        return $this->auditLogDir() . '/audit.log';
    }

    private function writeAuditFile($action, $elementType, array $payload) {
        try {
            $dir = $this->auditLogDir();
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            $entry = array(
                'ts'      => date('c'),
                'action'  => $action,
                'type'    => $elementType,
                'user'    => $this->modx->user ? (int) $this->modx->user->get('id') : null,
                'payload' => $payload,
            );
            @file_put_contents($dir . '/audit.log', json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            /* auditing must never break the action */
        }
    }

    /**
     * Read back the modxMCP audit trail (last N entries, newest last), optionally
     * filtered to one action.
     */
    /** Inspect request state without re-executing the original action. */
    private function getRequestStatus($data) {
        require_once $this->config['corePath'] . 'model/requeststore.class.php';
        if (!isset($data['request_id']) || !ModxMCPRequestStore::validId($data['request_id'])) {
            throw new ModxMCPClientException('A valid request_id is required.');
        }
        if (array_key_exists('include_result', $data) && !is_bool($data['include_result'])) {
            throw new ModxMCPClientException('include_result must be a JSON boolean.');
        }
        $directory = rtrim($this->modx->getOption('core_path'), '/\\') . '/modxmcp-data/requests';
        $store = new ModxMCPRequestStore($directory,
            $this->modx->getOption('modxmcp.request_retention_seconds', null, 86400),
            $this->modx->getOption('modxmcp.max_response_bytes', null, 4194304));
        return $store->status($data['request_id'], !empty($data['include_result']));
    }

    private function readAuditLog($data) {
        $filterAction = isset($data['action']) ? (string) $data['action'] : '';
        $tail = ModxMCPLogReader::tail($this->auditLogPath(), isset($data['limit']) ? $data['limit'] : 100,
            $this->modx->getOption('modxmcp.max_read_bytes', null, 262144), function ($line) use ($filterAction) {
                $entry = json_decode($line, true);
                if (!is_array($entry) || ($filterAction !== '' && (!isset($entry['action']) || $entry['action'] !== $filterAction))) { return null; }
                return $entry;
            });
        $entries = $tail['items'];
        unset($tail['items']);
        return array_merge(array('total' => count($entries), 'entries' => $entries), $tail);
    }

    /**
     * Refresh the MODX cache. Pass partitions (e.g. ['resource','context_settings'])
     * to refresh only those; omit for a full refresh.
     */
    private function clearCacheAction($data) {
        $partitions = (isset($data['partitions']) && is_array($data['partitions'])) ? $data['partitions'] : array();
        $cm = $this->modx->getCacheManager();
        if (!empty($partitions)) {
            $providers = array();
            foreach ($partitions as $p) { $providers[(string) $p] = array(); }
            $cm->refresh($providers);
        } else {
            $cm->refresh();
        }
        $this->logAudit('clear_cache', 'system', array('partitions' => $partitions));
        return array('cleared' => true, 'partitions' => !empty($partitions) ? $partitions : 'all');
    }

    /**
     * Generic passthrough to any MODX processor. High privilege — gated behind
     * modxmcp.allow_run_processor (off by default).
     */
    private function runProcessorPassthrough($data) {
        if (!$this->modx->getOption('modxmcp.allow_run_processor', null, false)) {
            throw new ModxMCPClientException('run_processor is disabled. Set modxmcp.allow_run_processor = Yes to enable it.');
        }
        $processor = isset($data['processor']) ? (string) $data['processor'] : '';
        if ($processor === '') { throw new ModxMCPClientException('run_processor: "processor" path is required.'); }
        $props = (isset($data['properties']) && is_array($data['properties'])) ? $data['properties'] : array();
        $options = array();
        if (!empty($data['processors_path'])) { $options['processors_path'] = (string) $data['processors_path']; }
        $response = $this->modx->runProcessor($processor, $props, $options);
        if (!$response) { throw new ModxMCPClientException('run_processor: no response (processor not found?).'); }
        if ($response->isError()) { throw new ModxMCPClientException($this->formatProcessorErrors($response)); }
        $this->logAudit('run_processor', 'system', array('processor' => $processor));
        $decoded = json_decode($response->getResponse(), true);
        return is_array($decoded) ? $decoded : $this->normalizeProcessorResponse($response);
    }

    /**
     * Reports which capability groups exist, which are off, and the flat list of disabled
     * action names — the client uses this to hide disabled tools from its tool list.
     */
    /**
     * Built-in documentation (RAG-lite): returns a help topic's markdown on demand. No topic
     * (or 'index') returns the topic list. Docs live in core/components/modxmcp/docs/.
     */
    private function getHelp($data) {
        $dir = rtrim($this->modx->getOption('core_path'), '/') . '/components/modxmcp/docs/';
        $topic = isset($data['topic']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string) $data['topic'])) : '';
        $available = array();
        foreach ((array) glob($dir . '*.md') as $f) { $available[] = basename($f, '.md'); }
        sort($available);
        if ($topic === '' || $topic === 'index') {
            $idx = @file_get_contents($dir . 'index.md');
            return array('topics' => $available, 'index' => ($idx !== false) ? $idx : '');
        }
        $file = $dir . $topic . '.md';
        if (!file_exists($file)) {
            return array('error' => "Unknown help topic '{$topic}'.", 'topics' => $available);
        }
        return array('topic' => $topic, 'content' => (string) @file_get_contents($file));
    }
}
