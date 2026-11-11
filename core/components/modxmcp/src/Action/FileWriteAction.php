<?php
namespace ModxMCP\Action;

use ModxMCP\Schema;

/** Shared result shape of the file-changing tools. */
abstract class FileWriteAction extends WriteAction {
    public function group() { return 'files'; }

    public function outputSchema() {
        return Schema::obj(array(
            'files'        => Schema::arr(Schema::obj(array(), array(), true)),
            'changeset_id' => array('type' => array('integer', 'null')),
            'reversible'   => Schema::bool(),
            'dry_run'      => Schema::bool(),
        ), array(), true);
    }

    /** Result from the transaction's commit info. */
    protected function filesWritten(array $info) {
        $files = array();
        $reversible = true;
        foreach ($info['changes'] as $change) {
            if ($change['kind'] !== 'file') { continue; }
            $files[] = array('path' => substr($change['id'], strpos($change['id'], ':') + 1), 'op' => $change['op'], 'revision' => $change['revision']);
            if (!$change['reversible']) { $reversible = false; }
        }
        return array('files' => $files, 'changeset_id' => $info['changeset_id'], 'reversible' => $reversible);
    }
}
