<?php
/** files operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPFilesTrait {
    // --- Media-source file & folder operations (modMediaSource API) ---

    private function initMediaSource($data) {
        $sel = array();
        if (isset($data['source']) && $data['source'] !== '') {
            if (is_numeric($data['source'])) { $sel['id'] = (int) $data['source']; }
            else { $sel['name'] = (string) $data['source']; }
        } elseif (!empty($data['id'])) {
            $sel['id'] = (int) $data['id'];
        } elseif (!empty($data['name'])) {
            $sel['name'] = (string) $data['name'];
        }
        $source = $this->resolveMediaSource($sel);
        if (!$source) { throw new ModxMCPClientException('Media source not found (provide "source" = id or name).'); }
        $source->initialize();
        return $source;
    }

    private function mediaSourceError($source, $fallback) {
        $errs = method_exists($source, 'getErrors') ? $source->getErrors() : array();
        if (is_array($errs) && $errs) {
            $parts = array();
            foreach ($errs as $k => $v) { $parts[] = is_string($k) ? "{$k}: {$v}" : (string) $v; }
            return implode('; ', $parts);
        }
        return $fallback;
    }

    private function createMediaFile($data) {
        $source = $this->initMediaSource($data);
        // createObject concatenates container + name, so the container must end with "/".
        $dir = isset($data['path']) ? trim((string) $data['path'], '/') : '';
        $dir = ($dir === '') ? '/' : $dir . '/';
        $name = isset($data['name']) ? (string) $data['name'] : '';
        if ($name === '') { throw new ModxMCPClientException('create_media_file: "name" is required.'); }
        $res = $source->createObject($dir, $name, isset($data['content']) ? (string) $data['content'] : '');
        if ($res === false) { throw new ModxMCPClientException('create_media_file failed: ' . $this->mediaSourceError($source, 'unknown error')); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('create_media_file', 'source', array('source' => (int) $source->get('id'), 'path' => $dir, 'name' => $name));
        return array('created' => true, 'path' => rtrim($dir, '/') . '/' . $name);
    }

    private function updateMediaFile($data) {
        $source = $this->initMediaSource($data);
        $path = isset($data['path']) ? (string) $data['path'] : '';
        if ($path === '') { throw new ModxMCPClientException('update_media_file: "path" (file) is required.'); }
        if (!array_key_exists('content', $data)) { throw new ModxMCPClientException('update_media_file: "content" is required.'); }
        $res = $source->updateObject($path, (string) $data['content']);
        if ($res === false) { throw new ModxMCPClientException('update_media_file failed: ' . $this->mediaSourceError($source, 'unknown error')); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('update_media_file', 'source', array('source' => (int) $source->get('id'), 'path' => $path));
        return array('updated' => true, 'path' => $path);
    }

    private function deleteMediaFile($data) {
        $source = $this->initMediaSource($data);
        $path = isset($data['path']) ? (string) $data['path'] : '';
        if ($path === '') { throw new ModxMCPClientException('delete_media_file: "path" is required.'); }
        $res = $source->removeObject($path);
        if ($res === false) { throw new ModxMCPClientException('delete_media_file failed: ' . $this->mediaSourceError($source, 'unknown error')); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('delete_media_file', 'source', array('source' => (int) $source->get('id'), 'path' => $path));
        return array('deleted' => true, 'path' => $path);
    }

    private function renameMediaFile($data) {
        $source = $this->initMediaSource($data);
        $path = isset($data['path']) ? (string) $data['path'] : '';
        $newName = isset($data['new_name']) ? (string) $data['new_name'] : '';
        if ($path === '' || $newName === '') { throw new ModxMCPClientException('rename_media_file: "path" and "new_name" are required.'); }
        $res = $source->renameObject($path, $newName);
        if ($res === false) { throw new ModxMCPClientException('rename_media_file failed: ' . $this->mediaSourceError($source, 'unknown error')); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('rename_media_file', 'source', array('source' => (int) $source->get('id'), 'path' => $path, 'new_name' => $newName));
        return array('renamed' => true, 'path' => $path, 'new_name' => $newName);
    }

    private function createMediaFolder($data) {
        $source = $this->initMediaSource($data);
        $parent = isset($data['parent']) ? (string) $data['parent'] : '/';
        $name = isset($data['name']) ? (string) $data['name'] : '';
        if ($name === '') { throw new ModxMCPClientException('create_media_folder: "name" is required.'); }
        $res = $source->createContainer($name, $parent);
        if ($res === false) { throw new ModxMCPClientException('create_media_folder failed: ' . $this->mediaSourceError($source, 'unknown error')); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('create_media_folder', 'source', array('source' => (int) $source->get('id'), 'parent' => $parent, 'name' => $name));
        return array('created' => true, 'parent' => $parent, 'name' => $name);
    }

    /** Resolve only a real, non-root descendant of a local filesystem Media Source. */
    private function resolveMediaFolderDeletion($source, $relative) {
        if (!($source instanceof modFileMediaSource) || !($source->fileHandler instanceof modFileHandler)) {
            throw new ModxMCPClientException('delete_media_folder supports local filesystem Media Sources only.');
        }
        clearstatcache(true);
        $base = $source->getBasePath();
        if (!is_string($base) || $base === '' || strpos($base, '://') !== false) {
            throw new ModxMCPClientException('Media Source does not have a local filesystem root.');
        }
        $root = realpath($base);
        if ($root === false || !is_dir($root)) { throw new ModxMCPClientException('Media Source root does not exist.'); }
        $candidate = $this->joinMediaSourcePath($root, $relative);
        // Check every component before realpath can hide a symlink in an ancestor.
        $cursor = rtrim($root, '/\\');
        foreach (explode('/', $relative) as $part) {
            $cursor .= DIRECTORY_SEPARATOR . $part;
            clearstatcache(true, $cursor);
            if (is_link($cursor)) { throw new ModxMCPClientException('Deleting through a symbolic link is not allowed.'); }
        }
        $path = realpath($candidate);
        if ($path === false || !is_dir($path)) { throw new ModxMCPClientException('Folder not found inside the Media Source.'); }
        if ($path === $root || !$this->pathStartsWith($path, $root)) {
            throw new ModxMCPClientException('Refusing to delete the Media Source root or a folder outside it.');
        }
        // The core handler rewrites separators and trailing dots. Refuse any rewrite that
        // would make its removal path differ from the validated physical directory.
        $sanitized = $source->fileHandler->sanitizePath($source->fileHandler->postfixSlash($path));
        if (realpath($sanitized) !== $path) {
            throw new ModxMCPClientException('MODX would rewrite this folder path. Deletion refused.');
        }
        $stat = lstat($path);
        if ($stat === false) { throw new ModxMCPClientException('Cannot inspect the selected folder.'); }
        return array('root' => $root, 'path' => $path, 'device' => $stat['dev'], 'inode' => $stat['ino']);
    }

    /** Inspect the entire tree, while returning a bounded preview. Never follow links. */
    private function inspectMediaFolderDeletion(array $target, $relative, $limit) {
        $stack = array(array($target['path'], $relative));
        $entries = array();
        $files = 0;
        $directories = 0;
        $bytes = 0;
        $hash = hash_init('sha256');
        hash_update($hash, $target['root'] . "\0" . $target['path'] . "\n");
        while ($stack) {
            list($path, $rel) = array_pop($stack);
            if (preg_match('//u', $rel) !== 1) { throw new ModxMCPClientException('Folder contains a filename that is not valid UTF-8. Rename it before using this tool.'); }
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false) { throw new ModxMCPClientException('Folder contents changed or cannot be inspected; retry the preview.'); }
            $kind = $stat['mode'] & 0170000;
            if ($kind === 0120000) { throw new ModxMCPClientException('Folder contains a symbolic link; recursive deletion refused: ' . $rel); }
            if ($kind !== 0040000 && $kind !== 0100000) {
                throw new ModxMCPClientException('Folder contains a special filesystem entry; deletion refused: ' . $rel);
            }
            $real = realpath($path);
            if ($real === false || !$this->pathStartsWith($real, $target['root']) || !$this->pathStartsWith($real, $target['path'])) {
                throw new ModxMCPClientException('Folder contents resolve outside the selected folder or Media Source.');
            }
            $directory = $kind === 0040000;
            if ($directory) {
                if (!is_readable($path) || !is_writable($path)) {
                    throw new ModxMCPClientException('Folder is not readable/writable for recursive deletion: ' . $rel);
                }
                $names = @scandir($path);
                if ($names === false) { throw new ModxMCPClientException('Cannot read folder contents: ' . $rel); }
                // Reverse pushes produce deterministic ascending traversal/fingerprints.
                foreach (array_reverse($names) as $name) {
                    if ($name === '.' || $name === '..') { continue; }
                    $stack[] = array($path . DIRECTORY_SEPARATOR . $name, $rel . '/' . $name);
                }
                $directories++;
            } else {
                $files++;
                $bytes += $stat['size'];
            }
            hash_update($hash, serialize(array($rel, $kind, $stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime'])) . "\n");
            if (count($entries) < $limit) {
                $entries[] = array('path' => $rel, 'type' => $directory ? 'directory' : 'file', 'bytes' => $directory ? 0 : $stat['size']);
            }
        }
        return array('files' => $files, 'directories' => $directories, 'bytes' => $bytes,
            'entries' => $entries, 'truncated' => $files + $directories > count($entries),
            'revision' => hash_final($hash));
    }

    private function deleteMediaFolder($data) {
        if (!isset($data['path']) || !is_string($data['path'])) {
            throw new ModxMCPClientException('delete_media_folder: a relative folder path is required.');
        }
        if (strpos($data['path'], "\0") !== false || trim($data['path']) !== $data['path']) {
            throw new ModxMCPClientException('Folder path must not contain null bytes or leading/trailing whitespace.');
        }
        $raw = str_replace('\\', '/', $data['path']);
        if (strpos($raw, "\0") !== false || strpos($raw, ':') !== false || $this->isAbsolutePath($raw)) {
            throw new ModxMCPClientException('Use a relative path inside the Media Source; absolute paths and stream URLs are not allowed.');
        }
        $relative = $this->normalizeRelativePath($raw);
        if ($relative === '') { throw new ModxMCPClientException('Refusing to delete the Media Source root.'); }
        if (isset($data['dry_run']) && !is_bool($data['dry_run'])) {
            throw new ModxMCPClientException('dry_run must be a JSON boolean.');
        }
        $dry = !empty($data['dry_run']);
        $limit = isset($data['limit']) ? min(500, max(1, (int) $data['limit'])) : 200;
        $source = $this->initMediaSource($data);
        $target = $this->resolveMediaFolderDeletion($source, $relative);
        $lock = $dry ? null : $this->acquireContentLock('media-folder:' . $target['root']);
        try {
            $preview = $this->inspectMediaFolderDeletion($target, $relative, $limit);
            if (array_key_exists('expected_revision', $data)) {
                $expected = $data['expected_revision'];
                if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D', $expected) || !hash_equals($preview['revision'], $expected)) {
                    throw new ModxMCPClientException('Folder differs from the reviewed preview. Run dry_run again before deleting.');
                }
            }
            $result = array_merge(array('source' => (int) $source->get('id'), 'path' => $target['path'],
                'relative_path' => $relative, 'dry_run' => $dry, 'deleted' => false), $preview);
            if ($dry) { return $result; }
            $current = $this->resolveMediaFolderDeletion($source, $relative);
            if ($current !== $target) { throw new ModxMCPClientException('Folder location changed during inspection; preview again.'); }
            $directory = $source->fileHandler->make($target['path']);
            if (!($directory instanceof modDirectory) || !$directory->isReadable() || !$directory->isWritable() || !is_writable(dirname($target['path']))) {
                throw new ModxMCPClientException('Selected folder cannot be removed with the current filesystem permissions.');
            }
            $cacheRefreshed = false;
            try {
                // Use the underlying core operation, but do not apply cache-cleanup exclusions
                // (e.g. .svn) to an explicitly selected media folder. Verify actual completion.
                $directory->remove(array('deleteTop' => true, 'skipDirs' => false, 'extensions' => array(),
                    'delete_exclude_items' => array('.', '..'), 'delete_exclude_patterns' => array()));
                clearstatcache(true, $target['path']);
                if (file_exists($target['path']) || is_link($target['path'])) {
                    throw new ModxMCPClientException('Folder removal is incomplete; some contents may already be deleted. Inspect the folder before retrying.');
                }
            } catch (Throwable $e) {
                $this->logContentSave('delete_media_folder_failed', 'source', array('source' => (int) $source->get('id'), 'path' => $target['path'], 'partial_deletion_possible' => true));
                if ($e instanceof ModxMCPClientException) { throw $e; }
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[modxmcp] Media folder deletion failed: ' . $e->getMessage());
                throw new ModxMCPClientException('Folder deletion failed; some contents may already be deleted. Inspect the folder and MODX error log before retrying.', 0, $e);
            } finally {
                $cacheRefreshed = $this->refreshContentCache();
            }
            // Preserve the core manager event/action on successful removal.
            try {
                $this->modx->invokeEvent('OnFileManagerDirRemove', array('directory' => $source->fileHandler->postfixSlash($target['path']), 'source' => &$source));
                $this->modx->logManagerAction('directory_remove', '', $directory->getPath());
            } catch (Throwable $e) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[modxmcp] Folder removed, but post-delete notification failed: ' . $e->getMessage());
            }
            $this->logContentSave('delete_media_folder', 'source', array('source' => (int) $source->get('id'), 'path' => $target['path'], 'files' => $preview['files'], 'directories' => $preview['directories']));
            $result['deleted'] = true;
            $result['cache_refreshed'] = $cacheRefreshed;
            return $result;
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    /**
     * Media (file) sources: create/update via source/* core processors.
     * `properties` is a simple {name: value} map of source parameters (basePath, baseUrl, ...)
     * that is MERGED into the source's existing params (the rest are preserved).
     */
    private function saveMediaSource($data, $isCreate) {
        $props = is_array($data) ? $data : array();
        unset($props['action'], $props['elementType']);
        $userProps = (isset($props['properties']) && is_array($props['properties'])) ? $props['properties'] : null;
        unset($props['properties']);
        if ($isCreate) {
            if (empty($props['name'])) { throw new ModxMCPClientException('create_media_source: name is required.'); }
            if (empty($props['class_key'])) { $props['class_key'] = 'sources.modFileMediaSource'; }
            $resp = $this->modx->runProcessor('source/create', $props);
        } else {
            if (empty($props['id'])) { throw new ModxMCPClientException('update_media_source: id is required.'); }
            $resp = $this->modx->runProcessor('source/update', $props);
        }
        if (!$resp) { throw new ModxMCPClientException('media source: no response.'); }
        if ($resp->isError()) { throw new ModxMCPClientException($this->formatProcessorErrors($resp)); }
        $obj = $resp->getObject();
        $id = $isCreate ? ((is_array($obj) && isset($obj['id'])) ? (int) $obj['id'] : 0) : (int) $props['id'];
        if ($userProps !== null && $id > 0) { $this->mergeMediaSourceProperties($id, $userProps); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit($isCreate ? 'create_media_source' : 'update_media_source', 'source', array('id' => $id, 'name' => isset($props['name']) ? $props['name'] : null));
        return array('id' => $id, 'properties_set' => $userProps !== null ? array_keys($userProps) : array());
    }

    private function mergeMediaSourceProperties($id, array $map) {
        $source = $this->modx->getObject('sources.modMediaSource', (int) $id);
        if (!$source) { throw new ModxMCPClientException("media source {$id} not found for properties update."); }
        $current = $source->getProperties();
        if (!is_array($current)) { $current = array(); }
        foreach ($map as $k => $v) {
            if (isset($current[$k]) && is_array($current[$k])) {
                $current[$k]['value'] = $v;
            } else {
                $current[$k] = array('name' => $k, 'desc' => '', 'type' => 'textfield', 'options' => array(), 'value' => $v, 'area' => '');
            }
        }
        $source->setProperties($current);
        if (!$source->save()) { throw new ModxMCPClientException("Could not save media source {$id} properties."); }
    }

    private function deleteMediaSource($data) {
        if (empty($data['id'])) { throw new ModxMCPClientException('delete_media_source: id is required.'); }
        $resp = $this->modx->runProcessor('source/remove', array('id' => (int) $data['id']));
        if (!$resp) { throw new ModxMCPClientException('delete_media_source: no response.'); }
        if ($resp->isError()) { throw new ModxMCPClientException($this->formatProcessorErrors($resp)); }
        if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
        $this->logAudit('delete_media_source', 'source', array('id' => (int) $data['id']));
        return array('deleted' => true, 'id' => (int) $data['id']);
    }

    private function listMediaSources() {
        $sources = $this->modx->getCollection('sources.modMediaSource');
        $result = [];
        foreach ($sources as $source) {
            $result[] = $this->normalizeMediaSource($source, false);
        }
        return $result;
    }

    private function getMediaSource(array $data) {
        $source = $this->resolveMediaSource($data);
        if (!$source) {
            throw new ModxMCPClientException('Media source not found.');
        }
        return $this->normalizeMediaSource($source, true);
    }

    private function listMediaSourceFiles(array $data) {
        $source = $this->resolveMediaSource($data);
        if (!$source) {
            throw new ModxMCPClientException('Media source not found.');
        }
        $this->assertMediaSourceReadAllowed($source);

        $rootPath = $this->getMediaSourceRootPath($source);
        $relativePath = !empty($data['path']) ? $this->normalizeRelativePath($data['path']) : '';
        $absolutePath = $this->joinMediaSourcePath($rootPath, $relativePath);

        if (!is_dir($absolutePath)) {
            throw new ModxMCPClientException("Directory not found: {$relativePath}");
        }

        $entries = [];
        foreach (scandir($absolutePath) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $entryAbsolute = $absolutePath . DIRECTORY_SEPARATOR . $entry;
            $entryRelative = ltrim(str_replace('\\', '/', ($relativePath ? $relativePath . '/' : '') . $entry), '/');
            $entries[] = [
                'name' => $entry,
                'path' => $entryRelative,
                'is_dir' => is_dir($entryAbsolute),
                'size' => is_file($entryAbsolute) ? filesize($entryAbsolute) : null,
                'modified_on' => filemtime($entryAbsolute),
            ];
        }

        usort($entries, function ($a, $b) {
            if ($a['is_dir'] === $b['is_dir']) {
                return strcmp($a['name'], $b['name']);
            }
            return $a['is_dir'] ? -1 : 1;
        });

        return [
            'media_source' => $this->normalizeMediaSource($source, false),
            'path' => $relativePath,
            'entries' => $entries,
        ];
    }

    private function readMediaSourceFile(array $data) {
        $source = $this->resolveMediaSource($data);
        if (!$source) {
            throw new ModxMCPClientException('Media source not found.');
        }
        $this->assertMediaSourceReadAllowed($source);
        if (empty($data['path'])) {
            throw new ModxMCPClientException('File path is required.');
        }

        $rootPath = $this->getMediaSourceRootPath($source);
        $relativePath = $this->normalizeRelativePath($data['path']);
        $absolutePath = $this->joinMediaSourcePath($rootPath, $relativePath);

        if (!is_file($absolutePath)) {
            throw new ModxMCPClientException("File not found: {$relativePath}");
        }

        return array_merge([
            'media_source' => $this->normalizeMediaSource($source, false),
            'path' => $relativePath,
        ], $this->readFileBounded($absolutePath, $data));
    }

    /**
     * Read a file with a byte cap (modxmcp.max_read_bytes, default 256KB) and an optional
     * offset/bytes window, so a huge file can't blow up the client's context. Returns
     * mime/encoding/content plus size (total file size), offset, returned_bytes and a
     * `truncated` flag (true when more bytes remain past offset+returned).
     */
    private function readFileBounded($absolutePath, array $data) {
        $total = (int) filesize($absolutePath);
        $maxAllowed = (int) $this->modx->getOption('modxmcp.max_read_bytes', null, 262144);
        if ($maxAllowed <= 0) { $maxAllowed = 262144; }
        $offset = isset($data['offset']) ? max(0, (int) $data['offset']) : 0;
        if ($offset > $total) { $offset = $total; }
        $requested = isset($data['bytes']) ? (int) $data['bytes'] : (isset($data['length']) ? (int) $data['length'] : $maxAllowed);
        if ($requested <= 0 || $requested > $maxAllowed) { $requested = $maxAllowed; }

        $raw = ($total > 0 && $offset < $total)
            ? (string) file_get_contents($absolutePath, false, null, $offset, $requested)
            : '';
        $returned = strlen($raw);

        $mime = function_exists('mime_content_type') ? mime_content_type($absolutePath) : 'application/octet-stream';
        $isText = is_string($mime) && (
            strpos($mime, 'text/') === 0 ||
            strpos($mime, 'json') !== false ||
            strpos($mime, 'xml') !== false ||
            strpos($mime, 'javascript') !== false ||
            strpos($mime, 'svg') !== false ||
            strpos($mime, 'x-httpd-php') !== false
        );

        return [
            'mime' => $mime,
            'size' => $total,
            'offset' => $offset,
            'returned_bytes' => $returned,
            'truncated' => ($offset + $returned) < $total,
            'encoding' => $isText ? 'utf-8' : 'base64',
            'content' => $isText ? $raw : base64_encode($raw),
        ];
    }

    private function listInstalledComponents() {
        $components = [];

        foreach ($this->getComponentCodeRoots() as $scope => $rootPath) {
            if (!is_dir($rootPath)) {
                continue;
            }

            foreach (scandir($rootPath) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $componentPath = $rootPath . DIRECTORY_SEPARATOR . $entry;
                if (!is_dir($componentPath)) {
                    continue;
                }

                if (!isset($components[$entry])) {
                    $components[$entry] = [
                        'name' => $entry,
                        'scopes' => [],
                    ];
                }

                $components[$entry]['scopes'][$scope] = [
                    'path' => $this->normalizeFilesystemPath($componentPath),
                ];
            }
        }

        ksort($components, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($components);
    }

    private function getComponentFiles(array $data) {
        if (empty($data['name'])) {
            throw new ModxMCPClientException('Component name is required.');
        }

        $relativePath = !empty($data['path']) ? $this->normalizeRelativePath($data['path']) : '';
        $scopes = $this->resolveComponentScopes(isset($data['scope']) ? $data['scope'] : null);
        $results = [];

        foreach ($scopes as $scope) {
            $componentRoot = $this->getComponentRootPath($data['name'], $scope);
            if (!is_dir($componentRoot)) {
                continue;
            }

            $absolutePath = $this->joinMediaSourcePath($componentRoot, $relativePath);
            if (!is_dir($absolutePath)) {
                continue;
            }

            $entries = [];
            foreach (scandir($absolutePath) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $entryAbsolute = $absolutePath . DIRECTORY_SEPARATOR . $entry;
                $entryRelative = ltrim(str_replace('\\', '/', ($relativePath ? $relativePath . '/' : '') . $entry), '/');
                $entries[] = [
                    'name' => $entry,
                    'path' => $entryRelative,
                    'is_dir' => is_dir($entryAbsolute),
                    'size' => is_file($entryAbsolute) ? filesize($entryAbsolute) : null,
                    'modified_on' => filemtime($entryAbsolute),
                ];
            }

            usort($entries, function ($a, $b) {
                if ($a['is_dir'] === $b['is_dir']) {
                    return strcmp($a['name'], $b['name']);
                }
                return $a['is_dir'] ? -1 : 1;
            });

            $results[] = [
                'scope' => $scope,
                'component' => $data['name'],
                'root_path' => $componentRoot,
                'path' => $relativePath,
                'entries' => $entries,
            ];
        }

        if (empty($results)) {
            throw new ModxMCPClientException("Component not found or path unavailable: {$data['name']}.");
        }

        return count($results) === 1 ? $results[0] : $results;
    }

    private function readComponentFile(array $data) {
        if (empty($data['name'])) {
            throw new ModxMCPClientException('Component name is required.');
        }
        if (empty($data['path'])) {
            throw new ModxMCPClientException('Component file path is required.');
        }

        $relativePath = $this->normalizeRelativePath($data['path']);
        $scopes = $this->resolveComponentScopes(isset($data['scope']) ? $data['scope'] : null);

        foreach ($scopes as $scope) {
            $componentRoot = $this->getComponentRootPath($data['name'], $scope);
            if (!is_dir($componentRoot)) {
                continue;
            }

            $absolutePath = $this->joinMediaSourcePath($componentRoot, $relativePath);
            if (!is_file($absolutePath)) {
                continue;
            }

            return array_merge([
                'component' => $data['name'],
                'scope' => $scope,
                'root_path' => $componentRoot,
                'path' => $relativePath,
            ], $this->readFileBounded($absolutePath, $data));
        }

        throw new ModxMCPClientException("Component file not found: {$data['name']} / {$relativePath}.");
    }

    private function resolveMediaSource(array $data) {
        if (!empty($data['id'])) {
            return $this->modx->getObject('sources.modMediaSource', (int)$data['id']);
        }
        if (!empty($data['name'])) {
            return $this->modx->getObject('sources.modMediaSource', ['name' => $data['name']]);
        }
        return null;
    }

    private function normalizeMediaSource($source, $includeProperties = true) {
        $result = [
            'id' => $source->get('id'),
            'name' => $source->get('name'),
            'class_key' => $source->get('class_key'),
            'description' => $source->get('description'),
        ];

        if ($includeProperties) {
            $result['properties'] = $this->normalizeMediaSourceProperties($source->get('properties'));
            try {
                $result['root_path'] = $this->getMediaSourceRootPath($source);
            } catch (Exception $e) {
                $result['root_path'] = null;
                $result['root_path_error'] = $e->getMessage();
            }
        }

        return $result;
    }

    private function getMediaSourceRootPath($source) {
        $basePath = $this->extractMediaSourcePropertyValue($source->get('properties'), 'basePath');

        if (($basePath === '' || $basePath === null) && method_exists($source, 'initialize')) {
            try {
                $source->initialize();
            } catch (Exception $e) {
                // Ignore initialization failures and continue with fallback resolution.
            }
        }

        if (($basePath === '' || $basePath === null) && method_exists($source, 'getProperty')) {
            $basePath = $source->getProperty('basePath');
        }

        if (($basePath === '' || $basePath === null) && method_exists($source, 'getBasePath')) {
            $basePath = $source->getBasePath();
        }

        if (($basePath === '' || $basePath === null) && method_exists($source, 'getBases')) {
            $bases = $source->getBases('');
            if (is_array($bases) && !empty($bases['path'])) {
                $basePath = $bases['path'];
            }
        }

        if (($basePath === '' || $basePath === null) && $this->isFilesystemMediaSource($source)) {
            $basePath = $this->modx->getOption('assets_path');
        }

        if ($basePath === '' || $basePath === null) {
            throw new ModxMCPClientException("Media source {$source->get('id')} does not define basePath.");
        }

        $resolved = $this->resolveModxPathPlaceholders((string)$basePath);
        if ($resolved === '' && $this->isFilesystemMediaSource($source)) {
            $resolved = (string)$this->modx->getOption('assets_path');
        }

        if (!$this->isAbsolutePath($resolved)) {
            $resolved = rtrim($this->modx->getOption('base_path'), '/\\') . DIRECTORY_SEPARATOR . ltrim($resolved, '/\\');
        }

        return $this->normalizeFilesystemPath($resolved);
    }

    private function normalizeRelativePath($path) {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new ModxMCPClientException('Path traversal is not allowed.');
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function joinMediaSourcePath($rootPath, $relativePath) {
        $absolutePath = $rootPath;
        if ($relativePath !== '') {
            $absolutePath .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        }

        $rootReal = realpath($rootPath);
        if ($rootReal === false) {
            $rootReal = $this->normalizeFilesystemPath($rootPath);
        }

        $targetDir = file_exists($absolutePath)
            ? realpath($absolutePath)
            : realpath(dirname($absolutePath));
        if ($targetDir === false) {
            $targetDir = $this->normalizeFilesystemPath(dirname($absolutePath));
        }

        if (!$this->pathStartsWith($targetDir, $rootReal)) {
            throw new ModxMCPClientException('Resolved path is outside of media source root.');
        }

        return $this->normalizeFilesystemPath($absolutePath);
    }

    private function isAbsolutePath($path) {
        return preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) === 1;
    }

    private function normalizeMediaSourceProperties($properties) {
        if (!is_array($properties)) {
            return $properties;
        }

        $normalized = [];
        foreach ($properties as $key => $value) {
            if (is_array($value) && array_key_exists('value', $value) && count($value) === 1) {
                $normalized[$key] = $value['value'];
                continue;
            }

            $normalized[$key] = is_array($value)
                ? $this->normalizeMediaSourceProperties($value)
                : $value;
        }

        return $normalized;
    }

    private function extractMediaSourcePropertyValue($properties, $key) {
        if (!is_array($properties) || !array_key_exists($key, $properties)) {
            return '';
        }

        $value = $properties[$key];
        if (is_array($value) && array_key_exists('value', $value)) {
            return $value['value'];
        }

        return $value;
    }

    private function resolveModxPathPlaceholders($path) {
        $replacements = [
            '{base_path}' => $this->modx->getOption('base_path'),
            '{core_path}' => $this->modx->getOption('core_path'),
            '{assets_path}' => $this->modx->getOption('assets_path'),
            '[[++base_path]]' => $this->modx->getOption('base_path'),
            '[[++core_path]]' => $this->modx->getOption('core_path'),
            '[[++assets_path]]' => $this->modx->getOption('assets_path'),
        ];

        return strtr((string)$path, $replacements);
    }

    private function normalizeFilesystemPath($path) {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$path), DIRECTORY_SEPARATOR);
    }

    private function pathStartsWith($path, $rootPath) {
        $path = $this->normalizeFilesystemPath($path);
        $rootPath = $this->normalizeFilesystemPath($rootPath);

        if ($path === $rootPath) {
            return true;
        }

        return strpos($path, $rootPath . DIRECTORY_SEPARATOR) === 0;
    }

    private function isFilesystemMediaSource($source) {
        $classKey = (string)$source->get('class_key');
        $name = (string)$source->get('name');

        return stripos($classKey, 'File') !== false || strcasecmp($name, 'Filesystem') === 0;
    }

    private function assertMediaSourceReadAllowed($source) {
        if ($this->isFilesystemMediaSource($source)) {
            $allow = (bool)$this->modx->getOption('modxmcp.allow_root_filesystem_read', null, false);
            if (!$allow) {
                throw new ModxMCPClientException('Filesystem media source browsing is disabled by modxmcp.allow_root_filesystem_read. Use component read tools for installed package code.');
            }
        }
    }

    private function getComponentCodeRoots() {
        $configuredRoots = (string)$this->modx->getOption(
            'modxmcp.component_code_roots',
            null,
            'core/components,assets/components'
        );

        $scopePaths = [];
        foreach (explode(',', $configuredRoots) as $configuredRoot) {
            $configuredRoot = trim($configuredRoot);
            if ($configuredRoot === '') {
                continue;
            }

            $resolved = $this->resolveModxPathPlaceholders($configuredRoot);
            if (!$this->isAbsolutePath($resolved)) {
                $resolved = rtrim($this->modx->getOption('base_path'), '/\\') . DIRECTORY_SEPARATOR . ltrim($resolved, '/\\');
            }

            $normalized = $this->normalizeFilesystemPath($resolved);
            $scope = stripos(str_replace('\\', '/', $configuredRoot), 'assets/components') !== false ? 'assets' : 'core';
            $scopePaths[$scope] = $normalized;
        }

        return $scopePaths;
    }

    private function resolveComponentScopes($scope = null) {
        if ($scope === null || $scope === '' || $scope === 'all') {
            return ['core', 'assets'];
        }

        if (!in_array($scope, ['core', 'assets'], true)) {
            throw new ModxMCPClientException('Component scope must be one of: core, assets, all.');
        }

        return [$scope];
    }

    private function getComponentRootPath($componentName, $scope) {
        $roots = $this->getComponentCodeRoots();
        if (empty($roots[$scope])) {
            throw new ModxMCPClientException("Component code root is not configured for scope: {$scope}.");
        }

        $safeName = $this->normalizeRelativePath($componentName);
        if (strpos($safeName, '/') !== false) {
            throw new ModxMCPClientException('Component name must be a single directory name.');
        }

        return $this->joinMediaSourcePath($roots[$scope], $safeName);
    }
}
