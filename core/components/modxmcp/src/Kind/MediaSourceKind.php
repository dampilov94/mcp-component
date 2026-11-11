<?php
namespace ModxMCP\Kind;

use ModxMCP\Auth\Scope;

final class MediaSourceKind extends Kind {
    public function name() { return 'media_source'; }

    public function label() { return 'Media source'; }

    public function modelClass() { return 'sources.modMediaSource'; }

    public function group() { return 'files'; }

    public function writeScope() { return Scope::ADMIN; }

    public function processorPath() { return 'source/'; }

    public function processorExtras($op) { return array(); }

    public function fields() {
        return array(
            'id'          => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'        => array('type' => 'string', 'list' => true, 'required' => true),
            'description' => array('type' => 'string'),
            'class_key'   => array('type' => 'string', 'list' => true, 'description' => 'Source class; sources.modFileMediaSource is a local directory.'),
            'base_path'   => array('type' => 'string', 'list' => true, 'derived' => true, 'computed' => true, 'description' => 'Directory of a local source on the server, as resolved now.'),
        );
    }

    public function value($object, $field) {
        if ($field !== 'base_path') { return parent::value($object, $field); }
        if (stripos((string) $object->get('class_key'), 'modFileMediaSource') === false) { return ''; }
        try {
            $object->initialize();
            return (string) $object->getBasePath();
        } catch (\Throwable $e) {
            return '';
        }
    }
}
