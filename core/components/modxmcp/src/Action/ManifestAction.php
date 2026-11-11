<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Kernel;
use ModxMCP\Manifest;

/** Protocol action: the tool list and contracts for the calling token. Not a tool itself. */
final class ManifestAction extends Action {
    const INSTRUCTIONS = 'Tools for one live MODX Revolution site. modx_site_info gives the overview; '
        . 'modx_capabilities explains what this token may do when something is unavailable. Objects are addressed '
        . 'by kind; modx_describe returns the fields of a kind. Every change is journaled and can be reverted with '
        . 'modx_undo. modx_help {topic: "getting_started"} is the working guide.';

    public function name() { return 'manifest'; }

    public function description() { return 'Tool manifest for this token.'; }

    public function exposed() { return false; }

    public function run(array $args, Context $context) {
        $tools = Manifest::tools($context);
        return array(
            'protocol'       => Kernel::PROTOCOL,
            'server_version' => Kernel::VERSION,
            'hash'           => Manifest::hash($tools),
            'instructions'   => self::INSTRUCTIONS,
            'token'          => array(
                'label'     => $context->token['label'],
                'scope'     => $context->token['scope'],
                'plan_only' => $context->token['plan_only'],
            ),
            'error_codes'    => array_keys(ApiException::CODES),
            'tools'          => $tools,
        );
    }
}
