<?php
namespace ModxMCP\Kind;

use ModxMCP\Auth\Scope;
use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Schema;
use ModxMCP\Write\Transaction;

final class ResourceGroupKind extends Kind {
    public function name() { return 'resource_group'; }
    public function label() { return 'Resource group'; }
    public function modelClass() { return 'modResourceGroup'; }
    public function processorPath() { return 'security/resourcegroup/'; }
    public function group() { return 'access'; }
    public function writeScope() { return Scope::ADMIN; }
    public function processorExtras($op) { return array(); }
    public function fields() {
        return array(
            'id' => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name' => array('type' => 'string', 'required' => true, 'list' => true),
        );
    }
    public function relationSpecs() {
        return array(
            'resources' => array('class' => 'modResourceGroupResource', 'fk' => 'document_group',
                'present' => 'document', 'description' => 'Ids of resources in the group.'),
            // Rules and TV restrictions that point at the group go with it; keep them for undo.
            'acl' => array('class' => 'modAccessResourceGroup', 'fk' => 'target', 'delete_only' => true),
            'tv_access' => array('class' => 'modTemplateVarResourceGroup', 'fk' => 'documentgroup', 'delete_only' => true),
        );
    }
    public function operations() {
        $input = Schema::obj(array('resource' => Schema::int(null, array('minimum' => 1))), array('resource'));
        return array(
            'add_resource' => array('description' => 'Add a resource to the group.', 'read_only' => false, 'input' => $input, 'handler' => 'opAddResource'),
            'remove_resource' => array('description' => 'Remove a resource from the group.', 'read_only' => false, 'input' => $input, 'handler' => 'opRemoveResource'),
        );
    }
    public function opAddResource(Context $context, Transaction $tx, $object, array $args): array {
        return $this->changeResource($context, $tx, $object, $args['resource'], true);
    }
    public function opRemoveResource(Context $context, Transaction $tx, $object, array $args): array {
        return $this->changeResource($context, $tx, $object, $args['resource'], false);
    }
    private function changeResource(Context $context, Transaction $tx, $group, $id, $add): array {
        $resourceKind = Objects::kind($context, 'resource', true);
        if ($tx->touch($resourceKind, $id) === null) { throw new ApiException('not_found', 'Resource not found: ' . $id); }
        $resource = Objects::resolve($context, $resourceKind, array('id' => $id));
        $ok = $add ? $resource->joinGroup((int) $group->get('id')) : $resource->leaveGroup((int) $group->get('id'));
        if (!$ok) { throw new ApiException('validation_failed', $add ? 'Cannot add resource: it may already be a member.' : 'Cannot remove resource: it may not be a member.'); }
        $context->modx->invokeEvent($add ? 'OnResourceAddToResourceGroup' : 'OnResourceRemoveFromResourceGroup', array(
            'mode' => $add ? 'resource-group-tree-drag' : 'resource-group-tree-remove-resource', 'resource' => &$resource, 'resourceGroup' => &$group));
        return array();
    }

}
