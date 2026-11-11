<?php
namespace ModxMCP\Action;

use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Schema;

final class CheckpointAction extends Action {
    public function name() { return 'checkpoint'; }

    public function group() { return 'history'; }

    // Marks a point in the journal; it changes nothing on the site, but only tokens that can
    // write have any use for it.
    public function scope() { return Scope::DEVELOP; }

    public function description() {
        return 'Mark the current point in the journal with a label, before starting a larger task. '
            . 'modx_undo with to_checkpoint reverts everything done through MCP after it.';
    }

    public function inputSchema() {
        return Schema::obj(array('label' => Schema::str('What is about to be done.', array('minLength' => 1, 'maxLength' => 200))), array('label'));
    }

    public function outputSchema() {
        return Schema::obj(array('checkpoint_id' => Schema::int(), 'label' => Schema::str()), array('checkpoint_id', 'label'), true);
    }

    public function run(array $args, Context $context) {
        $checkpoint = $context->journal()->addCheckpoint($args['label'], $context->token['id'], $context->sessionId);
        return array('checkpoint_id' => $checkpoint['id'], 'label' => $checkpoint['label'], 'after_changeset' => $checkpoint['last_changeset_id']);
    }
}
