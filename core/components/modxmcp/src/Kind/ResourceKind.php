<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Integration\MiniShop2;
use ModxMCP\Integration\Migx;

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

    // ---- miniShop2: a product (class_key msProduct) keeps its price, options and extra categories outside the resource ----

    private $context;

    public function attach(Context $context) {
        $this->context = $context;
    }

    /** miniShop2 can be used on this site (installed, code present, group not switched off). */
    private function ms2Active() {
        return $this->context !== null && MiniShop2::siteAvailable($this->context);
    }

    public function prepare(\modX $modx) {
        if ($this->ms2Active()) { MiniShop2::load($modx); }
    }

    /**
     * A recorded state that holds product rows cannot be put back while miniShop2 is switched off or gone: the rows would
     * be left as they are and the undo would claim to have restored them.
     */
    public function assertUndoable(\modX $modx, $key, $target) {
        if ($target === null || $this->ms2Active()) { return; }
        foreach (array('ms2_data', 'ms2_options', 'ms2_categories') as $name) {
            if (!empty($target['relations'][$name])) {
                throw new ApiException('not_reversible', "Resource {$key} was recorded with its miniShop2 product data, which cannot be restored while miniShop2 is switched off or not installed. Switch it on (modxmcp.disabled_groups) and undo again.");
            }
        }
    }

    private static function isMs2Relation($name) {
        return in_array($name, array('ms2_data', 'ms2_options', 'ms2_categories'), true);
    }

    private static function ms2Specs() {
        return array(
            'ms2_data' => array(
                'class' => 'msProductData', 'fk' => 'id', 'present' => 'id', 'writable' => true, 'map' => true,
                'description' => 'miniShop2 product data (msProduct only) as {column: value}: article, price, old_price, weight, vendor (msVendor id), '
                    . 'made_in, new, popular, favorite, tags, color, size (lists of strings), source (media source id); image and thumb are read-only '
                    . '(the gallery maintains them). Writing changes only the given columns. A product has no options with the keys tags, color or size: '
                    . 'miniShop2 copies those columns into option values.',
            ),
            'ms2_options' => array(
                'class' => 'msProductOption', 'fk' => 'product_id', 'present' => 'key', 'writable' => true, 'map' => true,
                'description' => 'miniShop2 option values of a product (msProduct only) as {option key: [values]} (kind ms2_option declares the keys). '
                    . 'Writing replaces the values of each given key; [] removes the key; a key that is not a miniShop2 option is refused.',
            ),
            'ms2_categories' => array(
                'class' => 'msCategoryMember', 'fk' => 'product_id', 'present' => 'category_id', 'writable' => true,
                'description' => 'Ids of the additional miniShop2 categories of a product (msProduct only), not its parent. Writing replaces the set; every id must be an msCategory resource.',
            ),
        );
    }

    /** The relations that exist for this object: a resource of another class has no product data. */
    public function hasRelation(\modX $modx, $object, $name) {
        return !self::isMs2Relation($name) || (string) $object->get('class_key') === 'msProduct';
    }

    public function relationNotApplicable($object, $name) {
        if (!self::isMs2Relation($name)) { return parent::relationNotApplicable($object, $name); }
        return "Resource {$object->get('id')} is not a miniShop2 product (class_key msProduct); {$name} applies to products only.";
    }

    public function relationBlockReason(Context $context, $name) {
        return self::isMs2Relation($name) ? $context->capabilities->groupBlockReason(MiniShop2::GROUP, $context->token) : null;
    }

    /** msProduct::toArray() mixes in the product data, its option values and its vendor; those are relations here. */
    public function objectColumns($object) {
        if (method_exists($object, 'getResourceFieldsNames')) {
            $raw = property_exists($object, '_fields') ? $object->_fields : $object->toArray('', true);
            return array_intersect_key($raw, array_flip($object->getResourceFieldsNames()));
        }
        return parent::objectColumns($object);
    }

    /** A product must never be left without its data row (the shop breaks): the create processor makes it, this makes sure. */
    public function beforeCreate(Context $context, array $columns) {
        if (isset($columns['class_key']) && $columns['class_key'] === 'msProduct' && MiniShop2::siteAvailable($context)) {
            return function ($tx, $id) use ($context) { $this->ensureData($context->modx, (int) $id); };
        }
        return null;
    }

    private function ensureData(\modX $modx, $id) {
        MiniShop2::load($modx);
        if ($modx->getObject('msProductData', array('id' => $id), false)) { return $modx->getObject('msProductData', array('id' => $id), false); }
        $data = $modx->newObject('msProductData');
        $data->fromArray(array('id' => $id), '', true, true);
        if (!$data->save()) { throw new \RuntimeException("Cannot create the miniShop2 data of product {$id}."); }
        return $data;
    }

    /** One column of msProductData as the API shows it. */
    private static function dataValue($type, $raw) {
        if ($raw === null) { return $type === 'list' ? array() : ($type === 'string' ? '' : ($type === 'boolean' ? false : ($type === 'float' ? 0.0 : 0))); }
        switch ($type) {
            case 'float':   return (float) $raw;
            case 'integer': return (int) $raw;
            case 'boolean': return (bool) (int) $raw;
            case 'list':
                $list = is_string($raw) ? json_decode($raw, true) : $raw;
                return is_array($list) ? array_values($list) : array();
            default:        return (string) $raw;
        }
    }

    /** Values as miniShop2 keeps option values: trimmed, without duplicates and empty ones (msProductData::prepareOptionValues). */
    private static function cleanValues(array $values) {
        $out = array();
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && !in_array($value, $out, true)) { $out[] = $value; }
        }
        return $out;
    }

    private function dataMap($modx, $id, $writableOnly) {
        $data = $modx->getObject('msProductData', array('id' => $id), false);
        $out = array();
        if (!$data) { return $out; }
        $row = $data->toArray('', true);
        foreach (MiniShop2::DATA_COLUMNS as $column => $definition) {
            if ($writableOnly && $definition[1]) { continue; }
            $out[$column] = self::dataValue($definition[0], array_key_exists($column, $row) ? $row[$column] : null);
        }
        return $out;
    }

    /** {option key: [values]} of a product without the keys miniShop2 mirrors from the JSON columns of its data. */
    private function optionMap($modx, $id) {
        $out = array();
        foreach ($modx->getCollection('msProductOption', array('product_id' => $id)) as $row) {
            $key = (string) $row->get('key');
            if (in_array($key, MiniShop2::MIRRORED_OPTIONS, true)) { continue; }
            $out[$key][] = (string) $row->get('value');
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    public function relationSpecs() {
        $specs = $this->baseRelationSpecs();
        return $this->ms2Active() ? $specs + self::ms2Specs() : $specs;
    }

    private function baseRelationSpecs() {
        return array(
            'tvs' => array(
                'class' => 'modTemplateVarResource', 'fk' => 'contentid', 'present' => 'value', 'writable' => true, 'map' => true,
                'description' => 'Template variable values as {tv name: value}, for the TVs of the resource template. '
                    . 'Reads show the stored value, or the TV default when none is stored. Writing sets only the named TVs. '
                    . 'A TV of input type migx holds a JSON list of objects: it is read as that list when the stored text is valid JSON '
                    . '(otherwise as the text) and may be written as a list of objects (items without MIGX_id get one) or as a string.',
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

    /** TVs attached to the resource template: name => [id, default, input type]. */
    private function templateTvs(\modX $modx, $object) {
        $out = array();
        foreach ($modx->getCollection('modTemplateVarTemplate', array('templateid' => (int) $object->get('template'))) as $link) {
            $tv = $modx->getObject('modTemplateVar', array('id' => $link->get('tmplvarid')), false);
            if ($tv) { $out[(string) $tv->get('name')] = array((int) $tv->get('id'), (string) $tv->get('default_text'), (string) $tv->get('type')); }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    public function presentRelation(\modX $modx, $object, $name) {
        if ($name === 'ms2_data') { $map = $this->dataMap($modx, (int) $object->get('id'), false); return $map ? $map : new \stdClass(); }
        if ($name === 'ms2_options') { $map = $this->optionMap($modx, (int) $object->get('id')); return $map ? $map : new \stdClass(); }
        if ($name === 'ms2_categories') {
            $ids = parent::presentRelation($modx, $object, $name);
            sort($ids, SORT_NUMERIC);
            return $ids;
        }
        if ($name !== 'tvs') { return parent::presentRelation($modx, $object, $name); }
        $stored = array();
        foreach ($modx->getCollection('modTemplateVarResource', array('contentid' => $object->get('id'))) as $row) {
            $stored[(int) $row->get('tmplvarid')] = (string) $row->get('value');
        }
        $out = array();
        foreach ($this->templateTvs($modx, $object) as $tvName => $tv) {
            $text = isset($stored[$tv[0]]) ? $stored[$tv[0]] : $tv[1];
            $out[$tvName] = $tv[2] === Migx::TV_TYPE ? self::migxValue($text) : $text;
        }
        return $out ? $out : new \stdClass();
    }

    public function storedRelation(\modX $modx, $object, $name) {
        if ($name === 'ms2_data') { $map = $this->dataMap($modx, (int) $object->get('id'), true); return $map ? $map : new \stdClass(); }
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
        if (self::isMs2Relation($name)) { return $this->writeMs2($modx, $object, $name, $value); }
        if ($name !== 'tvs') { return parent::writeRelation($modx, $object, $name, $value); }
        $known = $this->templateTvs($modx, $object);
        foreach ($value as $tvName => $tvValue) {
            if (!isset($known[$tvName])) {
                throw new ApiException('validation_failed', "TV '{$tvName}' is not attached to template {$object->get('template')} of this resource. Its TVs: " . implode(', ', array_keys($known)) . '.');
            }
            if ($known[$tvName][2] === Migx::TV_TYPE && is_array($tvValue)) {
                $value[$tvName] = self::encodeMigxValue($tvName, $tvValue);
            } elseif (!is_scalar($tvValue)) {
                throw new ApiException('validation_failed', $known[$tvName][2] === Migx::TV_TYPE
                    ? "The value of TV '{$tvName}' must be a string or a list of objects."
                    : "The value of TV '{$tvName}' must be a string, number or boolean.");
            }
        }
        foreach ($value as $tvName => $tvValue) {
            if (!$object->setTVValue($known[$tvName][0], is_bool($tvValue) ? (int) $tvValue : (string) $tvValue)) {
                throw new \RuntimeException("Cannot save TV '{$tvName}'.");
            }
        }
    }

    // ---- MIGX: a TV of input type migx keeps a JSON list of objects ----

    /**
     * The stored text of a MIGX TV as the API shows it: the decoded list (or object) when the text is valid JSON,
     * otherwise the text itself (an empty value, a default such as "@CHUNK x", something an old version wrote).
     */
    private static function migxValue($text) {
        if (!is_string($text) || trim($text) === '') { return $text; }
        $decoded = json_decode($text, false);
        return json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded)) ? $decoded : $text;
    }

    /**
     * The text MIGX stores for a list of objects: every item without MIGX_id gets one, continuing from the highest
     * present (as MIGX's own update processor does for a new row); the items keep their order.
     */
    private static function encodeMigxValue($tvName, array $items) {
        if ($items && array_keys($items) !== range(0, count($items) - 1)) {
            throw new ApiException('validation_failed', "The value of TV '{$tvName}' must be a list of objects (an object is not a list of rows).");
        }
        $highest = 0;
        foreach ($items as $index => $item) {
            if (!is_array($item) || ($item && array_keys($item) === range(0, count($item) - 1))) {
                throw new ApiException('validation_failed', "The value of TV '{$tvName}': item {$index} must be an object.");
            }
            if (isset($item['MIGX_id']) && $item['MIGX_id'] !== '' && is_numeric($item['MIGX_id']) && $item['MIGX_id'] > $highest) { $highest = $item['MIGX_id']; }
        }
        foreach ($items as $index => $item) {
            if (!isset($item['MIGX_id']) || $item['MIGX_id'] === '') { $items[$index]['MIGX_id'] = ++$highest; }
        }
        $text = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($text === false) { throw new ApiException('validation_failed', "The value of TV '{$tvName}' cannot be encoded as JSON (" . json_last_error_msg() . ').'); }
        return $text;
    }

    // ---- writing the product relations ----
    //
    // The rows are written directly (inside the transaction), the way msProductData::save() and the miniShop2 product
    // processors write them. The product update processor is not used for them: with `options` in its request, it
    // deletes every option value that is not assigned to one of the product's categories. miniShop2 fires no event of
    // its own around a product save; the core events OnBeforeDocFormSave / OnDocFormSave belong to the resource
    // processor, which runs when resource fields are written.

    private function writeMs2(\modX $modx, $object, $name, $value) {
        if (!$this->ms2Active()) { throw new ApiException('validation_failed', "Unknown relation '{$name}' for resource. See modx_describe."); }
        $id = (int) $object->get('id');
        if ((string) $object->get('class_key') !== 'msProduct') {
            throw new ApiException('validation_failed', "Resource {$id} is not a miniShop2 product (class_key msProduct); {$name} applies to products only.");
        }
        MiniShop2::load($modx);
        if ($name === 'ms2_data') { return $this->writeData($modx, $id, (array) $value); }
        if ($name === 'ms2_options') { return $this->writeOptions($modx, $id, (array) $value); }
        return $this->writeCategories($modx, $object, $id, (array) $value);
    }

    private function writeData(\modX $modx, $id, array $value) {
        $set = array();
        foreach ($value as $column => $given) {
            $column = (string) $column;
            if (!isset(MiniShop2::DATA_COLUMNS[$column])) {
                throw new ApiException('validation_failed', "Unknown column '{$column}' of ms2_data. Columns: " . implode(', ', array_keys(MiniShop2::DATA_COLUMNS)) . '.');
            }
            list($type, $readOnly) = MiniShop2::DATA_COLUMNS[$column];
            if ($readOnly) { throw new ApiException('validation_failed', "Column '{$column}' of ms2_data is read-only (the product gallery maintains it)."); }
            $set[$column] = $this->dataInput($modx, $column, $type, $given);
        }
        $data = $modx->getObject('msProductData', array('id' => $id), false);
        if (!$data) {
            $data = $modx->newObject('msProductData');
            $data->fromArray(array('id' => $id), '', true, true);
        }
        foreach ($set as $column => $given) { $data->set($column, $given); }
        if (!$data->save()) { throw new \RuntimeException("Cannot save the miniShop2 data of product {$id}."); }
    }

    private function dataInput(\modX $modx, $column, $type, $given) {
        $fail = function ($what) use ($column) { throw new ApiException('validation_failed', "Column '{$column}' of ms2_data must be {$what}."); };
        switch ($type) {
            case 'string':
                if (!is_string($given)) { $fail('a string'); }
                return $given;
            case 'float':
                if ((!is_int($given) && !is_float($given)) || !is_finite((float) $given) || $given < 0) { $fail('a number, zero or more'); }
                return $given;
            case 'integer':
                if (!is_int($given) || $given < 0) { $fail('a whole number, zero or more'); }
                if ($column === 'vendor' && $given > 0 && !$modx->getCount('msVendor', array('id' => $given))) {
                    throw new ApiException('validation_failed', "miniShop2 vendor {$given} does not exist.");
                }
                if ($column === 'source' && !$modx->getCount('sources.modMediaSource', array('id' => $given))) {
                    throw new ApiException('validation_failed', "Media source {$given} does not exist.");
                }
                return $given;
            case 'boolean':
                if (!is_bool($given)) { $fail('true or false'); }
                return $given ? 1 : 0;
            default:
                if (!is_array($given) || ($given && array_keys($given) !== range(0, count($given) - 1))) { $fail('a list of strings'); }
                foreach ($given as $item) { if (!is_string($item)) { $fail('a list of strings'); } }
                return self::cleanValues($given);
        }
    }

    private function writeOptions(\modX $modx, $id, array $value) {
        $plan = array();
        foreach ($value as $key => $values) {
            $key = (string) $key;
            if (in_array($key, MiniShop2::MIRRORED_OPTIONS, true)) {
                throw new ApiException('validation_failed', "'{$key}' is a column of ms2_data (miniShop2 copies it into option values); change it there.");
            }
            if (!$modx->getCount('msOption', array('key' => $key))) { throw new ApiException('validation_failed', "Unknown miniShop2 option '{$key}'."); }
            if (!is_array($values) || ($values && array_keys($values) !== range(0, count($values) - 1))) {
                throw new ApiException('validation_failed', "The value of option '{$key}' must be a list of values ([] removes the key).");
            }
            foreach ($values as $item) {
                if (!is_string($item) && !is_int($item) && !is_float($item)) { throw new ApiException('validation_failed', "The values of option '{$key}' must be strings or numbers."); }
            }
            $plan[$key] = self::cleanValues($values);
        }
        foreach ($plan as $key => $values) {
            $current = array();
            foreach ($modx->getCollection('msProductOption', array('product_id' => $id, 'key' => $key)) as $row) { $current[] = (string) $row->get('value'); }
            if ($current === $values) { continue; }
            $modx->removeCollection('msProductOption', array('product_id' => $id, 'key' => $key));
            foreach ($values as $item) {
                $row = $modx->newObject('msProductOption');
                $row->fromArray(array('product_id' => $id, 'key' => $key, 'value' => $item), '', true, true);
                if (!$row->save()) { throw new \RuntimeException("Cannot save option '{$key}' of product {$id}."); }
            }
        }
    }

    private function writeCategories(\modX $modx, $object, $id, array $value) {
        $parent = (int) $object->get('parent');
        $want = array();
        foreach ($value as $category) {
            if (!is_int($category) || $category < 1) { throw new ApiException('validation_failed', 'ms2_categories must be a list of resource ids.'); }
            if ($category === $parent) { throw new ApiException('validation_failed', "Resource {$category} is the parent category of the product; ms2_categories lists the additional categories only."); }
            if (!$modx->getCount('modResource', array('id' => $category, 'class_key' => 'msCategory'))) {
                throw new ApiException('validation_failed', "Resource {$category} is not a miniShop2 category (class_key msCategory).");
            }
            $want[$category] = true;
        }
        $have = array();
        foreach ($modx->getCollection('msCategoryMember', array('product_id' => $id)) as $row) { $have[(int) $row->get('category_id')] = true; }
        foreach (array_keys(array_diff_key($have, $want)) as $category) {
            $modx->removeCollection('msCategoryMember', array('product_id' => $id, 'category_id' => $category));
        }
        foreach (array_keys(array_diff_key($want, $have)) as $category) {
            $row = $modx->newObject('msCategoryMember');
            $row->fromArray(array('product_id' => $id, 'category_id' => $category), '', true, true);
            if (!$row->save()) { throw new \RuntimeException("Cannot add product {$id} to category {$category}."); }
        }
    }

    /**
     * msProductData::save() creates a new row with the default media source whatever the row said, so a restored
     * row gets its source put back.
     */
    public function restoreRelation(\modX $modx, $name, array $spec, $key, array $rows) {
        if ($name !== 'ms2_data') { return false; }
        $modx->removeCollection('msProductData', array('id' => $key));
        foreach ($rows as $row) {
            $data = $modx->newObject('msProductData');
            $data->fromArray($row, '', true, true);
            if (!$data->save()) { throw new \RuntimeException("Cannot restore the miniShop2 data of product {$key}."); }
            if (isset($row['source']) && (string) $data->get('source') !== (string) $row['source']) {
                $data->set('source', $row['source']);
                if (!$data->save()) { throw new \RuntimeException("Cannot restore the miniShop2 data of product {$key}."); }
            }
        }
        return true;
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
        // With miniShop2 usable, a product's data is part of its state and is journaled.
        if ($class === 'msProduct' && $this->ms2Active()) { return array(); }
        if (in_array($class, array('', 'modDocument', 'modResource', 'modWebLink', 'modSymLink', 'modStaticResource'), true)) { return array(); }
        return array("Resource {$object->get('id')} has class {$class}: data that class keeps outside the resource table is not journaled and is not restored by modx_undo.");
    }
}
