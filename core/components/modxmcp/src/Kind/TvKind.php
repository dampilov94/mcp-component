<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Schema;

final class TvKind extends Kind {
    public function name() { return 'tv'; }

    public function label() { return 'Template variable'; }

    public function modelClass() { return 'modTemplateVar'; }

    public function processorPath() { return 'element/tv/'; }

    public function eventNames() {
        return array('save' => 'OnTVFormSave', 'delete' => 'OnTVFormDelete', 'param' => 'tv');
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
            'input_properties' => array('type' => 'object', 'json' => true,
                'description' => 'Options of the input type as {name: value}, e.g. {"configs": "gallery"} binds a MIGX TV to its configuration. Writing replaces the map.'),
        );
    }

    /** The TV processors read input options only from properties named inopt_<name>. */
    public function processorData($op, array $data) {
        if (!array_key_exists('input_properties', $data)) { return $data; }
        $options = $data['input_properties'];
        unset($data['input_properties']);
        if (is_string($options)) { $decoded = json_decode($options, true); $options = is_array($decoded) ? $decoded : array(); }
        if ($options instanceof \stdClass) { $options = (array) $options; }
        foreach ((array) $options as $name => $value) {
            $data['inopt_' . $name] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
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
                'delete_only' => true,
            ),
        );
    }

    public function writeRelation(\modX $modx, $object, $name, $value) {
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
        $modx->removeCollection('modTemplateVarTemplate', array('tmplvarid' => $id));
        foreach (array_keys($ids) as $template) {
            $row = $modx->newObject('modTemplateVarTemplate');
            $row->fromArray(array('tmplvarid' => $id, 'templateid' => $template, 'rank' => 0), '', true, true);
            if (!$row->save()) { throw new \RuntimeException('Cannot attach the TV to a template.'); }
        }
    }
    public function operations() {
        return array(
            'list_input_types' => array('description' => 'List core and installed custom TV input types.', 'read_only' => true,
                'needs_object' => false, 'input' => Schema::obj(), 'handler' => 'opListInputTypes'),
            'suggest_type' => array('description' => 'Suggest TV input types for a need described in English or Russian.', 'read_only' => true,
                'needs_object' => false, 'input' => Schema::obj(array('description' => Schema::str()), array('description')), 'handler' => 'opSuggestType'),
        );
    }
    public function opListInputTypes(Context $context, $object, array $args): array {
        return TvInputTypes::listTvInputTypes($context->modx);
    }
    public function opSuggestType(Context $context, $object, array $args): array {
        return TvInputTypes::suggestTvType($context->modx, $args);
    }

}
