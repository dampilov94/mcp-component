<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Schema;
use ModxMCP\Verify\Runner;
use ModxMCP\Write\Writer;

/**
 * Runs code of the site to see what it produces. The code can do anything a snippet can do: what it changes is not tracked,
 * so the call is gated by a setting, needs the develop scope and leaves only an informational changeset.
 */
final class RunAction extends WriteAction {
    const WARNING = 'Changes made by the executed code are not journaled.';

    public function name() { return 'run'; }
    public function group() { return 'verify'; }
    public function scope() { return Scope::DEVELOP; }
    public function destructive() { return true; }
    public function reversible() { return false; }
    public function openWorld() { return true; }

    public function unavailableReason(Context $context) {
        return $context->capabilities->settingEnabled('allow_run') ? null : 'disabled_by_setting';
    }

    public function description() {
        return 'Run a snippet with properties, render a chunk with placeholders, or parse a string of MODX tags, in a chosen context and for a chosen resource; '
            . 'returns the output, how long it took, new error-log lines and tags that stayed unparsed (usually a missing element). '
            . 'Give exactly one of snippet, chunk, text. A snippet\'s echo and return value are joined in output, as MODX does. '
            . 'The code really runs: anything it changes on the site (saves, deletes, mail) is NOT journaled and modx_undo cannot revert it. '
            . 'Disabled unless the administrator turns it on. An error thrown by the code is a result (ok: false), not a tool error.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'snippet'    => Schema::str('Name of the snippet to run.', array('minLength' => 1, 'maxLength' => 255)),
            'chunk'      => Schema::str('Name of the chunk to render.', array('minLength' => 1, 'maxLength' => 255)),
            'text'       => Schema::str('Text with MODX tags to parse (up to 20000 characters).', array('maxLength' => 20000)),
            'properties' => Schema::obj(array(), array(), true) + array('description' => 'Properties of the snippet, or placeholders of the chunk.'),
            'context'    => Schema::str('Context key, default web.', array('minLength' => 1, 'maxLength' => 100)),
            'resource'   => Schema::int('Resource id the code runs for ($modx->resource); it must belong to the context.', array('minimum' => 1)),
            'max_output' => Schema::int('Bytes of output returned, default 20000.', array('minimum' => 1, 'maximum' => 200000)),
            'dry_run'    => Schema::bool('Say what would run without running it.'),
        ));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'performed'    => Schema::bool(),
            'ok'           => Schema::bool(),
            'mode'         => Schema::str(),
            'context'      => Schema::str(),
            'output'       => Schema::str(),
            'output_type'  => Schema::str(),
            'echoed'       => Schema::str(),
            'truncated'    => Schema::bool(),
            'bytes'        => Schema::int(),
            'duration_ms'  => Schema::int(),
            'new_log_errors' => Schema::int(),
            'log_lines'    => Schema::arr(Schema::obj(array(), array(), true)),
            'unparsed_tags' => Schema::arr(Schema::obj(array(), array(), true)),
            'unparsed_count' => Schema::int(),
            'changeset_id' => array('type' => array('integer', 'null')),
            'reversible'   => Schema::bool(),
        ), array('performed'), true);
    }

    public function run(array $args, Context $context) {
        $modes = array_values(array_intersect(array('snippet', 'chunk', 'text'), array_keys($args)));
        if (count($modes) !== 1) { throw new ApiException('validation_failed', 'Give exactly one of snippet, chunk or text.'); }
        $mode = $modes[0];
        if ($mode === 'text' && isset($args['properties'])) { throw new ApiException('validation_failed', 'properties go with snippet or chunk, not with text.'); }
        $modx = $context->modx;
        $contextKey = isset($args['context']) ? (string) $args['context'] : 'web';
        if (!$modx->getObject('modContext', array('key' => $contextKey))) { throw new ApiException('validation_failed', "Unknown context '{$contextKey}'."); }
        $resource = null;
        if (isset($args['resource'])) {
            $resource = $modx->getObject('modResource', (int) $args['resource']);
            if (!$resource) { throw new ApiException('validation_failed', "Resource {$args['resource']} does not exist."); }
            if ((string) $resource->get('context_key') !== $contextKey) {
                throw new ApiException('validation_failed', "Resource {$args['resource']} belongs to context '{$resource->get('context_key')}', not '{$contextKey}'.");
            }
        }
        $name = $mode === 'text' ? '' : (string) $args[$mode];
        if ($mode === 'snippet' && !$modx->getObject('modSnippet', array('name' => $name))) { throw new ApiException('not_found', "No snippet named '{$name}'."); }
        if ($mode === 'chunk' && !$modx->getObject('modChunk', array('name' => $name))) { throw new ApiException('not_found', "No chunk named '{$name}'."); }

        $label = $mode === 'text' ? 'run text' : "run {$mode} {$name}";
        if ($this->isDryRun($args, $context)) {
            return $this->plan($context, array(
                'performed' => false, 'reversible' => false, 'mode' => $mode, 'context' => $contextKey,
                'would_run' => $label . ' in context ' . $contextKey . ($resource ? ' for resource ' . (int) $resource->get('id') : ''),
                'warning' => self::WARNING,
            ) + ($mode === 'text' ? array() : array('name' => $name)) + ($resource ? array('resource' => (int) $resource->get('id')) : array()));
        }

        $spec = array(
            'mode' => $mode, 'name' => $name, 'text' => $mode === 'text' ? (string) $args['text'] : '',
            'properties' => isset($args['properties']) && is_array($args['properties']) ? $args['properties'] : array(),
            'context' => $contextKey, 'resource' => $resource,
            'max_output' => isset($args['max_output']) ? (int) $args['max_output'] : 20000,
        );
        $outcome = array();
        list(, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $spec, $label, &$outcome) {
            $tx->lock('run');
            $outcome = Runner::execute($context, $spec);
            return array('result' => array(), 'summary' => $label . ($outcome['ok'] ? '' : ' (failed)'), 'informational' => true);
        });
        $context->warnings[] = self::WARNING;
        return array('performed' => true) + $outcome + array('changeset_id' => $info['changeset_id'], 'reversible' => false);
    }
}
