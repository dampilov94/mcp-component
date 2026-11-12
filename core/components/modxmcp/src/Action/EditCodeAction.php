<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Code\LineEdit;
use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class EditCodeAction extends WriteAction {
    public function name() { return 'edit_code'; }

    public function group() { return 'code'; }

    public function description() {
        return 'Edit code of a chunk, snippet, template or plugin, or the content of a resource, by line ranges, so only changed lines travel. '
            . 'Line numbers are 1-based, inclusive, and are those of the code before this call (see modx_view_code); '
            . 'pass its revision as expected_revision. To insert, set end_line = start_line - 1; to append at the end, '
            . 'start_line = total_lines + 1. `expect` (current text of the lines being replaced) guards against stale '
            . 'line numbers. PHP must still parse afterwards.';
    }

    public function inputSchema() {
        $edit = Schema::obj(array(
            'start_line'  => Schema::int(null, array('minimum' => 1)),
            'end_line'    => Schema::int('Last line to replace; default start_line. Use start_line - 1 to insert before start_line without replacing anything.', array('minimum' => 0)),
            'replacement' => Schema::str('New text for the range; empty deletes the lines.'),
            'expect'      => Schema::str('Current text of the lines being replaced; not used for an insert.'),
        ), array('start_line'));
        return Schema::obj(array(
            'kind'      => Schema::str(null, array('pattern' => '^[a-z][a-z0-9_]{0,63}$')),
            'id'        => Schema::int(null, array('minimum' => 1)),
            'name'      => Schema::str('Name, when the id is not known.', array('maxLength' => 255)),
            'key'       => self::keyArgument(),
            'edits'     => Schema::arr($edit, 'Edits, all relative to the code before this call.', array('maxItems' => 100)),
            'skip_lint' => Schema::bool('Save PHP code even if it does not parse.'),
        ) + $this->commonInput(), array('kind', 'edits'));
    }

    public function run(array $args, Context $context) {
        $kind = Objects::kind($context, $args['kind'], true);
        $field = $kind->codeField();
        if ($field === null) { throw new ApiException('validation_failed', "Kind '{$kind->name()}' has no code."); }
        if (!$args['edits']) { throw new ApiException('validation_failed', 'edits must not be empty.'); }
        $id = $kind->keyOf(Objects::resolve($context, $kind, $args));
        $dry = $this->isDryRun($args, $context);
        $lines = array();

        list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $kind, $field, $id, $args, $dry, &$lines) {
            $before = $tx->touch($kind, $id);
            if ($before === null) { throw new ApiException('not_found', "No {$kind->name()} with " . Objects::keyText($kind, $id) . '.'); }
            Objects::assertRevision($context, $kind, $id, $before, $args);
            if (!empty($before['static_file_missing'])) {
                throw new ApiException('validation_failed', 'The static file of this element is missing. Restore it before editing.');
            }
            $column = $kind->column($field);
            list($content, $linesBefore, $linesAfter) = LineEdit::apply($before['fields'][$column], $args['edits']);
            $lines = array('total_lines_before' => $linesBefore, 'total_lines_after' => $linesAfter, 'edits_applied' => count($args['edits']));
            Objects::lint($context, $kind, array($column => $content), !empty($args['skip_lint']));
            if ($dry) {
                return array('result' => array(
                    'kind' => $kind->name(), $kind->keyField() => $id, 'revision' => Snapshot::revision($before),
                    'would_change' => $content === $before['fields'][$column] ? array() : array($field), 'reversible' => true,
                ) + $lines);
            }
            if ($content !== $before['fields'][$column]) {
                Objects::save($context, $tx, $kind, $id, array($column => $content), array());
            }
            return array('result' => array(), 'summary' => "edit code of {$kind->name()} {$id}");
        });
        if ($dry) { return $this->plan($context, $result); }
        return $this->written($kind, $id, $info, $lines + array('revision' => Snapshot::revision(Snapshot::capture($context->modx, $kind, $id))));
    }
}
