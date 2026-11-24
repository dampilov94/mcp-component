<?php
/* modxMCP: records changes made in the manager into the journal, and lets modx_check_page render an unpublished page. All logic is in the component. */
if ($modx->event->name === 'OnWebPageInit' && empty($_SERVER['HTTP_X_MCP_RENDER'])) { return; }
$autoload = $modx->getOption('modxmcp.core_path', null, $modx->getOption('core_path') . 'components/modxmcp/') . 'src/autoload.php';
if (!is_file($autoload)) { return; }
require_once $autoload;
if ($modx->event->name === 'OnWebPageInit') { \ModxMCP\Render\RenderGate::handle($modx); return; }
\ModxMCP\Manager\Recorder::handle($modx, $modx->event->name, $scriptProperties);
