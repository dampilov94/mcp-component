<?php
namespace ModxMCP\Kind;

use ModxMCP\Code\Rename;
use ModxMCP\Context;
use ModxMCP\Schema;
use ModxMCP\Write\Transaction;

/** The operation `rename` of chunks, snippets and TVs: the new name together with every exact reference to the old one. */
trait RenameOperation {
    protected function renameOperation() {
        return array('rename' => array(
            'description' => 'Rename the element and rewrite the exact references to it (tags, parameters, Fenom and PHP calls, default properties, MIGX) in one changeset. '
                . 'Without confirm it returns the plan: the references to rewrite, per object, with the old and the new text, the references that cannot be rewritten safely (manual) and revisions. '
                . 'Confirm with confirm: true and expected_revisions from the plan; it refuses if any listed object changed meanwhile. modx_undo reverts the whole rename. A static element keeps its file name.',
            'read_only' => false, 'needs_object' => true, 'plan' => 'planRename', 'handler' => 'opRename',
            'input' => Schema::obj(array(
                'new_name' => Schema::str('The new name: letters, digits and - . / _, no spaces, at most 50 characters.', array('maxLength' => 50)),
                'confirm' => Schema::bool('Really rename. Without it only the plan is returned.'),
                'expected_revisions' => Schema::obj(array(), array(), true) + array('description' => 'The revisions map of the plan (kind:id to revision), required with confirm.'),
            ), array('new_name'))));
    }

    public function planRename(Context $context, $object, array $args): array {
        return Rename::plan($context, $this, $object, $args['new_name']);
    }

    public function opRename(Context $context, Transaction $tx, $object, array $args): array {
        return Rename::apply($context, $tx, $this, $object, $args);
    }
}
