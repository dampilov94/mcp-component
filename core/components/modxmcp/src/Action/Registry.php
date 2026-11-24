<?php
namespace ModxMCP\Action;

use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Draft\DraftPolicy;

/** The list of actions this build provides. Add a class name here to add a tool. */
final class Registry {
    const ACTIONS = array(
        ManifestAction::class,
        PromptAction::class,
        CapabilitiesAction::class,
        SiteInfoAction::class,
        DescribeAction::class,
        HelpAction::class,
        ListAction::class,
        GetAction::class,
        CreateAction::class,
        UpdateAction::class,
        DeleteAction::class,
        DuplicateAction::class,
        InvokeAction::class,
        ResourceTreeAction::class,
        BulkResourcesAction::class,
        ViewCodeAction::class,
        EditCodeAction::class,
        SearchAction::class,
        ReplaceAction::class,
        FindUsagesAction::class,
        DependencyGraphAction::class,
        ListFilesAction::class,
        ReadFileAction::class,
        SearchFilesAction::class,
        WriteFileAction::class,
        EditFileAction::class,
        MovePathAction::class,
        DeletePathAction::class,
        HistoryAction::class,
        HistoryDiffAction::class,
        UndoAction::class,
        CheckpointAction::class,
        ReadLogAction::class,
        CheckPageAction::class,
        AuditSiteAction::class,
        RunAction::class,
        MaintenanceAction::class,
        InstallPackageAction::class,
        UninstallPackageAction::class,
        RunProcessorAction::class,
        QueryDbAction::class,
        RequestStatusAction::class,
    );

    private $actions = null;

    /** @return Action[] keyed by action name */
    public function all() {
        if ($this->actions === null) {
            $this->actions = array();
            foreach (self::ACTIONS as $class) {
                $action = new $class();
                $this->actions[$action->name()] = $action;
            }
        }
        return $this->actions;
    }

    /** @return Action|null */
    public function get($name) {
        $all = $this->all();
        return isset($all[$name]) ? $all[$name] : null;
    }

    /**
     * Why this token cannot call the action, or null when it can.
     * Reasons: forbidden_scope, draft_only, disabled_group, denied_for_token, not_installed,
     * code_missing, or an action-specific gate such as disabled_by_setting.
     */
    public function blockReason(Action $action, Context $context) {
        if (DraftPolicy::applies($context) && !in_array($action->name(), DraftPolicy::ACTIONS, true)) { return 'draft_only'; }
        if (!Scope::allows($context->token['scope'], $action->scope())) { return 'forbidden_scope'; }
        $reason = $context->capabilities->groupBlockReason($action->group(), $context->token);
        if ($reason !== null) { return $reason; }
        return $action->unavailableReason($context);
    }
}
