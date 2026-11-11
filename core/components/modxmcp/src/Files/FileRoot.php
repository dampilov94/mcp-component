<?php
namespace ModxMCP\Files;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;

/**
 * A directory the file tools may work in, and the rules for paths below it.
 * Two kinds of root exist: a local media source (readable and writable) and the code of an
 * installed component (read-only).
 */
final class FileRoot {
    /** File names that are never read or written, wherever they are. */
    const SECRET_NAMES = '/^(config\.core\.php|config\.inc\.php|\.env(\..*)?|\.htpasswd|\.git-credentials|id_rsa|id_ed25519)$/i';
    /** Extensions the web server may execute or that change how it behaves. */
    const EXECUTABLE = '/\.(php\d?|phtml|phar|pht|inc|cgi|pl|py|sh|htaccess|user\.ini)$|^\.htaccess$|^\.user\.ini$/i';

    /** @var string Stable prefix of journal keys, e.g. "s3". */
    public $id;
    /** @var string Absolute real path without a trailing slash. */
    public $path;
    /** @var bool */
    public $writable;
    /** @var string Human label, e.g. "media source 3 (Images)". */
    public $label;

    private $forbidden = array();

    private function __construct($id, $path, $writable, $label, array $forbidden) {
        $this->id = $id;
        $this->path = $path;
        $this->writable = $writable;
        $this->label = $label;
        $this->forbidden = $forbidden;
    }

    /** Arguments shared by the file tools. */
    public static function inputProperties() {
        return array(
            'source'    => array('type' => 'integer', 'minimum' => 1, 'description' => 'Media source id (see modx_list {kind: "media_source"}).'),
            'component' => array('type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$', 'description' => 'Instead of source: namespace of an installed component, to read its code. Read-only.'),
            'area'      => array('type' => 'string', 'enum' => array('core', 'assets'), 'description' => 'With component: core (default) or assets directory.'),
        );
    }

    /** Resolve the root named by the tool arguments. */
    public static function fromArgs(Context $context, array $args) {
        if (isset($args['source']) === isset($args['component'])) {
            throw new ApiException('validation_failed', 'Give exactly one of: source (media source id) or component (namespace).');
        }
        return isset($args['source']) ? self::forSource($context, $args['source']) : self::forComponent($context, $args['component'], isset($args['area']) ? $args['area'] : 'core');
    }

    /** Resolve a root from the prefix of a journal key ("s3"). */
    public static function fromId(Context $context, $id) {
        if (!preg_match('/^s([1-9][0-9]*)$/D', (string) $id, $match)) { throw new ApiException('not_found', "Unknown file root '{$id}'."); }
        return self::forSource($context, (int) $match[1]);
    }

    private static function real($path) {
        $real = realpath($path);
        return $real === false ? false : rtrim(str_replace('\\', '/', $real), '/');
    }

    private static function forSource(Context $context, $id) {
        $modx = $context->modx;
        $source = $modx->getObject('sources.modMediaSource', array('id' => (int) $id));
        if (!$source) { throw new ApiException('not_found', "No media source with id {$id}."); }
        if (stripos((string) $source->get('class_key'), 'modFileMediaSource') === false) {
            throw new ApiException('validation_failed', "Media source {$id} is not a local file source ({$source->get('class_key')}); the file tools only work on the server's own disk.");
        }
        $source->initialize();
        $base = (string) $source->getBasePath();
        $root = self::real($base === '' ? $modx->getOption('base_path') : $base);
        if ($root === false || !is_dir($root)) { throw new ApiException('validation_failed', "The directory of media source {$id} does not exist."); }

        $core = self::real($modx->getOption('core_path'));
        $site = self::real($modx->getOption('base_path'));
        // A source that spans the whole site or the core exposes code and configuration.
        $spansSite = $root === $site || ($core !== false && ($root === $core || strpos($core . '/', $root . '/') === 0 || strpos($root . '/', $core . '/') === 0));
        if ($spansSite && !$modx->getOption('modxmcp.allow_root_filesystem_read', null, false)) {
            throw new ApiException('unavailable', "Media source {$id} covers the site root or the MODX core. Access to it is off (modxmcp.allow_root_filesystem_read).", array('reason' => 'disabled_by_setting'));
        }
        $forbidden = array();
        if ($core !== false) { $forbidden[] = $core . '/config'; }
        $data = self::real($context->dataDir());
        if ($data !== false) { $forbidden[] = $data; }
        return new self('s' . (int) $id, $root, true, "media source {$id} ({$source->get('name')})", $forbidden);
    }

    private static function forComponent(Context $context, $namespace, $area) {
        $modx = $context->modx;
        if (!$modx->getCount('modNamespace', array('name' => $namespace))) { throw new ApiException('not_found', "No component with namespace '{$namespace}'."); }
        $base = $area === 'assets' ? $modx->getOption('assets_path') : $modx->getOption('core_path');
        $root = self::real(rtrim($base, '/\\') . '/components/' . $namespace);
        if ($root === false || !is_dir($root)) { throw new ApiException('not_found', "Component '{$namespace}' has no {$area} directory."); }
        return new self('c:' . $namespace . ':' . $area, $root, false, "component {$namespace} ({$area})", array());
    }

    /** Clean a relative path: forward slashes, no traversal, no absolute or stream paths. "" is the root. */
    public static function normalize($path) {
        $path = (string) $path;
        if (strpos($path, "\0") !== false || strpos($path, '://') !== false || preg_match('#^([A-Za-z]:|[\\\\/])#', $path)) {
            throw new ApiException('validation_failed', 'Paths must be relative to the root, without a drive, leading slash or scheme.');
        }
        $parts = array();
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { throw new ApiException('validation_failed', 'Path traversal ("..") is not allowed.'); }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    /**
     * Absolute path of a relative path below this root. The real location (symlinks resolved)
     * of the target, or of its nearest existing ancestor, must stay inside the root.
     */
    public function resolve($relative) {
        $relative = self::normalize($relative);
        $target = $relative === '' ? $this->path : $this->path . '/' . $relative;
        $probe = $target;
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) { break; }
            $probe = $parent;
        }
        $real = self::real($probe);
        if ($real === false || ($real !== $this->path && strpos($real . '/', $this->path . '/') !== 0)) {
            throw new ApiException('validation_failed', 'The path leaves the root (through a symbolic link).');
        }
        // Use the real location of what exists, so guards see through links.
        $final = $probe === $target ? $real : $real . substr($target, strlen($probe));
        foreach ($this->forbidden as $forbidden) {
            if ($final === $forbidden || strpos($final . '/', $forbidden . '/') === 0) {
                throw new ApiException('validation_failed', 'This location holds site configuration or modxMCP data and is not accessible.');
            }
        }
        if ($relative !== '' && preg_match(self::SECRET_NAMES, basename($final))) {
            throw new ApiException('validation_failed', 'Files with credentials are not accessible.');
        }
        return $final;
    }

    /** Check that this token may write the given relative path here. */
    public function assertWritable(Context $context, $relative) {
        if (!$this->writable) { throw new ApiException('validation_failed', ucfirst($this->label) . ' is read-only.'); }
        if (self::normalize($relative) === '') { throw new ApiException('validation_failed', 'The root itself cannot be changed.'); }
        if (preg_match(self::EXECUTABLE, basename($relative)) && !Scope::allows($context->token['scope'], Scope::DEVELOP)) {
            throw new ApiException('forbidden_scope', "Writing server-executed files such as '" . basename($relative) . "' requires the 'develop' scope; this token has '{$context->token['scope']}'.");
        }
    }

    /**
     * Relative paths of every file below a directory. Symbolic links inside the tree are
     * refused: following them could reach files outside the root, and removing them is not
     * something a snapshot can undo.
     */
    public function filesBelow($relative, $limit = 500) {
        $start = $this->resolve($relative);
        $base = self::normalize($relative);
        $out = array();
        $stack = array(array($start, $base));
        while ($stack) {
            list($dir, $rel) = array_pop($stack);
            foreach (array_diff((array) scandir($dir), array('.', '..')) as $name) {
                $full = $dir . '/' . $name;
                $child = $rel === '' ? $name : $rel . '/' . $name;
                if (is_link($full)) { throw new ApiException('validation_failed', "'{$child}' is a symbolic link; a directory containing links cannot be changed as a whole."); }
                if (is_dir($full)) { $stack[] = array($full, $child); continue; }
                $out[] = $child;
                if (count($out) > $limit) { throw new ApiException('validation_failed', "The directory holds more than {$limit} files; change it in parts."); }
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    public static function isExecutable($relative) {
        return (bool) preg_match(self::EXECUTABLE, basename($relative));
    }
}
