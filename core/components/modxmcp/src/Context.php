<?php
namespace ModxMCP;

use ModxMCP\Action\Registry;
use ModxMCP\Journal\BlobStore;
use ModxMCP\Journal\Journal;
use ModxMCP\Kind\KindRegistry;
use ModxMCP\Request\RequestStore;
use ModxMCP\Verify\PageFetcher;
use ModxMCP\Verify\CurlPageFetcher;

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
    /** @var \ModxMCP\Write\Transaction|null The write transaction in progress, for kinds whose writes change other objects. */
    public $transaction = null;

    /** @var int|null Set only by forManager(): the manager user on whose behalf changes are made. The HTTP kernel never sets it. */
    public $managerUserId = null;

    /**
     * @var bool Set only by the manager recorder, and only while it handles an event that has no before-event
     * (publishing from the tree, a save whose before-event was not seen): then the difference between the
     * journal's last state and the current one IS what the person just did. Everywhere else such a difference
     * was made earlier by somebody unknown.
     */
    public $outsideChangeIsManagers = false;

    /** @var string[] Warnings returned in meta.warnings. */
    public $warnings = array();

    private $requestStoreFactory;
    private $pageFetcher;

    public function __construct(\modX $modx, array $token, $corePath, callable $requestStoreFactory) {
        $this->modx = $modx;
        $this->token = $token;
        $this->corePath = rtrim($corePath, '/\\') . '/';
        $this->capabilities = new Capabilities($modx);
        $this->actions = new Registry();
        $this->kinds = new KindRegistry($this);
        $this->requestStoreFactory = $requestStoreFactory;
    }

    /**
     * A context for a person working in the MODX manager (the history page). It has the full admin scope of
     * a token, but its changes are journaled as source "manager" with the user, not as an MCP token's.
     * Only manager code calls this; no request of the API can reach it.
     * @param \modX $modx
     * @param \modUser $user the manager user
     */
    public static function forManager(\modX $modx, $user, $corePath) {
        $token = array(
            'id' => null, 'label' => 'manager: ' . (string) $user->get('username'), 'prefix' => '', 'scope' => 'admin',
            'plan_only' => false, 'denied_groups' => array(), 'expires_at' => null, 'last_used_at' => null,
            'created_by' => 0, 'created_at' => 0, 'revoked_at' => null,
        );
        $context = new self($modx, $token, $corePath, function () { throw new \RuntimeException('There is no request store in the manager.'); });
        $context->managerUserId = (int) $user->get('id');
        return $context;
    }

    /** The manager user this context acts for, or null for an API token. */
    public function actingUserId() {
        return $this->managerUserId;
    }

    /** Private runtime state of the component, outside the MODX cache and the component files. */
    public function dataDir() {
        return rtrim($this->modx->getOption('core_path'), '/\\') . '/modxmcp-data';
    }

    /** @return Journal */
    public function journal() {
        return new Journal($this->modx);
    }

    /** @return BlobStore */
    public function blobs() {
        return new BlobStore($this->dataDir() . '/history', $this->modx->getOption('modxmcp.history_max_blob_bytes', null, 5242880));
    }

    /** @return RequestStore */
    public function requestStore() {
        return call_user_func($this->requestStoreFactory);
    }
    public function pageFetcher(): PageFetcher {
        if ($this->pageFetcher === null) { $this->pageFetcher = new CurlPageFetcher(); }
        return $this->pageFetcher;
    }
    public function setPageFetcher(PageFetcher $fetcher): void {
        $this->pageFetcher = $fetcher;
    }

}
