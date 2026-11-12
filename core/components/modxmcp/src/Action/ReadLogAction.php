<?php
namespace ModxMCP\Action;

use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Schema;
use ModxMCP\Verify\LogReader;

final class ReadLogAction extends Action {
    public function name() { return 'read_log'; }
    public function scope() { return Scope::DEVELOP; }
    public function group() { return 'verify'; }
    public function description() {
        return 'Tail of the MODX error log, newest last. Pass the returned end_offset as after_offset after a change to see only new errors.';
    }
    public function inputSchema() {
        return Schema::obj(array('limit' => Schema::int('Entries, default 50.', array('minimum' => 1, 'maximum' => 500)),
            'contains' => Schema::str('Case-insensitive substring.', array('maxLength' => 200)),
            'after_offset' => Schema::int('Byte cursor from a previous call.', array('minimum' => 0))));
    }
    public function outputSchema() {
        return Schema::obj(array('size' => Schema::int(), 'end_offset' => Schema::int(),
            'entries' => Schema::arr(Schema::obj(array(), array(), true)), 'returned' => Schema::int(),
            'truncated' => Schema::bool(), 'reset' => Schema::bool()), array('size', 'end_offset', 'entries', 'returned', 'truncated', 'reset'));
    }
    public function run(array $args, Context $context) {
        return LogReader::tail($context->modx->getCachePath() . 'logs/error.log', isset($args['limit']) ? $args['limit'] : 50,
            $context->modx->getOption('modxmcp.max_read_bytes', null, 262144), isset($args['contains']) ? $args['contains'] : '',
            isset($args['after_offset']) ? $args['after_offset'] : null);
    }
}
