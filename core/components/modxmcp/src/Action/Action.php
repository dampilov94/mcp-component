<?php
namespace ModxMCP\Action;

use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Schema;

/**
 * One API action. An action declares its whole contract here; the manifest, argument
 * validation, access checks and client annotations are all derived from these methods.
 */
abstract class Action {
    /** Action name without the tool prefix, e.g. "site_info". */
    abstract public function name();

    /** Short description shown to the model. Details belong in describe/help. */
    abstract public function description();

    /** Input schema (Schema subset). */
    public function inputSchema() {
        return Schema::obj();
    }

    /** The `key` argument of every tool that picks one object: for kinds without a numeric id. */
    protected static function keyArgument() {
        return Schema::str('Key of the object, for kinds without a numeric id (see modx_describe).', array('maxLength' => 255));
    }

    /** Output schema. Every action returns a JSON object. */
    public function outputSchema() {
        return Schema::obj(array(), array(), true);
    }

    /** Minimum token scope. */
    public function scope() {
        return Scope::READ;
    }

    /** Capability group; toggleable groups can be switched off per site or per token. */
    public function group() {
        return 'core';
    }

    /** False for actions that change the site; those run under request-ID replay protection. */
    public function readOnly() {
        return true;
    }

    public function destructive() {
        return false;
    }

    /** Whether the change can be undone from the journal. Meaningless for read-only actions. */
    public function reversible() {
        return true;
    }

    /** Whether the action reaches systems other than this site. */
    public function openWorld() {
        return false;
    }

    /** False for protocol-level actions that are not offered to the model as tools. */
    public function exposed() {
        return true;
    }

    /** A reason code when a site setting gates this action off, otherwise null. */
    public function unavailableReason(Context $context) {
        return null;
    }

    /** @return array JSON object */
    abstract public function run(array $args, Context $context);
}
