<?php
namespace ModxMCP\Kind;

final class PluginKind extends Kind {
    public function name() { return 'plugin'; }

    public function label() { return 'Plugin'; }

    public function modelClass() { return 'modPlugin'; }

    public function fields() {
        return $this->elementFields('plugincode') + array(
            'disabled'            => array('type' => 'boolean', 'list' => true, 'description' => 'A disabled plugin does not run.'),
            'property_preprocess' => array('type' => 'boolean', 'description' => 'Parse tags in property values before use.'),
        );
    }

    public function relations() {
        return array(
            'events'        => 'System events the plugin listens to, with priority.',
            'property_sets' => 'Property sets attached to the plugin.',
        );
    }
}
