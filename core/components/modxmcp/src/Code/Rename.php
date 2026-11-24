<?php
namespace ModxMCP\Code;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Kind\Kind;
use ModxMCP\Kind\Objects;
use ModxMCP\Kind\Properties;
use ModxMCP\Write\Transaction;

/**
 * Rename a chunk, a snippet or a TV together with the references to it: the plan (every reference that will be rewritten,
 * per object, and the ones that cannot be rewritten safely) and its application in one changeset.
 *
 * What is looked at is what modx_find_usages looks at (the code of chunks, snippets, templates, plugins and the content of
 * resources) plus the structured places the graph reads: the default properties of elements and of property sets, the
 * options of TVs, MIGX configurations. Rewriting itself is ReferenceRewriter's and JsonReferences'.
 */
final class Rename {
    /** Objects one rename may rewrite. */
    const OBJECT_LIMIT = 500;
    /** Changes shown per object in a plan. */
    const SHOWN = 20;
    const MANUAL_LIMIT = 200;

    /** The classes whose default properties are read: the elements and the property sets. */
    const PROPERTY_CLASSES = array('modChunk', 'modSnippet', 'modTemplate', 'modPlugin', 'modTemplateVar', 'modPropertySet');

    /** @return string the new name, checked: valid for the kind, different, and free */
    public static function checkName(Kind $kind, $object, $newName) {
        $problem = ReferenceRewriter::nameProblem($kind->name(), $newName);
        if ($problem !== null) { throw new ApiException('validation_failed', $problem); }
        $oldName = (string) $kind->value($object, $kind->nameField());
        if ($newName === $oldName) { throw new ApiException('validation_failed', 'The new name is the same as the current one.'); }
        $same = $kind->find($object->xpdo, array('name' => $newName));
        if ($same && (int) $same->get('id') !== (int) $object->get('id')) {
            throw new ApiException('validation_failed', "A {$kind->name()} named '{$newName}' already exists (id {$same->get('id')}).");
        }
        return $newName;
    }

    /** The plan: what would be renamed and rewritten, and what is left to a person. */
    public static function plan(Context $context, Kind $kind, $object, $newName) {
        $newName = self::checkName($kind, $object, $newName);
        $old = (string) $kind->value($object, $kind->nameField());
        $found = self::collect($context, $kind, $object, $old, $newName);
        $references = array();
        $revisions = array();
        $total = 0;
        foreach ($found['targets'] as $key => $target) {
            $count = count($target['changes']);
            $total += $count;
            $references[] = array('kind' => $target['kind']->name(), 'id' => $target['id'], 'name' => $target['name'], 'static' => $target['static'],
                'fields' => array_keys($target['fields']), 'change_count' => $count, 'changes' => array_slice($target['changes'], 0, self::SHOWN),
                'revision' => $target['revision']) + Objects::openPlan($context, $target['kind'], $target['id']);
            $revisions[$key] = $target['revision'];
        }
        $selfKey = $kind->name() . ':' . $kind->keyOf($object);
        if (!isset($revisions[$selfKey])) { $revisions[$selfKey] = Snapshot::revision(Snapshot::capture($context->modx, $kind, $kind->keyOf($object))); }
        $notes = $found['notes'];
        if (isset($kind->fields()['static']) && $object->get('static')) {
            $notes[] = 'The element is static: only its name changes, the static file keeps its name and path.';
        }
        $plan = array(
            'rename' => array('kind' => $kind->name(), 'id' => (int) $object->get('id'), 'from' => $old, 'to' => $newName),
            'references' => $references,
            'total_changes' => $total,
            'manual' => array_slice($found['manual'], 0, self::MANUAL_LIMIT),
            'manual_count' => count($found['manual']),
            'revisions' => $revisions,
            'next' => 'Confirm with args {new_name, confirm: true, expected_revisions: <revisions>} to rename and rewrite all of the references as one changeset; modx_undo reverts all of it.',
        );
        if ($notes) { $plan['notes'] = $notes; }
        return $plan;
    }

    /**
     * Rename and rewrite inside the open transaction of modx_invoke. The plan is made again; it must list only objects of the
     * reviewed plan, and none of them may have changed since.
     */
    public static function apply(Context $context, Transaction $tx, Kind $kind, $object, array $args) {
        if (!isset($args['expected_revisions']) || (!is_array($args['expected_revisions']) && !is_object($args['expected_revisions']))) {
            throw new ApiException('validation_failed', 'Run the operation without confirm first, then pass the revisions of its plan as expected_revisions.');
        }
        $expected = (array) $args['expected_revisions'];
        $newName = self::checkName($kind, $object, isset($args['new_name']) ? $args['new_name'] : '');
        $old = (string) $kind->value($object, $kind->nameField());
        $id = $kind->keyOf($object);
        $found = self::collect($context, $kind, $object, $old, $newName);
        $selfKey = $kind->name() . ':' . $id;
        $wanted = array_values(array_unique(array_merge(array_keys($found['targets']), array($selfKey))));
        $unexpected = array_values(array_diff($wanted, array_keys($expected)));
        if ($unexpected) {
            throw new ApiException('revision_conflict', 'References appeared since the plan. Run the operation without confirm again.', array('changed' => $unexpected));
        }
        $changed = array();
        foreach ($expected as $key => $revision) {
            if (!preg_match('/^([a-z_0-9]+):([^:\s]+)$/D', (string) $key, $m) || ($holder = $context->kinds->get($m[1])) === null) {
                throw new ApiException('validation_failed', 'Invalid expected_revisions entry: ' . $key . '. Use the map of the plan.');
            }
            $state = $tx->touch($holder, $holder->normalizeKey($m[2]));
            if ($state === null || !is_string($revision) || Snapshot::revision($state) !== $revision) { $changed[] = (string) $key; }
        }
        if ($changed) { throw new ApiException('revision_conflict', 'Reviewed objects changed. Run the operation without confirm again.', array('changed' => $changed)); }
        // One changeset: an object that is open in the manager stops all of it.
        foreach ($found['targets'] as $target) { Objects::assertNotOpen($context, $target['kind'], $target['id'], $args); }

        $written = 0;
        $references = 0;
        foreach ($found['targets'] as $key => $target) {
            if ($key === $selfKey) { continue; }
            Objects::save($context, $tx, $target['kind'], $target['id'], Objects::normalize($target['kind'], $target['fields'], false), array());
            ++$written;
            $references += count($target['changes']);
        }
        // The element itself: its name and the references inside its own code, in one save.
        $fields = array($kind->nameField() => $newName);
        if (isset($found['targets'][$selfKey])) {
            $fields = $found['targets'][$selfKey]['fields'] + $fields;
            ++$written;
            $references += count($found['targets'][$selfKey]['changes']);
        }
        Objects::save($context, $tx, $kind, $id, Objects::normalize($kind, $fields, false), array());
        return array('renamed' => array('kind' => $kind->name(), 'id' => (int) $id, 'from' => $old, 'to' => $newName),
            'objects_rewritten' => $written, 'references_rewritten' => $references, 'manual_count' => count($found['manual']),
            'manual' => array_slice($found['manual'], 0, self::MANUAL_LIMIT),
            'next' => $found['manual'] ? 'The manual list holds references that were not rewritten; check them by hand.' : 'Nothing is left to check by hand.',
            'summary' => "rename {$kind->name()} {$id} from '{$old}' to '{$newName}', {$references} references in {$written} objects");
    }

    // ---- what refers to the element ----

    /** A kind this token can write, or null (the group is off or the add-on is absent). */
    private static function writable(Context $context, $name) {
        try { return Objects::kind($context, $name, true); } catch (ApiException $e) { return null; }
    }

    private static function kindOfClass(Context $context, $class) {
        foreach ($context->kinds->all() as $kind) { if ($kind->modelClass() === $class) { return $kind; } }
        return null;
    }

    private static function mentions($text, $name) {
        if (!is_string($text) || $text === '') { return false; }
        return stripos($text, $name) !== false || (preg_match('//u', $text) === 1 && function_exists('mb_stripos') && mb_stripos($text, $name, 0, 'UTF-8') !== false);
    }

    /** @return array targets ("kind:id" => [kind, id, name, static, fields (API name => new value), changes, revision]), manual, notes */
    private static function collect(Context $context, Kind $kind, $object, $old, $newName) {
        $modx = $context->modx;
        $what = $kind->name();
        $selfId = (int) $object->get('id');
        $targets = array();
        $manual = array();
        $notes = array();
        $fenom = Fenom::active($modx);
        $pattern = $fenom ? Fenom::syntaxOf($modx) : Fenom::SYNTAX;

        $addManual = function (Kind $holder, $id, $name, $field, array $items) use (&$manual) {
            foreach ($items as $item) {
                $manual[] = array('kind' => $holder->name(), 'id' => (int) $id, 'name' => (string) $name, 'field' => $field, 'line' => $item['line'],
                    'text' => $item['text'], 'reason' => $item['reason']);
            }
        };
        $record = function (Kind $holder, $holderObject, $field, $value, array $changes) use (&$targets, $context) {
            $id = $holder->keyOf($holderObject);
            $key = $holder->name() . ':' . $id;
            if (!isset($targets[$key])) {
                $targets[$key] = array('kind' => $holder, 'id' => $id, 'name' => (string) $holder->value($holderObject, $holder->nameField()),
                    'static' => isset($holder->fields()['static']) && (bool) $holderObject->get('static'), 'fields' => array(), 'changes' => array(),
                    'revision' => Snapshot::revision(Snapshot::capture($context->modx, $holder, $id)));
            }
            $targets[$key]['fields'][$field] = $value;
            foreach ($changes as $change) { $targets[$key]['changes'][] = array('field' => $field) + $change; }
        };

        // ---- code: chunks, snippets, templates, plugins, resources ----
        $documents = array();
        foreach (array('chunk', 'snippet', 'template', 'plugin', 'resource') as $name) {
            $holder = self::writable($context, $name);
            if (!$holder) { $notes[] = "Kind {$name} is not available to this token; its code was not looked at."; continue; }
            foreach (CodeSearch::objects($context, $holder, $old) as $entry) {
                list($holderObject, $content) = $entry;
                $field = $holder->codeField();
                $blocked = $content[3] || $content[4];
                $text = $blocked ? (string) $holderObject->get($holder->column($field)) : $content[0];
                if (!self::mentions($text, $old)) { continue; }
                $documents[] = array('kind' => $holder, 'object' => $holderObject, 'field' => $field, 'text' => $text, 'blocked' => $blocked,
                    'why' => $content[4] ? 'The static file is outside modxmcp.static_roots (or protected); it was not read, so the copy in the database was checked. Change the file by hand.'
                        : 'The static file is missing; the copy in the database was checked.');
            }
        }
        $placeholderHolders = $what === 'tv' ? self::linkedToTv($context, $documents, $selfId, $old) : array();
        foreach ($documents as $doc) {
            $holder = $doc['kind'];
            $holderName = $holder->name();
            $looksFenom = $fenom && in_array($holderName, array('chunk', 'template'), true) && Fenom::looksLike($doc['text'], $pattern, true);
            $options = array('fenom' => $looksFenom, 'php' => in_array($holderName, array('snippet', 'plugin'), true),
                'placeholders' => isset($placeholderHolders[$holderName . ':' . (int) $doc['object']->get('id')]));
            $result = ReferenceRewriter::rewrite($what, $old, $newName, $doc['text'], $options);
            $label = (string) $holder->value($doc['object'], $holder->nameField());
            if ($doc['blocked']) {
                $items = array();
                foreach ($result['changes'] as $change) { $items[] = array('line' => $change['line'], 'text' => $change['old'], 'reason' => $doc['why']); }
                $addManual($holder, $doc['object']->get('id'), $label, $doc['field'], array_merge($items, $result['manual']));
                continue;
            }
            $addManual($holder, $doc['object']->get('id'), $label, $doc['field'], $result['manual']);
            if ($result['changes']) { $record($holder, $doc['object'], $doc['field'], $result['text'], $result['changes']); }
        }
        unset($documents);

        // ---- default properties of elements and property sets: template parameters ----
        if ($what === 'chunk') {
            foreach (self::PROPERTY_CLASSES as $class) {
                $holder = self::kindOfClass($context, $class);
                $holder = $holder ? self::writable($context, $holder->name()) : null;
                if (!$holder) { continue; }
                foreach ($modx->getCollection($class) as $holderObject) {
                    $stored = Properties::stored($holderObject);
                    $changes = array();
                    $other = array();
                    foreach ($stored as $propertyName => $entry) {
                        if (!isset($entry['value']) || !is_scalar($entry['value']) || trim((string) $entry['value']) !== $old) { continue; }
                        if (preg_match(ReferenceRewriter::TEMPLATE_KEY, (string) $propertyName)) {
                            $stored[$propertyName]['value'] = $newName;
                            $changes[] = array('where' => 'property ' . $propertyName, 'old' => (string) $entry['value'], 'new' => $newName, 'via' => 'property');
                        } else {
                            $other[] = array('line' => 0, 'text' => $propertyName . ' = ' . $entry['value'],
                                'reason' => "A default property that is not a template parameter has the name as its value ({$propertyName}); check it by hand.");
                        }
                    }
                    $label = (string) $holder->value($holderObject, $holder->nameField());
                    $addManual($holder, $holderObject->get('id'), $label, 'properties', $other);
                    if ($changes) { $record($holder, $holderObject, 'properties', Properties::present($stored), $changes); }
                }
            }
        }

        // ---- TVs: input options and bindings; MIGX configurations ----
        $tvKind = self::writable($context, 'tv');
        if ($tvKind) {
            foreach ($modx->getCollection('modTemplateVar') as $tv) {
                $label = (string) $tv->get('name');
                if ($what === 'chunk') {
                    foreach (array('elements', 'default_text') as $field) {
                        $value = (string) $tv->get($field);
                        if (!self::mentions($value, $old)) { continue; }
                        $result = ReferenceRewriter::rewrite('chunk', $old, $newName, $value, array('tvfield' => true));
                        $addManual($tvKind, $tv->get('id'), $label, $field, $result['manual']);
                        if ($result['changes']) { $record($tvKind, $tv, $field, $result['text'], $result['changes']); }
                    }
                }
                $options = $tvKind->value($tv, 'input_properties');
                if (is_object($options) && get_object_vars($options)) {
                    list($rewritten, $changes) = JsonReferences::rewrite($options, $what === 'tv' ? 'tv' : 'chunk', $old, $newName);
                    if ($changes) { $record($tvKind, $tv, 'input_properties', $rewritten, $changes); }
                }
            }
        }
        $migx = self::writable($context, 'migx_config');
        if ($migx && $what !== 'snippet') {
            foreach ($modx->getCollection('migxConfig', array('deleted' => 0)) as $config) {
                foreach (array('formtabs', 'columns') as $field) {
                    $value = $migx->value($config, $field);
                    if (!is_object($value) && !is_array($value)) { continue; }
                    list($rewritten, $changes) = JsonReferences::rewrite($value, $what === 'tv' ? 'tv' : 'chunk', $old, $newName);
                    if ($changes) { $record($migx, $config, $field, $rewritten, $changes); }
                }
            }
        }

        // ---- places that are only reported ----
        if ($what === 'chunk') {
            foreach ($modx->getCollection('modSystemSetting') as $setting) {
                if (preg_match('/tpl|chunk|template/i', (string) $setting->get('key')) && trim((string) $setting->get('value')) === $old) {
                    $manual[] = array('kind' => 'system_setting', 'id' => (string) $setting->get('key'), 'name' => (string) $setting->get('key'), 'field' => 'value',
                        'line' => 0, 'text' => (string) $setting->get('value'), 'reason' => 'A system setting holds the name of the chunk; change the setting by hand.');
                }
            }
        }
        $values = $modx->newQuery('modTemplateVarResource');
        $values->where(array('value:LIKE' => '%' . strtr($old, array('\\' => '\\\\', '%' => '\\%', '_' => '\\_')) . '%'));
        $values->limit(200);
        foreach ($modx->getCollection('modTemplateVarResource', $values) as $row) {
            $result = ReferenceRewriter::rewrite($what, $old, $newName, (string) $row->get('value'), array('placeholders' => false));
            $items = $result['manual'];
            foreach ($result['changes'] as $change) {
                $items[] = array('line' => $change['line'], 'text' => $change['old'], 'reason' => 'A TV value of the page holds this reference; edit the value of the TV on the page by hand.');
            }
            if (!$items) { continue; }
            $tv = $modx->getObject('modTemplateVar', array('id' => (int) $row->get('tmplvarid')), false);
            $page = $modx->getObject('modResource', array('id' => (int) $row->get('contentid')), false);
            foreach ($items as $item) {
                $manual[] = array('kind' => 'resource', 'id' => (int) $row->get('contentid'), 'name' => $page ? (string) $page->get('pagetitle') : '',
                    'field' => 'TV ' . ($tv ? $tv->get('name') : (int) $row->get('tmplvarid')), 'line' => $item['line'], 'text' => $item['text'], 'reason' => $item['reason']);
            }
        }

        if (count($targets) > self::OBJECT_LIMIT) {
            throw new ApiException('validation_failed', 'The name is referenced from ' . count($targets) . ' objects; at most ' . self::OBJECT_LIMIT . ' are rewritten in one call. Rename it in the manager or use modx_replace in steps.');
        }
        ksort($targets);
        return array('targets' => $targets, 'manual' => $manual, 'notes' => $notes);
    }

    /**
     * The templates and chunks "the graph links to the TV": the templates it is attached to, the templates and chunks that name it in a
     * [[*name]] tag, and the chunks that a tag with &includeTVs / &processTVs naming it takes as its template parameters.
     * Only there is a [[+name]] placeholder taken for the TV.
     * @return array "kind:id" => true
     */
    private static function linkedToTv(Context $context, array $documents, $tvId, $old) {
        $modx = $context->modx;
        $linked = array();
        foreach ($modx->getCollection('modTemplateVarTemplate', array('tmplvarid' => (int) $tvId)) as $link) { $linked['template:' . (int) $link->get('templateid')] = true; }
        $chunkNames = array();
        foreach ($documents as $doc) {
            $holderName = $doc['kind']->name();
            if (in_array($holderName, array('chunk', 'template'), true)) {
                $probe = ReferenceRewriter::rewrite('tv', $old, $old . 'x', $doc['text'], array('placeholders' => false));
                foreach ($probe['changes'] as $change) {
                    if ($change['via'] === 'tag') { $linked[$holderName . ':' . (int) $doc['object']->get('id')] = true; break; }
                }
            }
            $list = '/\[\[[^\[\]]*&(?:includeTVs|processTVs)\s*=\s*`[^`]*(?<![^,\s`])' . preg_quote($old, '/') . '(?![^,\s`])[^`]*`[^\[\]]*\]\]/u';
            if (preg_match_all($list, $doc['text'], $tags)) {
                foreach ($tags[0] as $tag) {
                    if (preg_match_all('/&[A-Za-z0-9_\-.]*(?i:tpl|chunk|wrapper)[A-Za-z0-9_\-.]*\s*=\s*`\s*([^`@\s]+)\s*`/u', $tag, $names)) {
                        foreach ($names[1] as $chunkName) { $chunkNames[$chunkName] = true; }
                    }
                }
            }
        }
        if ($chunkNames) {
            foreach ($modx->getCollection('modChunk', array('name:IN' => array_keys($chunkNames))) as $chunk) { $linked['chunk:' . (int) $chunk->get('id')] = true; }
        }
        return $linked;
    }
}
