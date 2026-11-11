<?php
namespace ModxMCP\Action;

use ModxMCP\Capabilities;
use ModxMCP\Context;
use ModxMCP\Kernel;
use ModxMCP\Manifest;
use ModxMCP\Schema;

final class CapabilitiesAction extends Action {
    public function name() { return 'capabilities'; }

    public function description() {
        return 'What this token can do on this site: available tools and object kinds, and for everything '
            . 'unavailable the reason (scope too low, group switched off, add-on not installed, setting off).';
    }

    public function outputSchema() {
        $names = Schema::arr(Schema::str());
        $blocked = Schema::arr(Schema::obj(array('name' => Schema::str(), 'reason' => Schema::str()), array('name', 'reason')));
        return Schema::obj(array(
            'server_version'    => Schema::str(),
            'token'             => Schema::obj(array(), array(), true),
            'tools'             => $names,
            'unavailable_tools' => $blocked,
            'kinds'             => $names,
            'unavailable_kinds' => $blocked,
            'groups'            => Schema::obj(array(), array(), true),
            'integrations'      => Schema::arr(Schema::obj(array(), array(), true)),
        ), array('server_version', 'token', 'tools', 'unavailable_tools', 'kinds', 'unavailable_kinds', 'groups', 'integrations'), true);
    }

    public function run(array $args, Context $context) {
        $tools = array();
        $blockedTools = array();
        foreach ($context->actions->all() as $action) {
            if (!$action->exposed()) { continue; }
            $reason = $context->actions->blockReason($action, $context);
            if ($reason === null) { $tools[] = Manifest::TOOL_PREFIX . $action->name(); }
            else { $blockedTools[] = array('name' => Manifest::TOOL_PREFIX . $action->name(), 'reason' => $reason); }
        }
        $kinds = array();
        $blockedKinds = array();
        foreach ($context->kinds->all() as $kind) {
            $reason = $context->kinds->blockReason($kind, $context);
            if ($reason === null) { $kinds[] = $kind->name(); }
            else { $blockedKinds[] = array('name' => $kind->name(), 'reason' => $reason); }
        }
        return array(
            'server_version'    => Kernel::VERSION,
            'token'             => array(
                'label'         => $context->token['label'],
                'scope'         => $context->token['scope'],
                'plan_only'     => $context->token['plan_only'],
                'denied_groups' => $context->token['denied_groups'],
                'expires_at'    => $context->token['expires_at'],
            ),
            'tools'             => $tools,
            'unavailable_tools' => $blockedTools,
            'kinds'             => $kinds,
            'unavailable_kinds' => $blockedKinds,
            'groups'            => array(
                'toggleable' => Capabilities::TOGGLEABLE,
                'disabled'   => $context->capabilities->disabledGroups(),
            ),
            'integrations'      => $context->capabilities->integrationsReport(),
        );
    }
}
