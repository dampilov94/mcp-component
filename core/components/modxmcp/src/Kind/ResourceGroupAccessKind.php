<?php
namespace ModxMCP\Kind;

/** An access rule of a user group on a resource group (modAccessResourceGroup). */
final class ResourceGroupAccessKind extends AbstractAclKind {
    public function name() { return 'resourcegroup_access'; }
    public function label() { return 'Resource group access rule'; }
    public function modelClass() { return 'modAccessResourceGroup'; }
    public function processorPath() { return 'security/access/usergroup/resourcegroup/'; }

    public function fields() {
        return $this->aclFields(
            array('type' => 'integer', 'list' => true, 'required' => true, 'description' => 'Id of the resource group (kind resource_group).'),
            array('context_key' => array('type' => 'string', 'list' => true, 'description' => 'Key of the context the rule applies in; empty for every context.'))
        );
    }

    protected function targetLabel(\modX $modx, $object) {
        $group = $modx->getObject('modResourceGroup', array('id' => (int) $object->get('target')), false);
        return $group ? (string) $group->get('name') : '#' . (int) $object->get('target');
    }
}
