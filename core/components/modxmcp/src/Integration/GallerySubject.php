<?php
namespace ModxMCP\Integration;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Journal\Subject;
use ModxMCP\Kind\Objects;

/** One stable, byte-level gallery subject for a product. */
final class GallerySubject implements Subject {
    const NAME = 'ms2_gallery';

    private $context;
    private $id;
    private $expected = array();
    private $mutationStarted = false;
    public $storage;

    public function __construct(Context $context, $product, $historyOnly = false) {
        $this->context = $context;
        $this->id = $historyOnly ? (int) $product : (int) $product->get('id');
        $this->storage = $historyOnly ? null : GalleryStorage::open($context, $product);
    }

    public static function fromId(Context $context, $key, $write) {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', (string) $key)) { throw new ApiException('not_reversible', 'Invalid recorded product ID.'); }
        GalleryStorage::checkAccess($context, $write);
        $kind = Objects::kind($context, 'resource', $write);
        if (!$write) { return new self($context, $key, true); }
        try {
            return new self($context, Objects::resolveKey($context, $kind, (int) $key));
        } catch (\Throwable $e) {
            throw new ApiException('not_reversible', 'The recorded product gallery is unavailable.');
        }
    }

    public function kindName() { return self::NAME; }
    public function key() { return (string) $this->id; }
    public function publicKey() { return $this->id; }

    /** Save the exact new paths expected from the native processor so rollback can remove orphan output. */
    public function expectFiles(array $paths) {
        foreach ($paths as $path) {
            $this->storage->path($path);
            $this->expected[$path] = true;
        }
        $this->mutationStarted = true;
    }

    private function verifyRoot() {
        $product = $this->context->modx->getObject('modResource', array('id' => $this->id), false);
        $current = GalleryStorage::open($this->context, $product);
        if ($current->identity !== $this->storage->identity || $current->root->id !== $this->storage->root->id) {
            throw new ApiException('not_reversible', 'The product media source or canonical root changed.');
        }
    }

    public function capture() {
        $this->verifyRoot();
        $rows = $this->storage->rows();
        list($files, $dirs) = $this->storage->captureFiles($this->storage->mapped($rows));
        $state = array('kind' => self::NAME, 'key' => $this->key(), 'source' => $this->storage->root->id,
            'identity' => $this->storage->identity, 'rows' => $rows, 'files' => $files, 'dirs' => $dirs, 'cover' => $this->storage->cover());
        if (!$this->context->blobs()->accepts(Snapshot::encode($state))) {
            throw new ApiException('validation_failed', 'The encoded gallery snapshot exceeds the configured history blob limit.');
        }
        return $state;
    }

    public function assertRestorable($state) {
        if (!$state || $state['key'] !== $this->key() || $state['source'] !== $this->storage->root->id || $state['identity'] !== $this->storage->identity) {
            throw new ApiException('not_reversible', 'The recorded gallery belongs to a different product or media root.');
        }
        if (!$this->context->blobs()->accepts(Snapshot::encode($state))) {
            throw new ApiException('not_reversible', 'The recorded gallery exceeds the current history blob limit.');
        }
        $this->storage->mapped($state['rows']);
    }

    public function captureDeleteExtra() { return array(); }

    public function restore($state, array $extra) {
        $this->assertRestorable($state);
        $current = $this->capture();
        $this->expectFiles(array_keys($state['files']));
        $this->storage->restore($state, $current['files']);
    }

    /** Native failure and oversize recovery must not require reading a complete current snapshot. */
    public function rollback(array $before) {
        if (!$this->mutationStarted) { return; }
        $this->verifyRoot();
        $remove = $before['files'] + $this->expected;
        foreach ($this->storage->rows() as $row) {
            try { $path = $this->storage->relative($row); } catch (ApiException $e) { continue; }
            $remove[$path] = true;
        }
        $this->storage->restore($before, $remove);
    }

    public function openInManager() {
        return $this->context->kinds->get('resource')->openInManager($this->context->modx, $this->id);
    }

    public function assertNotOpen(array $args) {
        Objects::assertNotOpen($this->context, $this->context->kinds->get('resource'), $this->id, $args);
    }

    public function label($state) { return 'Product gallery ' . $this->id; }

    public function changed($before, $after) {
        $out = array();
        foreach (array('rows', 'files', 'dirs', 'cover') as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) { $out[] = $field; }
        }
        return $out;
    }

    public function describeChange($before, $after) {
        $out = array();
        foreach ($this->changed($before, $after) as $field) {
            if ($field === 'cover') { $out[$field] = array('old' => 'Previous cover', 'new' => 'Updated cover'); continue; }
            $out[$field] = array('old' => count($before[$field] ?? array()), 'new' => count($after[$field] ?? array()));
        }
        return $out;
    }
}
