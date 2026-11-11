<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Files\FileRoot;
use ModxMCP\Files\FileSubject;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Schema;

final class ReadFileAction extends Action {
    const MAX_FILE = 8388608;

    public function name() { return 'read_file'; }

    public function group() { return 'files'; }

    public function description() {
        return 'Read a file from a media source or from an installed component\'s code. Returns text with its '
            . 'revision (pass it to modx_write_file as expected_revision); long files are cut at max_bytes. '
            . 'Binary files report their size only, unless as_base64 is true.';
    }

    public function inputSchema() {
        return Schema::obj(FileRoot::inputProperties() + array(
            'path'      => Schema::str('File relative to the root.', array('minLength' => 1, 'maxLength' => 1000)),
            'offset'    => Schema::int('Byte offset to start at, default 0.', array('minimum' => 0)),
            'max_bytes' => Schema::int('Bytes to return, default 100000.', array('minimum' => 1, 'maximum' => 1000000)),
            'as_base64' => Schema::bool('Return a binary file base64-encoded (at most 300000 bytes).'),
        ), array('path'));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'path' => Schema::str(), 'size' => Schema::int(), 'revision' => Schema::str(), 'binary' => Schema::bool(),
            'content' => Schema::str(), 'truncated' => Schema::bool(),
        ), array('path', 'size', 'revision', 'binary'), true);
    }

    public function run(array $args, Context $context) {
        $root = FileRoot::fromArgs($context, $args);
        $relative = FileRoot::normalize($args['path']);
        $path = $root->resolve($relative);
        if (!is_file($path)) { throw new ApiException('not_found', "No file '{$relative}' in {$root->label}."); }
        if (filesize($path) > self::MAX_FILE) { throw new ApiException('validation_failed', 'The file is larger than ' . self::MAX_FILE . ' bytes and cannot be read through the API.'); }
        $state = (new FileSubject($root, $relative))->capture();
        $bytes = FileSubject::bytes($state);
        $result = array(
            'path' => $relative, 'size' => $state['size'], 'modified' => date('c', (int) @filemtime($path)),
            'revision' => Snapshot::revision($state), 'binary' => $state['encoding'] === 'base64',
        );
        if ($result['binary']) {
            if (!empty($args['as_base64'])) {
                if ($state['size'] > 300000) { throw new ApiException('validation_failed', 'Binary files over 300000 bytes cannot be returned.'); }
                $result['content'] = $state['content'];
                $result['encoding'] = 'base64';
            }
            return $result;
        }
        $offset = isset($args['offset']) ? $args['offset'] : 0;
        $max = isset($args['max_bytes']) ? $args['max_bytes'] : 100000;
        $slice = substr($bytes, $offset, $max);
        if ($slice === false) { $slice = ''; }
        // Never cut a multi-byte character in half.
        $slice = function_exists('mb_strcut') ? mb_strcut($slice, 0, strlen($slice), 'UTF-8') : $slice;
        $result['content'] = $slice;
        $result['truncated'] = $offset + strlen($slice) < $state['size'];
        if ($result['truncated']) { $result['next_offset'] = $offset + strlen($slice); }
        return $result;
    }
}
