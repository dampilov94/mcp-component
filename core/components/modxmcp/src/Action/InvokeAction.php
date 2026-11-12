<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
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
            'args' => Schema::obj(array(), array(), true), 'dry_run' => Schema::bool()), array('kind', 'op'));
    }
    public function outputSchema() {
        return Schema::obj(array('kind' => Schema::str(), 'op' => Schema::str()), array('kind', 'op'), true);
    }
    public function run(array $args, Context $context) {
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
        $handler = $spec['handler'];
        if ($read) { return $meta + $kind->$handler($context, $object, $values); }
        // An operation with a plan answers with it unless it is confirmed (a plan-only token and dry_run never confirm).
        if (!empty($spec['plan']) && ($this->isDryRun($args, $context) || empty($values['confirm']))) {
            return $this->plan($context, $meta + $kind->{$spec['plan']}($context, $object, $values));
        }
        if ($this->isDryRun($args, $context)) { return $this->plan($context, $meta + array('validated' => true)); }
        // An irreversible operation that does not change an object the journal could restore (an order's status, which sends
        // e-mails): the object is only locked, and a changeset without changes records that it happened.
        if (!empty($spec['informational'])) {
            list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $kind, $meta, $handler, $values, $key) {
                $tx->lock($kind->name() . ':' . $key);
                $object = Objects::resolveKey($context, $kind, $key);
                $result = $kind->$handler($context, $tx, $object, $values);
                $summary = isset($result['summary']) ? (string) $result['summary'] : $meta['op'] . ' ' . $meta['kind'] . ' ' . $key;
                unset($result['summary']);
                return array('result' => $result, 'summary' => $summary, 'informational' => true);
            });
            return $meta + array('changeset_id' => $info['changeset_id'], 'reversible' => false) + $result;
        }
        list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $kind, $meta, $handler, $values, $key) {
            $object = null;
            if ($key !== null) {
                if ($tx->touch($kind, $key) === null) { throw new ApiException('not_found', 'The target object no longer exists.'); }
                $object = Objects::resolveKey($context, $kind, $key);
            }
            $result = $kind->$handler($context, $tx, $object, $values);
            return array('result' => $result, 'summary' => $meta['op'] . ' ' . $meta['kind'] . ($key !== null ? ' ' . $key : ''));
        });
        if ($key === null) { return $meta + array('changeset_id' => $info['changeset_id']) + $result; }
        return $this->written($kind, $key, $info, array('revision' => Snapshot::revision(Snapshot::capture($context->modx, $kind, $key))))
            + $meta + $result;
    }
}
