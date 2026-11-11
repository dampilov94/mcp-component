<?php
namespace ModxMCP\Kind;

final class SnippetKind extends Kind {
    public function name() { return 'snippet'; }

    public function label() { return 'Snippet'; }

    public function modelClass() { return 'modSnippet'; }

    public function fields() {
        return $this->elementFields('snippet') + array(
            'property_preprocess' => array('type' => 'boolean', 'description' => 'Parse tags in property values before use.'),
        );
    }

    public function relations() {
        return array('property_sets' => 'Property sets attached to the snippet.');
    }
}
