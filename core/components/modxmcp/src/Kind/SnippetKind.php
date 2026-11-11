<?php
namespace ModxMCP\Kind;

final class SnippetKind extends Kind {
    public function name() { return 'snippet'; }

    public function label() { return 'Snippet'; }

    public function modelClass() { return 'modSnippet'; }

    public function processorPath() { return 'element/snippet/'; }

    public function lint() { return 'php'; }

    public function eventNames() {
        return array('save' => 'OnSnipFormSave', 'delete' => 'OnSnipFormDelete', 'param' => 'snippet');
    }

    public function fields() {
        return $this->elementFields('snippet') + array(
            'property_preprocess' => array('type' => 'boolean', 'description' => 'Parse tags in property values before use.'),
        );
    }

    public function relationSpecs() {
        return array('property_sets' => $this->propertySetRelation());
    }
}
