<?php
/** PSR-4 style loader for the ModxMCP\ namespace (file and directory names are case-sensitive). */
spl_autoload_register(function ($class) {
    if (strncmp($class, 'ModxMCP\\', 8) !== 0) { return; }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    if (is_file($file)) { require_once $file; }
});
