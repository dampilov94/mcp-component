<?php
/** minishop2 operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPMinishop2Trait {
    /**
     * miniShop2 product-link tooling: link TYPES (msLink: name + relation type +
     * description) and product-to-product LINKS (msProductLink: link + master + slave).
     * Relation types are: many_to_many, one_to_many, many_to_one, one_to_one.
     * Backed by miniShop2's own mgr processors via runMiniShop2Processor().
     */
    private function ms2LinkAction($action, $data) {
        $map = array(
            'ms2_list_link_types'     => array('p' => 'mgr/settings/link/getlist',     'list' => true),
            'ms2_get_link_type'       => array('p' => 'mgr/settings/link/get'),
            'ms2_create_link_type'    => array('p' => 'mgr/settings/link/create'),
            'ms2_update_link_type'    => array('p' => 'mgr/settings/link/update'),
            'ms2_delete_link_type'    => array('p' => 'mgr/settings/link/remove'),
            'ms2_list_product_links'  => array('p' => 'mgr/product/productlink/getlist', 'list' => true),
            'ms2_create_product_link' => array('p' => 'mgr/product/productlink/create'),
            'ms2_delete_product_link' => array('p' => 'mgr/product/productlink/remove'),
            'ms2_list_categories'     => array('p' => 'mgr/category/getlist', 'list' => true),
            'ms2_create_category'     => array('p' => 'mgr/category/create'),
            'ms2_update_category'     => array('p' => 'mgr/category/update'),
            'ms2_list_orders'         => array('p' => 'mgr/orders/getlist', 'list' => true),
            'ms2_get_order'           => array('p' => 'mgr/orders/get'),
            'ms2_update_order'        => array('p' => 'mgr/orders/update'),
        );
        if (!isset($map[$action])) { throw new ModxMCPClientException("Unknown miniShop2 link action: {$action}."); }
        $cfg = $map[$action];
        $props = is_array($data) ? $data : array();
        unset($props['action'], $props['elementType']);
        if (!empty($cfg['list']) && !isset($props['limit'])) { $props['limit'] = 0; }

        // A category is an msCategory resource. The ms2 category create/update processors extend
        // the resource processors with manager-only setup and don't run cleanly headless, so route
        // create/update through the core resource processors with class_key=msCategory instead.
        if ($action === 'ms2_create_category' || $action === 'ms2_update_category') {
            $props['class_key'] = 'msCategory';
            if ($action === 'ms2_create_category') {
                if (!isset($props['context_key'])) { $props['context_key'] = 'web'; }
                if (!isset($props['parent'])) { $props['parent'] = 0; }
                if (!isset($props['published'])) { $props['published'] = 1; }
                $resp = $this->modx->runProcessor('resource/create', $props);
            } else {
                $resp = $this->modx->runProcessor('resource/update', $props);
            }
            if (!$resp || $resp->isError()) { throw new ModxMCPClientException($resp ? $this->formatProcessorErrors($resp) : 'ms2 category: no response.'); }
            if ($this->modx->getCacheManager()) { $this->refreshContentCache(); }
            $this->logAudit($action, 'ms2', array_intersect_key($props, array_flip(array('id', 'pagetitle', 'parent'))));
            return $this->normalizeProcessorResponse($resp);
        }

        $result = $this->runMiniShop2Processor($cfg['p'], $props);
        $this->logAudit($action, 'ms2', array_intersect_key($props, array_flip(array('id', 'link', 'master', 'slave', 'name', 'type'))));
        return $result;
    }

    private function listMs2OptionTypes(array $data = []) {
        return $this->runMiniShop2Processor('mgr/settings/option/gettypes', $data);
    }

    private function listMs2Options(array $data = []) {
        $payload = [];
        foreach (['query', 'category', 'modcategory', 'limit', 'start'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }
        if (empty($payload['limit'])) {
            $payload['limit'] = 100;
        }
        return $this->runMiniShop2Processor('mgr/settings/option/getlist', $payload);
    }

    private function getMs2Option(array $data) {
        $id = $this->requirePositiveInt($data, 'id');
        return $this->runMiniShop2Processor('mgr/settings/option/get', ['id' => $id]);
    }

    private function createMs2Option(array $data) {
        $payload = $this->prepareMs2OptionPayload($data, false);
        $result = $this->runMiniShop2Processor('mgr/settings/option/create', $payload);
        $this->refreshContentCache();
        $this->logAudit('ms2_create_option', 'ms2_option', ['key' => isset($payload['key']) ? $payload['key'] : null]);
        return $result;
    }

    private function updateMs2Option(array $data) {
        $payload = $this->prepareMs2OptionPayload($data, true);
        $result = $this->runMiniShop2Processor('mgr/settings/option/update', $payload);
        $this->refreshContentCache();
        $this->logAudit('ms2_update_option', 'ms2_option', ['id' => $payload['id']]);
        return $result;
    }

    private function assignMs2OptionToCategory(array $data) {
        $payload = [
            'option_id' => $this->requirePositiveInt($data, 'option_id'),
            'category_id' => $this->requirePositiveInt($data, 'category_id'),
        ];
        $result = $this->runMiniShop2Processor('mgr/settings/option/assign', $payload);
        $this->refreshContentCache();
        $this->logAudit('ms2_assign_option_to_category', 'ms2_option', $payload);
        return $result;
    }

    private function getMs2ProductOptions(array $data) {
        $productId = $this->requirePositiveInt($data, 'product_id');
        $this->assertMs2Product($productId);
        $this->loadMiniShop2Service();

        $query = $this->modx->newQuery('msProductOption');
        $query->leftJoin('msOption', 'Option', 'Option.key = msProductOption.key');
        $query->where(['msProductOption.product_id' => $productId]);
        $query->sortby('msProductOption.key', 'ASC');
        $query->select($this->modx->getSelectColumns('msProductOption', 'msProductOption'));
        $query->select([
            'caption' => 'Option.caption',
            'type' => 'Option.type',
            'measure_unit' => 'Option.measure_unit',
        ]);

        $rows = [];
        if ($query->prepare() && $query->stmt->execute()) {
            $rows = $query->stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'product_id' => $productId,
            'options' => $rows,
        ];
    }

    private function updateMs2ProductOptions(array $data) {
        $productId = $this->requirePositiveInt($data, 'product_id');
        $this->assertMs2Product($productId);

        if (empty($data['options']) || !is_array($data['options'])) {
            throw new ModxMCPClientException('options payload must be a non-empty object.');
        }

        $this->loadMiniShop2Service();
        $changed = [];

        return $this->runWithTransaction(function () use ($productId, $data, &$changed) {
            foreach ($data['options'] as $key => $value) {
                $key = trim((string)$key);
                if ($key === '') {
                    continue;
                }

                if (!$this->modx->getObject('msOption', ['key' => $key])) {
                    throw new ModxMCPClientException("miniShop2 option not found: {$key}.");
                }

                if ($value === null) {
                    $this->modx->removeCollection('msProductOption', [
                        'product_id' => $productId,
                        'key' => $key,
                    ]);
                    $changed[$key] = null;
                    continue;
                }

                if (is_array($value)) {
                    $value = implode('||', array_map('strval', $value));
                } elseif (is_bool($value)) {
                    $value = $value ? '1' : '0';
                } else {
                    $value = (string)$value;
                }

                $this->modx->removeCollection('msProductOption', [
                    'product_id' => $productId,
                    'key' => $key,
                ]);

                $option = $this->modx->newObject('msProductOption');
                $option->set('product_id', $productId);
                $option->set('key', $key);
                $option->set('value', $value);
                if (!$option->save()) {
                    throw new ModxMCPClientException("Could not save product option: {$key}.");
                }
                $changed[$key] = $value;
            }

            $this->refreshContentCache();
            $this->logAudit('ms2_update_product_options', 'ms2_product_option', [
                'product_id' => $productId,
                'keys' => array_keys($changed),
            ]);

            return $this->getMs2ProductOptions(['product_id' => $productId]);
        });
    }

    private function assertMs2Product($productId) {
        $product = $this->modx->getObject('modResource', $productId);
        if (!$product) {
            throw new ModxMCPClientException("Product resource not found: {$productId}.");
        }
        if ($product->get('class_key') !== 'msProduct') {
            throw new ModxMCPClientException("Resource {$productId} is not an msProduct.");
        }
    }

    private function prepareMs2OptionPayload(array $data, $requireId) {
        $payload = [];
        if ($requireId) {
            $payload['id'] = $this->requirePositiveInt($data, 'id');
        }

        foreach (['key', 'caption', 'description', 'measure_unit', 'category', 'type'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (!$requireId) {
            foreach (['key', 'caption', 'type'] as $field) {
                if (!isset($payload[$field]) || $payload[$field] === '') {
                    throw new ModxMCPClientException("{$field} is required for miniShop2 option creation.");
                }
            }
            if (!array_key_exists('category', $payload)) {
                $payload['category'] = 0;
            }
        }

        if (array_key_exists('properties', $data)) {
            $payload['properties'] = is_array($data['properties'])
                ? json_encode($data['properties'], JSON_UNESCAPED_UNICODE)
                : $data['properties'];
        }

        if (!empty($data['category_ids']) && is_array($data['category_ids'])) {
            $categories = [];
            foreach ($data['category_ids'] as $categoryId) {
                $categoryId = (int)$categoryId;
                if ($categoryId > 0) {
                    $categories[$categoryId] = true;
                }
            }
            if (!empty($categories)) {
                $payload['categories'] = json_encode($categories);
            }
        }

        return $payload;
    }

    private function runMiniShop2Processor($action, array $payload = []) {
        $this->loadMiniShop2Service();
        $response = $this->modx->runProcessor($action, $payload, [
            'processors_path' => $this->getMiniShop2CorePath() . 'processors/',
        ]);

        if (!$response || $response->isError()) {
            throw new ModxMCPClientException('miniShop2 processor failed: ' . ($response ? $this->formatProcessorErrors($response) : 'No processor response.'));
        }

        return $this->normalizeProcessorResponse($response);
    }

    private function loadMiniShop2Service() {
        $corePath = $this->getMiniShop2CorePath();
        $service = $this->modx->getService('miniShop2', 'miniShop2', $corePath . 'model/minishop2/');
        if (!$service) {
            throw new ModxMCPClientException('Could not load miniShop2 service. Is miniShop2 installed on this MODX site?');
        }
        $service->initialize($this->modx->context ? $this->modx->context->get('key') : 'mgr');
        return $service;
    }

    private function getMiniShop2CorePath() {
        return $this->modx->getOption('minishop2.core_path', null, $this->modx->getOption('core_path') . 'components/minishop2/');
    }
}
