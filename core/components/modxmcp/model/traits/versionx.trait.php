<?php
/** versionx operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPVersionxTrait {
    private function listVersionXVersions(array $data) {
        $meta = $this->resolveVersionXType($data);
        $contentId = $this->requirePositiveInt($data, 'content_id');
        $limit = !empty($data['limit']) ? max(1, min((int)$data['limit'], 100)) : 20;

        $this->loadVersionXService();

        $query = $this->modx->newQuery($meta['class']);
        $query->where(['content_id' => $contentId]);
        $query->sortby('saved', 'DESC');
        $query->sortby('version_id', 'DESC');
        $query->limit($limit);

        $versions = $this->modx->getCollection($meta['class'], $query);
        $items = [];
        foreach ($versions as $version) {
            $items[] = $this->normalizeVersionXVersion($version, $meta, false);
        }

        return [
            'type' => $data['type'],
            'content_id' => $contentId,
            'count' => count($items),
            'versions' => $items,
        ];
    }

    private function getVersionXVersion(array $data) {
        $meta = $this->resolveVersionXType($data);
        $contentId = $this->requirePositiveInt($data, 'content_id');
        $versionId = $this->requirePositiveInt($data, 'version_id');

        $this->loadVersionXService();
        $version = $this->modx->getObject($meta['class'], [
            'content_id' => $contentId,
            'version_id' => $versionId,
        ]);
        if (!$version) {
            throw new ModxMCPClientException("VersionX version not found: {$data['type']} content_id={$contentId} version_id={$versionId}.");
        }

        return $this->normalizeVersionXVersion($version, $meta, true);
    }

    private function revertVersionXVersion(array $data) {
        $meta = $this->resolveVersionXType($data);
        $contentId = $this->requirePositiveInt($data, 'content_id');
        $versionId = $this->requirePositiveInt($data, 'version_id');

        if (empty($data['confirm'])) {
            throw new ModxMCPClientException('confirm=true is required to revert a VersionX version.');
        }

        $this->loadVersionXService();

        $before = $this->modx->getObject($meta['content_class'], $contentId);
        $version = $this->modx->getObject($meta['class'], [
            'content_id' => $contentId,
            'version_id' => $versionId,
        ]);
        if (!$version) {
            throw new ModxMCPClientException("VersionX version not found: {$data['type']} content_id={$contentId} version_id={$versionId}.");
        }

        $processorPath = $this->getVersionXCorePath() . 'processors/mgr/';
        $response = $this->modx->runProcessor($meta['processor'] . '/revert', [
            'content_id' => $contentId,
            'version_id' => $versionId,
        ], [
            'processors_path' => $processorPath,
        ]);

        if (!$response || $response->isError()) {
            throw new ModxMCPClientException('VersionX revert failed: ' . ($response ? $this->formatProcessorErrors($response) : 'No processor response.'));
        }

        $after = $this->modx->getObject($meta['content_class'], $contentId);
        $this->logAudit('versionx_revert_version', $data['type'], [
            'content_id' => $contentId,
            'version_id' => $versionId,
        ]);

        return [
            'reverted' => true,
            'type' => $data['type'],
            'content_id' => $contentId,
            'version_id' => $versionId,
            'version' => $this->normalizeVersionXVersion($version, $meta, false),
            'before_exists' => (bool)$before,
            'after_exists' => (bool)$after,
            'after' => $after ? [
                'id' => $after->get('id'),
                'class_key' => $after->get('class_key'),
                'name' => $this->getLiveObjectLabel($after),
            ] : null,
        ];
    }

    private function resolveVersionXType(array $data) {
        $type = !empty($data['type']) ? strtolower((string)$data['type']) : '';
        if (empty($this->versionXTypes[$type])) {
            throw new ModxMCPClientException('VersionX type must be one of: ' . implode(', ', array_keys($this->versionXTypes)) . '.');
        }
        return $this->versionXTypes[$type];
    }

    private function loadVersionXService() {
        $corePath = $this->getVersionXCorePath();
        $service = $this->modx->getService('versionx', 'VersionX', $corePath . 'model/');
        if (!$service) {
            throw new ModxMCPClientException('Could not load VersionX service. Is VersionX installed on this MODX site?');
        }
        return $service;
    }

    private function getVersionXCorePath() {
        return $this->modx->getOption('versionx.core_path', null, $this->modx->getOption('core_path') . 'components/versionx/');
    }

    private function normalizeVersionXVersion(xPDOObject $version, array $meta, $includePayload = false) {
        $labelField = $meta['label'];
        $result = [
            'version_id' => (int)$version->get('version_id'),
            'content_id' => (int)$version->get('content_id'),
            'saved' => $version->get('saved'),
            'user' => (int)$version->get('user'),
            'mode' => $version->get('mode'),
            'marked' => (bool)$version->get('marked'),
            'label' => $version->get($labelField),
        ];

        if ($version->get('class') !== null) {
            $result['class'] = $version->get('class');
        }
        if ($version->get('context_key') !== null) {
            $result['context_key'] = $version->get('context_key');
        }

        $content = $this->getVersionXContent($version);
        if ($content !== null) {
            $result['content_length'] = strlen((string)$content);
            $result['content_preview'] = function_exists('mb_substr')
                ? mb_substr((string)$content, 0, 300, 'UTF-8')
                : substr((string)$content, 0, 300);
        }

        if ($includePayload) {
            $result['data'] = $version->toArray();
        }

        return $result;
    }

    private function getVersionXContent(xPDOObject $version) {
        foreach (['content', 'snippet', 'plugincode'] as $field) {
            $value = $version->get($field);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }
}
