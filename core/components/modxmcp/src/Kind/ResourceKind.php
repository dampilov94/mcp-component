<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;

final class ResourceKind extends Kind {
    /** More descendants than this cannot be changed in one call. */
    const CASCADE_LIMIT = 1000;

    public function name() { return 'resource'; }

    public function label() { return 'Resource (page)'; }

    public function modelClass() { return 'modResource'; }

    public function group() { return 'resources'; }

    public function writeScope() { return Scope::CONTENT; }

    public function nameField() { return 'pagetitle'; }

    public function uniqueNames() { return false; }

    public function duplicateOverrides($source) {
        // A copied schedule would publish the copy by itself later. Children are not copied, so the copy is not a container either.
        return array('published' => false, 'isfolder' => false, 'pub_date' => '', 'unpub_date' => '', 'alias' => '', 'uri' => '', 'uri_override' => false)
            + parent::duplicateOverrides($source);
    }

    public function processorPath() { return 'resource/'; }

    /** Resources are moved to the trash, not removed. */
    public function deleteProcessor() { return 'delete'; }

    // Without syncsite the resource processors skip the cache refresh.
    public function processorExtras($op) {
        return array('syncsite' => 1, 'clearCache' => 1);
    }

    public function eventNames() {
        return array('save' => 'OnDocFormSave', 'delete' => 'OnDocFormDelete', 'param' => 'resource');
    }

    public function searchColumns() {
        return array('pagetitle', 'alias', 'longtitle');
    }

    public function defaultSort() {
        return array(array('parent', 'ASC'), array('menuindex', 'ASC'), array('id', 'ASC'));
    }

    public function fields() {
        $bool = function ($description, $list = false) {
            return array('type' => 'boolean', 'description' => $description) + ($list ? array('list' => true) : array());
        };
        return array(
            'id'            => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'pagetitle'     => array('type' => 'string', 'list' => true, 'required' => true, 'description' => 'Title.'),
            'longtitle'     => array('type' => 'string'),
            'description'   => array('type' => 'string'),
            'introtext'     => array('type' => 'string', 'description' => 'Summary.'),
            'menutitle'     => array('type' => 'string'),
            'alias'         => array('type' => 'string', 'list' => true, 'description' => 'URL segment.'),
            'uri'           => array('type' => 'string', 'list' => true, 'description' => 'Full URL path. Generated from the aliases unless uri_override is on.'),
            'uri_override'  => $bool('Freeze uri at the given value.'),
            'parent'        => array('type' => 'integer', 'list' => true, 'description' => 'Parent resource id, 0 for the top level.'),
            'context_key'   => array('type' => 'string', 'list' => true),
            'template'      => array('type' => 'integer', 'list' => true, 'description' => 'Template id, 0 for none.'),
            'content'       => array('type' => 'string', 'code' => true, 'description' => 'Page content (HTML with MODX tags).'),
            'published'     => $bool('Visible on the site.', true),
            'deleted'       => array('type' => 'boolean', 'list' => true, 'read_only' => true, 'description' => 'In the trash. Changed with modx_delete and modx_undo.'),
            'hidemenu'      => $bool('Hidden from menus.'),
            'isfolder'      => $bool('Container.', true),
            'menuindex'     => array('type' => 'integer', 'description' => 'Order among siblings.'),
            'richtext'      => $bool('Edited with the rich text editor.'),
            'searchable'    => $bool('Included in site search.'),
            'cacheable'     => $bool('Output may be cached.'),
            'class_key'     => array('type' => 'string', 'list' => true, 'description' => 'Resource class, e.g. modDocument, modWebLink, msProduct.'),
            'content_type'  => array('type' => 'integer', 'description' => 'Content type id.'),
            'link_attributes' => array('type' => 'string'),
            'pub_date'      => array('type' => 'string', 'format' => 'datetime', 'description' => 'Publish automatically at this time.'),
            'unpub_date'    => array('type' => 'string', 'format' => 'datetime', 'description' => 'Unpublish automatically at this time.'),
            'publishedon'   => array('type' => 'string', 'format' => 'datetime', 'derived' => true),
            'createdon'     => array('type' => 'string', 'format' => 'datetime', 'derived' => true),
            'editedon'      => array('type' => 'string', 'format' => 'datetime', 'derived' => true),
        );
    }

    public function relationSpecs() {
        return array(
            'tvs' => array(
                'class' => 'modTemplateVarResource', 'fk' => 'contentid', 'present' => 'value', 'writable' => true, 'map' => true,
                'description' => 'Template variable values as {tv name: value}, for the TVs of the resource template. '
                    . 'Reads show the stored value, or the TV default when none is stored. Writing sets only the named TVs.',
            ),
            'resource_groups' => array(
                'class' => 'modResourceGroupResource', 'fk' => 'document', 'present' => 'document_group',
                'description' => 'Ids of resource groups the resource belongs to.',
            ),
        );
    }

    /** editedon/editedby change on every save, and uri is derived unless frozen. */
    public function stateColumns(array $columns) {
        unset($columns['editedon'], $columns['editedby']);
        if (empty($columns['uri_override'])) { unset($columns['uri']); }
        return $columns;
    }

    /** By id, or by a name that is an alias, a URI or a title; ambiguous names are refused. */
    public function find(\modX $modx, array $ref) {
        if (isset($ref['id'])) { return parent::find($modx, $ref); }
        if (!isset($ref['name']) || $ref['name'] === '') { return null; }
        foreach (array('alias', 'uri', 'pagetitle') as $column) {
            $found = $modx->getCollection('modResource', array($column => (string) $ref['name']));
            if (count($found) > 1) {
                $ids = array();
                foreach ($found as $item) { $ids[] = $item->get('id'); }
                throw new ApiException('validation_failed', "Several resources have {$column} '{$ref['name']}' (ids " . implode(', ', $ids) . '). Use the id.');
            }
            if ($found) { return parent::find($modx, array('id' => reset($found)->get('id'))); }
        }
        return null;
    }

    /** TVs attached to the resource template: name => [id, default]. */
    private function templateTvs(\modX $modx, $object) {
        $out = array();
        foreach ($modx->getCollection('modTemplateVarTemplate', array('templateid' => (int) $object->get('template'))) as $link) {
            $tv = $modx->getObject('modTemplateVar', array('id' => $link->get('tmplvarid')), false);
            if ($tv) { $out[(string) $tv->get('name')] = array((int) $tv->get('id'), (string) $tv->get('default_text')); }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    public function presentRelation(\modX $modx, $object, $name) {
        if ($name !== 'tvs') { return parent::presentRelation($modx, $object, $name); }
        $stored = array();
        foreach ($modx->getCollection('modTemplateVarResource', array('contentid' => $object->get('id'))) as $row) {
            $stored[(int) $row->get('tmplvarid')] = (string) $row->get('value');
        }
        $out = array();
        foreach ($this->templateTvs($modx, $object) as $tvName => $tv) {
            $out[$tvName] = isset($stored[$tv[0]]) ? $stored[$tv[0]] : $tv[1];
        }
        return $out ? $out : new \stdClass();
    }

    public function storedRelation(\modX $modx, $object, $name) {
        if ($name !== 'tvs') { return parent::storedRelation($modx, $object, $name); }
        $names = array();
        foreach ($this->templateTvs($modx, $object) as $tvName => $tv) { $names[$tv[0]] = $tvName; }
        $out = array();
        foreach ($modx->getCollection('modTemplateVarResource', array('contentid' => $object->get('id'))) as $row) {
            $tvId = (int) $row->get('tmplvarid');
            if (isset($names[$tvId])) { $out[$names[$tvId]] = (string) $row->get('value'); }
        }
        ksort($out, SORT_STRING);
        return $out ? $out : new \stdClass();
    }

    public function writeRelation(\modX $modx, $object, $name, $value) {
        if ($name !== 'tvs') { return parent::writeRelation($modx, $object, $name, $value); }
        $known = $this->templateTvs($modx, $object);
        foreach ($value as $tvName => $tvValue) {
            if (!isset($known[$tvName])) {
                throw new ApiException('validation_failed', "TV '{$tvName}' is not attached to template {$object->get('template')} of this resource. Its TVs: " . implode(', ', array_keys($known)) . '.');
            }
            if (!is_scalar($tvValue)) { throw new ApiException('validation_failed', "The value of TV '{$tvName}' must be a string, number or boolean."); }
        }
        foreach ($value as $tvName => $tvValue) {
            if (!$object->setTVValue($known[$tvName][0], is_bool($tvValue) ? (int) $tvValue : (string) $tvValue)) {
                throw new \RuntimeException("Cannot save TV '{$tvName}'.");
            }
        }
    }

    /** All descendants of a resource, breadth first. */
    public function descendants(\modX $modx, $id) {
        $all = array();
        $level = array((int) $id);
        while ($level) {
            $next = array();
            foreach ($level as $parent) {
                foreach ($modx->getCollection('modResource', array('parent' => $parent)) as $child) { $next[] = (int) $child->get('id'); }
            }
            $all = array_merge($all, $next);
            if (count($all) > self::CASCADE_LIMIT) {
                throw new ApiException('validation_failed', "Resource {$id} has more than " . self::CASCADE_LIMIT . ' descendants; change it in parts.');
            }
            $level = $next;
        }
        return $all;
    }

    public function alsoAffected(\modX $modx, $id, array $columns, $op) {
        $affected = array();
        if ($op === 'delete' || $op === 'undelete') { return $this->descendants($modx, $id); }
        // MODX maintains isfolder of the old and new parent.
        if (isset($columns['parent']) && (int) $columns['parent'] > 0) { $affected[] = (int) $columns['parent']; }
        if ($id !== null) {
            $current = parent::find($modx, array('id' => $id));
            if ($current && isset($columns['parent']) && (int) $current->get('parent') > 0) { $affected[] = (int) $current->get('parent'); }
            // Children follow their parent into another context.
            if ($current && isset($columns['context_key']) && (string) $columns['context_key'] !== (string) $current->get('context_key')) {
                $affected = array_merge($affected, $this->descendants($modx, $id));
            }
        }
        return array_values(array_unique($affected));
    }

    public function writeWarnings($object) {
        $class = (string) $object->get('class_key');
        if (in_array($class, array('', 'modDocument', 'modResource', 'modWebLink', 'modSymLink', 'modStaticResource'), true)) { return array(); }
        return array("Resource {$object->get('id')} has class {$class}: data that class keeps outside the resource table is not journaled and is not restored by modx_undo.");
    }
}
