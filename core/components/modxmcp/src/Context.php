<?php
namespace ModxMCP;

use ModxMCP\Action\Registry;
use ModxMCP\Kind\KindRegistry;
use ModxMCP\Request\RequestStore;

/** Everything an action may need about the current request. */
final class Context {
    /** @var \modX */
    public $modx;
    /** @var array Public token record (see TokenStore::authenticate). */
    public $token;
    /** @var string|null */
    public $sessionId;
    /** @var string|null */
    public $requestId;
    /** @var Capabilities */
    public $capabilities;
    /** @var Registry */
    public $actions;
    /** @var KindRegistry */
    public $kinds;
    /** @var string Component core path with a trailing slash. */
    public $corePath;
    /** @var string[] Warnings returned in meta.warnings. */
    public $warnings = array();

    private $requestStoreFactory;

    public function __construct(\modX $modx, array $token, $corePath, callable $requestStoreFactory) {
        $this->modx = $modx;
        $this->token = $token;
        $this->corePath = rtrim($corePath, '/\\') . '/';
        $this->capabilities = new Capabilities($modx);
        $this->actions = new Registry();
        $this->kinds = new KindRegistry();
        $this->requestStoreFactory = $requestStoreFactory;
    }

    /** @return RequestStore */
    public function requestStore() {
        return call_user_func($this->requestStoreFactory);
    }
}
