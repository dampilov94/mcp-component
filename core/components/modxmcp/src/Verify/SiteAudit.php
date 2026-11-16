<?php
namespace ModxMCP\Verify;

use ModxMCP\Context;
use ModxMCP\Code\FenomCheck;
use ModxMCP\Draft\DraftStore;
use ModxMCP\Graph\GraphBuilder;
use ModxMCP\Lint\PhpLint;

/**
 * Concrete problems of the site, each tied to an object. Read-only. Every check is one method; the dependency graph
 * supplies missing_element and unused_element so the tag parsing exists only once.
 */
final class SiteAudit {
    const CHECKS = array('broken_link', 'missing_element', 'unused_element', 'static_file_missing', 'duplicate_alias', 'php_syntax', 'missing_template', 'fenom_syntax');

    /** The per-resource checks look at no more resources than this (the most recently edited when there are more). */
    public static $maxResources = 5000;

    /** Resources are read in pages of this many ids, so their content is never all in memory. */
    const PAGE = 100;

    private $context;
    private $modx;
    private $limit;
    private $wanted;
    private $summary = array();
    private $found = array();
    private $notes = array();
    /** @var array kind:id => name of the objects that contain a [[~id]] link */
    private $linkNames = array();
    /** @var array [kind, id, target] one per object and linked resource */
    private $links = array();

    /**
     * @param string[] $checks names from CHECKS; empty means all
     * @return array ['summary' => ..., 'findings' => [...], 'notes' => [...]]
     */
    public static function run(Context $context, array $checks, $limit) {
        $audit = new self($context, $checks, $limit);
        return $audit->execute();
    }

    private function __construct(Context $context, array $checks, $limit) {
        $this->context = $context;
        $this->modx = $context->modx;
        $this->limit = max(1, (int) $limit);
        $this->wanted = array_flip($checks ? $checks : self::CHECKS);
        foreach (self::CHECKS as $check) {
            if (isset($this->wanted[$check])) {
                $this->summary[$check] = array('count' => 0, 'truncated' => false, 'scanned' => 0);
                $this->found[$check] = array();
            }
        }
    }

    private function wants($check) {
        return isset($this->wanted[$check]);
    }

    private function execute() {
        $resourcePass = $this->wants('broken_link') || $this->wants('duplicate_alias') || $this->wants('missing_template');
        $elementPass = $this->wants('broken_link') || $this->wants('static_file_missing') || $this->wants('php_syntax') || $this->wants('fenom_syntax');
        if ($resourcePass) { $this->scanResources(); }
        if ($elementPass) { $this->scanElements(); }
        if ($this->wants('broken_link')) { $this->reportBrokenLinks(); }
        if ($this->wants('missing_element') || $this->wants('unused_element')) { $this->graphChecks(); }

        $findings = array();
        foreach (self::CHECKS as $check) {
            if (!$this->wants($check)) { continue; }
            foreach ($this->found[$check] as $finding) { $findings[] = $finding; }
            $count = $this->summary[$check]['count'];
            if ($count > count($this->found[$check])) {
                $this->notes[] = "{$check}: {$count} found, the first " . count($this->found[$check]) . ' are listed; raise limit (up to 200) or fix these and run again.';
            }
        }
        return array('summary' => $this->summary, 'findings' => $findings, 'notes' => $this->notes);
    }

    /** Count a finding; only the first `limit` of a check are kept in full. */
    private function add($check, $severity, $kindName, $id, $name, $message, array $details) {
        $this->summary[$check]['count']++;
        if (count($this->found[$check]) >= $this->limit) { return; }
        $kind = $this->context->kinds->get($kindName);
        $this->found[$check][] = array(
            'check' => $check, 'severity' => $severity, 'kind' => $kindName,
            $kind ? $kind->keyField() : 'id' => $kind ? $kind->publicKey($id) : $id,
            'name' => (string) $name, 'message' => $message,
            'details' => $details ? $details : new \stdClass(),
        );
    }

    /** One pass over the resources, in pages: links, aliases and templates are all read from the same rows. */
    private function scanResources() {
        $modx = $this->modx;
        $max = max(1, (int) self::$maxResources);
        $total = (int) $modx->getCount('modResource');
        $capped = $total > $max;
        $query = $modx->newQuery('modResource');
        $query->select(array('id'));
        if ($capped) {
            $query->sortby('editedon', 'DESC');
            $query->sortby('id', 'DESC');
            $query->limit($max);
        } else {
            $query->sortby('id', 'ASC');
        }
        $ids = array();
        foreach ($modx->getCollection('modResource', $query) as $row) { $ids[] = (int) $row->get('id'); }
        if ($capped) {
            foreach (array('broken_link', 'duplicate_alias', 'missing_template') as $check) {
                if ($this->wants($check)) { $this->summary[$check]['truncated'] = true; }
            }
            $this->notes[] = "Per-resource checks looked at the {$max} most recently edited of {$total} resources; problems in the others are not counted.";
        }

        $templates = array();
        if ($this->wants('missing_template')) {
            foreach ($modx->getCollection('modTemplate') as $template) { $templates[(int) $template->get('id')] = true; }
        }
        $routes = array();
        $graph = $this->wants('broken_link') ? new GraphBuilder($modx) : null;
        // A draft is a working copy of a page: its address and links are not problems of the site.
        try {
            $drafts = (new DraftStore($modx))->draftIds();
        } catch (\Throwable $e) {
            $drafts = array();
        }
        foreach (array_chunk($ids, self::PAGE) as $page) {
            $rows = array();
            foreach ($modx->getCollection('modResource', array('id:IN' => $page)) as $resource) { $rows[(int) $resource->get('id')] = $resource; }
            ksort($rows);
            foreach ($rows as $id => $resource) {
                // A page in the trash is not shown to anyone: its links and address are not problems of the site.
                if ((bool) $resource->get('deleted') || isset($drafts[$id])) { continue; }
                $title = (string) $resource->get('pagetitle');
                if ($graph) {
                    $this->summary['broken_link']['scanned']++;
                    foreach ($graph->linkTargets((string) $resource->get('content')) as $target) {
                        $this->links[] = array('resource', $id, $target);
                        $this->linkNames['resource:' . $id] = $title;
                    }
                }
                if ($this->wants('missing_template')) {
                    $this->summary['missing_template']['scanned']++;
                    $template = (int) $resource->get('template');
                    if ($template !== 0 && !isset($templates[$template])) {
                        $this->add('missing_template', 'error', 'resource', $id, $title, "Resource uses template {$template}, which does not exist.", array('template' => $template));
                    }
                }
                if ($this->wants('duplicate_alias')) {
                    $this->summary['duplicate_alias']['scanned']++;
                    $uri = (string) $resource->get('uri');
                    if ($uri !== '') { $routes[(string) $resource->get('context_key') . "\0" . $uri][] = array($id, $title); }
                }
            }
        }
        foreach ($routes as $route => $group) {
            if (count($group) < 2) { continue; }
            list($contextKey, $uri) = explode("\0", $route, 2);
            $groupIds = array_column($group, 0);
            sort($groupIds);
            $first = null;
            foreach ($group as $entry) { if ($entry[0] === $groupIds[0]) { $first = $entry; } }
            $this->add('duplicate_alias', 'warning', 'resource', $first[0], $first[1],
                count($group) . " resources share the address '{$uri}' in context '{$contextKey}'.",
                array('uri' => $uri, 'context' => $contextKey, 'ids' => $groupIds));
        }
    }

    /** @return \Generator objects of one element class, in id order, read 200 at a time */
    private function elementsOf($class) {
        for ($offset = 0; ; $offset += 200) {
            $query = $this->modx->newQuery($class);
            $query->sortby('id', 'ASC');
            $query->limit(200, $offset);
            $page = $this->modx->getCollection($class, $query);
            foreach ($page as $object) { yield $object; }
            if (count($page) < 200) { break; }
        }
    }

    /** One pass over every element: links in its code, static files and PHP syntax, reading code through the kinds. */
    private function scanElements() {
        $graph = $this->wants('broken_link') ? new GraphBuilder($this->modx) : null;
        $fenom = null;
        if ($this->wants('fenom_syntax')) {
            $fenom = new FenomCheck($this->modx);
            if (!$fenom->available()) {
                $this->notes[] = 'fenom_syntax was skipped: pdoTools is not installed, Fenom is switched off for chunks (pdotools_fenom_default) or its compiler could not be loaded.';
                $fenom = null;
            }
        }
        foreach (array('chunk', 'snippet', 'template', 'plugin', 'tv') as $kindName) {
            $kind = $this->context->kinds->get($kindName);
            if (!$kind) { continue; }
            $isCode = $kind->codeField() !== null;
            $php = $kind->lint() === 'php';
            foreach ($this->elementsOf($kind->modelClass()) as $object) {
                $id = (int) $object->get('id');
                $name = (string) $kind->value($object, $kind->nameField());
                $static = (bool) $object->get('static');
                $content = null;
                if ($isCode) {
                    try {
                        $effective = $kind->effectiveContent($object);
                        $content = $effective[3] ? null : $effective[0];
                        if ($effective[3]) { $this->staticMissing($kindName, $id, $name, $object, 'missing'); }
                    } catch (\RuntimeException $e) {
                        $this->staticMissing($kindName, $id, $name, $object, 'unreadable');
                    }
                } elseif ($static) {
                    $this->checkPlainStatic($kindName, $id, $name, $object);
                }
                if ($this->wants('static_file_missing') && $static) { $this->summary['static_file_missing']['scanned']++; }
                if ($graph && $isCode) {
                    $this->summary['broken_link']['scanned']++;
                    // Fenom is read in chunks and templates only: code of a snippet or a plugin can look like it without being it.
                    foreach ($content === null ? array() : $graph->linkTargets($content, $kindName === 'chunk' || $kindName === 'template') as $target) {
                        $this->links[] = array($kindName, $id, $target);
                        $this->linkNames[$kindName . ':' . $id] = $name;
                    }
                }
                if ($fenom !== null && $content !== null && $kind->lint() === 'fenom') {
                    $this->summary['fenom_syntax']['scanned']++;
                    $error = $fenom->check($content, $kindName . ' ' . $name);
                    if ($error !== null) {
                        $this->add('fenom_syntax', 'error', $kindName, $id, $name, "Fenom syntax error on line {$error['line']}: {$error['message']}.", array('line' => $error['line'], 'error' => $error['message'], 'static' => $static));
                    }
                }
                if ($php && $content !== null && $this->wants('php_syntax')) {
                    $this->summary['php_syntax']['scanned']++;
                    $error = PhpLint::check($content);
                    if ($error !== null) {
                        $details = array('line' => $error['line'], 'error' => $error['message'], 'static' => $static);
                        if ($kindName === 'plugin') { $details['disabled'] = (bool) $object->get('disabled'); }
                        $this->add('php_syntax', 'error', $kindName, $id, $name, "PHP syntax error on line {$error['line']}: {$error['message']}.", $details);
                    }
                }
            }
        }
    }

    private function staticMissing($kindName, $id, $name, $object, $reason) {
        if (!$this->wants('static_file_missing')) { return; }
        $file = (string) $object->get('static_file');
        $this->add('static_file_missing', 'error', $kindName, $id, $name,
            'The static file ' . ($reason === 'missing' ? 'is missing' : 'cannot be read') . ($file !== '' ? ": {$file}." : '.'),
            array('static_file' => $file, 'reason' => $reason));
    }

    /** A static element that has no code field (a TV): its file is checked directly. */
    private function checkPlainStatic($kindName, $id, $name, $object) {
        if (!$this->wants('static_file_missing')) { return; }
        $path = $object->getSourceFile();
        if (!$path || strpos($path, '://') !== false || !is_file($path)) { $this->staticMissing($kindName, $id, $name, $object, 'missing'); return; }
        if (!is_readable($path)) { $this->staticMissing($kindName, $id, $name, $object, 'unreadable'); }
    }

    /** Resolve the collected [[~id]] links in batches and report those that do not lead to a live resource. */
    private function reportBrokenLinks() {
        $targets = array();
        foreach ($this->links as $link) { $targets[$link[2]] = true; }
        $state = array();
        foreach (array_chunk(array_keys($targets), 200) as $batch) {
            $query = $this->modx->newQuery('modResource', array('id:IN' => $batch));
            $query->select(array('id', 'deleted', 'published'));
            foreach ($this->modx->getCollection('modResource', $query) as $row) {
                $state[(int) $row->get('id')] = array('deleted' => (bool) $row->get('deleted'), 'published' => (bool) $row->get('published'));
            }
        }
        foreach ($this->links as $link) {
            list($kindName, $id, $target) = $link;
            $name = $this->linkNames[$kindName . ':' . $id];
            if (!isset($state[$target])) {
                $this->add('broken_link', 'error', $kindName, $id, $name, "Link [[~{$target}]] points to a resource that does not exist.", array('target' => $target, 'exists' => false));
            } elseif ($state[$target]['deleted'] || !$state[$target]['published']) {
                $why = $state[$target]['deleted'] ? 'deleted' : 'unpublished';
                $this->add('broken_link', 'warning', $kindName, $id, $name, "Link [[~{$target}]] points to a resource that is {$why}.",
                    array('target' => $target, 'exists' => true, 'deleted' => $state[$target]['deleted'], 'published' => $state[$target]['published']));
            }
        }
    }

    /** missing_element and unused_element come from the dependency graph's own summary. */
    private function graphChecks() {
        $graph = (new GraphBuilder($this->modx))->build(array('format' => 'summary'));
        $stats = $graph['stats'];
        $byType = $stats['by_type'];
        if ($this->wants('missing_element')) {
            $this->summary['missing_element']['scanned'] = (int) $stats['nodes'];
            foreach ($graph['missing'] as $row) {
                $from = explode(':', $row['from'], 2);
                $this->add('missing_element', 'error', $from[0], $row['from_id'], isset($from[1]) ? $from[1] : '',
                    "Calls the {$row['kind']} '{$row['name']}', which does not exist.", array('calls' => $row['kind'], 'missing' => $row['name']));
            }
            $this->notes[] = 'missing_element uses the dependency graph: explicit [[$chunk]] and [[snippet]] tags in the code of elements, and Fenom calls ({include \'chunk\'}, | chunk, runSnippet) in chunks and templates when pdoTools is installed. Resource content is not scanned for it; modx_check_page shows tags that fail on a rendered page.';
        }
        if ($this->wants('unused_element')) {
            $scanned = 0;
            foreach (array('chunk', 'snippet', 'tv', 'template') as $type) { $scanned += isset($byType[$type]) ? (int) $byType[$type] : 0; }
            $this->summary['unused_element']['scanned'] = $scanned;
            foreach ($graph['orphans'] as $row) {
                $this->add('unused_element', 'info', $row['type'], $row['id'], $row['name'], ucfirst($row['type']) . " '{$row['name']}': {$row['reason']}.", array('reason' => $row['reason']));
            }
            $this->notes[] = 'unused_element follows the dependency graph and lists candidates, not proof. A chunk, snippet or TV counts as used when element code, default properties, a property set, a system setting or a miniShop2 order status refers to it, or (TV) a template has it attached; chunks and TVs named in resource content count as used only when the graph verified orphans against resources'
                . ($stats['orphans_verified'] ? ' (it did).' : ' (it did NOT on this site, which has more than 5000 resources, so some may be used only inside pages).')
                . ' A template counts as used when a non-deleted resource uses it. Fenom calls in chunks and templates count as references when pdoTools is installed. Plugins are never reported; a name built at run time, or used by an add-on from its own tables, is invisible.';
        }
    }
}
