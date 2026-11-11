<?php
namespace ModxMCP\Kind;

final class ChunkKind extends Kind {
    public function name() { return 'chunk'; }

    public function label() { return 'Chunk'; }

    public function modelClass() { return 'modChunk'; }

    public function fields() {
        return $this->elementFields('snippet') + array(
            'property_preprocess' => array('type' => 'boolean', 'description' => 'Parse tags in property values before use.'),
        );
    }

    public function relations() {
        return array('property_sets' => 'Property sets attached to the chunk.');
    }
}
