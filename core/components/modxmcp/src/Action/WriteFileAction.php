<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Files\FileRoot;
use ModxMCP\Files\FileSubject;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Lint\PhpLint;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class WriteFileAction extends FileWriteAction {
    public function name() { return 'write_file'; }

    public function description() {
        return 'Create or replace a file in a media source; missing directories are created. Journaled and '
            . 'reversible with modx_undo. Pass expected_revision from modx_read_file when replacing. PHP files '
            . 'must parse and, like other server-executed files, need the develop scope.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'source'    => Schema::int('Media source id.', array('minimum' => 1)),
            'path'      => Schema::str('File relative to the source root.', array('minLength' => 1, 'maxLength' => 1000)),
            'content'   => Schema::str('New content of the whole file.'),
            'encoding'  => array('type' => 'string', 'enum' => array('utf8', 'base64'), 'description' => 'How content is encoded; default utf8.'),
            'must_be_new' => Schema::bool('Fail if the file already exists.'),
            'skip_lint' => Schema::bool('Save a PHP file even if it does not parse.'),
        ) + $this->commonInput(), array('source', 'path', 'content'));
    }

    public function run(array $args, Context $context) {
        $root = FileRoot::fromArgs($context, array('source' => $args['source']));
        $relative = FileRoot::normalize($args['path']);
        $root->assertWritable($context, $relative);
        $path = $root->resolve($relative);
        if (is_dir($path)) { throw new ApiException('validation_failed', "'{$relative}' is a directory."); }
        $content = $args['content'];
        if (isset($args['encoding']) && $args['encoding'] === 'base64') {
            $content = base64_decode($content, true);
            if ($content === false) { throw new ApiException('validation_failed', 'content is not valid base64.'); }
        }
        if (preg_match('/\.(php\d?|phtml|inc)$/i', $relative) && $context->modx->getOption('modxmcp.lint_php', null, true)) {
            $error = PhpLint::checkFile($content);
            if ($error !== null && empty($args['skip_lint'])) {
                throw new ApiException('lint_failed', "PHP syntax error on line {$error['line']}: {$error['message']}. Nothing was saved.", $error);
            }
            if ($error !== null) { $context->warnings[] = "PHP syntax check skipped on request: {$error['message']} on line {$error['line']}."; }
        }
        $subject = new FileSubject($root, $relative);
        $dry = $this->isDryRun($args, $context);

        list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($subject, $relative, $path, $content, $args, $dry) {
            $before = $tx->touchSubject($subject);
            if ($before !== null && !empty($args['must_be_new'])) { throw new ApiException('validation_failed', "'{$relative}' already exists."); }
            if (isset($args['expected_revision']) && !hash_equals((string) Snapshot::revision($before), $args['expected_revision'])) {
                throw new ApiException('revision_conflict', "'{$relative}' has changed since it was read. Read it again and retry.", array('current_revision' => Snapshot::revision($before)));
            }
            if ($dry) {
                return array('result' => array(
                    'files' => array(array('path' => $relative, 'op' => $before === null ? 'create' : (FileSubject::bytes($before) === $content ? 'none' : 'update'))),
                    'reversible' => true,
                ));
            }
            Snapshot::writeFile($path, $content);
            return array('result' => array(), 'summary' => ($before === null ? 'create' : 'update') . " file {$relative}");
        });
        if ($dry) { return $this->plan($context, $result); }
        return $this->filesWritten($info) + array('size' => strlen($content));
    }
}
