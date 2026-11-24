<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Schema;

final class TvKind extends Kind {
    use RenameOperation;

    public function name() { return 'tv'; }

    public function label() { return 'Template variable'; }

    public function modelClass() { return 'modTemplateVar'; }

    public function processorPath() { return 'element/tv/'; }

    public function eventNames() {
        return array(
            'before_save' => 'OnBeforeTVFormSave', 'save' => 'OnTVFormSave',
            'before_delete' => 'OnBeforeTVFormDelete', 'delete' => 'OnTVFormDelete', 'param' => 'tv',
        );
    }

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
            'properties'   => self::propertiesField('Default properties of the TV.'),
            'input_properties' => array('type' => 'object', 'json' => true,
                'description' => 'Options of the input type as {name: value}, e.g. {"configs": "gallery"} binds a MIGX TV to its configuration. Writing replaces the map.'),
            'output_properties' => array('type' => 'object', 'json' => true,
                'description' => 'Options of the output type (display) as {name: value}, e.g. {"w": 200} for an image output. Writing replaces the map.'),
        );
    }

    /** The TV processors read input options only from properties named inopt_<name>, output options from prop_<name>. */
    public function processorData($op, array $data) {
        $data = $this->propertiesData($data);
        foreach (array('input_properties' => 'inopt_', 'output_properties' => 'prop_') as $field => $prefix) {
            if (!array_key_exists($field, $data)) { continue; }
            $options = $data[$field];
            unset($data[$field]);
            if (is_string($options)) { $decoded = json_decode($options, true); $options = is_array($decoded) ? $decoded : array(); }
            if ($options instanceof \stdClass) { $options = (array) $options; }
            foreach ((array) $options as $name => $value) {
                $data[$prefix . $name] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
            }
        }
        return $data;
    }

    public function relationSpecs() {
        return array(
            'templates' => array(
                'class' => 'modTemplateVarTemplate', 'fk' => 'tmplvarid', 'present' => 'templateid', 'writable' => true,
                'description' => 'Ids of templates the TV is attached to. Writing replaces the list.',
            ),
            'resource_groups' => array(
                'class' => 'modTemplateVarResourceGroup', 'fk' => 'tmplvarid', 'present' => 'documentgroup',
                'description' => 'Ids of resource groups that restrict access to the TV.',
            ),
            'property_sets' => $this->propertySetRelation(),
            // Values live on resources but are destroyed with the TV, so a delete must keep them.
            'values' => array('class' => 'modTemplateVarResource', 'fk' => 'tmplvarid', 'delete_only' => true),
            'media_sources' => array(
                'class' => 'sources.modMediaSourceElement', 'fk' => 'object', 'where' => array('object_class' => 'modTemplateVar'),
                'present' => array('context_key', 'source'), 'writable' => true, 'map' => true,
                'description' => 'Media source per context as {context_key: source_id}, e.g. {"web": 2}. Only the contexts you give are changed; null removes the context\'s row (the default source applies).',
            ),
        );
    }

    public function presentRelation(\modX $modx, $object, $name) {
        if ($name !== 'media_sources') { return parent::presentRelation($modx, $object, $name); }
        $out = array();
        foreach ($modx->getCollection('sources.modMediaSourceElement', array('object' => (int) $object->get('id'), 'object_class' => 'modTemplateVar')) as $row) {
            $out[(string) $row->get('context_key')] = (int) $row->get('source');
        }
        ksort($out, SORT_STRING);
        return $out ? (object) $out : new \stdClass();
    }

    /** Like modElementTvUpdateProcessor::setMediaSources(), but only for the given contexts: the row of a context is replaced. */
    private function writeMediaSources(\modX $modx, $object, array $sources) {
        $id = (int) $object->get('id');
        $plan = array();
        foreach ($sources as $context => $source) {
            $context = (string) $context;
            if (!$modx->getCount('modContext', array('key' => $context))) { throw new ApiException('validation_failed', "Context '{$context}' does not exist."); }
            if ($source !== null) {
                if (!is_int($source) || $source < 1) { throw new ApiException('validation_failed', "media_sources.{$context} must be a media source id or null."); }
                if (!$modx->getCount('sources.modMediaSource', array('id' => $source))) { throw new ApiException('validation_failed', "Media source {$source} does not exist."); }
            }
            $plan[$context] = $source;
        }
        foreach ($plan as $context => $source) {
            // The source is part of the key of a row, so a row cannot be updated in place: remove it and add the new one.
            $modx->removeCollection('sources.modMediaSourceElement', array('object' => $id, 'object_class' => 'modTemplateVar', 'context_key' => $context));
            if ($source === null) { continue; }
            $row = $modx->newObject('sources.modMediaSourceElement');
            $row->fromArray(array('object' => $id, 'object_class' => 'modTemplateVar', 'context_key' => $context, 'source' => $source), '', true, true);
            if (!$row->save()) { throw new \RuntimeException("Cannot set the media source of context {$context}."); }
        }
    }

    public function writeRelation(\modX $modx, $object, $name, $value) {
        if ($name === 'media_sources') { $this->writeMediaSources($modx, $object, (array) $value); return; }
        if ($name !== 'templates') { return parent::writeRelation($modx, $object, $name, $value); }
        $ids = array();
        foreach ($value as $item) {
            if (!is_int($item) || $item < 1) { throw new ApiException('validation_failed', 'templates must be a list of template ids.'); }
            if (!$modx->getCount('modTemplate', array('id' => $item))) {
                throw new ApiException('validation_failed', "Template {$item} does not exist.");
            }
            $ids[$item] = true;
        }
        $id = (int) $object->get('id');
        // A template that stays attached keeps its rank (the order of the TV tabs in the manager); a new one gets 0, as in MODX.
        $ranks = array();
        foreach ($modx->getCollection('modTemplateVarTemplate', array('tmplvarid' => $id)) as $existing) { $ranks[(int) $existing->get('templateid')] = $existing->get('rank'); }
        $modx->removeCollection('modTemplateVarTemplate', array('tmplvarid' => $id));
        foreach (array_keys($ids) as $template) {
            $row = $modx->newObject('modTemplateVarTemplate');
            $row->fromArray(array('tmplvarid' => $id, 'templateid' => $template, 'rank' => isset($ranks[$template]) ? $ranks[$template] : 0), '', true, true);
            if (!$row->save()) { throw new \RuntimeException('Cannot attach the TV to a template.'); }
        }
    }
    public function operations() {
        return array(
            'list_input_types' => array('description' => 'List core and installed custom TV input types.', 'read_only' => true,
                'needs_object' => false, 'input' => Schema::obj(), 'handler' => 'opListInputTypes'),
            'suggest_type' => array('description' => 'Suggest TV input types for a need described in English or Russian.', 'read_only' => true,
                'needs_object' => false, 'input' => Schema::obj(array('description' => Schema::str()), array('description')), 'handler' => 'opSuggestType'),
        ) + $this->renameOperation();
    }
    public function opListInputTypes(Context $context, $object, array $args): array {
        return TvInputTypes::listTvInputTypes($context->modx);
    }
    public function opSuggestType(Context $context, $object, array $args): array {
        return TvInputTypes::suggestTvType($context->modx, $args);
    }

}
