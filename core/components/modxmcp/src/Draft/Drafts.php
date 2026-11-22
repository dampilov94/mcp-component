<?php
namespace ModxMCP\Draft;

use ModxMCP\ApiException;
use ModxMCP\Code\Diff;
use ModxMCP\Context;
use ModxMCP\Journal\Authors;
use ModxMCP\Journal\ObjectSubject;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Kind\Objects;
use ModxMCP\Kind\ResourceKind;
use ModxMCP\Write\Transaction;

/**
 * Resource drafts: the operations create_draft, apply_draft, discard_draft and list_drafts of the kind resource.
 *
 * A draft is an unpublished copy of a page (made the way modx_duplicate copies it). The live page is not touched until the
 * draft is applied: then the editorial fields and the TV values go onto the live page through the normal update path, and the
 * draft resource is removed the way an undo removes an object (not through the delete processor: nothing lands in the trash).
 */
final class Drafts {
    /** What an applied draft copies to the original. Everything else (alias, parent, published, schedule, …) stays as it is. */
    const FIELDS = array('pagetitle', 'longtitle', 'description', 'introtext', 'content', 'menutitle', 'link_attributes', 'template', 'richtext');

    const PREVIEW_NOTE = 'The draft is an unpublished page. MODX shows it only to a user who is logged in to the manager in the same browser; everyone else gets the "page not found" page.';

    private static function store(Context $context) {
        return new DraftStore($context->modx);
    }

    // ---- create_draft ----

    private static function assertDraftable(ResourceKind $kind, DraftStore $store, $original) {
        $id = (int) $original->get('id');
        $class = (string) $original->get('class_key');
        if ($class !== 'modDocument') {
            throw new ApiException('validation_failed', "Resource {$id} has the class {$class}: drafts are made for modDocument pages only (a copy would not carry the data that class keeps outside the resource).");
        }
        if ((bool) $original->get('deleted')) { throw new ApiException('validation_failed', "Resource {$id} is in the trash; restore it before making a draft."); }
        $link = $store->linkOf($id);
        if ($link && $link['draft_id'] === $id) { throw new ApiException('validation_failed', "Resource {$id} is itself a draft of resource {$link['original_id']}; a draft cannot have a draft."); }
        if ($link) { throw new ApiException('validation_failed', "Resource {$id} already has the open draft {$link['draft_id']}; apply or discard it first."); }
    }

    public static function create(ResourceKind $kind, Context $context, Transaction $tx, $original, array $args) {
        $modx = $context->modx;
        $store = self::store($context);
        $id = (int) $original->get('id');
        self::assertDraftable($kind, $store, $original);

        $values = array();
        foreach ($kind->fields() as $field => $definition) {
            if (empty($definition['read_only']) && empty($definition['derived']) && empty($definition['write_only'])) { $values[$field] = $kind->value($original, $field); }
        }
        $alias = (string) $original->get('alias');
        // The alias carries the draft's id, which is known only once it exists: a unique placeholder first.
        $values = array_replace($values, array(
            'published' => false, 'hidemenu' => true, 'searchable' => false, 'pub_date' => '', 'unpub_date' => '',
            'isfolder' => false, 'uri' => '', 'uri_override' => false, 'alias' => 'draft-' . bin2hex(random_bytes(6)),
        ));
        $columns = Objects::normalize($kind, $values, true);
        // As modx_duplicate copies a resource: the TV values (resource groups are not writable through the API, so not copied).
        $relations = array('tvs' => (array) $kind->storedRelation($modx, $original, 'tvs'));
        Objects::assertWritableRelations($kind, $relations, $context);

        $base = Snapshot::capture($modx, $kind, $id);
        $draftId = (int) Objects::save($context, $tx, $kind, null, $columns, $relations);
        $final = ($alias !== '' ? mb_substr($alias, 0, 200, 'UTF-8') . '-draft-' : 'draft-') . $draftId;
        Objects::save($context, $tx, $kind, $draftId, array('alias' => $final), array());

        // Last: a draft without a link is a plain page, a link without a draft is dropped by reconcile().
        $store->add($id, $draftId, time(), $context->token['id'], $context->actingUserId(), (string) Snapshot::revision($base), $base);
        return array(
            'draft_id' => $draftId, 'preview_url' => self::previewUrl($modx, $draftId), 'preview_note' => self::PREVIEW_NOTE,
            'base_revision' => Snapshot::revision($base), 'summary' => "create draft {$draftId} of resource {$id}",
        );
    }

    private static function previewUrl(\modX $modx, $draftId) {
        try {
            return (string) $modx->makeUrl($draftId, '', '', 'full');
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ---- what apply changes ----

    private static function linkFor(DraftStore $store, $resourceId) {
        $link = $store->linkOf((int) $resourceId);
        if (!$link) { throw new ApiException('validation_failed', "Resource {$resourceId} has no open draft and is not a draft."); }
        return $link;
    }

    private static function load(ResourceKind $kind, Context $context, $id) {
        $object = $kind->findByKey($context->modx, (int) $id);
        if (!$object) { throw new ApiException('not_found', "No resource with id {$id}."); }
        return $object;
    }

    private static function tvMap(ResourceKind $kind, \modX $modx, $object) {
        return (array) $kind->presentRelation($modx, $object, 'tvs');
    }

    /** @return array [fields => name => {old,new}|{diff}, tvs => name => {old,new}] what the draft would change on the original */
    private static function differences(ResourceKind $kind, \modX $modx, $original, $draft) {
        $fields = array();
        foreach (self::FIELDS as $field) {
            $old = $kind->value($original, $field);
            $new = $kind->value($draft, $field);
            if ($old === $new) { continue; }
            $fields[$field] = $field === 'content' ? array('diff' => Diff::lines($old, $new)) : array('old' => $old, 'new' => $new);
        }
        $tvs = array();
        $oldTvs = self::tvMap($kind, $modx, $original);
        $newTvs = self::tvMap($kind, $modx, $draft);
        foreach (array_unique(array_merge(array_keys($oldTvs), array_keys($newTvs))) as $name) {
            $old = array_key_exists($name, $oldTvs) ? $oldTvs[$name] : null;
            $new = array_key_exists($name, $newTvs) ? $newTvs[$name] : null;
            if (json_encode($old) !== json_encode($new)) { $tvs[(string) $name] = array('old' => $old, 'new' => $new); }
        }
        ksort($tvs, SORT_STRING);
        return array($fields, $tvs);
    }

    /** @return array [changed?, names of what changed since the draft was made] */
    private static function originalChanged(ResourceKind $kind, \modX $modx, array $link) {
        $state = Snapshot::capture($modx, $kind, $link['original_id']);
        $changed = Snapshot::revision($state) !== $link['base_revision'];
        return array($changed, $changed && $link['base_state'] ? Snapshot::changed($kind, $link['base_state'], $state) : array(), Snapshot::revision($state));
    }

    // ---- apply_draft ----

    public static function planApply(ResourceKind $kind, Context $context, $object, array $args) {
        $modx = $context->modx;
        $link = self::linkFor(self::store($context), $object->get('id'));
        $original = self::load($kind, $context, $link['original_id']);
        $draft = self::load($kind, $context, $link['draft_id']);
        list($fields, $tvs) = self::differences($kind, $modx, $original, $draft);
        list($changed, $names) = self::originalChanged($kind, $modx, $link);
        $plan = array(
            'performed' => false, 'original_id' => $link['original_id'], 'draft_id' => $link['draft_id'],
            'changes' => $fields ? $fields : new \stdClass(), 'tvs' => $tvs ? $tvs : new \stdClass(),
            'original_changed' => $changed, 'original_changed_fields' => $names,
            'reversible' => true,
            'warning' => 'Confirming puts these changes on the live page and removes the draft; modx_undo brings back both the old page and the draft.'
                . ($changed ? ' The live page was changed after the draft was made: confirming needs force: true and overwrites those changes in the fields above.' : ''),
        );
        $open = Objects::openPlan($context, $kind, $link['original_id']);
        return $plan + $open;
    }

    public static function apply(ResourceKind $kind, Context $context, Transaction $tx, $object, array $args) {
        $modx = $context->modx;
        $link = self::linkFor(self::store($context), $object->get('id'));
        $originalId = $link['original_id'];
        $draftId = $link['draft_id'];
        $force = !empty($args['force']);
        if ($tx->touch($kind, $originalId) === null || $tx->touch($kind, $draftId) === null) { throw new ApiException('not_found', 'The draft or its original no longer exists.'); }
        // The call itself checked the resource it was made on.
        foreach (array($originalId, $draftId) as $other) {
            if ($other !== (int) $object->get('id')) { Objects::assertNotOpen($context, $kind, $other, array('force' => $force)); }
        }
        list($changed, $names, $current) = self::originalChanged($kind, $modx, $link);
        if ($changed && !$force) {
            throw new ApiException('revision_conflict', "Resource {$originalId} was changed after draft {$draftId} was made"
                . ($names ? ' (' . implode(', ', $names) . ')' : '') . '. Applying the draft would overwrite those changes. Check the plan and repeat with force: true.',
                array('current_revision' => $current, 'base_revision' => $link['base_revision'], 'changed' => $names));
        }
        if ($changed) { $context->warnings[] = "Resource {$originalId} was changed after the draft was made; applied anyway because force is set."; }

        $original = self::load($kind, $context, $originalId);
        $draft = self::load($kind, $context, $draftId);
        list($fields, $tvDiff) = self::differences($kind, $modx, $original, $draft);

        $values = array();
        foreach (self::FIELDS as $field) { $values[$field] = $kind->value($draft, $field); }
        $columns = Objects::normalize($kind, $values, false);
        // Every TV of the draft's template: its stored value, or its default (which clears the value on the original).
        $stored = (array) $kind->storedRelation($modx, $draft, 'tvs');
        $tvs = array();
        foreach ($kind->templateTvs($modx, $draft) as $name => $tv) { $tvs[$name] = array_key_exists($name, $stored) ? $stored[$name] : $tv[1]; }
        Objects::save($context, $tx, $kind, $originalId, $columns, array('tvs' => $tvs));

        self::removeDraft($kind, $context, $draftId);
        return array(
            'original_id' => $originalId, 'draft_id' => $draftId, 'applied' => true, 'applied_fields' => array_keys($fields), 'applied_tvs' => array_keys($tvDiff),
            'summary' => "apply draft {$draftId} to resource {$originalId}",
        );
    }

    /** Remove the draft resource the way an undo removes an object: no trash, no processors. It is touched already. */
    private static function removeDraft(ResourceKind $kind, Context $context, $draftId) {
        (new ObjectSubject($context->modx, $kind, $draftId))->restore(null, array());
        $cache = $context->modx->getCacheManager();
        if ($cache) { $cache->refresh(); }
    }

    // ---- discard_draft ----

    public static function planDiscard(ResourceKind $kind, Context $context, $object, array $args) {
        $link = self::linkFor(self::store($context), $object->get('id'));
        $draft = self::load($kind, $context, $link['draft_id']);
        return array(
            'performed' => false, 'original_id' => $link['original_id'], 'draft_id' => $link['draft_id'],
            'would_remove' => array('pagetitle' => (string) $draft->get('pagetitle'), 'alias' => (string) $draft->get('alias')),
            'reversible' => true,
            'warning' => 'Confirming removes the draft; the live page stays as it is. modx_undo brings the draft back.',
        ) + Objects::openPlan($context, $kind, $link['draft_id']);
    }

    public static function discard(ResourceKind $kind, Context $context, Transaction $tx, $object, array $args) {
        $link = self::linkFor(self::store($context), $object->get('id'));
        $draftId = $link['draft_id'];
        if ($tx->touch($kind, $draftId) === null) { throw new ApiException('not_found', 'The draft no longer exists.'); }
        Objects::assertNotOpen($context, $kind, $draftId, array('force' => !empty($args['force'])));
        self::removeDraft($kind, $context, $draftId);
        return array('original_id' => $link['original_id'], 'draft_id' => $draftId, 'discarded' => true, 'summary' => "discard draft {$draftId} of resource {$link['original_id']}");
    }

    // ---- list_drafts ----

    /** Every open draft, oldest first. */
    public static function items(ResourceKind $kind, Context $context) {
        $modx = $context->modx;
        $authors = new Authors($modx);
        $items = array();
        $owner = DraftPolicy::applies($context) ? $context->token['id'] : null;
        foreach (self::store($context)->all($owner) as $link) {
            $original = $modx->getObject('modResource', array('id' => $link['original_id']));
            list($changed, $names) = self::originalChanged($kind, $modx, $link);
            $items[] = array(
                'original_id' => $link['original_id'], 'original_title' => (string) $original->get('pagetitle'), 'draft_id' => $link['draft_id'],
                'created_at' => $link['created_at'], 'time' => date('c', $link['created_at']),
                'author' => $authors->name(array('source' => $link['user_id'] !== null ? 'manager' : 'mcp', 'token_id' => $link['token_id'], 'user_id' => $link['user_id'])),
                'preview_url' => self::previewUrl($modx, $link['draft_id']),
                'original_changed' => $changed, 'original_changed_fields' => $names,
            );
        }
        return $items;
    }
}
