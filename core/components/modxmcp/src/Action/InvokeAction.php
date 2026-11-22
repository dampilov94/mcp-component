<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Draft\DraftPolicy;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class InvokeAction extends WriteAction {
    public function name() { return 'invoke'; }
    public function scope() { return Scope::READ; }
    public function destructive() { return true; }
    public function description() {
        return 'Run a special operation of a kind; modx_describe {kind} lists its operations with their arguments.';
    }
    public function inputSchema() {
        return Schema::obj(array('kind' => Schema::str(),
            'op' => Schema::str(null, array('pattern' => '^[a-z][a-z0-9_]{0,63}$')),
            'id' => Schema::int(null, array('minimum' => 1)), 'name' => Schema::str(null, array('maxLength' => 255)),
            'key' => self::keyArgument(),
            'args' => Schema::obj(array(), array(), true), 'dry_run' => Schema::bool(),
            'force' => Schema::bool('Write even though the object is open in the manager.')), array('kind', 'op'));
    }
    public function outputSchema() {
        return Schema::obj(array('kind' => Schema::str(), 'op' => Schema::str()), array('kind', 'op'), true);
    }
    public function run(array $args, Context $context) {
        if (DraftPolicy::applies($context) && ($args['kind'] !== DraftPolicy::KIND || !in_array($args['op'], DraftPolicy::OPERATIONS, true))) {
            throw new ApiException('forbidden_scope', 'A draft-only token can only create or list page drafts.');
        }
        $kind = Objects::kind($context, $args['kind']);
        $ops = $kind->operations();
        if (!isset($ops[$args['op']])) { throw new ApiException('not_found', 'Unknown operation. Available: ' . implode(', ', array_keys($ops)) . '; see modx_describe.'); }
        $spec = $ops[$args['op']];
        $read = !empty($spec['read_only']);
        if (!$read) { Objects::kind($context, $args['kind'], true); }
        $needsObject = !isset($spec['needs_object']) || $spec['needs_object'];
        if (!$needsObject && (isset($args['id']) || isset($args['name']) || isset($args['key']))) { throw new ApiException('validation_failed', 'This operation takes no id or name.'); }
        $object = $needsObject ? Objects::resolve($context, $kind, $args) : null;
        $values = isset($args['args']) ? (array) $args['args'] : array();
        $invalid = Schema::validate($spec['input'], json_decode(json_encode($values ? $values : new \stdClass())), 'args');
        if ($invalid !== null) { throw new ApiException('validation_failed', $invalid); }
        $meta = array('kind' => $kind->name(), 'op' => $args['op']);
        $keyField = $kind->keyField();
        $key = $object ? $kind->keyOf($object) : null;
        if ($object) { $meta[$keyField] = $key; }
        // The call's own force reaches the handler: an operation may have conflicts of its own that force overrides.
        if (!$read && !empty($args['force'])) { $values['force'] = true; }
        $handler = $spec['handler'];
        if ($read) { return $meta + $kind->$handler($context, $object, $values); }
        // An operation with a plan answers with it unless it is confirmed (a plan-only token and dry_run never confirm).
        if (!empty($spec['plan']) && ($this->isDryRun($args, $context) || empty($values['confirm']))) {
            return $this->plan($context, $meta + $kind->{$spec['plan']}($context, $object, $values) + ($key !== null ? Objects::openPlan($context, $kind, $key) : array()));
        }
        if ($this->isDryRun($args, $context)) { return $this->plan($context, $meta + array('validated' => true) + ($key !== null ? Objects::openPlan($context, $kind, $key) : array())); }
        // An irreversible operation that does not change an object the journal could restore (an order's status, which sends
        // e-mails): the object is only locked, and a changeset without changes records that it happened.
        if (!empty($spec['informational'])) {
            list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $kind, $meta, $handler, $values, $key, $args) {
                $tx->lock($kind->name() . ':' . $key);
                Objects::assertNotOpen($context, $kind, $key, $args);
                $object = Objects::resolveKey($context, $kind, $key);
                $result = $kind->$handler($context, $tx, $object, $values);
                $summary = isset($result['summary']) ? (string) $result['summary'] : $meta['op'] . ' ' . $meta['kind'] . ' ' . $key;
                unset($result['summary']);
                return array('result' => $result, 'summary' => $summary, 'informational' => true);
            });
            return $meta + array('changeset_id' => $info['changeset_id'], 'reversible' => false) + $result;
        }
        list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $kind, $meta, $handler, $values, $key, $args) {
            $object = null;
            if ($key !== null) {
                if ($tx->touch($kind, $key) === null) { throw new ApiException('not_found', 'The target object no longer exists.'); }
                // An operation that does not change the object it is called on (a draft is made of a page) leaves a person's open page alone.
                if (!isset($spec['check_open']) || $spec['check_open']) { Objects::assertNotOpen($context, $kind, $key, $args); }
                $object = Objects::resolveKey($context, $kind, $key);
            }
            $result = $kind->$handler($context, $tx, $object, $values);
            // A handler may name its own changeset summary.
            $summary = isset($result['summary']) ? (string) $result['summary'] : $meta['op'] . ' ' . $meta['kind'] . ($key !== null ? ' ' . $key : '');
            unset($result['summary']);
            return array('result' => $result, 'summary' => $summary);
        });
        $kind->afterInvoke($context, $meta['op'], $result, $info);
        if ($key === null) { return $meta + array('changeset_id' => $info['changeset_id']) + $result; }
        return $this->written($kind, $key, $info, array('revision' => Snapshot::revision(Snapshot::capture($context->modx, $kind, $key))))
            + $meta + $result;
    }
}
