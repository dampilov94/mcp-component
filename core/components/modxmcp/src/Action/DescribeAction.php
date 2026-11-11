<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Schema;

final class DescribeAction extends Action {
    public function name() { return 'describe'; }

    public function description() {
        return 'Schema of an object kind: fields, relations and special operations. Without `kind`, lists '
            . 'the kinds available to this token.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'kind' => Schema::str('Kind name, e.g. "chunk". Omit to list kinds.', array('pattern' => '^[a-z][a-z0-9_]{0,63}$')),
        ));
    }

    public function run(array $args, Context $context) {
        if (!isset($args['kind'])) {
            $kinds = array();
            foreach ($context->kinds->all() as $kind) {
                if ($context->kinds->blockReason($kind, $context) !== null) { continue; }
                $kinds[] = array('kind' => $kind->name(), 'label' => $kind->label(), 'group' => $kind->group());
            }
            return array('kinds' => $kinds);
        }
        $kind = $context->kinds->get($args['kind']);
        if (!$kind) {
            throw new ApiException('not_found', "Unknown kind '{$args['kind']}'. Call modx_describe without arguments to list kinds.");
        }
        $reason = $context->kinds->blockReason($kind, $context);
        if ($reason !== null) {
            throw new ApiException('unavailable', "Kind '{$args['kind']}' is unavailable: {$reason}.", array('reason' => $reason));
        }
        return $kind->describe();
    }
}
