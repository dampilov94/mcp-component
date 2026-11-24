<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Capabilities;
use ModxMCP\Integration\Redirector;
use ModxMCP\Integration\RedirectorRename;

/** Native Redirector rules, readable by administrators. The journal can undo its generated rules. */
final class RedirectKind extends Kind {
    const NAME = 'redirect';

    public function name() { return self::NAME; }
    public function label() { return 'Redirector rule'; }
    public function modelClass() { return 'modRedirect'; }
    public function processorPath() { return 'mgr/redirect/'; }
    public function group() { return Redirector::GROUP; }
    public function nameField() { return 'pattern'; }
    public function readScope() { return Scope::ADMIN; }
    public function writeScope() { return Scope::ADMIN; }
    public function genericWrites() { return false; }
    public function genericWritesMessage() { return 'Edit existing rules in the Redirector manager. Use resource.rename_with_redirect to generate a rule for a moved page.'; }

    public function prepare(\modX $modx) { Redirector::load($modx); }

    public function fields() {
        $readOnly = array('list' => true, 'read_only' => true);
        return array(
            'id' => array('type' => 'integer') + $readOnly,
            'pattern' => array('type' => 'string') + $readOnly,
            'target' => array('type' => 'string') + $readOnly,
            'context_key' => array('type' => 'string') + $readOnly,
            'active' => array('type' => 'boolean') + $readOnly,
            'triggered' => array('type' => 'integer', 'read_only' => true),
            'triggered_first' => array('type' => 'string', 'format' => 'datetime', 'read_only' => true),
            'triggered_last' => array('type' => 'string', 'format' => 'datetime', 'read_only' => true),
        );
    }

    public function stateColumns(array $columns) {
        unset($columns['triggered'], $columns['triggered_first'], $columns['triggered_last']);
        return $columns;
    }

    public function assertRestorable(array $state) {
        RedirectorRename::assertStoredRule($state['fields']);
    }

    public function assertUndoable(\modX $modx, $key, $target) {
        if ((new Capabilities($modx))->groupBlockReason(Redirector::GROUP, array('denied_groups' => array())) !== null) {
            throw new ApiException('not_reversible', 'Redirector is unavailable to restore this rule.');
        }
        if ($target !== null) { RedirectorRename::assertRestorableContext($modx, $key, $target['fields']); }
    }
}
