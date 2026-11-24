<?php
namespace ModxMCP\Integration;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Db;
use ModxMCP\Draft\DraftPolicy;
use ModxMCP\Files\FileRoot;
use ModxMCP\Files\FileSubject;
use ModxMCP\Journal\Snapshot;

/** Checked native media source, product folder and fixed miniShop2 gallery columns. */
final class GalleryStorage {
    const MAX_FILES = 200;
    const MAX_BYTES = 16777216;
    const COLUMNS = array('id', 'product_id', 'source', 'parent', 'name', 'description', 'path', 'file', 'type', 'createdon', 'createdby', 'rank', 'url', 'properties', 'hash', 'active');

    private $context;
    public $id;
    public $product;
    public $source;
    public $root;
    public $properties;
    public $thumbnails;
    public $identity;

    private function __construct(Context $context, $product, $source, FileRoot $root, array $properties, array $thumbnails) {
        $this->context = $context;
        $this->id = (int) $product->get('id');
        $this->product = $product;
        $this->source = $source;
        $this->root = $root;
        $this->properties = $properties;
        $this->thumbnails = $thumbnails;
        $stat = @stat($root->path);
        if (!$stat) { throw new ApiException('validation_failed', 'The gallery source root could not be inspected.'); }
        $this->identity = hash('sha256', $root->path . "\0" . $stat['dev'] . ':' . $stat['ino']);
        $this->directory((string) $this->id);
    }

    public static function checkAccess(Context $context, $write) {
        if (DraftPolicy::applies($context) || ($write && !Scope::allows($context->token['scope'], Scope::CONTENT))) {
            throw new ApiException('forbidden_scope', 'The token cannot access gallery changes.');
        }
        $reason = $context->capabilities->groupBlockReason(MiniShop2::GROUP, $context->token);
        if ($reason !== null) { throw new ApiException('unavailable', 'The gallery needs the miniShop2 group.', array('reason' => $reason)); }
    }

    public static function open(Context $context, $product) {
        self::checkAccess($context, true);
        if (!$product || $product->get('class_key') !== 'msProduct' || $product->get('deleted')) {
            throw new ApiException('validation_failed', 'Select an active miniShop2 product.');
        }
        $modx = $context->modx;
        MiniShop2::load($modx);
        $data = $modx->getObject('msProductData', array('id' => (int) $product->get('id')), false);
        $sourceId = $data ? (int) $data->get('source') : 0;
        $source = $sourceId > 0 ? $modx->getObject('sources.modMediaSource', array('id' => $sourceId), false) : null;
        if (!$source || $source->get('class_key') !== 'sources.modFileMediaSource') {
            throw new ApiException('validation_failed', 'The product needs a stock local filesystem media source.');
        }
        $key = (string) $product->get('context_key');
        $siteContext = $modx->getContext($key);
        if (!$siteContext) { throw new ApiException('validation_failed', 'The product context could not be loaded.'); }
        $source->set('ctx', $key);
        $properties = $source->getPropertyList();
        $base = (string) ($properties['basePath'] ?? '');
        $basePath = !empty($properties['basePathRelative']) ? $siteContext->getOption('base_path', $modx->getOption('base_path')) . $base : $base;
        $lexical = rtrim(str_replace('\\', '/', $basePath), '/');
        $actual = realpath($basePath);
        $assets = realpath($modx->getOption('assets_path'));
        if ($base === '' || $actual === false || $assets === false || $lexical !== rtrim(str_replace('\\', '/', $actual), '/')
            || strpos($lexical . '/', rtrim(str_replace('\\', '/', $assets), '/') . '/') !== 0) {
            throw new ApiException('validation_failed', 'The native gallery source must have a canonical directory inside assets.');
        }
        $thumbnails = self::thumbnailConfig($properties);
        $root = FileRoot::fromLocalSource($context, $source);
        if ($root->path !== $lexical) { throw new ApiException('validation_failed', 'The native media source resolves to a different directory.'); }
        return new self($context, $product, $source, $root, $properties, $thumbnails);
    }

    private static function thumbnailConfig(array $properties) {
        $type = empty($properties['thumbnailType']) ? 'jpg' : (string) $properties['thumbnailType'];
        $raw = $properties['thumbnails'] ?? '';
        $options = $raw === '' || $raw === null ? array() : json_decode((string) $raw, true);
        if (!is_array($options) || count($options) > 8) { self::unsupportedThumb(); }
        if (!$options) { $options = array(array('w' => 120, 'h' => 90, 'f' => $type)); }
        $result = array();
        foreach ($options as $key => $option) {
            if (!is_array($option) || array_diff(array_keys($option), array('w', 'h', 'q', 'zc', 'bg', 'f', 'name'))) { self::unsupportedThumb(); }
            $w = self::thumbInteger($option['w'] ?? '', 1024);
            $h = self::thumbInteger($option['h'] ?? '', 1024);
            if (isset($option['q'])) { self::thumbInteger($option['q'], 100); }
            if (isset($option['zc']) && !in_array((string) $option['zc'], array('0', '1', '2', '3', 'T', 'C', 'B', 'L', 'R', 'TL', 'TR', 'BL', 'BR'), true)) { self::unsupportedThumb(); }
            if (isset($option['bg']) && !preg_match('/^[a-f0-9]{6}$/iD', (string) $option['bg'])) { self::unsupportedThumb(); }
            $format = empty($option['f']) ? $type : $option['f'];
            $name = empty($option['name']) ? (is_numeric($key) ? $w . 'x' . $h : $key) : $option['name'];
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]{1,32}$/D', $name) || !in_array($format, array('jpg', 'png'), true)
                || isset($result[strtolower($name)])) { self::unsupportedThumb(); }
            $result[strtolower($name)] = array($name, $format);
        }
        return array_column($result, 1, 0);
    }

    private static function thumbInteger($value, $max) {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,3}$/D', (string) $value) || (int) $value > $max) { self::unsupportedThumb(); }
        return (int) $value;
    }

    private static function unsupportedThumb() { throw new ApiException('validation_failed', 'The gallery uses an unsupported thumbnail configuration.'); }

    public function cover() {
        $rows = $this->select('msProductData', array('image', 'thumb'), array('id' => $this->id));
        if (count($rows) !== 1) { throw new ApiException('validation_failed', 'Product data is unavailable.'); }
        return $rows[0];
    }

    public function rows() {
        return $this->select('msProductFile', self::COLUMNS, array('product_id' => $this->id));
    }

    private function select($class, array $columns, array $criteria) {
        $modx = $this->context->modx;
        try {
            $query = $modx->newQuery($class);
            $query->select(array_map(array($modx, 'escape'), $columns));
            $query->where($criteria)->sortby($modx->escape('id'), 'ASC')->limit(self::MAX_FILES + 1);
            if (!$query->prepare() || !$query->stmt->execute()) { throw new \RuntimeException(); }
            $rows = $query->stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            throw new ApiException('validation_failed', 'Could not verify current gallery records.');
        }
        if (count($rows) > self::MAX_FILES) { throw new ApiException('validation_failed', 'The gallery exceeds the limit of 200 stored image and thumbnail rows.'); }
        return $rows;
    }

    public function relative(array $row) {
        $prefix = $this->id . '/';
        if ((int) $row['product_id'] !== $this->id || (int) $row['source'] !== (int) $this->source->get('id') || $row['type'] !== 'image'
            || !(preg_match('/^' . $this->id . '\/[A-Za-z0-9_]{1,32}\/$/D', $row['path']) || $row['path'] === $prefix)
            || ((int) $row['parent'] === 0) !== ($row['path'] === $prefix)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,119}\.(?:jpe?g|png)$/iD', $row['file'])) {
            throw new ApiException('validation_failed', 'A gallery record has an incompatible location, source or image type.');
        }
        return $row['path'] . $row['file'];
    }

    public function path($relative) {
        if (!is_string($relative) || !preg_match('/^' . $this->id . '\/(?:[A-Za-z0-9_]{1,32}\/)?[A-Za-z0-9][A-Za-z0-9_.-]{0,119}\.(?:jpe?g|png)$/iD', $relative)) {
            throw new ApiException('validation_failed', 'The gallery path is not a supported product image path.');
        }
        $path = $this->root->assertWritable($this->context, $relative);
        if (file_exists($path) && (!is_file($path) || @fileperms($path) === false || @stat($path)['nlink'] !== 1)) {
            throw new ApiException('validation_failed', 'A gallery file is not a regular owned file.');
        }
        return $path;
    }

    private function directory($relative) {
        if (!preg_match('/^' . $this->id . '(?:\/[A-Za-z0-9_]{1,32})?$/D', $relative)) {
            throw new ApiException('validation_failed', 'The gallery directory is outside the product layout.');
        }
        $path = $this->root->assertWritable($this->context, $relative);
        if (file_exists($path) && !is_dir($path)) { throw new ApiException('validation_failed', 'The product gallery path is not a directory.'); }
        return $path;
    }

    /** Returns declared file paths and verifies that native child removal cannot reach other products. */
    public function mapped(array $rows) {
        $ids = array();
        $paths = array();
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $path = $this->relative($row);
            if ($id < 1 || isset($paths[$path]) || isset($ids[$id])) {
                throw new ApiException('validation_failed', 'Gallery rows do not have unique IDs and file paths.');
            }
            $ids[$id] = (int) $row['parent'];
            $paths[$path] = true;
        }
        foreach ($ids as $parent) {
            if ($parent && (!isset($ids[$parent]) || $ids[$parent] !== 0)) {
                throw new ApiException('validation_failed', 'Gallery thumbnails must belong directly to a root image.');
            }
        }
        if ($ids) {
            foreach ($this->select('msProductFile', array('id', 'parent', 'product_id'), array('parent:IN' => array_keys($ids))) as $child) {
                if ((int) $child['product_id'] !== $this->id || !isset($ids[(int) $child['id']])) {
                    throw new ApiException('validation_failed', 'Another product links to this gallery’s image IDs.');
                }
            }
        }
        return $paths;
    }

    /** Check that native writes cannot overwrite unrelated files and that all stored rows have bytes. */
    public function captureFiles(array $mapped) {
        $files = array();
        $dirs = array();
        $todo = array((string) $this->id);
        $remaining = self::MAX_BYTES;
        while ($todo) {
            $relative = array_pop($todo);
            $dir = $this->directory($relative);
            if (!is_dir($dir)) { continue; }
            $dirs[] = $relative;
            foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $entry) {
                $name = $entry->getFilename();
                $path = $relative . '/' . $name;
                if ($entry->isDir() && !$entry->isLink() && $relative === (string) $this->id && preg_match('/^[A-Za-z0-9_]{1,32}$/D', $name)) {
                    $todo[] = $path;
                    if (count($todo) + count($dirs) > self::MAX_FILES) { throw new ApiException('validation_failed', 'Too many gallery directories.'); }
                    continue;
                }
                if (!isset($mapped[$path])) { throw new ApiException('validation_failed', 'The product directory contains an unmanaged gallery file.'); }
                $full = $this->path($path);
                $size = @filesize($full);
                if ($size === false || $size > $remaining) { throw new ApiException('validation_failed', 'The gallery bytes exceed the safe snapshot limit.'); }
                $bytes = @file_get_contents($full, false, null, 0, $size + 1);
                if (!is_string($bytes) || strlen($bytes) !== $size) { throw new ApiException('validation_failed', 'Could not read stable gallery bytes.'); }
                $files[$path] = array('content' => base64_encode($bytes), 'mode' => @fileperms($full) & 0777);
                $remaining -= $size;
            }
        }
        if (count($files) !== count($mapped)) { throw new ApiException('validation_failed', 'One or more gallery record files are missing.'); }
        ksort($files, SORT_STRING);
        sort($dirs, SORT_STRING);
        return array($files, $dirs);
    }

    /** Snapshot-only restore. Avoid native remove() cascades and keep unrelated product data. */
    public function restore(array $state, array $remove) {
        $modx = $this->context->modx;
        $this->mapped($state['rows']);
        foreach ($state['rows'] as $row) {
            $existing = $this->select('msProductFile', array('product_id'), array('id' => (int) $row['id']));
            if ($existing && (int) $existing[0]['product_id'] !== $this->id) {
                throw new ApiException('not_reversible', 'A recorded gallery ID now belongs to another product.');
            }
        }
        foreach (array_keys($state['files'] + $remove) as $path) { $this->path($path); }
        foreach ($state['dirs'] as $dir) { $this->directory($dir); }

        foreach ($state['dirs'] as $dir) {
            $path = $this->directory($dir);
            if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) { throw new \RuntimeException('Could not restore a gallery directory.'); }
        }
        foreach ($state['files'] as $path => $file) {
            $full = $this->path($path);
            $bytes = base64_decode($file['content'], true);
            if ($bytes === false) { throw new ApiException('not_reversible', 'A stored gallery file is invalid.'); }
            if (!is_file($full) || !hash_equals(hash('sha256', $bytes), (string) @hash_file('sha256', $full))) {
                try { Snapshot::writeFile($full, $bytes); } catch (\Throwable $e) { throw new \RuntimeException('Could not restore gallery bytes.'); }
            }
            if (!@chmod($full, $file['mode'])) { throw new \RuntimeException('Could not restore a gallery file mode.'); }
        }
        foreach (array_diff_key($remove, $state['files']) as $path => $ignored) {
            $full = $this->path($path);
            if (is_file($full) && !@unlink($full)) { throw new \RuntimeException('Could not remove a gallery file.'); }
            if (!in_array(dirname($path), $state['dirs'], true)) { FileSubject::pruneEmptyDirectories(dirname($full), $this->root->path . '/' . $this->id); }
        }
        if (!$state['dirs']) { @rmdir($this->root->path . '/' . $this->id); } // Only an empty product directory can disappear.
        try {
            $table = $modx->getTableName('msProductFile');
            $columns = array_map(array($modx, 'escape'), self::COLUMNS);
            Db::run($modx, 'DELETE FROM ' . $table . ' WHERE `product_id` = ?', array($this->id));
            foreach ($state['rows'] as $row) {
                $values = array();
                foreach (self::COLUMNS as $name) { $values[] = $row[$name]; }
                Db::run($modx, 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')', $values);
            }
            Db::run($modx, 'UPDATE ' . $modx->getTableName('msProductData') . ' SET `image` = ?, `thumb` = ? WHERE `id` = ?',
                array($state['cover']['image'], $state['cover']['thumb'], $this->id));
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not restore the stored gallery metadata.');
        }
        if ($this->context->modx->getCacheManager()) { $this->context->modx->getCacheManager()->refresh(); }
    }
}
