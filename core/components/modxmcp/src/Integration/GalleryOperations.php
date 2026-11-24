<?php
namespace ModxMCP\Integration;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Kind\Objects;
use ModxMCP\Write\Transaction;

/** Native operations whose product-local effects are captured by GallerySubject. */
final class GalleryOperations {
    const MAX_INPUT_BYTES = 2097152;
    const MAX_DIMENSION = 4096;

    public static function planUpload(Context $context, $product, array $args) {
        $subject = new GallerySubject($context, $product);
        list($bytes, $paths) = self::uploadPlan($subject->storage, $args);
        $subject->capture();
        return array('filename' => $args['filename'], 'bytes' => strlen($bytes), 'files' => count($paths), 'reversible' => true);
    }

    public static function upload(Context $context, Transaction $tx, $product, array $args) {
        $subject = new GallerySubject($context, $product);
        $storage = $subject->storage;
        list($bytes, $paths) = self::uploadPlan($storage, $args);
        $before = $tx->touchSubject($subject);
        $subject->expectFiles($paths);
        self::nativeUpload($context, $storage->id, $args['filename'], $bytes);

        $after = $subject->capture();
        $beforeRows = self::indexed($before['rows']);
        $afterRows = self::indexed($after['rows']);
        $added = array_diff_key($afterRows, $beforeRows);
        $rootId = null;
        $produced = array();
        foreach ($added as $row) {
            $produced[] = $storage->relative($row);
            if ((int) $row['parent'] === 0) {
                if ($rootId !== null || $row['type'] !== 'image' || $storage->relative($row) !== $paths[0]) {
                    throw new ApiException('internal_error', 'miniShop2 did not create the expected gallery image.');
                }
                $rootId = (int) $row['id'];
            }
        }
        foreach ($added as $row) {
            if ((int) $row['id'] !== $rootId && ((int) $row['parent'] !== $rootId || $row['type'] !== 'image')) {
                throw new ApiException('internal_error', 'miniShop2 stored an unexpected thumbnail.');
            }
        }
        sort($produced, SORT_STRING);
        $expected = $paths;
        sort($expected, SORT_STRING);
        $newFiles = array_diff_key($after['files'], $before['files']);
        if ($rootId === null || $produced !== $expected || count($newFiles) !== count($paths)
            || array_diff_key(array_flip($paths), $newFiles)
            || ($newFiles[$paths[0]]['content'] ?? null) !== base64_encode($bytes)) {
            throw new ApiException('internal_error', 'miniShop2 did not save the original and all configured thumbnails.');
        }
        self::assertUnchanged($beforeRows, array_intersect_key($afterRows, $beforeRows), $before['files'], array_intersect_key($after['files'], $before['files']));
        return array('image_id' => $rootId, 'summary' => 'upload gallery image ' . $rootId . ' on product ' . $storage->id);
    }

    public static function planDelete(Context $context, $product, array $args) {
        $subject = new GallerySubject($context, $product);
        $rows = self::imageFiles($context, $subject->storage, $args['image_id']);
        $subject->capture();
        return array('image_id' => $args['image_id'], 'files' => count($rows), 'reversible' => true);
    }

    public static function delete(Context $context, Transaction $tx, $product, array $args) {
        $subject = new GallerySubject($context, $product);
        $storage = $subject->storage;
        $before = $tx->touchSubject($subject);
        $removed = self::imageFiles($context, $storage, $args['image_id']);
        $paths = array_map(array($storage, 'relative'), $removed);
        $subject->expectFiles($paths);

        // The native model removes the image and children. The stock manager processor also
        // removes a directory in the default media source on the last image, which is not this operation's scope.
        MiniShop2::service($context->modx);
        $row = $context->modx->getObject('msProductFile', array('id' => $args['image_id'], 'product_id' => $storage->id), false);
        if (!$row || !$row->remove()) { throw new ApiException('internal_error', 'miniShop2 could not remove the gallery image.'); }
        $storage->product->updateProductImage();

        foreach ($paths as $relative) {
            $path = $storage->path($relative);
            clearstatcache(true, $path);
            if (file_exists($path) || is_link($path)) {
                throw new ApiException('internal_error', 'miniShop2 did not remove every selected gallery file.');
            }
        }
        $after = $subject->capture();
        self::assertUnchanged(array_diff_key(self::indexed($before['rows']), $removed), self::indexed($after['rows']),
            array_diff_key($before['files'], array_flip($paths)), $after['files']);
        return array('image_id' => $args['image_id'], 'summary' => 'delete gallery image ' . $args['image_id'] . ' on product ' . $storage->id);
    }

    private static function uploadPlan(GalleryStorage $storage, array $args) {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}\.(jpe?g|png)$/iD', $args['filename'], $match)) {
            throw new ApiException('validation_failed', 'Give a simple JPEG or PNG filename.'); }
        if (strlen($args['content_base64']) > 4 * (int) ceil(self::MAX_INPUT_BYTES / 3)) {
            throw new ApiException('validation_failed', 'The image is too large.'); }
        $bytes = base64_decode($args['content_base64'], true);
        $extension = strtolower($match[1]);
        $imageExtensions = array_map('trim', explode(',', strtolower($storage->properties['imageExtensions'] ?? '')));
        $allowed = strtolower($storage->properties['allowedFileTypes'] ?? '');
        if (!in_array($extension, $imageExtensions, true)
            || ($allowed !== '' && !in_array($extension, array_map('trim', explode(',', $allowed)), true))) {
            throw new ApiException('validation_failed', 'This media source does not accept that gallery image format.');
        }
        $image = $bytes !== false ? @getimagesizefromstring($bytes) : false;
        $type = $extension === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
        if (!$image || $image[2] !== $type || $image[0] < 1 || $image[1] < 1
            || max($image[0], $image[1]) > self::MAX_DIMENSION || strlen($bytes) > self::MAX_INPUT_BYTES) {
            throw new ApiException('validation_failed', 'The filename and supported JPEG or PNG image bytes must match, within 2 MiB and 4096 pixels per side.');
        }
        $hash = sha1(substr($bytes, 0, 80000)); // miniShop2 3.0.7 hashes this prefix of the uploaded file.
        $basename = $hash;
        if (($storage->properties['imageNameType'] ?? '') === 'friendly') {
            $basename = $storage->product->cleanAlias(strtolower(pathinfo($args['filename'], PATHINFO_FILENAME)));
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}$/D', $basename)) {
                throw new ApiException('validation_failed', 'The media source could not derive a safe image filename.');
            }
        }
        $paths = array($storage->id . '/' . $basename . '.' . $extension);
        foreach ($storage->thumbnails as $folder => $format) { $paths[] = $storage->id . '/' . $folder . '/' . $basename . '.' . $format; }
        foreach ($storage->rows() as $row) {
            if (in_array($storage->relative($row), $paths, true) || ((int) $row['parent'] === 0 && $row['hash'] === $hash)) {
                throw new ApiException('validation_failed', 'A gallery file with this content or destination already exists.');
            }
        }
        foreach ($paths as $relative) {
            $path = $storage->path($relative);
            if (file_exists($path) || is_link($path)) { throw new ApiException('validation_failed', 'A gallery destination is occupied.'); }
        }
        return array($bytes, $paths);
    }

    private static function nativeUpload(Context $context, $product, $filename, $bytes) {
        $directory = $context->dataDir() . '/gallery-stage-' . bin2hex(random_bytes(12));
        if (!@mkdir($directory, 0700, true)) { throw new \RuntimeException('Cannot prepare private gallery staging.'); }
        $file = $directory . '/' . $filename;
        $files = $_FILES;
        try {
            if (@file_put_contents($file, $bytes, LOCK_EX) !== strlen($bytes) || !@chmod($file, 0600)) {
                throw new \RuntimeException('Cannot stage the gallery image.');
            }
            $_FILES = array();
            MiniShop2::service($context->modx);
            try {
                Objects::process($context->modx, 'mgr/gallery/upload', array('id' => $product, 'file' => $file),
                    array('processors_path' => MiniShop2::processorsPath($context->modx)));
            } catch (\Throwable $e) {
                // Vendor errors can include private paths and media settings.
                throw new ApiException('validation_failed', 'The native miniShop2 gallery upload did not complete.');
            }
        } finally {
            $_FILES = $files;
            if (is_file($file)) { @unlink($file); }
            @rmdir($directory);
        }
    }

    private static function imageFiles(Context $context, GalleryStorage $storage, $id) {
        $rows = self::indexed($storage->rows());
        $root = $rows[$id] ?? null;
        if (!$root || (int) $root['parent'] !== 0 || $root['type'] !== 'image') {
            throw new ApiException('validation_failed', 'Choose an original image in this product gallery.');
        }
        $removed = array($id => $root);
        foreach (self::children($context, array($id)) as $child) {
            $childId = (int) $child['id'];
            if ((int) $child['product_id'] !== (int) $storage->id || !isset($rows[$childId]) || $rows[$childId]['type'] !== 'image') {
                throw new ApiException('validation_failed', 'This image has an unsupported child record.');
            }
            $removed[$childId] = $rows[$childId];
        }
        $children = array_diff(array_keys($removed), array($id));
        if ($children && self::children($context, array_values($children))) {
            throw new ApiException('validation_failed', 'This image has unsupported nested gallery records.');
        }
        foreach ($removed as $row) {
            if (!is_file($storage->path($storage->relative($row)))) {
                throw new ApiException('validation_failed', 'An original or thumbnail is missing.');
            }
        }
        return $removed;
    }

    private static function children(Context $context, array $ids) {
        $query = $context->modx->newQuery('msProductFile');
        $query->select(array('id', 'product_id'));
        $query->where(array('parent:IN' => $ids));
        if (!$query->prepare() || !$query->stmt->execute()) { throw new \RuntimeException('Cannot verify gallery child records.'); }
        return $query->stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function indexed(array $rows) {
        $byId = array_column($rows, null, 'id');
        ksort($byId, SORT_NUMERIC);
        return $byId;
    }

    private static function assertUnchanged(array $beforeRows, array $afterRows, array $beforeFiles, array $afterFiles) {
        foreach ($beforeRows as &$row) { unset($row['rank']); }
        unset($row);
        foreach ($afterRows as &$row) { unset($row['rank']); }
        unset($row);
        ksort($beforeFiles, SORT_STRING);
        ksort($afterFiles, SORT_STRING);
        if ($beforeRows !== $afterRows || $beforeFiles !== $afterFiles) {
            throw new ApiException('internal_error', 'miniShop2 did not preserve the rest of this gallery.');
        }
    }
}
