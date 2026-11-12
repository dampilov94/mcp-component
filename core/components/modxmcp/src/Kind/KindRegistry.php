<?php
namespace ModxMCP\Kind;

use ModxMCP\Auth\Scope;
use ModxMCP\Context;

/** The object kinds this build knows. Add a class name here to add a kind. */
final class KindRegistry {
    const KINDS = array(
        ChunkKind::class,
        SnippetKind::class,
        TemplateKind::class,
        PluginKind::class,
        TvKind::class,
        CategoryKind::class,
        ResourceKind::class,
        MediaSourceKind::class,
        UserGroupKind::class,
        RoleKind::class,
        ResourceGroupKind::class,
        SystemSettingKind::class,
        ContextKind::class,
        ContextSettingKind::class,
        NamespaceKind::class,
        UserKind::class,
    );

    private $kinds = null;

    /** @return Kind[] keyed by kind name */
    public function all() {
        if ($this->kinds === null) {
            $this->kinds = array();
            foreach (self::KINDS as $class) {
                $kind = new $class();
                $this->kinds[$kind->name()] = $kind;
            }
        }
        return $this->kinds;
    }

    /** Add a kind instance to this registry. For tests only; production never calls it. */
    public function register(Kind $kind) {
        $this->all();
        $this->kinds[$kind->name()] = $kind;
    }

    /** @return Kind|null */
    public function get($name) {
        $all = $this->all();
        return isset($all[$name]) ? $all[$name] : null;
    }

    /** Why this token cannot read the kind, or null when it can. */
    public function blockReason(Kind $kind, Context $context) {
        if (!Scope::allows($context->token['scope'], $kind->readScope())) { return 'forbidden_scope'; }
        return $context->capabilities->groupBlockReason($kind->group(), $context->token);
    }
}
