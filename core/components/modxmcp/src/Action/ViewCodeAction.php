<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Code\LineEdit;
use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Kind\Objects;
use ModxMCP\Schema;

final class ViewCodeAction extends Action {
    public function name() { return 'view_code'; }

    public function group() { return 'code'; }

    public function description() {
        return 'Code of a chunk, snippet, template or plugin as numbered lines, optionally only a line range. '
            . 'Use the line numbers and the returned revision with modx_edit_code.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'kind'       => Schema::str(null, array('pattern' => '^[a-z][a-z0-9_]{0,63}$')),
            'id'         => Schema::int(null, array('minimum' => 1)),
            'name'       => Schema::str('Name, when the id is not known.', array('maxLength' => 255)),
            'start_line' => Schema::int(null, array('minimum' => 1)),
            'end_line'   => Schema::int(null, array('minimum' => 1)),
        ), array('kind'));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'kind' => Schema::str(), 'id' => Schema::int(), 'name' => Schema::str(), 'static' => Schema::bool(),
            'revision' => Schema::str(), 'total_lines' => Schema::int(), 'start_line' => Schema::int(),
            'end_line' => Schema::int(), 'numbered' => Schema::str(),
        ), array('kind', 'id', 'revision', 'total_lines', 'numbered'), true);
    }

    public function run(array $args, Context $context) {
        $kind = Objects::kind($context, $args['kind']);
        if ($kind->codeField() === null) { throw new ApiException('validation_failed', "Kind '{$kind->name()}' has no code."); }
        $object = Objects::resolve($context, $kind, $args);
        $content = $kind->effectiveContent($object);
        $view = LineEdit::numbered($content[0], isset($args['start_line']) ? $args['start_line'] : null, isset($args['end_line']) ? $args['end_line'] : null);
        $id = (int) $object->get('id');
        if ($content[3]) { $context->warnings[] = 'The static file of this element is missing.'; }
        return array(
            'kind' => $kind->name(), 'id' => $id, 'name' => (string) $kind->value($object, $kind->nameField()),
            'static' => $content[1], 'revision' => Snapshot::revision(Snapshot::capture($context->modx, $kind, $id)),
            'total_lines' => $view[1], 'start_line' => $view[2], 'end_line' => $view[3], 'numbered' => $view[0],
        );
    }
}
