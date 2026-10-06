<?php
/** elements operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPElementsTrait {
    // --- Обработчики связей ---

    private function handlePluginEvents($pluginId, $data) {
        if (empty($data['events'])) return;
        $events = is_string($data['events']) ? array_map('trim', explode(',', $data['events'])) : $data['events'];
        if (!is_array($events)) return;

        $pluginId = (int)$pluginId;
        $this->modx->removeCollection('modPluginEvent', ['pluginid' => $pluginId]);

        foreach ($events as $eventName) {
            $eventName = trim($eventName);
            if (empty($eventName)) continue;
            if ($this->modx->getObject('modEvent',['name' => $eventName])) {
                $pe = $this->modx->newObject('modPluginEvent');
                $pe->fromArray(['pluginid' => $pluginId, 'event' => $eventName, 'priority' => 0, 'propertyset' => 0], '', true, true);
                $pe->save();
            }
        }
    }

    private function getPluginEvents($pluginId) {
        $pes = $this->modx->getCollection('modPluginEvent',['pluginid' => $pluginId]);
        $res =[];
        foreach($pes as $p) $res[] = $p->get('event');
        return $res;
    }

    private function handleTvRelations($tvId, $data) {
        $tv = $this->modx->getObject('modTemplateVar', $tvId);
        if (!$tv) return;

        if (isset($data['templates']) && is_array($data['templates'])) {
            $this->modx->removeCollection('modTemplateVarTemplate', ['tmplvarid' => $tvId]);
            foreach ($data['templates'] as $tplId) {
                if (empty($tplId)) continue;
                $tvt = $this->modx->newObject('modTemplateVarTemplate');
                $tvt->fromArray(['tmplvarid' => $tvId, 'templateid' => $tplId], '', true, true);
                $tvt->save();
            }
        }

        if (isset($data['input_properties']) && is_array($data['input_properties'])) {
            $tv->set('input_properties', $data['input_properties']);
            $tv->save();
        }

        if (isset($data['media_source'])) {
            $sourceId = (int)$data['media_source'];
            $sourceEl = $this->modx->getObject('sources.modMediaSourceElement',[
                'object' => $tvId, 'object_class' => 'modTemplateVar', 'context_key' => 'web'
            ]);
            if (!$sourceEl) {
                $sourceEl = $this->modx->newObject('sources.modMediaSourceElement');
                $sourceEl->fromArray(['object' => $tvId, 'object_class' => 'modTemplateVar', 'context_key' => 'web'], '', true, true);
            }
            $sourceEl->set('source', $sourceId);
            $sourceEl->save();
        }
    }

    private function getTvTemplates($tvId) {
        $tvts = $this->modx->getCollection('modTemplateVarTemplate',['tmplvarid' => $tvId]);
        $res =[];
        foreach($tvts as $t) $res[] = $t->get('templateid');
        return $res;
    }

    private function resolveIdByName($elementType, $name) {
        $classMap =[
            'chunk'    =>['class' => 'modChunk', 'field' => 'name'],
            'snippet'  =>['class' => 'modSnippet', 'field' => 'name'],
            'template' =>['class' => 'modTemplate', 'field' => 'templatename'],
            'resource' =>['class' => 'modResource', 'field' => 'pagetitle'],
            'tv'       =>['class' => 'modTemplateVar', 'field' => 'name'],
            'category' =>['class' => 'modCategory', 'field' => 'category'],
            'plugin'   =>['class' => 'modPlugin', 'field' => 'name']
        ];
        if ($elementType === 'resource') {
            foreach (['alias', 'uri', 'pagetitle'] as $field) {
                $obj = $this->modx->getObject('modResource', [$field => $name]);
                if ($obj) {
                    return $obj->get('id');
                }
            }
            return null;
        }
        $obj = $this->modx->getObject($classMap[$elementType]['class'], [$classMap[$elementType]['field'] => $name]);
        return $obj ? $obj->get('id') : null;
    }

    /**
     * Preview a delete without performing it: what the object is, and (for elements) where its
     * name is still referenced; (for resources) how many child resources it has.
     */
    private function previewDelete($elementType, $id) {
        if ($elementType === 'resource') {
            $r = $this->modx->getObject('modResource', $id);
            if (!$r) { throw new ModxMCPClientException("resource {$id} not found."); }
            $children = (int) $this->modx->getCount('modResource', array('parent' => $id));
            return array(
                'dry_run' => true,
                'would_delete' => array('type' => 'resource', 'id' => $id, 'pagetitle' => $r->get('pagetitle'), 'uri' => $r->get('uri')),
                'child_resources' => $children,
                'warning' => $children > 0 ? "Has {$children} child resource(s) that would be affected." : null,
            );
        }
        $classMap = array(
            'chunk' => array('modChunk', 'name'), 'snippet' => array('modSnippet', 'name'),
            'template' => array('modTemplate', 'templatename'), 'tv' => array('modTemplateVar', 'name'),
            'category' => array('modCategory', 'category'), 'plugin' => array('modPlugin', 'name'),
        );
        if (!isset($classMap[$elementType])) { throw new ModxMCPClientException("Cannot preview delete for type {$elementType}."); }
        list($class, $nameField) = $classMap[$elementType];
        $obj = $this->modx->getObject($class, $id);
        if (!$obj) { throw new ModxMCPClientException("{$elementType} {$id} not found."); }
        $name = (string) $obj->get($nameField);
        $usages = ($elementType === 'category') ? array('name' => $name) : $this->findUsages(array('name' => $name, 'limit' => 100));
        // Drop the element's own name-field self-match — that's not a usage.
        if (isset($usages['content_matches'])) {
            $usages['content_matches'] = array_values(array_filter($usages['content_matches'], function ($h) use ($id, $elementType) {
                return !((int) $h['id'] === (int) $id && $h['type'] === $elementType);
            }));
        }
        $matchCount = isset($usages['content_matches']) ? count($usages['content_matches']) : 0;
        return array(
            'dry_run' => true,
            'would_delete' => array('type' => $elementType, 'id' => $id, 'name' => $name),
            'usages' => $usages,
            'warning' => $matchCount > 0 ? "Name '{$name}' is referenced in {$matchCount} place(s) (and possibly more) — deleting may break them." : null,
        );
    }

    private function duplicateElement($data) {
        $type = isset($data['type']) ? (string) $data['type'] : '';
        if (!in_array($type, array('chunk', 'snippet', 'template', 'plugin', 'tv'), true)) {
            throw new ModxMCPClientException('duplicate_element: type must be one of chunk, snippet, template, plugin, tv.');
        }
        if (empty($data['id'])) { throw new ModxMCPClientException('duplicate_element: "id" is required.'); }
        $props = array('id' => (int) $data['id']);
        if (!empty($data['name'])) { $props['name'] = (string) $data['name']; }
        $resp = $this->modx->runProcessor('element/' . $type . '/duplicate', $props);
        if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'duplicate_element: no response.'); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('duplicate_element', $type, array('id' => (int) $data['id']));
        return $this->normalizeProcessorResponse($resp);
    }

    private function shouldAutoStatic($type) {
        if (!in_array($type, array('chunk', 'snippet', 'template', 'plugin'), true)) { return false; }
        return (bool) $this->modx->getOption('modxmcp.auto_static', null, false);
    }

    private function staticElementMap() {
        return array(
            'chunk'    => array('class' => 'modChunk',    'field' => 'snippet',    'dir' => 'chunks',    'ext' => 'tpl'),
            'snippet'  => array('class' => 'modSnippet',  'field' => 'snippet',    'dir' => 'snippets',  'ext' => 'php'),
            'template' => array('class' => 'modTemplate', 'field' => 'content',    'dir' => 'templates', 'ext' => 'tpl'),
            'plugin'   => array('class' => 'modPlugin',   'field' => 'plugincode', 'dir' => 'plugins',   'ext' => 'php'),
        );
    }

    /**
     * Convert DB-only code once. Existing static elements keep their exact path/source.
     * New files live under the configured core path and use MODX's native source=0.
     */
    private function makeElementStatic($type, $id) {
        $map = $this->staticElementMap();
        $id = (int) $id;
        if (!isset($map[$type]) || $id < 1) {
            throw new ModxMCPClientException('make_static: supported type and positive element ID are required.');
        }
        $m = $map[$type];
        $existing = $this->modx->getObject($m['class'], $id, false);
        if (!$existing) { throw new ModxMCPClientException("make_static: {$type} {$id} not found."); }
        if ((bool) $existing->get('static')) {
            return array('type' => $type, 'id' => $id,
                'name' => $existing->get($type === 'template' ? 'templatename' : 'name'),
                'static_file' => $existing->get('static_file'), 'source' => (int) $existing->get('source'),
                'status' => 'already_static');
        }
        $lock = $this->acquireContentLock($m['class'] . ':' . $id);
        $reserved = null;
        $staged = null;
        $abs = null;
        $rel = null;
        $converted = false;
        try {
            $el = $this->modx->getObject($m['class'], $id, false);
            if (!$el) { throw new ModxMCPClientException("make_static: {$type} {$id} not found."); }
            $nameField = ($type === 'template') ? 'templatename' : 'name';
            $name = (string) $el->get($nameField);
            if ((bool) $el->get('static')) {
                // Do not re-export a stale DB copy, relocate files or replace a custom source.
                return array('type' => $type, 'id' => $id, 'name' => $name,
                    'static_file' => $el->get('static_file'), 'source' => (int) $el->get('source'),
                    'status' => 'already_static');
            }
            $content = (string) $el->get($m['field']);
            $slug = trim(substr(preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), 0, 120), '-._');
            if ($slug === '') { $slug = $type; }
            $corePath = rtrim($this->modx->getOption('core_path'), '/\\') . '/';
            $dir = $corePath . 'elements/' . $m['dir'];
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new ModxMCPClientException('make_static: cannot create the static element directory.');
            }
            // Reserve exclusively, including when a pre-existing file has the same ID/name.
            // An extra suffix resolves collisions without modifying the occupied file.
            for ($attempt = 0; $attempt < 1000; $attempt++) {
                $filename = $slug . '-' . $id . ($attempt ? '-' . $attempt : '') . '.' . $m['ext'];
                $candidate = $dir . '/' . $filename;
                $reserved = @fopen($candidate, 'x+b');
                if ($reserved) {
                    $abs = $candidate;
                    $rel = '[[++core_path]]elements/' . $m['dir'] . '/' . $filename;
                    break;
                }
                clearstatcache(true, $candidate);
                if (!file_exists($candidate) && !is_link($candidate)) {
                    throw new ModxMCPClientException('make_static: cannot reserve a new static file. Check directory permissions.');
                }
            }
            if (!$reserved) { throw new ModxMCPClientException('make_static: no unused filename could be reserved.'); }
            $stat = fstat($reserved);
            if ($stat === false) { throw new Exception('make_static: cannot inspect the reserved file.'); }
            $staged = $this->stageContentFile($abs, $content, $stat['mode'] & 0777);
            fclose($reserved);
            $reserved = null;
            if (!@rename($staged, $abs)) { throw new Exception('make_static: cannot finish the new static file.'); }
            $staged = null;

            // Only attach the new file. modElement::save() can rewrite/delete static files,
            // and considers a zero-byte file write a false result. The core create/update
            // processor already saved/validated the content before auto-static runs.
            $sql = 'UPDATE ' . $this->modx->getTableName($m['class'])
                . ' SET static = 1, static_file = :path, source = 0'
                . ' WHERE id = :id AND static = 0 AND BINARY COALESCE(' . $this->modx->escape($m['field']) . ", '') = BINARY :content"
                . " AND COALESCE(static_file, '') = :old_path AND COALESCE(source, 0) = :old_source";
            $stmt = $this->modx->prepare($sql);
            $props = array(':path' => $rel, ':id' => $id, ':content' => $content,
                ':old_path' => (string) $el->get('static_file'), ':old_source' => (int) $el->get('source'));
            if (!$stmt || !$stmt->execute($props)) { throw new Exception('make_static: cannot save the static file reference.'); }
            if ($stmt->rowCount() !== 1) {
                throw new ModxMCPClientException('make_static: element changed during conversion. Read it again before retrying.');
            }
            $converted = true;
            return array('type' => $type, 'id' => $id, 'name' => $name,
                'static_file' => $rel, 'source' => 0, 'status' => 'converted');
        } catch (Throwable $e) {
            if (!($e instanceof Exception)) { throw new Exception('make_static: conversion failed.', 0, $e); }
            throw $e;
        } finally {
            if (is_resource($reserved)) { fclose($reserved); }
            if ($staged !== null) { @unlink($staged); }
            if ($abs !== null && !$converted) {
                // If a DB connection failed after UPDATE, keep a file that may be referenced.
                try {
                    $saved = $this->modx->getObject($m['class'], $id, false);
                    if (!$saved || !(bool) $saved->get('static') || (string) $saved->get('static_file') !== $rel || (int) $saved->get('source') !== 0) {
                        @unlink($abs);
                    }
                } catch (Throwable $cleanupError) {
                    $this->modx->log(modX::LOG_LEVEL_ERROR, '[modxmcp] Could not confirm failed static conversion cleanup; retained file: ' . $abs);
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Make one element static (data: type, id) or a batch (data: items=[{type,id}]).
     */
    private function makeStatic($data) {
        if (isset($data['items']) && is_array($data['items']) && $this->cacheDeferralDepth === 0) {
            return $this->withDeferredCacheRefresh(function () use ($data) { return $this->makeStatic($data); });
        }
        if (isset($data['items']) && is_array($data['items'])) {
            $out = array();
            foreach ($data['items'] as $it) {
                $t = isset($it['type']) ? $it['type'] : '';
                $i = isset($it['id']) ? (int) $it['id'] : 0;
                try { $out[] = $this->makeElementStatic($t, $i); }
                catch (Exception $e) { $out[] = array('type' => $t, 'id' => $i, 'error' => $e->getMessage()); }
            }
            $this->refreshContentCache();
            $this->logAudit('make_static', 'batch', array('count' => count($out)));
            return array('count' => count($out), 'results' => $out);
        }
        $res = $this->makeElementStatic(isset($data['type']) ? $data['type'] : '', isset($data['id']) ? $data['id'] : 0);
        $this->refreshContentCache();
        $this->logAudit('make_static', $res['type'], array('id' => $res['id']));
        return $res;
    }

    // --- Line-based viewing / editing (token-efficient partial edits) ---

    private function lineEditMap() {
        return array(
            'chunk'    => array('class' => 'modChunk',    'field' => 'snippet',    'proc' => 'element/chunk/'),
            'snippet'  => array('class' => 'modSnippet',  'field' => 'snippet',    'proc' => 'element/snippet/'),
            'template' => array('class' => 'modTemplate', 'field' => 'content',    'proc' => 'element/template/'),
            'plugin'   => array('class' => 'modPlugin',   'field' => 'plugincode', 'proc' => 'element/plugin/'),
        );
    }

    /** Resolve a chunk/snippet/template/plugin by type + id|name. Returns [el, type, id, mapEntry]. */
    private function resolveLineEditElement($data) {
        $type = isset($data['type']) ? (string) $data['type'] : '';
        $map = $this->lineEditMap();
        if (!isset($map[$type])) {
            throw new ModxMCPClientException('type must be one of: chunk, snippet, template, plugin.');
        }
        $id = !empty($data['id']) ? (int) $data['id'] : (int) $this->resolveIdByName($type, isset($data['name']) ? $data['name'] : '');
        if ($id <= 0) {
            throw new ModxMCPClientException('Element not found (provide id or name).');
        }
        $el = $this->modx->getObject($map[$type]['class'], $id);
        if (!$el) {
            throw new ModxMCPClientException("{$type} {$id} not found.");
        }
        return array($el, $type, $id, $map[$type]);
    }

    /** Read without synchronising the file back into the database. */
    private function readEffectiveContent($el, $m) {
        if (!(bool) $el->get('static')) {
            return array((string) $el->get($m['field']), false, null);
        }
        // Use the same Media Source resolution as the core update processor.
        $path = $el->getSourceFile();
        if (!$path || strpos($path, '://') !== false) {
            throw new ModxMCPClientException('Line edits require a local static file.');
        }
        $absolute = realpath($path);
        if ($absolute === false || !is_file($absolute)) {
            throw new ModxMCPClientException('Static file is missing. Restore it before editing this element.');
        }
        $content = @file_get_contents($absolute);
        if ($content === false) {
            throw new ModxMCPClientException('Cannot read the static file.');
        }
        return array($content, true, $absolute);
    }

    private function contentRevision($content) {
        return hash('sha256', $content);
    }

    private function assertContentRevision($content, $expected) {
        if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D', $expected)) {
            throw new ModxMCPClientException('expected_revision must be the revision returned by view_element or replace_across dry_run.');
        }
        if (!hash_equals($expected, $this->contentRevision($content))) {
            throw new ModxMCPClientException('Element content has changed. Read it again and retry with the new revision.');
        }
    }

    /** Separate lock files survive atomic renames and cache refreshes. Do not unlink them. */
    private function acquireContentLock($key) {
        $dir = rtrim($this->config['corePath'], '/\\') . '/locks';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ModxMCPClientException('Cannot create the element lock directory.');
        }
        $lock = @fopen($dir . '/' . hash('sha256', $key) . '.lock', 'c');
        if (!$lock) { throw new ModxMCPClientException('Cannot open the element lock.'); }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new ModxMCPClientException('Element is being edited by another MCP request. Read it again and retry.');
        }
        return $lock;
    }

    /** PDO can begin a transaction even when a MyISAM table cannot roll back. */
    private function assertTransactionalContentTable($class) {
        if (isset($this->transactionalContentTables[$class])) { return; }
        $table = trim($this->modx->getTableName($class), '`');
        $sql = 'SELECT e.TRANSACTIONS FROM information_schema.TABLES t '
            . 'JOIN information_schema.ENGINES e ON e.ENGINE = t.ENGINE '
            . 'WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = :table';
        $stmt = $this->modx->prepare($sql);
        if (!$stmt || !$stmt->execute(array(':table' => $table)) || $stmt->fetchColumn() !== 'YES') {
            throw new ModxMCPClientException('Safe content edits require a transactional element table (for example InnoDB). No files were changed.');
        }
        $this->transactionalContentTables[$class] = true;
    }

    /** Stage in the same directory so rename replaces the target on the same filesystem. */
    private function stageContentFile($target, $content, $mode) {
        $temp = dirname($target) . '/.modxmcp-' . bin2hex(random_bytes(16)) . '.tmp';
        $handle = @fopen($temp, 'x+b');
        if (!$handle) { throw new ModxMCPClientException('Cannot stage the static file. Check directory permissions and free space.'); }
        try {
            if (!@chmod($temp, 0600)) { throw new Exception('Cannot protect the staged file.'); }
            $length = strlen($content);
            for ($offset = 0; $offset < $length; $offset += $written) {
                $written = fwrite($handle, substr($content, $offset));
                if ($written === false || $written === 0) { throw new Exception('Cannot write the complete staged file.'); }
            }
            if (!fflush($handle) || !@chmod($temp, $mode)) { throw new Exception('Cannot finish staging the static file.'); }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($temp);
            throw $e;
        }
        fclose($handle);
        return $temp;
    }

    /**
     * Save one line-edit/replace result. The file is visible to save-event plugins before
     * the processor runs, but a failed processor/transaction restores its original bytes.
     * File locks coordinate these MCP writers; arbitrary FTP writers do not take the lock.
     */
    private function writeEffectiveContent($el, $m, $isStatic, $staticAbs, $content, $expectedRevision) {
        $locks = array();
        $activeTransaction = false;
        $backup = null;
        $staged = null;
        $replaced = false;
        $keepBackup = false;
        try {
            $id = (int) $el->get('id');
            $locks[] = $this->acquireContentLock($m['class'] . ':' . $id);
            if (!$this->modx->beginTransaction()) { throw new Exception('Cannot start the element transaction.'); }
            $activeTransaction = true;
            $this->assertTransactionalContentTable($m['class']);
            // Keep ordinary database updates from changing the element while we save it.
            $stmt = $this->modx->prepare('SELECT id FROM ' . $this->modx->getTableName($m['class']) . ' WHERE id = ' . $id . ' FOR UPDATE');
            if (!$stmt || !$stmt->execute() || $stmt->fetchColumn() === false) {
                throw new ModxMCPClientException('Cannot lock the element row; it may have been deleted.');
            }
            $current = $this->modx->getObject($m['class'], $id, false);
            if (!$current) { throw new ModxMCPClientException('Element no longer exists.'); }
            foreach (array('static', 'static_file', 'source') as $field) {
                if ((string) $current->get($field) !== (string) $el->get($field)) {
                    throw new ModxMCPClientException('Element storage has changed. Read the element again before editing.');
                }
            }
            list($before, $currentStatic, $currentPath) = $this->readEffectiveContent($current, $m);
            if ($currentStatic !== $isStatic || $currentPath !== $staticAbs) {
                throw new ModxMCPClientException('Static file location has changed. Read the element again before editing.');
            }
            if ($isStatic) {
                $locks[] = $this->acquireContentLock('file:' . $staticAbs);
                list($before) = $this->readEffectiveContent($current, $m);
            }
            $this->assertContentRevision($before, $expectedRevision);
            if ($isStatic) {
                $mode = @fileperms($staticAbs);
                if ($mode === false || !is_writable($staticAbs)) {
                    throw new ModxMCPClientException('Static file is not writable.');
                }
                $backup = $this->stageContentFile($staticAbs, $before, $mode & 0777);
                $staged = $this->stageContentFile($staticAbs, $content, $mode & 0777);
                // Detect external changes during staging before replacing the file.
                list($latest) = $this->readEffectiveContent($current, $m);
                $this->assertContentRevision($latest, $expectedRevision);
                if (!@rename($staged, $staticAbs)) { throw new Exception('Cannot atomically replace the static file.'); }
                $staged = null;
                $replaced = true;
                clearstatcache(true, $staticAbs);
            }
            $type = trim(str_replace('element/', '', $m['proc']), '/');
            $data = $current->toArray();
            $data[$m['field']] = $content;
            $resp = $this->modx->runProcessor($m['proc'] . 'update', $this->filterProcessorData($type, $data));
            if (!$resp || $resp->isError()) {
                throw new ModxMCPClientException('Element save failed: ' . ($resp ? $this->formatProcessorErrors($resp) : 'no response.'));
            }
            $saved = $this->modx->getObject($m['class'], $id, false);
            if (!$saved) { throw new Exception('Saved element cannot be read.'); }
            list($savedContent) = $this->readEffectiveContent($saved, $m);
            $revision = $this->contentRevision($savedContent);
            if (!$this->modx->commit()) { throw new Exception('Cannot commit the element transaction.'); }
            $activeTransaction = false;
            return $revision;
        } catch (Throwable $e) {
            if ($activeTransaction) {
                try {
                    if (!$this->modx->rollback()) { throw new Exception('Database rollback failed.'); }
                } catch (Throwable $rollbackError) {
                    $keepBackup = true;
                    throw new Exception('Element transaction rollback failed. Recovery copy: ' . ($backup ?: 'none (database element)'), 0, $e);
                }
            }
            if ($replaced) {
                if (!@rename($backup, $staticAbs)) {
                    $keepBackup = true;
                    throw new Exception('Cannot restore the static file. Recovery copy retained at ' . $backup, 0, $e);
                }
                $backup = null;
                clearstatcache(true, $staticAbs);
                $this->refreshContentCache();
            }
            // Keep PHP errors within the existing endpoint's Exception error handling.
            if (!($e instanceof Exception)) { throw new Exception('Element save failed.', 0, $e); }
            throw $e;
        } finally {
            if ($staged !== null) { @unlink($staged); }
            if ($backup !== null && !$keepBackup) { @unlink($backup); }
            foreach (array_reverse($locks) as $lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Split content into lines, reporting the dominant EOL so it can be rejoined unchanged. */
    private function splitLines($content, &$eol) {
        $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
        return explode("\n", str_replace("\r\n", "\n", $content));
    }

    /**
     * Return an element's content as numbered lines (like `cat -n`), optionally windowed by
     * start_line/end_line — so the model can target edits without pulling the whole file.
     * data: type, id|name, start_line?, end_line?.
     */
    private function viewElementLines($data) {
        list($el, $type, $id, $m) = $this->resolveLineEditElement($data);
        list($content, $isStatic, $staticAbs) = $this->readEffectiveContent($el, $m);
        $eol = "\n";
        $lines = $this->splitLines($content, $eol);
        $total = count($lines);
        $start = isset($data['start_line']) ? max(1, (int) $data['start_line']) : 1;
        $end = isset($data['end_line']) ? (int) $data['end_line'] : $total;
        if ($end > $total) { $end = $total; }
        if ($end < $start) { $end = $start; }
        $buf = array();
        for ($i = $start; $i <= $end && $i <= $total; $i++) {
            $buf[] = $i . "\t" . $lines[$i - 1];
        }
        return array(
            'type' => $type,
            'id' => $id,
            'name' => $el->get($type === 'template' ? 'templatename' : 'name'),
            'static' => $isStatic,
            'revision' => $this->contentRevision($content),
            'total_lines' => $total,
            'start_line' => $start,
            'end_line' => min($end, $total),
            'numbered' => implode("\n", $buf),
        );
    }

    /**
     * Resolve one edit's target range. With `expect` given, it is the anchor: verified at the
     * stated position, else relocated to its unique match anywhere in the file (so a small line
     * drift doesn't corrupt). Without `expect`, the literal range is used (must be in bounds).
     */
    private function locateEditRange($lines, $start, $end, $expect, $idx) {
        $total = count($lines);
        if ($expect === null) {
            if ($start < 1 || $end > $total) {
                throw new ModxMCPClientException("edit #{$idx}: lines {$start}-{$end} out of range (1..{$total}).");
            }
            return array($start, $end);
        }
        $expectLines = explode("\n", str_replace("\r\n", "\n", $expect));
        $len = count($expectLines);
        if ($start >= 1 && ($start + $len - 1) <= $total && array_slice($lines, $start - 1, $len) === $expectLines) {
            return array($start, $start + $len - 1);
        }
        $matches = array();
        for ($i = 0; $i + $len <= $total; $i++) {
            if (array_slice($lines, $i, $len) === $expectLines) { $matches[] = $i + 1; }
        }
        if (count($matches) === 1) { return array($matches[0], $matches[0] + $len - 1); }
        if (count($matches) === 0) {
            throw new ModxMCPClientException("edit #{$idx}: expected text not found (the lines have changed since you read them).");
        }
        throw new ModxMCPClientException("edit #{$idx}: expected text matches " . count($matches) . " places; make it more specific.");
    }

    /**
     * Apply line-based edits to a chunk/snippet/template/plugin. Only the changed lines travel —
     * no need to resend the whole element. data:
     *   type, id|name, expected_revision? (from view_element),
     *   edits: [ { start_line, end_line?, replacement?, expect? }, ... ]
     * Semantics (1-based, inclusive): replace [start_line..end_line] with `replacement`
     * (multi-line ok; "" = delete the lines). Insert = empty range (end_line = start_line - 1),
     * inserting `replacement` before start_line. `expect` (current text of the lines) is an
     * optional safety anchor — verified/relocated before applying; mismatch aborts the WHOLE
     * call. Saves one element with DB rollback and static-file recovery; preserves EOL.
     */
    private function editElementLines($data) {
        list($el, $type, $id, $m) = $this->resolveLineEditElement($data);
        $edits = (isset($data['edits']) && is_array($data['edits'])) ? $data['edits'] : null;
        if (!$edits) {
            throw new ModxMCPClientException('edit_element_lines: a non-empty "edits" array is required.');
        }
        list($content, $isStatic, $staticAbs) = $this->readEffectiveContent($el, $m);
        if (array_key_exists('expected_revision', $data)) { $this->assertContentRevision($content, $data['expected_revision']); }
        $eol = "\n";
        $lines = $this->splitLines($content, $eol);
        $total = count($lines);

        // Resolve every edit against the ORIGINAL lines (so ranges/anchors are consistent).
        $resolved = array();
        foreach ($edits as $idx => $e) {
            if (!is_array($e) || !isset($e['start_line'])) {
                throw new ModxMCPClientException("edit #{$idx}: start_line is required.");
            }
            $s = (int) $e['start_line'];
            $en = isset($e['end_line']) ? (int) $e['end_line'] : $s;
            $isInsert = ($en === $s - 1);
            $replacement = isset($e['replacement']) ? (string) $e['replacement'] : '';
            $expect = array_key_exists('expect', $e) ? (string) $e['expect'] : null;

            if ($isInsert) {
                if ($s < 1 || $s > $total + 1) {
                    throw new ModxMCPClientException("edit #{$idx}: insert position {$s} out of range (1.." . ($total + 1) . ").");
                }
                $rs = $s; $re = $s - 1;
            } else {
                if ($en < $s) {
                    throw new ModxMCPClientException("edit #{$idx}: end_line < start_line.");
                }
                list($rs, $re) = $this->locateEditRange($lines, $s, $en, $expect, $idx);
            }
            $replLines = ($replacement === '') ? array() : explode("\n", str_replace("\r\n", "\n", $replacement));
            $resolved[] = array('s' => $rs, 'e' => $re, 'repl' => $replLines, 'insert' => $isInsert);
        }

        // Reject overlapping replace/delete ranges.
        $covered = array();
        foreach ($resolved as $r) {
            if ($r['insert']) { continue; }
            for ($i = $r['s']; $i <= $r['e']; $i++) {
                if (isset($covered[$i])) {
                    throw new ModxMCPClientException("edits overlap on line {$i}.");
                }
                $covered[$i] = true;
            }
        }

        // Apply bottom-up so earlier line indices stay valid.
        usort($resolved, function ($a, $b) { return $b['s'] - $a['s']; });
        foreach ($resolved as $r) {
            $offset = $r['s'] - 1;
            $length = $r['insert'] ? 0 : ($r['e'] - $r['s'] + 1);
            array_splice($lines, $offset, $length, $r['repl']);
        }

        $newContent = implode($eol, $lines);
        $totalAfter = count($lines);

        $revision = $this->writeEffectiveContent($el, $m, $isStatic, $staticAbs, $newContent, $this->contentRevision($content));
        $this->logContentSave('edit_element_lines', $type, array('id' => $id, 'edits' => count($edits), 'revision' => $revision));
        return array(
            'type' => $type,
            'id' => $id,
            'static' => $isStatic,
            'revision' => $revision,
            'edits_applied' => count($edits),
            'total_lines_before' => $total,
            'total_lines_after' => $totalAfter,
            'cache_refreshed' => $this->refreshContentCache(),
        );
    }

    /**
     * Site-wide search & replace across code elements (chunk/snippet/template/plugin). Finds
     * every element whose CONTENT contains `find` and replaces all occurrences with
     * `replacement`, in one call. Honours static files vs DB. Set `dry_run` to preview the
     * affected elements + occurrence counts without writing. data:
     *   find (required), replacement (required), types?[], case_sensitive?, dry_run?, limit?.
     */
    private function replaceAcross($data) {
        $find = isset($data['find']) ? (string) $data['find'] : '';
        if ($find === '') { throw new ModxMCPClientException('replace_across: "find" is required.'); }
        if (!array_key_exists('replacement', $data)) { throw new ModxMCPClientException('replace_across: "replacement" is required.'); }
        $replacement = (string) $data['replacement'];
        $cs = array_key_exists('case_sensitive', $data) ? !empty($data['case_sensitive']) : true;
        $dry = !empty($data['dry_run']);
        $allowed = $this->lineEditMap();
        $types = (isset($data['types']) && is_array($data['types']) && $data['types'])
            ? array_values(array_unique(array_filter($data['types'], function ($t) use ($allowed) { return isset($allowed[$t]); })))
            : array('chunk', 'snippet', 'template', 'plugin');
        if (!$types) { throw new ModxMCPClientException('replace_across: types must be among chunk, snippet, template, plugin.'); }
        $limit = isset($data['limit']) ? min(200, max(1, (int) $data['limit'])) : 200;
        $hasExpected = array_key_exists('expected_revisions', $data);
        $expected = $hasExpected ? $data['expected_revisions'] : array();
        if (!is_array($expected) || count($expected) > $limit) {
            throw new ModxMCPClientException('expected_revisions must be a map of type:id to revision, within limit (max 200).');
        }
        if ($hasExpected) {
            // Apply exactly the reviewed selection; do not silently include new matches.
            $hits = array('results' => array());
            foreach ($expected as $key => $revision) {
                if (!preg_match('/^(chunk|snippet|template|plugin):([1-9][0-9]*)$/D', $key, $match) || !in_array($match[1], $types, true)) {
                    throw new ModxMCPClientException('Invalid element key in expected_revisions. Use the revisions map from dry_run.');
                }
                $hits['results'][] = array('type' => $match[1], 'id' => (int) $match[2]);
            }
        } else {
            $hits = $this->searchCode(array('query' => $find, 'types' => $types, 'limit' => $limit, 'case_sensitive' => $cs));
        }

        // Preflight all revisions before the first write. Each save checks again under lock.
        $plans = array();
        foreach ($hits['results'] as $h) {
            $type = $h['type'];
            if (!isset($allowed[$type])) { continue; }
            $m = $allowed[$type];
            $id = (int) $h['id'];
            $key = $type . ':' . $id;
            $el = $this->modx->getObject($m['class'], $id, false);
            if (!$el) { throw new ModxMCPClientException('Element no longer exists: ' . $key); }
            list($content, $isStatic, $staticAbs) = $this->readEffectiveContent($el, $m);
            if ($hasExpected) { $this->assertContentRevision($content, $expected[$key]); }
            $count = $cs ? substr_count($content, $find) : substr_count(strtolower($content), strtolower($find));
            if ($count === 0) { continue; }
            $name = $el->get($type === 'template' ? 'templatename' : 'name');
            $plans[] = array('el' => $el, 'm' => $m, 'type' => $type, 'id' => $id, 'key' => $key,
                'name' => $name, 'content' => $content, 'static' => $isStatic, 'path' => $staticAbs,
                'revision' => $this->contentRevision($content), 'count' => $count);
        }

        $results = array();
        $revisions = array();
        $totalOcc = 0;
        $committed = array();
        $cacheRefreshed = null;
        try {
            foreach ($plans as $plan) {
                $revision = $plan['revision'];
                if (!$dry) {
                    $new = $cs ? str_replace($find, $replacement, $plan['content']) : str_ireplace($find, $replacement, $plan['content']);
                    try {
                        $revision = $this->writeEffectiveContent($plan['el'], $plan['m'], $plan['static'], $plan['path'], $new, $revision);
                    } catch (Exception $e) {
                        $message = 'replace_across stopped at ' . $plan['key'] . '. Already committed: ' . ($committed ? implode(', ', $committed) : 'none') . '. ';
                        if ($e instanceof ModxMCPClientException) { throw new ModxMCPClientException($message . $e->getMessage(), 0, $e); }
                        $errorId = uniqid('modxmcp_save_', true);
                        $this->modx->log(modX::LOG_LEVEL_ERROR, '[' . $errorId . '] ' . $message . $e->getMessage());
                        throw new ModxMCPClientException($message . 'Internal save error; see MODX error log: ' . $errorId, 0, $e);
                    }
                    $committed[] = $plan['key'];
                    $this->logContentSave('replace_across', $plan['type'], array('id' => $plan['id'], 'occurrences' => $plan['count'], 'revision' => $revision));
                }
                $row = array('type' => $plan['type'], 'id' => $plan['id'], 'name' => $plan['name'], 'occurrences' => $plan['count'], 'static' => $plan['static'], 'revision' => $revision);
                if ($dry) {
                    $eol = "\n";
                    $ls = $this->splitLines($plan['content'], $eol);
                    $needle = $cs ? $find : strtolower($find);
                    $preview = array();
                    foreach ($ls as $li => $lt) {
                        $hay = $cs ? $lt : strtolower($lt);
                        if (strpos($hay, $needle) !== false) {
                            $preview[] = array('line' => $li + 1, 'line_text' => $lt);
                            if (count($preview) >= 5) { break; }
                        }
                    }
                    $row['preview'] = $preview;
                }
                $results[] = $row;
                $revisions[$plan['key']] = $revision;
                $totalOcc += $plan['count'];
            }
        } finally {
            // Also clear after a partial batch; committed items must not retain stale cache.
            if ($committed) { $cacheRefreshed = $this->refreshContentCache(); }
        }
        return array('find' => $find, 'replacement' => $replacement, 'case_sensitive' => $cs,
            'dry_run' => $dry, 'elements' => count($results), 'total_occurrences' => $totalOcc,
            'capped_at' => $limit, 'results' => $results, 'revisions' => (object) $revisions,
            'cache_refreshed' => $cacheRefreshed);
    }

    private function filterProcessorData($elementType, array $data) {
        $allowed = [
            'chunk' => ['id', 'name', 'description', 'snippet', 'category', 'static', 'static_file', 'source', 'property_preprocess'],
            'snippet' => ['id', 'name', 'description', 'snippet', 'category', 'static', 'static_file', 'source', 'property_preprocess'],
            'template' => ['id', 'templatename', 'description', 'content', 'category', 'static', 'static_file', 'source'],
            'resource' => ['id', 'pagetitle', 'longtitle', 'description', 'alias', 'parent', 'template', 'content', 'published', 'context_key', 'class_key', 'isfolder', 'hidemenu', 'introtext', 'menutitle', 'menuindex', 'article', 'price', 'old_price', 'weight', 'remains', 'vendor', 'made_in', 'new', 'popular', 'favorite', 'tags', 'color', 'size'],
            'tv' => ['id', 'name', 'caption', 'description', 'category', 'type', 'elements', 'display', 'default_text', 'rank'],
            'category' => ['id', 'category', 'parent', 'rank'],
            'plugin' => ['id', 'name', 'description', 'plugincode', 'category', 'disabled', 'static', 'static_file', 'source', 'property_preprocess'],
        ];

        if (empty($allowed[$elementType])) {
            return $data;
        }

        return array_intersect_key($data, array_flip($allowed[$elementType]));
    }
}
