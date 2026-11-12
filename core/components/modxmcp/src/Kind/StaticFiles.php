<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Write\Transaction;

/** Files owned by automatic/static conversion, always reserved without overwriting. */
final class StaticFiles {
    public static function makeStatic(Context $context, Transaction $tx, Kind $kind, $id): array {
        $object = Objects::resolveKey($context, $kind, $id);
        if ($object->get('static')) { return array('status' => 'already_static', 'static_file' => (string) $object->get('static_file')); }
        $map = array('chunk' => array('chunks', 'tpl'), 'snippet' => array('snippets', 'php'),
            'template' => array('templates', 'tpl'), 'plugin' => array('plugins', 'php'));
        if (!isset($map[$kind->name()])) { throw new ApiException('validation_failed', 'This kind cannot be made static.'); }
        list($folder, $ext) = $map[$kind->name()];
        $code = $kind->effectiveContent($object)[0];
        $slug = trim(substr(preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $kind->value($object, $kind->nameField())), 0, 120), '-._');
        if ($slug === '') { $slug = $kind->name(); }
        $root = rtrim($context->modx->getOption('core_path'), '/\\') . '/elements';
        $dir = $root . '/' . $folder;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { throw new \RuntimeException('Cannot create static element directory.'); }
        if (strpos(realpath($dir) . '/', realpath($root) . '/') !== 0) { throw new ApiException('validation_failed', 'Static directory resolves outside core/elements.'); }
        $path = null;
        $handle = null;
        try {
            for ($i = 0; $i <= 20; $i++) {
                $file = $slug . '-' . $id . ($i ? '-' . $i : '') . '.' . $ext;
                $candidate = $dir . '/' . $file;
                $handle = @fopen($candidate, 'x+b');
                if ($handle) { $path = $candidate; break; }
                if (!file_exists($candidate) && !is_link($candidate)) { throw new \RuntimeException('Cannot reserve a static file.'); }
            }
            if (!$handle) { throw new ApiException('validation_failed', 'No unused static filename through suffix 20.'); }
            $written = 0;
            $data = $kind->fileContent($code);
            while ($written < strlen($data)) {
                $bytes = fwrite($handle, substr($data, $written));
                if ($bytes === false || $bytes === 0) { throw new \RuntimeException('Cannot write static file.'); }
                $written += $bytes;
            }
            if (!fflush($handle)) { throw new \RuntimeException('Cannot flush static file.'); }
            fclose($handle);
            $handle = null;
            $stored = '[[++core_path]]elements/' . $folder . '/' . $file;
            Objects::save($context, $tx, $kind, $id, array('static' => 1, 'static_file' => $stored, 'source' => 0), array());
            $content = $kind->effectiveContent(Objects::resolveKey($context, $kind, $id));
            if (!$content[1] || $content[3] || $content[0] !== $code) { throw new \RuntimeException('Static conversion verification failed.'); }
            return array('status' => 'converted', 'static_file' => $stored);
        } catch (\Throwable $e) {
            if (is_resource($handle)) { fclose($handle); }
            if ($path !== null) { self::removeManaged($context->modx, $path); }
            throw $e;
        }
    }
    public static function isManaged(\modX $modx, $absolutePath): bool {
        if (!is_string($absolutePath) || $absolutePath === '') { return false; }
        $root = realpath(rtrim($modx->getOption('core_path'), '/\\') . '/elements');
        $real = realpath($absolutePath);
        return $root !== false && $real !== false && strpos($real, $root . DIRECTORY_SEPARATOR) === 0;
    }
    public static function removeManaged(\modX $modx, $absolutePath): void {
        try {
            if (!self::isManaged($modx, $absolutePath)) { return; }
            $path = realpath($absolutePath);
            $root = realpath(rtrim($modx->getOption('core_path'), '/\\') . '/elements');
            if (!@unlink($path)) { throw new \RuntimeException('Cannot remove managed static file: ' . $path); }
            $dir = dirname($path);
            while ($dir !== $root && strpos($dir, $root . DIRECTORY_SEPARATOR) === 0) {
                $entries = @scandir($dir);
                if ($entries === false) { throw new \RuntimeException('Cannot inspect static directory: ' . $dir); }
                if (count($entries) > 2) { break; }
                if (!@rmdir($dir)) { throw new \RuntimeException('Cannot remove empty static directory: ' . $dir); }
                $dir = dirname($dir);
            }
        } catch (\Throwable $e) {
            try { $modx->log(\modX::LOG_LEVEL_ERROR, '[modxmcp] ' . $e->getMessage()); }
            catch (\Throwable $ignored) { /* Cleanup must not fail the object operation. */ }
        }
    }
}
