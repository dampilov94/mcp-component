<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Files\FileRoot;
use ModxMCP\Files\FileSubject;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class DeletePathAction extends FileWriteAction {
    public function name() { return 'delete_path'; }

    public function destructive() { return true; }

    public function description() {
        return 'Delete a file, or a directory with everything in it, from a media source. Run with dry_run first '
            . 'to see the files. Their content is kept in the journal, so modx_undo restores them; files over '
            . 'the journal size limit are deleted but reported as not reversible.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'source'  => Schema::int('Media source id.', array('minimum' => 1)),
            'path'    => Schema::str('File or directory relative to the source root.', array('minLength' => 1, 'maxLength' => 1000)),
            'dry_run' => Schema::bool('List what would be deleted without deleting anything.'),
        ), array('source', 'path'));
    }

    public function run(array $args, Context $context) {
        $root = FileRoot::fromArgs($context, array('source' => $args['source']));
        $relative = FileRoot::normalize($args['path']);
        $root->assertWritable($context, $relative);
        $path = $root->resolve($relative);
        if (is_link($root->path . '/' . $relative)) { throw new ApiException('validation_failed', "'{$relative}' is a symbolic link and cannot be deleted through the API."); }
        if (!file_exists($path)) { throw new ApiException('not_found', "No '{$relative}' in {$root->label}."); }
        $isDir = is_dir($path);
        $files = $isDir ? $root->filesBelow($relative) : array($relative);
        foreach ($files as $file) { $root->assertWritable($context, $file); }
        $dry = $this->isDryRun($args, $context);

        list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($root, $files, $path, $relative, $isDir, $dry) {
            $bytes = 0;
            foreach ($files as $file) {
                $state = $tx->touchSubject(new FileSubject($root, $file));
                $bytes += $state === null ? 0 : $state['size'];
            }
            if ($dry) {
                return array('result' => array(
                    'files' => array_map(function ($file) { return array('path' => $file, 'op' => 'delete'); }, array_slice($files, 0, 100)),
                    'count' => count($files), 'bytes' => $bytes, 'directory' => $isDir, 'reversible' => true,
                ));
            }
            foreach ($files as $file) {
                $full = $root->resolve($file);
                if (!@unlink($full)) { throw new \RuntimeException("Cannot delete '{$file}'."); }
            }
            if ($isDir) { self::removeEmptyTree($path); }
            FileSubject::pruneEmptyDirectories(dirname($path), $root->path);
            return array('result' => array(), 'summary' => "delete {$relative}" . ($isDir ? ' (' . count($files) . ' files)' : ''));
        });
        if ($dry) { return $this->plan($context, $result); }
        return $this->filesWritten($info);
    }

    /** Remove a directory tree that no longer holds files. */
    private static function removeEmptyTree($dir) {
        foreach (array_diff((array) scandir($dir), array('.', '..')) as $name) {
            if (is_dir($dir . '/' . $name) && !is_link($dir . '/' . $name)) { self::removeEmptyTree($dir . '/' . $name); }
        }
        @rmdir($dir);
    }
}
