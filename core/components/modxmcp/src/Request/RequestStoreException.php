<?php
namespace ModxMCP\Request;

class RequestStoreException extends \Exception {
    public $httpStatus;

    public function __construct($message, $httpStatus = 503) {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}
