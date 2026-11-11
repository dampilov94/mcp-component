<?php
namespace ModxMCP\Action;

use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Schema;

/**
 * Base of every action that changes the site. All of them share one contract: optional
 * expected_revision and dry_run in, and kind/id/revision/changeset_id/reversible/changed out.
 */
abstract class WriteAction extends Action {
    public function readOnly() {
        return false;
    }

    // The lowest scope that can write anything (resources). Each kind's own write scope is
    // checked when the action runs, so a "content" token still cannot change elements.
    public function scope() {
        return Scope::CONTENT;
    }

    public function group() {
        return 'objects';
    }

    /** Properties every write accepts. */
    protected function commonInput() {
        return array(
            'expected_revision' => Schema::str('Revision the object had when you read it. The write is refused if it changed since.', array('pattern' => '^[a-f0-9]{64}$')),
            'dry_run' => Schema::bool('Validate and report what would change without changing anything.'),
        );
    }

    public function outputSchema() {
        return Schema::obj(array(
            'kind'         => Schema::str(),
            'id'           => Schema::int(),
            'revision'     => array('type' => array('string', 'null')),
            'changeset_id' => array('type' => array('integer', 'null')),
            'reversible'   => Schema::bool(),
            'changed'      => Schema::arr(Schema::str()),
            'dry_run'      => Schema::bool(),
        ), array(), true);
    }

    /** A plan-only token never changes anything, whatever the caller asked for. */
    protected function isDryRun(array $args, Context $context) {
        return !empty($args['dry_run']) || !empty($context->token['plan_only']);
    }

    /** Result of a dry run. */
    protected function plan(Context $context, array $plan) {
        $plan['dry_run'] = true;
        if (!empty($context->token['plan_only'])) { $plan['planned'] = true; }
        return $plan;
    }

    /** Result of a single-object write, from the transaction's commit info. */
    protected function written($kindName, $id, array $info, array $extra = array()) {
        $change = null;
        foreach ($info['changes'] as $item) {
            if ($item['kind'] === $kindName && (int) $item['id'] === (int) $id) { $change = $item; }
        }
        return array(
            'kind'         => $kindName,
            'id'           => (int) $id,
            'revision'     => $change ? $change['revision'] : (isset($extra['revision']) ? $extra['revision'] : null),
            'changeset_id' => $info['changeset_id'],
            'reversible'   => $change ? $change['reversible'] : true,
            'changed'      => $change ? $change['changed'] : array(),
        ) + $extra;
    }
}
