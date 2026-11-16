<?php
namespace ModxMCP\Kind;

final class ChunkKind extends Kind {
    public function name() { return 'chunk'; }

    public function label() { return 'Chunk'; }

    public function modelClass() { return 'modChunk'; }

    public function processorPath() { return 'element/chunk/'; }

    public function eventNames() {
        return array(
            'before_save' => 'OnBeforeChunkFormSave', 'save' => 'OnChunkFormSave',
            'before_delete' => 'OnBeforeChunkFormDelete', 'delete' => 'OnChunkFormDelete', 'param' => 'chunk',
        );
    }

    public function fields() {
        return $this->elementFields('snippet') + array(
            'property_preprocess' => array('type' => 'boolean', 'description' => 'Parse tags in property values before use.'),
        );
    }

    public function lint() { return 'fenom'; }

    public function operations() { return $this->staticOperations(); }

    public function relationSpecs() {
        return array('property_sets' => $this->propertySetRelation());
    }
}
