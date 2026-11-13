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
