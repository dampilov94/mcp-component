<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Code\CodeSearch;
use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class ReplaceAction extends WriteAction {
    public function name() { return 'replace'; }
    public function group() { return 'code'; }
    public function destructive() { return true; }
    public function description() {
        return 'Literal replace across element code. First call with dry_run to review objects and get revisions; '
            . 'pass them as expected_revisions to apply one undoable changeset.';
    }
    public function inputSchema() {
        return Schema::obj(array(
            'find' => Schema::str('Literal text.', array('minLength' => 2, 'maxLength' => 500)),
            'replacement' => Schema::str(),
            'kinds' => Schema::arr(Schema::str(), 'Default: chunk, snippet, template, plugin.'),
            'case_sensitive' => Schema::bool('Default true.'),
            'limit' => Schema::int('Preview objects, default 50.', array('minimum' => 1, 'maximum' => 100)),
            'dry_run' => Schema::bool(),
            'expected_revisions' => Schema::obj(array(), array(), true) + array('description' => 'Reviewed kind:id to revision map from dry_run.'),
        ), array('find', 'replacement'));
    }
    public function outputSchema() {
        return Schema::obj(array('objects' => Schema::arr(Schema::obj(array(), array(), true)),
            'total_occurrences' => Schema::int(), 'changeset_id' => array('type' => array('integer', 'null')),
            'reversible' => Schema::bool(), 'dry_run' => Schema::bool(), 'truncated' => Schema::bool(),
            'revisions' => Schema::obj(array(), array(), true)), array('objects', 'total_occurrences'), true);
    }
    private function replace($code, array $args, &$count) {
        if (!isset($args['case_sensitive']) || $args['case_sensitive']) {
            return str_replace($args['find'], $args['replacement'], $code, $count);
        }
        // str_ireplace folds ASCII only; a Unicode-aware pattern also matches Cyrillic in any case.
        if (preg_match('//u', $code) !== 1 || preg_match('//u', $args['find']) !== 1) {
            return str_ireplace($args['find'], $args['replacement'], $code, $count);
        }
        $replacement = $args['replacement'];
        $new = preg_replace_callback('/' . preg_quote($args['find'], '/') . '/iu', function () use ($replacement) { return $replacement; }, $code, -1, $count);
        if ($new === null) { throw new \RuntimeException('Replacement failed on this text.'); }
        return $new;
    }
    public function run(array $args, Context $context) {
        $kinds = CodeSearch::kinds($context, isset($args['kinds']) ? $args['kinds'] : null, true);
        if ($this->isDryRun($args, $context)) { return $this->preview($args, $context, $kinds); }
        if (!isset($args['expected_revisions'])) { throw new ApiException('validation_failed', 'Run dry_run first, then pass its revisions as expected_revisions.'); }
        $expected = (array) $args['expected_revisions'];
        if (count($expected) > 100) { throw new ApiException('validation_failed', 'At most 100 expected_revisions are allowed.'); }
        $selection = array();
        foreach ($expected as $key => $revision) {
            if (!preg_match('/^([a-z_]+):([1-9][0-9]*)$/D', $key, $m) || !isset($kinds[$m[1]])
                || (string) (int) $m[2] !== $m[2] || !is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)) {
                throw new ApiException('validation_failed', 'Invalid expected_revisions entry: ' . $key . '. Use the dry_run map and allowed kinds.');
            }
            $selection[$key] = array($kinds[$m[1]], (int) $m[2]);
        }
        list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $args, $expected, $selection) {
            $states = array();
            $changed = array();
            foreach ($selection as $key => $item) {
                $state = $tx->touch($item[0], $item[1]);
                if ($state === null) { throw new ApiException('not_found', 'Object no longer exists: ' . $key); }
                $states[$key] = $state;
                if (Snapshot::revision($state) !== $expected[$key]) { $changed[] = $key; }
            }
            if ($changed) { throw new ApiException('revision_conflict', 'Reviewed objects changed. Run dry_run again.', array('changed' => $changed)); }
            $plans = array();
            foreach ($selection as $key => $item) {
                list($kind, $id) = $item;
                if (!empty($states[$key]['static_file_missing'])) { throw new ApiException('validation_failed', 'Static file is missing: ' . $key); }
                $column = $kind->column($kind->codeField());
                $count = 0;
                $new = $this->replace($states[$key]['fields'][$column], $args, $count);
                if ($count) {
                    try { Objects::lint($context, $kind, array($column => $new)); }
                    catch (ApiException $e) {
                        if ($e->errorCode === 'lint_failed') { $e->details['object'] = $key; }
                        throw $e;
                    }
                }
                $plans[$key] = array($column, $new, $count);
            }
            $objects = array();
            $total = 0;
            foreach ($selection as $key => $item) {
                list($kind, $id) = $item;
                list($column, $new, $count) = $plans[$key];
                if ($count) { Objects::save($context, $tx, $kind, $id, array($column => $new), array()); }
                $objects[] = array('kind' => $kind->name(), 'id' => $id,
                    'name' => (string) $states[$key]['fields'][$kind->column($kind->nameField())], 'occurrences' => $count,
                    'revision' => Snapshot::revision(Snapshot::capture($context->modx, $kind, $id)));
                $total += $count;
            }
            return array('result' => array('objects' => $objects, 'total_occurrences' => $total), 'summary' => 'replace in ' . count($objects) . ' objects');
        });
        $reversible = true;
        foreach ($info['changes'] as $change) { $reversible = $reversible && $change['reversible']; }
        return $result + array('changeset_id' => $info['changeset_id'], 'reversible' => $reversible);
    }
    private function preview(array $args, Context $context, array $kinds) {
        $limit = isset($args['limit']) ? $args['limit'] : 50;
        $sensitive = !isset($args['case_sensitive']) || $args['case_sensitive'];
        $objects = array();
        $truncated = false;
        foreach ($kinds as $kind) {
            $hits = array();
            foreach (CodeSearch::objects($context, $kind, $args['find']) as $entry) {
                list($object, $content) = $entry;
                if ($content[3]) { continue; }
                $count = 0;
                $this->replace($content[0], $args, $count);
                if (!$count) { continue; }
                if (count($objects) + count($hits) === $limit) { $truncated = true; break; }
                $id = (int) $object->get('id');
                $matches = array();
                foreach (preg_split('/\r\n|\r|\n/', $content[0]) as $i => $line) {
                    $pos = CodeSearch::position($line, $args['find'], $sensitive);
                    if ($pos !== false) { $matches[] = array('line' => $i + 1, 'text' => CodeSearch::excerpt($line, $pos)); }
                    if (count($matches) === 3) { break; }
                }
                $hits[] = array('kind' => $kind->name(), 'id' => $id, 'name' => (string) $kind->value($object, $kind->nameField()),
                    'static' => $content[1], 'occurrences' => $count, 'revision' => Snapshot::revision(Snapshot::capture($context->modx, $kind, $id)), 'matches' => $matches);
            }
            usort($hits, function ($a, $b) { return strcmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']; });
            $objects = array_merge($objects, $hits);
            if ($truncated) { break; }
        }
        $revisions = array();
        $total = 0;
        foreach ($objects as $object) { $revisions[$object['kind'] . ':' . $object['id']] = $object['revision']; $total += $object['occurrences']; }
        return $this->plan($context, array('objects' => $objects, 'total_occurrences' => $total, 'truncated' => $truncated,
            'revisions' => $revisions ? $revisions : new \stdClass()));
    }
}
