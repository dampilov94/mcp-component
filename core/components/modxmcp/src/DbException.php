<?php
namespace ModxMCP;

class DbException extends \RuntimeException {
    public $tableMissing;

    public function __construct($message, $tableMissing = false) {
        parent::__construct($message);
        $this->tableMissing = (bool) $tableMissing;
    }
}
