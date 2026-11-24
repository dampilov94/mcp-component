<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Code\Diff;
use ModxMCP\Code\FileLines;
use ModxMCP\Context;
use ModxMCP\Files\FileRoot;
use ModxMCP\Files\FileSubject;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Lint\PhpLint;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class EditFileAction extends FileWriteAction {
    public function name() { return 'edit_file'; }

    public function description() {
        return 'Edit a text file of a media source by line ranges, so only changed lines travel; the file\'s line endings '
            . 'and final newline are kept. Edits work exactly as in modx_edit_code: 1-based inclusive lines of the file as '
            . 'it was read (modx_read_file with start_line / numbered), insert with end_line = start_line - 1, `expect` '
            . 'guards against stale numbers. Pass the revision from modx_read_file as expected_revision. Journaled and '
            . 'reversible with modx_undo; PHP files must still parse and need the develop scope. The file must exist '
            . '(modx_write_file creates files).';
    }

    public function inputSchema() {
        $edit = Schema::obj(array(
            'start_line'  => Schema::int(null, array('minimum' => 1)),
            'end_line'    => Schema::int('Last line to replace; default start_line. Use start_line - 1 to insert before start_line without replacing anything.', array('minimum' => 0)),
            'replacement' => Schema::str('New text for the range; empty deletes the lines.'),
            'expect'      => Schema::str('Current text of the lines being replaced; not used for an insert.'),
        ), array('start_line'));
        return Schema::obj(array(
            'source'    => Schema::int('Media source id.', array('minimum' => 1)),
            'root'      => Schema::str('Instead of source: the root id returned by modx_read_file, e.g. "s3".', array('pattern' => '^s[1-9][0-9]*$')),
            'path'      => Schema::str('File relative to the root.', array('minLength' => 1, 'maxLength' => 1000)),
            'edits'     => Schema::arr($edit, 'Edits, all relative to the file before this call.', array('minItems' => 1, 'maxItems' => 100)),
            'skip_lint' => Schema::bool('Save a PHP file even if it does not parse.'),
        ) + array_diff_key($this->commonInput(), array('force' => 1)), array('path', 'edits'));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'root' => Schema::str(), 'path' => Schema::str(), 'revision' => Schema::str(),
            'changeset_id' => array('type' => array('integer', 'null')), 'reversible' => Schema::bool(),
            'edits_applied' => Schema::int(), 'total_lines_before' => Schema::int(), 'total_lines_after' => Schema::int(),
            'diff' => Schema::str(), 'dry_run' => Schema::bool(),
        ), array('root', 'path', 'revision', 'reversible', 'edits_applied', 'total_lines_before', 'total_lines_after'), true);
    }

    public function run(array $args, Context $context) {
        if (isset($args['source']) === isset($args['root'])) { throw new ApiException('validation_failed', 'Give exactly one of: source (media source id) or root (as returned by modx_read_file).'); }
        $root = isset($args['source']) ? FileRoot::fromArgs($context, array('source' => $args['source'])) : FileRoot::fromId($context, $args['root']);
        $relative = FileRoot::normalize($args['path']);
        $path = $root->assertWritable($context, $relative);
        if (!is_file($path)) { throw new ApiException('validation_failed', "No file '{$relative}' in {$root->label}; use modx_write_file to create a file."); }
        $max = (int) $context->modx->getOption('modxmcp.max_read_bytes', null, 262144);
        if (filesize($path) > $max) { throw new ApiException('validation_failed', "'{$relative}' is larger than modxmcp.max_read_bytes ({$max}); line edits are for files up to that size."); }
        $subject = new FileSubject($root, $relative);
        $dry = $this->isDryRun($args, $context);
        $applied = array();

        list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $subject, $relative, $path, $args, $dry, &$applied) {
            $before = $tx->touchSubject($subject);
            if ($before === null) { throw new ApiException('validation_failed', "No file '{$relative}'; use modx_write_file to create a file."); }
            if ($before['encoding'] === 'base64') { throw new ApiException('validation_failed', "'{$relative}' is a binary file; line edits are for text."); }
            if (isset($args['expected_revision']) && !hash_equals((string) Snapshot::revision($before), $args['expected_revision'])) {
                throw new ApiException('revision_conflict', "'{$relative}' has changed since it was read. Read it again and retry.", array('current_revision' => Snapshot::revision($before)));
            }
            list($content, $linesBefore, $linesAfter) = FileLines::edit($before['content'], $args['edits']);
            $applied = array('edits_applied' => count($args['edits']), 'total_lines_before' => $linesBefore, 'total_lines_after' => $linesAfter);
            if (preg_match('/\.(php\d?|phtml|inc)$/i', $relative) && $context->modx->getOption('modxmcp.lint_php', null, true)) {
                $error = PhpLint::checkFile($content);
                if ($error !== null && empty($args['skip_lint'])) {
                    throw new ApiException('lint_failed', "PHP syntax error on line {$error['line']}: {$error['message']}. Nothing was saved.", $error);
                }
                if ($error !== null) { $context->warnings[] = "PHP syntax check skipped on request: {$error['message']} on line {$error['line']}."; }
            }
            if ($dry) {
                return array('result' => array(
                    'root' => $subject->root()->id, 'path' => $relative, 'revision' => Snapshot::revision($before),
                    'diff' => Diff::lines($before['content'], $content), 'reversible' => true,
                ) + $applied);
            }
            if ($content !== $before['content']) { Snapshot::writeFile($path, $content); }
            return array('result' => array(), 'summary' => "edit file {$relative}");
        });
        if ($dry) { return $this->plan($context, $result); }
        return array(
            'root' => $root->id, 'path' => $relative, 'revision' => Snapshot::revision($subject->capture()),
            'changeset_id' => $info['changeset_id'], 'reversible' => $this->filesWritten($info)['reversible'],
        ) + $applied;
    }
}
