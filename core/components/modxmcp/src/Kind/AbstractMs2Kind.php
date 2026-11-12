<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Integration\MiniShop2;

/**
 * What the miniShop2 kinds have in common: the group, the xPDO package registered before an action works with them, and
 * the add-on's own processors (found through `processors_path`).
 */
abstract class AbstractMs2Kind extends Kind {
    /** @var Context|null */
    protected $context;

    public function group() { return MiniShop2::GROUP; }
    public function processorExtras($op) { return array(); }

    public function attach(Context $context) { $this->context = $context; }

    /** Registers the models. Reading the journal must work without miniShop2, so a missing add-on is not an error here. */
    public function prepare(\modX $modx) {
        try { MiniShop2::load($modx); } catch (\RuntimeException $e) { /* assertUndoable() and the actions report it where it matters */ }
    }

    /** An object cannot be put back (or taken away) without the miniShop2 models. */
    public function assertUndoable(\modX $modx, $key, $target) {
        try { MiniShop2::load($modx); } catch (\RuntimeException $e) { throw new ApiException('not_reversible', 'miniShop2 is not installed; its objects cannot be restored.'); }
    }

    /** The processors are miniShop2's own. */
    public function processorOptions() {
        return $this->context ? array('processors_path' => MiniShop2::processorsPath($this->context->modx)) : array();
    }
}
