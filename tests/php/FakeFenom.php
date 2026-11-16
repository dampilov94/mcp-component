<?php
// A stand-in for the part of pdoTools and Fenom that the syntax check uses: the service answers getFenom(), whose getRawTemplate()->source()
// throws Fenom's own CompileException (a real-looking message with the line) for the marker {BROKEN}, a RuntimeException (a problem of
// Fenom or the site, not of the text) for {EXPLODE}, and records what it was asked to compile.

namespace Fenom\Error {
    class CompileException extends \ErrorException {}
}

namespace {
    class FakeFenomTemplate {
        private $fenom;
        public function __construct(FakeFenom $fenom) { $this->fenom = $fenom; }
        public function source($name, $src, $compile = true) {
            $this->fenom->compiled[] = array($name, $src);
            $at = strpos($src, '{BROKEN}');
            if ($at !== false) {
                $line = substr_count(substr($src, 0, $at), "\n") + 1;
                throw new \Fenom\Error\CompileException("Unexpected token '}' in {$name} line {$line}, near '{BROKEN' <- there", 0, 1, $name, $line);
            }
            if (strpos($src, '{EXPLODE}') !== false) { throw new \RuntimeException('Template provider failed'); }
            return $this;
        }
    }

    class FakeFenom {
        public $compiled = array();
        public function getRawTemplate() { return new FakeFenomTemplate($this); }
    }

    class FakePdoTools {
        public $fenom;
        public $fails = false;
        public function __construct() { $this->fenom = new FakeFenom(); }
        public function getFenom() { return $this->fails ? false : $this->fenom; }
    }
}
