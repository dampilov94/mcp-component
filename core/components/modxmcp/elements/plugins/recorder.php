<?php
/* modxMCP: records changes made in the manager into the journal. All logic is in the component. */
$autoload = $modx->getOption('modxmcp.core_path', null, $modx->getOption('core_path') . 'components/modxmcp/') . 'src/autoload.php';
if (!is_file($autoload)) { return; }
require_once $autoload;
\ModxMCP\Manager\Recorder::handle($modx, $modx->event->name, $scriptProperties);
