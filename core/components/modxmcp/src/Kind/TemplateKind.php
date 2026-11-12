<?php
namespace ModxMCP\Kind;

final class TemplateKind extends Kind {
    public function name() { return 'template'; }

    public function label() { return 'Template'; }

    public function modelClass() { return 'modTemplate'; }

    public function processorPath() { return 'element/template/'; }

    public function eventNames() {
        return array('save' => 'OnTempFormSave', 'delete' => 'OnTempFormDelete', 'param' => 'template');
    }

    public function fields() {
        return $this->elementFields('content', 'templatename') + array(
            'icon' => array('type' => 'string', 'description' => 'Manager tree icon class.'),
        );
    }

    public function operations() { return $this->staticOperations(); }

    public function relationSpecs() {
        return array(
            'tvs' => array(
                'class' => 'modTemplateVarTemplate', 'fk' => 'templateid', 'present' => 'tmplvarid',
                'description' => 'Ids of template variables attached to the template.',
            ),
            'property_sets' => $this->propertySetRelation(),
        );
    }
}
