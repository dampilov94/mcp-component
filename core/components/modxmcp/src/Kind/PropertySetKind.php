<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Schema;
use ModxMCP\Write\Transaction;

/** A property set (modPropertySet): named default parameters that can be attached to chunks, snippets, templates, plugins and TVs. */
final class PropertySetKind extends Kind {
    /** @var Context|null */
    private $context;

    public function attach(Context $context) { $this->context = $context; }

    public function name() { return 'property_set'; }
    public function label() { return 'Property set'; }
    public function modelClass() { return 'modPropertySet'; }
    public function group() { return 'property_sets'; }
    public function processorPath() { return 'element/propertyset/'; }
    public function processorExtras($op) { return array(); }

    public function fields() {
        return array(
            'id'          => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'        => array('type' => 'string', 'list' => true, 'required' => true, 'description' => 'Unique name; the characters ! @ ? ` & are removed by MODX.'),
            'description' => array('type' => 'string', 'list' => true),
            'category'    => array('type' => 'integer', 'list' => true, 'description' => 'Category id, 0 for none.'),
            'properties'  => self::propertiesField('The parameters of the set.'),
        );
    }

    public function relationSpecs() {
        return array(
            'elements' => array(
                'class' => 'modElementPropertySet', 'fk' => 'property_set', 'present' => array('element_class', 'element'), 'writable' => true,
                'description' => 'Elements the set is attached to, as a list of {kind, id} (kinds: chunk, snippet, template, plugin, tv). Writing replaces the list; '
                    . 'it is the same link as the property_sets of an element.',
            ),
        );
    }

    public function presentRelation(\modX $modx, $object, $name) {
        if ($name !== 'elements') { return parent::presentRelation($modx, $object, $name); }
        $out = array();
        foreach (PropertySets::elementsOf($modx, $this->keyOf($object)) as $pair) { $out[] = array('kind' => $pair[0], 'id' => $pair[1]); }
        return $out;
    }

    public function relationBlockReason(Context $context, $name) { return null; }

    public function relationAffects(\modX $modx, $object, $name, $value) {
        if ($name !== 'elements') { return array(); }
        $pairs = PropertySets::checkElements($modx, $value, $this->context);
        return array_merge(PropertySets::elementsOf($modx, $this->keyOf($object)), $pairs);
    }

    public function writeRelation(\modX $modx, $object, $name, $value) {
        if ($name !== 'elements') { return parent::writeRelation($modx, $object, $name, $value); }
        PropertySets::writeElements($modx, $this->keyOf($object), PropertySets::checkElements($modx, $value, $this->context));
    }

    public function operations() {
        $element = Schema::obj(array(
            'kind' => Schema::str('Element kind.', array('enum' => array_keys(PropertySets::ELEMENTS))),
            'id'   => Schema::int(null, array('minimum' => 1)),
        ), array('kind', 'id'));
        return array(
            'assign' => array('description' => 'Attach the property set to an element ({kind, id}); the element\'s own journal entry records the link too.',
                'read_only' => false, 'input' => $element, 'handler' => 'opAssign'),
            'unassign' => array('description' => 'Detach the property set from an element ({kind, id}).',
                'read_only' => false, 'input' => $element, 'handler' => 'opUnassign'),
        );
    }

    public function opAssign(Context $context, Transaction $tx, $object, array $args): array { return $this->change($context, $tx, $object, $args, true); }

    public function opUnassign(Context $context, Transaction $tx, $object, array $args): array { return $this->change($context, $tx, $object, $args, false); }

    private function change(Context $context, Transaction $tx, $object, array $args, $assign) {
        $pair = PropertySets::checkElements($context->modx, array(array('kind' => $args['kind'], 'id' => $args['id'])), $context)[0];
        $elementKind = $context->kinds->get($pair[0]);
        $tx->touch($elementKind, $pair[1]);
        $class = PropertySets::ELEMENTS[$pair[0]];
        $setId = (int) $object->get('id');
        $linked = in_array($setId, PropertySets::setsOf($context->modx, $class, $pair[1]), true);
        if ($assign === $linked) { return array('status' => $assign ? 'already_assigned' : 'not_assigned'); }
        if ($assign) { PropertySets::link($context->modx, $setId, $class, $pair[1]); }
        else { PropertySets::unlink($context->modx, $setId, $class, $pair[1]); }
        return array('status' => $assign ? 'assigned' : 'unassigned');
    }
}
