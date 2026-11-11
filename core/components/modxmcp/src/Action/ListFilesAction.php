<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Files\FileRoot;
use ModxMCP\Schema;

final class ListFilesAction extends Action {
    public function name() { return 'list_files'; }

    public function group() { return 'files'; }

    public function description() {
        return 'List a directory of a media source, or of an installed component\'s code (read-only). '
            . 'Directories first; each entry has name, type, size and modification time.';
    }

    public function inputSchema() {
        return Schema::obj(FileRoot::inputProperties() + array(
            'path'  => Schema::str('Directory relative to the root; omit for the root.', array('maxLength' => 1000)),
            'limit' => Schema::int('Default 200.', array('minimum' => 1, 'maximum' => 1000)),
        ));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'root' => Schema::str(), 'path' => Schema::str(), 'writable' => Schema::bool(),
            'entries' => Schema::arr(Schema::obj(array(), array(), true)), 'total' => Schema::int(), 'truncated' => Schema::bool(),
        ), array('root', 'path', 'entries', 'total', 'truncated'), true);
    }

    public function run(array $args, Context $context) {
        $root = FileRoot::fromArgs($context, $args);
        $relative = FileRoot::normalize(isset($args['path']) ? $args['path'] : '');
        $dir = $root->resolve($relative);
        if (!is_dir($dir)) { throw new ApiException('not_found', "No directory '{$relative}' in {$root->label}."); }
        $limit = isset($args['limit']) ? $args['limit'] : 200;
        $entries = array();
        foreach (array_diff((array) scandir($dir), array('.', '..')) as $name) {
            $full = $dir . '/' . $name;
            $isDir = is_dir($full);
            $entry = array('name' => $name, 'type' => is_link($full) ? 'link' : ($isDir ? 'dir' : 'file'));
            if (!$isDir) { $entry['size'] = (int) @filesize($full); }
            $entry['modified'] = date('c', (int) @filemtime($full));
            $entries[] = $entry;
        }
        usort($entries, function ($a, $b) {
            return ($a['type'] === 'dir' ? 0 : 1) - ($b['type'] === 'dir' ? 0 : 1) ?: strcmp($a['name'], $b['name']);
        });
        $total = count($entries);
        return array(
            'root' => $root->label, 'path' => $relative, 'writable' => $root->writable,
            'entries' => array_slice($entries, 0, $limit), 'total' => $total, 'truncated' => $total > $limit,
        );
    }
}
