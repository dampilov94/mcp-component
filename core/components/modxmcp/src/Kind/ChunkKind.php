<?php
namespace ModxMCP\Kind;

final class ChunkKind extends Kind {
    public function name() { return 'chunk'; }

    public function label() { return 'Chunk'; }

    public function modelClass() { return 'modChunk'; }

    public function processorPath() { return 'element/chunk/'; }

    public function eventNames() {
        return array('save' => 'OnChunkFormSave', 'delete' => 'OnChunkFormDelete', 'param' => 'chunk');
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
