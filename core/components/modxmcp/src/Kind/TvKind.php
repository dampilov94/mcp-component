<?php
namespace ModxMCP\Kind;

final class TvKind extends Kind {
    public function name() { return 'tv'; }

    public function label() { return 'Template variable'; }

    public function modelClass() { return 'modTemplateVar'; }

    public function fields() {
        return array(
            'id'           => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'         => array('type' => 'string', 'list' => true, 'required' => true),
            'caption'      => array('type' => 'string', 'list' => true, 'description' => 'Label shown to editors.'),
            'description'  => array('type' => 'string'),
            'category'     => array('type' => 'integer', 'list' => true, 'description' => 'Category id, 0 for none.'),
            'type'         => array('type' => 'string', 'list' => true, 'description' => 'Input type, e.g. text, image, listbox.'),
            'elements'     => array('type' => 'string', 'description' => 'Input option values.'),
            'default_text' => array('type' => 'string', 'description' => 'Default value.'),
            'display'      => array('type' => 'string', 'description' => 'Output type.'),
            'rank'         => array('type' => 'integer', 'description' => 'Sort order.'),
            'locked'       => array('type' => 'boolean', 'description' => 'Locked for editing in the manager.'),
        );
    }

    public function relations() {
        return array(
            'templates'       => 'Templates the TV is attached to.',
            'resource_groups' => 'Resource groups that restrict access to the TV.',
        );
    }
}
