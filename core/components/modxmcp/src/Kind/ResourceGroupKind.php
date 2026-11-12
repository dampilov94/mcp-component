<?php
namespace ModxMCP\Kind;

use ModxMCP\Auth\Scope;

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
}
