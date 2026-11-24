<?php
namespace ModxMCP\Integration;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Kind\Objects;
use ModxMCP\Kind\ResourceKind;
use ModxMCP\Write\Transaction;

/** Opt-in rename of a page and the one generated rule that maintains its old address. */
final class RedirectorRename {
    const OP = 'rename_with_redirect';
    const MAX_ALIAS = 100;

    public static function preview(ResourceKind $kind, Context $context, $resource, array $args) {
        $plan = self::inspect($kind, $context, $resource, $args);
        return array('old_uri' => $plan['old_uri'], 'alias' => $args['alias']);
    }

    private static function inspect(ResourceKind $kind, Context $context, $resource, array $args, array $snapshot = null) {
        Redirector::checkRenameAccess($context);
        $modx = $context->modx;
        $id = (int) $resource->get('id');
        $key = (string) $resource->get('context_key');
        $site = $modx->getContext($key);
        if (!$site || !preg_match('/^[A-Za-z0-9_.-]+$/D', $key) || !$site->getOption('friendly_urls', $modx->getOption('friendly_urls'))) {
            throw new ApiException('validation_failed', 'An existing context and friendly URLs are required.');
        }
        if ($resource->get('class_key') !== 'modDocument' || !$resource->get('published') || $resource->get('deleted') || $resource->get('uri_override')
            || self::resourceExists($modx, array('parent' => $id))) {
            throw new ApiException('validation_failed', 'Choose a published page without children or a frozen URI.');
        }
        if (!preg_match('/^[\pL\pN_-]{1,' . self::MAX_ALIAS . '}$/uD', $args['alias'])) {
            throw new ApiException('validation_failed', 'Use one alias segment with letters, numbers, underscores or hyphens.');
        }
        if (mb_strtolower($args['alias'], 'UTF-8') === mb_strtolower((string) $resource->get('alias'), 'UTF-8')) {
            throw new ApiException('validation_failed', 'The requested alias matches the current alias.');
        }
        if (isset($args['expected_revision'])) {
            Objects::assertRevision($context, $kind, $id, $snapshot ?? Snapshot::capture($modx, $kind, $id), $args);
        }

        $old = (string) $resource->get('uri');
        self::assertAddressFree($modx, $key, $id, $old);
        $search = self::requestPath($modx, $site, $key, $old);
        // Dot is the only regex metacharacter in the permitted URI alphabet.
        $pattern = '^' . str_replace('.', '\\.', $search) . '$';
        if (mb_strlen($pattern, 'UTF-8') > 255) { throw new ApiException('validation_failed', 'The old URL is too long for an exact rule.'); }
        Redirector::assertNoMatchingRule($modx, $key, $search, $pattern);
        return array('key' => $key, 'site' => $site, 'old_uri' => $old, 'search' => $search, 'pattern' => $pattern);
    }

    public static function rename(ResourceKind $kind, Context $context, Transaction $tx, $resource, array $args) {
        $modx = $context->modx;
        $id = (int) $resource->get('id');
        $before = $tx->touch($kind, $id);
        $tx->lock('redirector:rules'); // Global rules participate in every context's collision check.
        $tx->requireReversible();
        $plan = self::inspect($kind, $context, $resource, $args, $before);
        Objects::save($context, $tx, $kind, $id, Objects::normalize($kind, array('alias' => $args['alias']), false), array());

        $updated = $kind->findByKey($modx, $id);
        $key = $plan['key'];
        if (!$updated || $updated->get('context_key') !== $key || !$updated->get('published') || $updated->get('deleted')
            || $updated->get('class_key') !== 'modDocument' || $updated->get('uri_override') || self::resourceExists($modx, array('parent' => $id))) {
            throw new ApiException('validation_failed', 'The updated page is ineligible for a redirect.');
        }
        $new = (string) $updated->get('uri');
        $newSearch = self::requestPath($modx, $plan['site'], $key, $new);
        if (self::equivalent($plan['old_uri'], $new) || self::equivalent($plan['search'], $newSearch)) {
            throw new ApiException('validation_failed', 'MODX kept the same effective page URL.');
        }
        self::assertAddressFree($modx, $key, $id, $plan['old_uri']);
        self::assertAddressFree($modx, $key, $id, $new);
        Redirector::assertNoMatchingRule($modx, $key, $plan['search'], $plan['pattern']);

        $ruleKind = Objects::kind($context, 'redirect', true);
        $rule = array('pattern' => $plan['pattern'], 'target' => $new, 'context_key' => $key, 'active' => 1);
        try {
            $result = Objects::process($modx, 'mgr/redirect/create', $rule, array('processors_path' => Redirector::processorsPath($modx)));
        } finally {
            // The matched rule was absent before invoking the native create path. Capture it even if the processor fails after saving.
            foreach ($modx->getCollection('modRedirect', array('pattern' => $plan['pattern'], 'context_key' => $key), false) as $candidate) {
                if ($candidate->get('pattern') === $plan['pattern'] && $candidate->get('target') === $new && $candidate->get('context_key') === $key) {
                    $tx->created($ruleKind, (int) $candidate->get('id'));
                }
            }
        }
        $redirectId = (int) ($result['id'] ?? 0);
        $created = $redirectId > 0 ? $ruleKind->findByKey($modx, $redirectId) : null;
        if (!$created || $created->get('pattern') !== $plan['pattern'] || $created->get('target') !== $new
            || $created->get('context_key') !== $key || !$created->get('active')) {
            throw new \RuntimeException('The generated Redirector rule was not saved as expected.');
        }
        return array('old_uri' => $plan['old_uri'], 'new_uri' => $new, 'redirect_id' => $redirectId,
            'summary' => 'rename page ' . $id . ' and add redirect ' . $redirectId);
    }

    /** An external rule edit must never turn a forced undo into a way to write an arbitrary redirect. */
    public static function assertStoredRule(array $state) {
        $pattern = $state['pattern'] ?? '';
        try {
            $literal = str_replace('\\.', '.', substr($pattern, 1, -1));
            if ($literal !== self::decoded($literal) || '^' . str_replace('.', '\\.', $literal) . '$' !== $pattern
                || !preg_match('/^[A-Za-z0-9_.-]+$/D', (string) ($state['context_key'] ?? ''))) {
                throw new \RuntimeException();
            }
            self::decoded((string) ($state['target'] ?? ''));
            return $literal;
        } catch (\Throwable $e) {
            throw new ApiException('not_reversible', 'Only generated exact Redirector rules with a local target can be restored or removed.');
        }
    }

    public static function assertRestorableContext(\modX $modx, $id, array $state) {
        $literal = self::assertStoredRule($state);
        $key = $state['context_key'];
        $context = $modx->getContext($key);
        try {
            if (!$context || $context->get('key') !== $key) { throw new \RuntimeException(); }
            self::requestPath($modx, $context, $key, $state['target']);
            Redirector::assertNoMatchingRule($modx, $key, $literal, $state['pattern'], $id);
        } catch (\Throwable $e) {
            throw new ApiException('not_reversible', 'The recorded redirect cannot be safely restored in its context.');
        }
    }

    /** Validate the native relative address both before and after one URL decode. */
    private static function decoded($uri) {
        if (!preg_match('/^(?:[\pL\pM\pN_.\/-]|%[0-9a-fA-F]{2})+$/uD', $uri) || $uri[0] === '/' || preg_match('/%2f/i', $uri)
            || mb_strlen($uri, 'UTF-8') > 255) {
            throw new ApiException('validation_failed', 'MODX must provide a safe relative page URL.');
        }
        $value = rawurldecode($uri);
        if (!preg_match('/^[\pL\pM\pN_.-]+(?:\/[\pL\pM\pN_.-]+)*\/?$/uD', $value)) {
            throw new ApiException('validation_failed', 'The decoded page URL is not a safe local path.');
        }
        foreach (explode('/', $value) as $part) {
            if ($part === '.' || $part === '..') { throw new ApiException('validation_failed', 'The page URL contains a dot segment.'); }
        }
        return $value;
    }

    private static function requestPath(\modX $modx, $context, $key, $uri) {
        $decoded = self::decoded($uri);
        $site = parse_url((string) $context->getOption('site_url', $modx->getOption('site_url')));
        if (!$site || !in_array(strtolower($site['scheme'] ?? ''), array('http', 'https'), true) || empty($site['host'])
            || array_intersect_key($site, array_flip(array('user', 'pass', 'fragment', 'query')))) {
            throw new ApiException('validation_failed', 'The page context needs a valid HTTP or HTTPS site URL.');
        }
        $prefix = trim($site['path'] ?? '', '/');
        $request = '/' . ($prefix === '' ? '' : self::decoded($prefix) . '/') . $decoded;
        $base = (string) $context->getOption('base_url', $modx->getOption('base_url', null, '/'));
        if ($base !== '' && $base !== '/' && $base !== ' ' && $base !== '/' . $key . '/') {
            if (strpos($request, $base) !== 0 || substr_count($request, $base) !== 1) {
                throw new ApiException('validation_failed', 'The context URL prefix cannot be matched unambiguously by Redirector.');
            }
            $request = str_replace($base, '', $request);
        }
        return self::decoded(ltrim($request, '/'));
    }

    private static function equivalent($left, $right) {
        return mb_strtolower(rtrim(self::decoded($left), '/'), 'UTF-8') === mb_strtolower(rtrim(self::decoded($right), '/'), 'UTF-8');
    }

    private static function assertAddressFree(\modX $modx, $key, $id, $uri) {
        $decoded = self::decoded($uri);
        $paths = array_unique(array($uri, $decoded, rtrim($uri, '/'), rtrim($decoded, '/'), rtrim($uri, '/') . '/', rtrim($decoded, '/') . '/'));
        if (self::resourceExists($modx, array('published' => 1, 'deleted' => 0, 'context_key' => $key, 'uri:IN' => $paths, 'id:!=' => $id))) {
            throw new ApiException('validation_failed', 'Another published page uses that URL.');
        }
    }

    private static function resourceExists(\modX $modx, array $criteria) {
        $query = $modx->newQuery('modResource');
        $query->select(array($modx->escape('id')));
        $query->where($criteria);
        $query->limit(1);
        if (!$query->prepare() || !$query->stmt->execute()) { throw new ApiException('validation_failed', 'Could not verify the page and its URL.'); }
        return $query->stmt->fetchColumn() !== false;
    }
}
