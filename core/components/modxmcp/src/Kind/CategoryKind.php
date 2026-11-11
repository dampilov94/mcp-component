<?php
namespace ModxMCP\Kind;

final class CategoryKind extends Kind {
    public function name() { return 'category'; }

    public function label() { return 'Category'; }

    public function modelClass() { return 'modCategory'; }

    public function fields() {
        return array(
            'id'     => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'   => array('type' => 'string', 'column' => 'category', 'list' => true, 'required' => true),
            'parent' => array('type' => 'integer', 'list' => true, 'description' => 'Parent category id, 0 for the top level.'),
            'rank'   => array('type' => 'integer', 'description' => 'Sort order.'),
        );
    }
}
