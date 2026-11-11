<?php
namespace ModxMCP\Kind;

final class TemplateKind extends Kind {
    public function name() { return 'template'; }

    public function label() { return 'Template'; }

    public function modelClass() { return 'modTemplate'; }

    public function fields() {
        return $this->elementFields('content', 'templatename') + array(
            'icon' => array('type' => 'string', 'description' => 'Manager tree icon class.'),
        );
    }

    public function relations() {
        return array(
            'tvs'           => 'Template variables attached to the template.',
            'property_sets' => 'Property sets attached to the template.',
        );
    }
}
