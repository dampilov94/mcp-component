<?php
/** resources operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPResourcesTrait {
    /**
     * List resources, optionally by parent/context, with a pagetitle/alias/uri filter.
     * data: parent (int), context (string), query (string), limit (default 100, max 500), start (int).
     */
    private function listResources($data) {
        $limit = isset($data['limit']) ? (int) $data['limit'] : 100;
        if ($limit < 1) { $limit = 1; }
        if ($limit > 500) { $limit = 500; }
        $start = isset($data['start']) ? max(0, (int) $data['start']) : 0;

        $c = $this->modx->newQuery('modResource');
        $and = array();
        if (isset($data['parent']) && $data['parent'] !== '') { $and['parent'] = (int) $data['parent']; }
        if (!empty($data['context'])) { $and['context_key'] = (string) $data['context']; }
        if (!empty($and)) { $c->where($and); }
        if (!empty($data['query'])) {
            $q = trim((string) $data['query']);
            $c->where(array(array(
                'pagetitle:LIKE' => '%' . $q . '%',
                'OR:alias:LIKE' => '%' . $q . '%',
                'OR:uri:LIKE' => '%' . $q . '%',
            )));
        }
        $total = $this->modx->getCount('modResource', $c);
        $c->sortby('parent', 'ASC');
        $c->sortby('menuindex', 'ASC');
        $c->limit($limit, $start);

        $rows = array();
        foreach ($this->modx->getCollection('modResource', $c) as $r) {
            $rows[] = array(
                'id' => (int) $r->get('id'),
                'pagetitle' => $r->get('pagetitle'),
                'alias' => $r->get('alias'),
                'uri' => $r->get('uri'),
                'parent' => (int) $r->get('parent'),
                'template' => (int) $r->get('template'),
                'published' => (bool) $r->get('published'),
                'isfolder' => (bool) $r->get('isfolder'),
                'class_key' => $r->get('class_key'),
                'context_key' => $r->get('context_key'),
            );
        }
        return array('total' => (int) $total, 'count' => count($rows), 'start' => $start, 'results' => $rows);
    }

    /**
     * Bulk resource operations. Select targets by explicit `ids` or by a parent/context/query
     * filter, then apply one operation to all of them. Operations: publish, unpublish,
     * set_template (needs `template`), move (needs `parent_to` and/or `context_to`), delete.
     * `dry_run` previews the change per resource without applying. Each change is routed through
     * the core resource processors (correct URI regeneration / events) via update/delete_element.
     */
    private function bulkResources($data) {
        if (empty($data['dry_run']) && $this->cacheDeferralDepth === 0) {
            return $this->withDeferredCacheRefresh(function () use ($data) { return $this->bulkResources($data); });
        }
        $op = isset($data['operation']) ? (string) $data['operation'] : '';
        $ops = array('publish', 'unpublish', 'set_template', 'move', 'delete');
        if (!in_array($op, $ops, true)) {
            throw new ModxMCPClientException('bulk_resources: operation must be one of: ' . implode(', ', $ops) . '.');
        }
        $limit = isset($data['limit']) ? max(1, (int) $data['limit']) : 200;

        $ids = array();
        if (isset($data['ids']) && is_array($data['ids'])) {
            foreach ($data['ids'] as $i) { $i = (int) $i; if ($i > 0) { $ids[] = $i; } }
        } elseif (isset($data['parent']) || !empty($data['context']) || !empty($data['query'])) {
            $lr = $this->listResources(array(
                'parent'  => isset($data['parent']) ? $data['parent'] : '',
                'context' => isset($data['context']) ? $data['context'] : '',
                'query'   => isset($data['query']) ? $data['query'] : '',
                'limit'   => $limit,
            ));
            foreach ($lr['results'] as $r) { $ids[] = (int) $r['id']; }
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) { throw new ModxMCPClientException('bulk_resources: no targets — pass "ids" or a parent/context/query filter.'); }
        if (count($ids) > $limit) { $ids = array_slice($ids, 0, $limit); }

        if ($op === 'set_template' && empty($data['template'])) { throw new ModxMCPClientException('bulk_resources: set_template requires "template".'); }
        if ($op === 'move' && !isset($data['parent_to']) && empty($data['context_to'])) { throw new ModxMCPClientException('bulk_resources: move requires "parent_to" and/or "context_to".'); }

        $dry = !empty($data['dry_run']);
        $results = array();
        foreach ($ids as $id) {
            $r = $this->modx->getObject('modResource', $id);
            if (!$r) { $results[] = array('id' => $id, 'status' => 'not_found'); continue; }
            $row = array('id' => $id, 'pagetitle' => $r->get('pagetitle'));

            if ($dry) {
                if ($op === 'publish')      { $row['change'] = 'published: ' . (int) $r->get('published') . ' -> 1'; }
                elseif ($op === 'unpublish'){ $row['change'] = 'published: ' . (int) $r->get('published') . ' -> 0'; }
                elseif ($op === 'set_template') { $row['change'] = 'template: ' . (int) $r->get('template') . ' -> ' . (int) $data['template']; }
                elseif ($op === 'move')     { $row['change'] = 'parent: ' . (int) $r->get('parent') . (isset($data['parent_to']) ? ' -> ' . (int) $data['parent_to'] : '') . (!empty($data['context_to']) ? '; context -> ' . $data['context_to'] : ''); }
                elseif ($op === 'delete')   { $row['change'] = 'DELETE'; $row['child_resources'] = (int) $this->modx->getCount('modResource', array('parent' => $id)); }
                $results[] = $row;
                continue;
            }

            try {
                if ($op === 'delete') {
                    $this->processRequest('delete_element', 'resource', array('id' => $id));
                    $row['status'] = 'deleted';
                } else {
                    $upd = array('id' => $id);
                    if ($op === 'publish')          { $upd['published'] = 1; }
                    elseif ($op === 'unpublish')    { $upd['published'] = 0; }
                    elseif ($op === 'set_template') { $upd['template'] = (int) $data['template']; }
                    elseif ($op === 'move') {
                        if (isset($data['parent_to']))   { $upd['parent'] = (int) $data['parent_to']; }
                        if (!empty($data['context_to'])) { $upd['context_key'] = (string) $data['context_to']; }
                    }
                    $this->processRequest('update_element', 'resource', $upd);
                    $row['status'] = 'ok';
                }
            } catch (Exception $e) {
                $row['status'] = 'error';
                $row['error'] = $e->getMessage();
            }
            $results[] = $row;
        }

        if (!$dry) { $this->refreshContentCache(); }
        $this->logAudit('bulk_resources', 'resource', array('operation' => $op, 'count' => count($ids), 'dry_run' => $dry));
        return array('operation' => $op, 'dry_run' => $dry, 'count' => count($results), 'results' => $results);
    }

    // --- Trash / duplicate / reorder (core resource & element processors) ---

    private function undeleteResource($data) {
        if (empty($data['id'])) { throw new ModxMCPClientException('undelete_resource: "id" is required.'); }
        $resp = $this->modx->runProcessor('resource/undelete', array('id' => (int) $data['id']));
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'undelete_resource: no response.'); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('undelete_resource', 'resource', array('id' => (int) $data['id']));
        return array('undeleted' => true, 'id' => (int) $data['id']);
    }

    private function emptyRecycleBin($data) {
        $resp = $this->modx->runProcessor('resource/emptyrecyclebin', array());
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'empty_recycle_bin: no response.'); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('empty_recycle_bin', 'resource', array());
        return $this->normalizeProcessorResponse($resp);
    }

    private function duplicateResource($data) {
        if (empty($data['id'])) { throw new ModxMCPClientException('duplicate_resource: "id" is required.'); }
        $props = array('id' => (int) $data['id']);
        if (!empty($data['name'])) { $props['name'] = (string) $data['name']; }
        if (isset($data['duplicate_children'])) { $props['duplicate_children'] = (bool) $data['duplicate_children']; }
        if (!empty($data['published_mode'])) { $props['published_mode'] = (string) $data['published_mode']; }
        $resp = $this->modx->runProcessor('resource/duplicate', $props);
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'duplicate_resource: no response.'); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('duplicate_resource', 'resource', array('id' => (int) $data['id']));
        return $this->normalizeProcessorResponse($resp);
    }

    private function reorderResources($data) {
        if ($this->cacheDeferralDepth === 0) {
            return $this->withDeferredCacheRefresh(function () use ($data) { return $this->reorderResources($data); });
        }
        $items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : null;
        if (!$items) { throw new ModxMCPClientException('reorder_resources: "items" (list of {id, menuindex, parent?}) is required.'); }
        $results = array();
        foreach ($items as $it) {
            if (empty($it['id']) || !isset($it['menuindex'])) {
                $results[] = array('id' => isset($it['id']) ? (int) $it['id'] : null, 'status' => 'skipped (need id + menuindex)');
                continue;
            }
            $upd = array('id' => (int) $it['id'], 'menuindex' => (int) $it['menuindex']);
            if (isset($it['parent'])) { $upd['parent'] = (int) $it['parent']; }
            try {
                $this->processRequest('update_element', 'resource', $upd);
                $results[] = array('id' => (int) $it['id'], 'status' => 'ok', 'menuindex' => (int) $it['menuindex']);
            } catch (Exception $e) {
                $results[] = array('id' => (int) $it['id'], 'status' => 'error', 'error' => $e->getMessage());
            }
        }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('reorder_resources', 'resource', array('count' => count($results)));
        return array('count' => count($results), 'results' => $results);
    }

    private function getResourceTvs(array $data) {
        $resourceId = !empty($data['resource_id']) ? (int)$data['resource_id'] : 0;
        if ($resourceId <= 0) {
            throw new ModxMCPClientException('resource_id is required.');
        }

        $resource = $this->modx->getObject('modResource', $resourceId);
        if (!$resource) {
            throw new ModxMCPClientException("Resource not found: {$resourceId}.");
        }

        $templateId = (int)$resource->get('template');
        $tvLinks = $this->modx->getCollection('modTemplateVarTemplate', ['templateid' => $templateId]);
        $result = [];

        foreach ($tvLinks as $link) {
            $tv = $this->modx->getObject('modTemplateVar', $link->get('tmplvarid'));
            if (!$tv) {
                continue;
            }
            $result[] = [
                'id' => $tv->get('id'),
                'name' => $tv->get('name'),
                'caption' => $tv->get('caption'),
                'type' => $tv->get('type'),
                'value' => $resource->getTVValue($tv->get('name')),
            ];
        }

        return [
            'resource_id' => $resourceId,
            'template_id' => $templateId,
            'tvs' => $result,
        ];
    }

    private function updateResourceTvs(array $data) {
        $resourceId = !empty($data['resource_id']) ? (int)$data['resource_id'] : 0;
        if ($resourceId <= 0) {
            throw new ModxMCPClientException('resource_id is required.');
        }
        if (empty($data['tvs']) || !is_array($data['tvs'])) {
            throw new ModxMCPClientException('tvs payload must be a non-empty object/array.');
        }

        $resource = $this->modx->getObject('modResource', $resourceId);
        if (!$resource) {
            throw new ModxMCPClientException("Resource not found: {$resourceId}.");
        }

        foreach ($data['tvs'] as $tvName => $tvValue) {
            $resource->setTVValue($tvName, $tvValue);
        }

        $this->refreshContentCache();
        $this->logAudit('update_resource_tvs', 'resource_tv', ['resource_id' => $resourceId, 'tv_keys' => array_keys($data['tvs'])]);
        return $this->getResourceTvs(['resource_id' => $resourceId]);
    }
}
