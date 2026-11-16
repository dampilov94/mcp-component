<?php
namespace ModxMCP\Code;

/**
 * Compiles a Fenom text with the site's own Fenom (the one pdoTools builds), without executing or caching it, to find
 * syntax errors before a visitor does. Nothing here changes the site.
 *
 * pdoTools compiles a chunk with $fenom->getRawTemplate()->source($name, $content, true) in pdoTools::_compileChunk(); that
 * method logs a compile error and returns an empty template instead of throwing, so the same call is made here directly.
 * Only Fenom\Error\CompileException (what Fenom throws for a syntax error, an unknown tag or modifier) is an error of the
 * text; any other exception is a problem of Fenom or of the site and never blocks anything: it is logged and the text passes.
 */
final class FenomCheck {
    private $modx;
    private $syntax;
    private $fenom = null;
    private $tried = false;

    public function __construct(\modX $modx) {
        $this->modx = $modx;
        $this->syntax = Fenom::syntaxOf($modx);
    }

    /** Fenom is in use for chunks and its compiler can be had. */
    public function available() {
        if (!Fenom::active($this->modx)) { return false; }
        return $this->fenom() !== null;
    }

    private function fenom() {
        if ($this->tried) { return $this->fenom; }
        $this->tried = true;
        try {
            $service = $this->modx->getService('pdoTools');
            if (!is_object($service)) {
                $core = $this->modx->getOption('pdotools.core_path', null, rtrim((string) $this->modx->getOption('core_path'), '/\\') . '/components/pdotools/');
                $service = $this->modx->getService('pdoTools', 'pdoTools', rtrim($core, '/\\') . '/model/pdotools/');
            }
            if (is_object($service) && method_exists($service, 'getFenom')) {
                $fenom = $service->getFenom();
                if (is_object($fenom) && method_exists($fenom, 'getRawTemplate')) { $this->fenom = $fenom; }
            }
        } catch (\Throwable $e) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[modxmcp] Fenom could not be loaded for the syntax check: ' . $e->getMessage());
        }
        return $this->fenom;
    }

    /**
     * @param string $text the code as stored (line numbers refer to it)
     * @param string $name how Fenom's messages name the template
     * @return array|null null when there is nothing wrong or no check can be made; else ['message' => …, 'line' => int]
     */
    public function check($text, $name) {
        if (!Fenom::looksLike($text, $this->syntax, true) || !$this->available()) { return null; }
        try {
            $this->fenom->getRawTemplate()->source($name, (string) $text, true);
            return null;
        } catch (\Throwable $e) {
            if ($e instanceof \Fenom\Error\CompileException) {
                return array('message' => $e->getMessage(), 'line' => max(1, (int) $e->getLine()));
            }
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[modxmcp] The Fenom syntax check of ' . $name . ' failed and was ignored: ' . $e->getMessage());
            return null;
        }
    }
}
