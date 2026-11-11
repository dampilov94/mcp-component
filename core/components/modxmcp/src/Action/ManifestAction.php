<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Kernel;
use ModxMCP\Manifest;

/** Protocol action: the tool list and contracts for the calling token. Not a tool itself. */
final class ManifestAction extends Action {
    const INSTRUCTIONS = 'Tools for one MODX Revolution site. Start with modx_site_info for an overview and '
        . 'modx_capabilities to see what this token may do. Objects are addressed by kind; '
        . 'modx_describe returns the fields of a kind. modx_help serves the built-in guide.';

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
