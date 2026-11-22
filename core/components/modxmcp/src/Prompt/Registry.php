<?php
namespace ModxMCP\Prompt;

use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Draft\DraftPolicy;
use ModxMCP\Manifest;

/** The prompts this build provides. Add a class name here to add a scenario. */
final class Registry {
    const PROMPTS = array(
        StudySitePrompt::class,
        AuditPrompt::class,
        ChangePagePrompt::class,
        NewPageTypePrompt::class,
        FixErrorsPrompt::class,
    );

    private $prompts = null;

    /** @return Prompt[] keyed by name */
    public function all() {
        if ($this->prompts === null) {
            $this->prompts = array();
            foreach (self::PROMPTS as $class) {
                $prompt = new $class();
                $this->prompts[$prompt->name()] = $prompt;
            }
        }
        return $this->prompts;
    }

    /** @return Prompt|null */
    public function get($name) {
        $all = $this->all();
        return isset($all[$name]) ? $all[$name] : null;
    }

    /**
     * Why this token cannot use the prompt, or null when it can: array(reason, tool) where reason is
     * forbidden_scope or tool_unavailable (tool names the missing tool).
     */
    public function blockReason(Prompt $prompt, Context $context) {
        if (DraftPolicy::applies($context)) { return array('tool_unavailable', 'prompt'); }
        if (!Scope::allows($context->token['scope'], $prompt->requiredScope())) { return array('forbidden_scope', null); }
        foreach ($prompt->requiredTools() as $tool) {
            $name = strncmp($tool, Manifest::TOOL_PREFIX, strlen(Manifest::TOOL_PREFIX)) === 0 ? substr($tool, strlen(Manifest::TOOL_PREFIX)) : $tool;
            $action = $context->actions->get($name);
            if ($action === null || $context->actions->blockReason($action, $context) !== null) { return array('tool_unavailable', $tool); }
        }
        return null;
    }
}
