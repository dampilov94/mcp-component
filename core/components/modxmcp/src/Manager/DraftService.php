<?php
namespace ModxMCP\Manager;

use ModxMCP\Action\InvokeAction;
use ModxMCP\ApiException;
use ModxMCP\Schema;

/**
 * What the "Drafts" block of the component panel needs. It runs the same modx_invoke operations as the API
 * (list_drafts, apply_draft, discard_draft), so every guard of the API applies to a person in the manager too, and what a
 * person applies is journaled as the manager's, with the user, like any change made in the manager.
 */
final class DraftService {
    private $modx;
    private $history;

    /** @param \modUser $user the manager user */
    public function __construct(\modX $modx, $user) {
        $this->modx = $modx;
        $this->history = new HistoryService($modx, $user);
    }

    /** @return array[] the open drafts (the result of list_drafts), each with the manager's own links to edit the page */
    public function drafts() {
        $result = $this->run('list_drafts', array());
        $url = rtrim((string) $this->modx->getOption('manager_url'), '/') . '/';
        foreach ($result['drafts'] as $index => $draft) {
            $result['drafts'][$index]['edit_url'] = $url . '?a=resource/update&id=' . $draft['original_id'];
            $result['drafts'][$index]['edit_draft_url'] = $url . '?a=resource/update&id=' . $draft['draft_id'];
        }
        return $result['drafts'];
    }

    /** The plan (without $confirm) or the result of putting a draft on its page. */
    public function apply($resourceId, $confirm = false, $force = false) {
        return $this->run('apply_draft', array('id' => (int) $resourceId), $confirm, $force);
    }

    /** The plan (without $confirm) or the result of throwing a draft away. */
    public function discard($resourceId, $confirm = false) {
        return $this->run('discard_draft', array('id' => (int) $resourceId), $confirm, false);
    }

    private function run($op, array $target, $confirm = false, $force = false) {
        $args = array('kind' => 'resource', 'op' => $op) + $target;
        if ($confirm) { $args['args'] = array('confirm' => true); }
        if ($force) { $args['force'] = true; }
        $action = new InvokeAction();
        $invalid = Schema::validate($action->inputSchema(), json_decode(json_encode($args)));
        if ($invalid !== null) { throw new ApiException('validation_failed', $invalid); }
        return $action->run($args, $this->history->context());
    }
}
