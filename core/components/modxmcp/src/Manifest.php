<?php
namespace ModxMCP;

/**
 * The tool list for one token. The client turns it into MCP tools; nothing about a tool is
 * defined on the client side.
 */
final class Manifest {
    const TOOL_PREFIX = 'modx_';

    /** @return array[] Tool entries this token may call. */
    public static function tools(Context $context) {
        $tools = array();
        foreach ($context->actions->all() as $action) {
            if (!$action->exposed() || $context->actions->blockReason($action, $context) !== null) { continue; }
            $readOnly = (bool) $action->readOnly();
            $tools[] = array(
                'name'         => self::TOOL_PREFIX . $action->name(),
                'action'       => $action->name(),
                'description'  => $action->description(),
                'inputSchema'  => $action->inputSchema(),
                'outputSchema' => $action->outputSchema(),
                'effect'       => array(
                    'read_only'   => $readOnly,
                    'destructive' => !$readOnly && (bool) $action->destructive(),
                    'reversible'  => $readOnly || (bool) $action->reversible(),
                    'open_world'  => (bool) $action->openWorld(),
                ),
                'scope'        => $action->scope(),
                'group'        => $action->group(),
            );
        }
        return $tools;
    }

    /** Changes whenever the tool list or any contract visible to this token changes. */
    public static function hash(array $tools) {
        return hash('sha256', json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
