<?php
/** runtime operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPRuntimeTrait {
    private function formatProcessorErrors($response) {
        $error = $response->getMessage();
        if ($response->hasFieldErrors()) {
            foreach ($response->getFieldErrors() as $fError) {
                $error .= " | Field '{$fError->field}': {$fError->message}";
            }
        }
        return $error ?: "Unknown error.";
    }

    private function logContentSave($action, $type, array $payload) {
        try {
            $this->logAudit($action, $type, $payload);
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[modxmcp] Content committed, but audit logging failed.');
        }
    }

    /** Defer only component-owned cache work; call the core once after a batch/partial batch. */
    private function withDeferredCacheRefresh(callable $callback) {
        $this->cacheDeferralDepth++;
        try {
            return $callback();
        } finally {
            $this->cacheDeferralDepth--;
            if ($this->cacheDeferralDepth === 0 && $this->cacheRefreshPending) {
                $this->cacheRefreshPending = false;
                $this->refreshContentCache();
            }
        }
    }

    /** Only internal aggregate fields/selected IDs are accepted; no raw SQL from callers. */
    private function resourceCountsBy($field, ?array $values = null, array $criteria = array()) {
        if (!in_array($field, array('template', 'parent', 'context_key'), true)) { throw new Exception('Invalid aggregate field.'); }
        if ($values !== null && !$values) { return array(); }
        $query = $this->modx->newQuery('modResource');
        $query->select($this->modx->escape($field) . ' AS group_key, COUNT(*) AS row_count');
        if ($values !== null) { $query->where(array($field . ':IN' => $values)); }
        if ($criteria) { $query->where($criteria); }
        $query->groupby($this->modx->escape($field));
        if (!$query->prepare() || !$query->stmt->execute()) { throw new Exception('Cannot count resource groups.'); }
        $counts = array();
        while ($row = $query->stmt->fetch(PDO::FETCH_ASSOC)) { $counts[$row['group_key']] = (int) $row['row_count']; }
        return $counts;
    }

    private function refreshContentCache() {
        if ($this->cacheDeferralDepth > 0) { $this->cacheRefreshPending = true; return true; }
        try {
            return (bool) $this->modx->getCacheManager()->refresh();
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[modxmcp] Content saved/restored, but cache refresh failed.');
            return false;
        }
    }

    /**
     * Schema introspection: list an xPDO class's fields (name + php/db type, null, default) and
     * its primary key, so the model can use real field names instead of guessing. Accepts a
     * class name (modResource) or a friendly alias (resource/chunk/tv/user/...). data: class.
     */
    private function describeObject($data) {
        $class = isset($data['class']) ? trim((string) $data['class']) : '';
        if ($class === '') { throw new ModxMCPClientException('describe_object: "class" is required (e.g. modResource, or alias "resource").'); }
        $alias = array(
            'chunk' => 'modChunk', 'snippet' => 'modSnippet', 'template' => 'modTemplate',
            'plugin' => 'modPlugin', 'tv' => 'modTemplateVar', 'resource' => 'modResource',
            'category' => 'modCategory', 'user' => 'modUser', 'usergroup' => 'modUserGroup',
            'context' => 'modContext', 'setting' => 'modSystemSetting',
        );
        if (isset($alias[strtolower($class)])) { $class = $alias[strtolower($class)]; }
        $meta = $this->modx->getFieldMeta($class);
        if (empty($meta)) {
            if (!$this->modx->loadClass($class)) {
                throw new ModxMCPClientException("describe_object: unknown class '{$class}'. For add-on classes load the package first (e.g. via a known action).");
            }
            $meta = $this->modx->getFieldMeta($class);
        }
        if (empty($meta)) { throw new ModxMCPClientException("describe_object: no field metadata for '{$class}'."); }
        $fields = array();
        foreach ($meta as $name => $def) {
            $fields[] = array(
                'field'   => $name,
                'phptype' => isset($def['phptype']) ? $def['phptype'] : null,
                'dbtype'  => isset($def['dbtype']) ? $def['dbtype'] : null,
                'null'    => isset($def['null']) ? (bool) $def['null'] : null,
                'default' => array_key_exists('default', $def) ? $def['default'] : null,
            );
        }
        return array('class' => $class, 'primary_key' => $this->modx->getPK($class), 'fields' => $fields);
    }

    private function runWithTransaction(callable $callback) {
        $this->modx->beginTransaction();
        try {
            $result = $callback();
            $this->modx->commit();
            return $result;
        } catch (Exception $e) {
            $this->modx->rollback();
            throw $e;
        }
    }

    private function getListLimit(array $data) {
        if (array_key_exists('limit', $data)) {
            $limit = (int)$data['limit'];
            if ($limit === 0) {
                return 0;
            }
            return max(1, min($limit, 500));
        }
        return 100;
    }

    private function getListStart(array $data) {
        return !empty($data['start']) ? max(0, (int)$data['start']) : 0;
    }

    private function normalizeProcessorResponse($response) {
        $raw = $response->getResponse();
        if (is_array($raw)) { return $this->unwrapProcessorPayload($raw); }
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            return $this->unwrapProcessorPayload($decoded);
        }
        $object = $response->getObject();
        if (!empty($object)) {
            return $object;
        }
        return ['success' => true];
    }

    /**
     * Processor responses are wrapped as {success, message, total, errors, object}. The client
     * only needs the object, so strip the envelope to save tokens.
     */
    private function unwrapProcessorPayload($decoded) {
        if (is_array($decoded) && array_key_exists('object', $decoded) && array_key_exists('success', $decoded)) {
            $obj = $decoded['object'];
            if (!empty($obj)) { return $obj; }
            return array('success' => !empty($decoded['success']));
        }
        return $decoded;
    }

    /**
     * Drop never-useful / sensitive columns from list rows (e.g. user password hashes) to keep
     * responses small.
     */
    private function stripNoiseFields($rows) {
        if (!is_array($rows)) { return $rows; }
        $noise = array('password', 'cachepwd', 'salt', 'hash_class', 'remote_data', 'remote_key', 'session_stale', 'sudo');
        foreach ($rows as &$row) {
            if (is_array($row)) {
                foreach ($noise as $k) { unset($row[$k]); }
            }
        }
        unset($row);
        return $rows;
    }

    private function requirePositiveInt(array $data, $key) {
        $value = !empty($data[$key]) ? (int)$data[$key] : 0;
        if ($value <= 0) {
            throw new ModxMCPClientException("{$key} is required and must be a positive integer.");
        }
        return $value;
    }

    private function getLiveObjectLabel(xPDOObject $object) {
        foreach (['pagetitle', 'name', 'templatename', 'caption'] as $field) {
            $value = $object->get($field);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return '';
    }
}
