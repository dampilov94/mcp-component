<?php
namespace ModxMCP;

/** Expected failure whose code and message are safe to return to the client. */
class ApiException extends \Exception {
    /** Fixed error codes of protocol 2 and their default HTTP status. */
    const CODES = array(
        'unsupported_protocol' => 400,
        'invalid_json'         => 400,
        'validation_failed'    => 400,
        'unknown_action'       => 400,
        'unauthorized'         => 401,
        'disabled'             => 403,
        'https_required'       => 403,
        'ip_not_allowed'       => 403,
        'forbidden_scope'      => 403,
        'unavailable'          => 403,
        'not_found'            => 404,
        'method_not_allowed'   => 405,
        'revision_conflict'    => 409,
        'locked'               => 409,
        'undo_conflict'        => 409,
        'not_reversible'       => 409,
        'request_state_error'  => 409,
        'request_too_large'    => 413,
        'unsupported_media_type' => 415,
        'lint_failed'          => 422,
        'response_too_large'   => 500,
        'response_encoding_failed' => 500,
        'internal_error'       => 500,
    );

    public $errorCode;
    public $httpStatus;
    public $details;

    public function __construct($errorCode, $message, array $details = array(), $httpStatus = null) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus !== null ? $httpStatus : (isset(self::CODES[$errorCode]) ? self::CODES[$errorCode] : 400);
        $this->details = $details;
    }
}
