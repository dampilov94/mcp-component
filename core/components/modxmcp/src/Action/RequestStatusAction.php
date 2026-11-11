<?php
namespace ModxMCP\Action;

use ModxMCP\Context;
use ModxMCP\Schema;

final class RequestStatusAction extends Action {
    public function name() { return 'request_status'; }

    public function description() {
        return 'Inspect a write request by request_id after a timeout or lost connection, without running it '
            . 'again. States: not_found, in_progress, unknown, completed, expired. To retry a call, reuse its '
            . 'ID as _request_id with exactly the same arguments.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'request_id'     => Schema::str(null, array('pattern' => '^[A-Za-z0-9_-]{16,128}$')),
            'include_result' => Schema::bool('Return the recorded response when it is still retained.'),
        ), array('request_id'));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'request_id'   => Schema::str(),
            'state'        => array('type' => 'string', 'enum' => array('not_found', 'in_progress', 'unknown', 'completed', 'expired')),
            'started_at'   => Schema::int(),
            'completed_at' => Schema::int(),
            'http_status'  => Schema::int(),
            'success'      => Schema::bool(),
            'response'     => Schema::obj(array(), array(), true),
        ), array('request_id', 'state'), true);
    }

    public function run(array $args, Context $context) {
        return $context->requestStore()->status($args['request_id'], !empty($args['include_result']));
    }
}
