<?php
namespace ModxMCP\Draft;

use ModxMCP\ApiException;
use ModxMCP\Context;

/** Restrictions specific to a token that prepares drafts for review in the manager. */
final class DraftPolicy {
    const SCOPE = 'draft_only';
    const ACTIONS = array('manifest', 'capabilities', 'site_info', 'help', 'describe', 'list', 'get', 'resource_tree', 'update', 'invoke');
    const KIND = 'resource';
    const OPERATIONS = array('create_draft', 'list_drafts');
    const FIELDS = array('pagetitle', 'longtitle', 'description', 'introtext', 'content', 'menutitle');

    public static function applies(Context $context) {
        return $context->token['scope'] === self::SCOPE;
    }

    public static function isPlainText($value) {
        return is_string($value) && strpbrk($value, '<>[]{}&') === false;
    }

    public static function assertUpdateArguments(array $args) {
        if (!empty($args['relations']) || !empty($args['force']) || !empty($args['skip_lint'])) {
            throw new ApiException('forbidden_scope', 'A draft-only token can edit text fields only and cannot bypass write checks.');
        }
        foreach ($args['fields'] ?? array() as $name => $value) {
            if (!in_array($name, self::FIELDS, true)) {
                throw new ApiException('forbidden_scope', "A draft-only token cannot change '{$name}'.");
            }
            if (!self::isPlainText($value)) {
                throw new ApiException('validation_failed', "Field '{$name}' must contain plain text without markup or template delimiters.");
            }
        }
    }

    /** Check the locked resource snapshot and its current draft link before writing or previewing changes. */
    public static function assertOwnDraft(Context $context, $id, array $fields) {
        $link = (new DraftStore($context->modx))->ofDraft($id);
        if (!$link || $link['token_id'] !== $context->token['id'] || $link['user_id'] !== null
            || ($fields['class_key'] ?? '') !== 'modDocument' || !empty($fields['published']) || !empty($fields['deleted'])
            || !empty($fields['pub_date']) || !empty($fields['unpub_date'])) {
            throw new ApiException('forbidden_scope', 'A draft-only token can edit only its own unpublished, unscheduled page draft.');
        }
    }

    public static function describe(array $description) {
        foreach ($description['fields'] as &$field) {
            if (!in_array($field['name'], self::FIELDS, true)) { $field['read_only'] = true; }
            if ($field['name'] === 'content') {
                unset($field['code']);
                $field['description'] = 'Page content. Updates with this token accept plain text.';
            }
        }
        unset($field);
        foreach ($description['relations'] as &$relation) { $relation['writable'] = false; }
        unset($relation);
        $description['operations'] = array_values(array_filter($description['operations'], function ($operation) {
            return in_array($operation['op'], self::OPERATIONS, true);
        }));
        foreach ($description['operations'] as &$operation) {
            $operation['description'] = $operation['op'] === 'create_draft'
                ? 'Create an unpublished copy of this modDocument page. Edit its plain-text fields with modx_update and give its preview link to a manager.'
                : 'List open drafts created with this token. Takes no id.';
        }
        unset($operation);
        $description['note'] = 'Use modx_update on your own draft only. A manager reviews and applies or discards drafts.';
        return $description;
    }
}
