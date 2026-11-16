<?php
namespace ModxMCP\Kind;

final class CategoryKind extends Kind {
    public function managerEvents() {
        // modCategory::save() / remove() fire these themselves, with the object under `category` and no id.
        return array(
            'OnCategoryBeforeSave' => array('role' => 'before_save', 'param' => 'category'),
            'OnCategorySave' => array('role' => 'save', 'param' => 'category'),
            'OnCategoryBeforeRemove' => array('role' => 'before_delete', 'param' => 'category'),
            'OnCategoryRemove' => array('role' => 'delete', 'param' => 'category'),
        );
    }

    public function name() { return 'category'; }

    public function label() { return 'Category'; }

    public function modelClass() { return 'modCategory'; }

    public function processorPath() { return 'element/category/'; }

    public function fields() {
        return array(
            'id'     => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'   => array('type' => 'string', 'column' => 'category', 'list' => true, 'required' => true),
            'parent' => array('type' => 'integer', 'list' => true, 'description' => 'Parent category id, 0 for the top level.'),
            'rank'   => array('type' => 'integer', 'description' => 'Sort order.'),
        );
    }
}
