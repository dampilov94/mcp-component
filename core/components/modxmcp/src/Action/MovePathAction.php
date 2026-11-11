<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Files\FileRoot;
use ModxMCP\Files\FileSubject;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class MovePathAction extends FileWriteAction {
    public function name() { return 'move_path'; }

    public function destructive() { return true; }

    public function description() {
        return 'Rename or move a file or a whole directory within one media source. The target must not exist. '
            . 'Journaled as one changeset, so modx_undo moves everything back.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'source'  => Schema::int('Media source id.', array('minimum' => 1)),
            'path'    => Schema::str('Existing file or directory, relative to the source root.', array('minLength' => 1, 'maxLength' => 1000)),
            'to'      => Schema::str('New relative path.', array('minLength' => 1, 'maxLength' => 1000)),
            'dry_run' => Schema::bool('Report what would move without moving anything.'),
        ), array('source', 'path', 'to'));
    }

    public function run(array $args, Context $context) {
        $root = FileRoot::fromArgs($context, array('source' => $args['source']));
        $from = FileRoot::normalize($args['path']);
        $to = FileRoot::normalize($args['to']);
        $root->assertWritable($context, $from);
        $root->assertWritable($context, $to);
        $fromPath = $root->resolve($from);
        $toPath = $root->resolve($to);
        if (!file_exists($fromPath)) { throw new ApiException('not_found', "No '{$from}' in {$root->label}."); }
        if (file_exists($toPath)) { throw new ApiException('validation_failed', "'{$to}' already exists."); }
        if (strpos($to . '/', $from . '/') === 0) { throw new ApiException('validation_failed', 'A directory cannot be moved into itself.'); }

        $pairs = array();
        if (is_dir($fromPath)) {
            foreach ($root->filesBelow($from) as $file) { $pairs[$file] = $to . substr($file, strlen($from)); }
        } else {
            $pairs[$from] = $to;
        }
        foreach ($pairs as $old => $new) { $root->assertWritable($context, $new); }
        $dry = $this->isDryRun($args, $context);

        list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($root, $pairs, $fromPath, $toPath, $from, $to, $dry) {
            foreach ($pairs as $old => $new) {
                $tx->touchSubject(new FileSubject($root, $old));
                $tx->touchSubject(new FileSubject($root, $new));
            }
            if ($dry) {
                $files = array();
                foreach ($pairs as $old => $new) { $files[] = array('path' => $old, 'to' => $new); }
                return array('result' => array('files' => $files, 'reversible' => true));
            }
            $parent = dirname($toPath);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) { throw new \RuntimeException('Cannot create the target directory.'); }
            if (!@rename($fromPath, $toPath)) { throw new \RuntimeException("Cannot move '{$from}'."); }
            FileSubject::pruneEmptyDirectories(dirname($fromPath), $root->path);
            return array('result' => array(), 'summary' => "move {$from} to {$to}");
        });
        if ($dry) { return $this->plan($context, $result); }
        return $this->filesWritten($info);
    }
}
