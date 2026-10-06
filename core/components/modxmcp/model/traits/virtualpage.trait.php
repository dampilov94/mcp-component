<?php
/** virtualpage operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPVirtualpageTrait {
    /**
     * Delete a VirtualPage object (vpEvent / vpHandler / vpRoute) by id or name.
     * Mirrors the direct-xPDO style of the other VP methods; xPDO removes composite
     * children (e.g. an event's routes) per the VP schema.
     */
    private function deleteVirtualPageObject($class, $action, array $data) {
        $obj = $this->resolveVirtualPageObject($class, $data);
        $id = (int) $obj->get('id');
        $name = $obj->get('name');
        if (!$obj->remove()) {
            throw new ModxMCPClientException("Could not delete {$class} {$id}.");
        }
        $this->clearVirtualPageCache();
        $this->logAudit($action, 'virtualpage', ['id' => $id, 'name' => $name]);
        return ['deleted' => true, 'id' => $id, 'name' => $name];
    }

    private function listVirtualPageEvents(array $data = []) {
        $this->loadVirtualPagePackage();

        $query = $this->modx->newQuery('vpEvent');
        $this->applyVirtualPageListFilters($query, $data, ['id', 'name', 'active']);
        $query->sortby('rank', 'ASC');
        $query->sortby('id', 'ASC');
        $query->limit($this->getListLimit($data), $this->getListStart($data));

        $items = [];
        foreach ($this->modx->getCollection('vpEvent', $query) as $event) {
            $items[] = $this->normalizeVirtualPageEvent($event, !empty($data['include_routes']));
        }

        return [
            'count' => count($items),
            'events' => $items,
        ];
    }

    private function getVirtualPageEvent(array $data) {
        $event = $this->resolveVirtualPageObject('vpEvent', $data);
        return $this->normalizeVirtualPageEvent($event, true);
    }

    private function createVirtualPageEvent(array $data) {
        $this->loadVirtualPagePackage();
        $payload = $this->prepareVirtualPagePayload($data, ['name', 'description', 'rank', 'active']);
        if (empty($payload['name'])) {
            throw new ModxMCPClientException('name is required for VirtualPage event creation.');
        }
        if ($this->modx->getObject('vpEvent', ['name' => $payload['name']])) {
            throw new ModxMCPClientException("VirtualPage event already exists: {$payload['name']}.");
        }
        if (!array_key_exists('active', $payload)) {
            $payload['active'] = 1;
        }
        if (!array_key_exists('rank', $payload)) {
            $payload['rank'] = $this->modx->getCount('vpEvent');
        }

        $event = $this->modx->newObject('vpEvent');
        $event->fromArray($payload, '', true, true);
        if (!$event->save()) {
            throw new ModxMCPClientException('Could not save VirtualPage event.');
        }

        $this->clearVirtualPageCache();
        $this->logAudit('virtualpage_create_event', 'virtualpage_event', ['id' => $event->get('id'), 'name' => $event->get('name')]);
        return $this->normalizeVirtualPageEvent($event, true);
    }

    private function updateVirtualPageEvent(array $data) {
        $event = $this->resolveVirtualPageObject('vpEvent', $data);
        $payload = $this->prepareVirtualPagePayload($data, ['name', 'description', 'rank', 'active']);
        if (empty($payload)) {
            throw new ModxMCPClientException('No VirtualPage event fields to update.');
        }
        if (!empty($payload['name'])) {
            $duplicate = $this->modx->getObject('vpEvent', ['name' => $payload['name']]);
            if ($duplicate && (int)$duplicate->get('id') !== (int)$event->get('id')) {
                throw new ModxMCPClientException("VirtualPage event already exists: {$payload['name']}.");
            }
        }

        $event->fromArray($payload, '', true, true);
        if (!$event->save()) {
            throw new ModxMCPClientException('Could not update VirtualPage event.');
        }

        $this->ensureVirtualPagePluginEvent($event->get('name'));
        $this->clearVirtualPageCache();
        $this->logAudit('virtualpage_update_event', 'virtualpage_event', ['id' => $event->get('id')]);
        return $this->normalizeVirtualPageEvent($event, true);
    }

    private function listVirtualPageHandlers(array $data = []) {
        $this->loadVirtualPagePackage();

        $query = $this->modx->newQuery('vpHandler');
        $this->applyVirtualPageListFilters($query, $data, ['id', 'name', 'type', 'entry', 'active']);
        $query->sortby('rank', 'ASC');
        $query->sortby('id', 'ASC');
        $query->limit($this->getListLimit($data), $this->getListStart($data));

        $items = [];
        foreach ($this->modx->getCollection('vpHandler', $query) as $handler) {
            $items[] = $this->normalizeVirtualPageHandler($handler, !empty($data['include_routes']));
        }

        return [
            'count' => count($items),
            'handlers' => $items,
        ];
    }

    private function getVirtualPageHandler(array $data) {
        $handler = $this->resolveVirtualPageObject('vpHandler', $data);
        return $this->normalizeVirtualPageHandler($handler, true);
    }

    private function createVirtualPageHandler(array $data) {
        $this->loadVirtualPagePackage();
        $payload = $this->prepareVirtualPagePayload($data, ['name', 'type', 'entry', 'content', 'description', 'cache', 'rank', 'active']);
        if (empty($payload['name'])) {
            throw new ModxMCPClientException('name is required for VirtualPage handler creation.');
        }
        if ($this->modx->getObject('vpHandler', ['name' => $payload['name']])) {
            throw new ModxMCPClientException("VirtualPage handler already exists: {$payload['name']}.");
        }
        if (!array_key_exists('type', $payload)) {
            $payload['type'] = 3;
        }
        $payload['type'] = $this->normalizeVirtualPageHandlerType($payload['type']);
        if (!array_key_exists('entry', $payload)) {
            $payload['entry'] = 0;
        }
        $this->assertVirtualPageHandlerEntry($payload['type'], $payload['entry']);
        if (!array_key_exists('active', $payload)) {
            $payload['active'] = 1;
        }
        if (!array_key_exists('cache', $payload)) {
            $payload['cache'] = 0;
        }
        if (!array_key_exists('rank', $payload)) {
            $payload['rank'] = $this->modx->getCount('vpHandler');
        }

        $handler = $this->modx->newObject('vpHandler');
        $handler->fromArray($payload, '', true, true);
        if (!$handler->save()) {
            throw new ModxMCPClientException('Could not save VirtualPage handler.');
        }

        $this->clearVirtualPageCache();
        $this->logAudit('virtualpage_create_handler', 'virtualpage_handler', ['id' => $handler->get('id'), 'name' => $handler->get('name')]);
        return $this->normalizeVirtualPageHandler($handler, true);
    }

    private function updateVirtualPageHandler(array $data) {
        $handler = $this->resolveVirtualPageObject('vpHandler', $data);
        $payload = $this->prepareVirtualPagePayload($data, ['name', 'type', 'entry', 'content', 'description', 'cache', 'rank', 'active']);
        if (empty($payload)) {
            throw new ModxMCPClientException('No VirtualPage handler fields to update.');
        }
        if (!empty($payload['name'])) {
            $duplicate = $this->modx->getObject('vpHandler', ['name' => $payload['name']]);
            if ($duplicate && (int)$duplicate->get('id') !== (int)$handler->get('id')) {
                throw new ModxMCPClientException("VirtualPage handler already exists: {$payload['name']}.");
            }
        }
        if (array_key_exists('type', $payload)) {
            $payload['type'] = $this->normalizeVirtualPageHandlerType($payload['type']);
        }
        $type = array_key_exists('type', $payload) ? $payload['type'] : (int)$handler->get('type');
        $entry = array_key_exists('entry', $payload) ? $payload['entry'] : $handler->get('entry');
        $this->assertVirtualPageHandlerEntry($type, $entry);

        $handler->fromArray($payload, '', true, true);
        if (!$handler->save()) {
            throw new ModxMCPClientException('Could not update VirtualPage handler.');
        }

        $this->clearVirtualPageCache();
        $this->logAudit('virtualpage_update_handler', 'virtualpage_handler', ['id' => $handler->get('id')]);
        return $this->normalizeVirtualPageHandler($handler, true);
    }

    private function listVirtualPageRoutes(array $data = []) {
        $this->loadVirtualPagePackage();

        $query = $this->modx->newQuery('vpRoute');
        $query->leftJoin('vpEvent', 'Event', 'Event.id = vpRoute.event');
        $query->leftJoin('vpHandler', 'Handler', 'Handler.id = vpRoute.handler');
        $query->select($this->modx->getSelectColumns('vpRoute', 'vpRoute'));
        $query->select([
            'event_name' => 'Event.name',
            'handler_name' => 'Handler.name',
        ]);

        if (!empty($data['event_name'])) {
            $query->where(['Event.name' => (string)$data['event_name']]);
        }
        if (!empty($data['handler_name'])) {
            $query->where(['Handler.name' => (string)$data['handler_name']]);
        }
        $this->applyVirtualPageListFilters($query, $data, ['id', 'route', 'handler', 'event', 'active'], 'vpRoute');
        if (!empty($data['method'])) {
            $query->where(['vpRoute.metod:LIKE' => '%' . $this->normalizeVirtualPageMethod($data['method']) . '%']);
        }
        $query->sortby('vpRoute.rank', 'ASC');
        $query->sortby('vpRoute.id', 'ASC');
        $query->limit($this->getListLimit($data), $this->getListStart($data));

        $items = [];
        if ($query->prepare() && $query->stmt->execute()) {
            foreach ($query->stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = $this->normalizeVirtualPageRouteArray($row);
            }
        }

        return [
            'count' => count($items),
            'routes' => $items,
        ];
    }

    private function getVirtualPageRoute(array $data) {
        $route = $this->resolveVirtualPageObject('vpRoute', $data);
        return $this->normalizeVirtualPageRoute($route);
    }

    private function createVirtualPageRoute(array $data) {
        $this->loadVirtualPagePackage();
        $payload = $this->prepareVirtualPageRoutePayload($data, false);
        $this->assertUniqueVirtualPageRoute($payload['route'], $payload['metod']);
        if (!array_key_exists('rank', $payload)) {
            $payload['rank'] = $this->modx->getCount('vpRoute');
        }

        $route = $this->modx->newObject('vpRoute');
        $route->fromArray($payload, '', true, true);
        if (!$route->save()) {
            throw new ModxMCPClientException('Could not save VirtualPage route.');
        }

        if ($event = $route->getOne('Event')) {
            $this->ensureVirtualPagePluginEvent($event->get('name'));
        }
        $this->clearVirtualPageCache();
        $this->logAudit('virtualpage_create_route', 'virtualpage_route', ['id' => $route->get('id'), 'route' => $route->get('route')]);
        return $this->normalizeVirtualPageRoute($route);
    }

    private function updateVirtualPageRoute(array $data) {
        $route = $this->resolveVirtualPageObject('vpRoute', $data);
        $payload = $this->prepareVirtualPageRoutePayload($data, true);
        if (empty($payload)) {
            throw new ModxMCPClientException('No VirtualPage route fields to update.');
        }

        $routePath = array_key_exists('route', $payload) ? $payload['route'] : $route->get('route');
        $method = array_key_exists('metod', $payload) ? $payload['metod'] : $route->get('metod');
        $this->assertUniqueVirtualPageRoute($routePath, $method, (int)$route->get('id'));

        $route->fromArray($payload, '', true, true);
        if (!$route->save()) {
            throw new ModxMCPClientException('Could not update VirtualPage route.');
        }

        if ($event = $route->getOne('Event')) {
            $this->ensureVirtualPagePluginEvent($event->get('name'));
        }
        $this->clearVirtualPageCache();
        $this->logAudit('virtualpage_update_route', 'virtualpage_route', ['id' => $route->get('id')]);
        return $this->normalizeVirtualPageRoute($route);
    }

    private function resolveVirtualPageRoute(array $data) {
        $this->loadVirtualPagePackage();
        $path = !empty($data['path']) ? (string)$data['path'] : (!empty($data['uri']) ? (string)$data['uri'] : '');
        if ($path === '') {
            throw new ModxMCPClientException('path or uri is required.');
        }
        $path = '/' . trim($path, '/');
        $rawPath = array_key_exists('path', $data) ? (string)$data['path'] : '';
        $rawUri = array_key_exists('uri', $data) ? (string)$data['uri'] : '';
        if (substr($rawPath, -1) === '/' || substr($rawUri, -1) === '/') {
            $path .= '/';
        }
        $method = !empty($data['method']) ? $this->normalizeVirtualPageMethod($data['method']) : 'GET';

        $routes = $this->listVirtualPageRoutes(['active' => 1, 'limit' => 0]);
        foreach ($routes['routes'] as $route) {
            $methods = array_map('trim', explode(',', strtoupper($route['method'])));
            if (!in_array($method, $methods, true)) {
                continue;
            }
            $match = $this->matchVirtualPageRoutePattern($route['route'], $path);
            if ($match === false) {
                continue;
            }
            $properties = is_array($route['properties']) ? $route['properties'] : [];
            $placeholders = array_merge($match, $properties);
            $placeholders['uri'] = $path;

            return [
                'found' => true,
                'path' => $path,
                'method' => $method,
                'route' => $route,
                'placeholders' => $placeholders,
                'placeholder_prefix' => $this->modx->getOption('virtualpage_prefix_placeholder', null, 'vp.'),
            ];
        }

        return [
            'found' => false,
            'path' => $path,
            'method' => $method,
        ];
    }

    private function clearVirtualPageCache() {
        $this->loadVirtualPagePackage();
        $service = $this->loadVirtualPageService(false);
        if ($service && method_exists($service, 'clearCache')) {
            $service->clearCache(['cache_key' => 'event/']);
        }
        if ($this->modx->getCacheManager()) {
            $this->modx->cacheManager->clean(['cache_key' => 'default/virtualpage/']);
            $this->refreshContentCache();
        }
        return ['cleared' => true];
    }

    private function loadVirtualPagePackage() {
        $corePath = $this->getVirtualPageCorePath();
        if (!is_dir($corePath)) {
            throw new ModxMCPClientException('Could not find VirtualPage component core path.');
        }
        $this->modx->addPackage('virtualpage', $corePath . 'model/');
        return true;
    }

    private function loadVirtualPageService($required = true) {
        $corePath = $this->getVirtualPageCorePath();
        $service = $this->modx->getService('virtualpage', 'virtualpage', $corePath . 'model/virtualpage/');
        if (!$service && $required) {
            throw new ModxMCPClientException('Could not load VirtualPage service. Is VirtualPage installed on this MODX site?');
        }
        return $service;
    }

    private function getVirtualPageCorePath() {
        return $this->modx->getOption('virtualpage_core_path', null, $this->modx->getOption('core_path') . 'components/virtualpage/');
    }

    private function resolveVirtualPageObject($classKey, array $data) {
        $this->loadVirtualPagePackage();
        if (!empty($data['id'])) {
            $object = $this->modx->getObject($classKey, (int)$data['id']);
        } elseif (!empty($data['name']) && in_array($classKey, ['vpEvent', 'vpHandler'], true)) {
            $object = $this->modx->getObject($classKey, ['name' => (string)$data['name']]);
        } elseif ($classKey === 'vpRoute' && !empty($data['route'])) {
            $criteria = ['route' => (string)$data['route']];
            if (!empty($data['method'])) {
                $criteria['metod'] = $this->normalizeVirtualPageMethod($data['method']);
            } elseif (!empty($data['metod'])) {
                $criteria['metod'] = $this->normalizeVirtualPageMethod($data['metod']);
            }
            $object = $this->modx->getObject($classKey, $criteria);
        } else {
            $object = null;
        }

        if (!$object) {
            throw new ModxMCPClientException("VirtualPage object not found: {$classKey}.");
        }
        return $object;
    }

    private function prepareVirtualPagePayload(array $data, array $allowedFields) {
        $payload = [];
        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (in_array($field, ['id', 'type', 'entry', 'rank', 'active', 'cache'], true)) {
                $value = (int)$value;
            }
            $payload[$field] = $value;
        }
        return $payload;
    }

    private function prepareVirtualPageRoutePayload(array $data, $isUpdate) {
        $payload = $this->prepareVirtualPagePayload($data, ['route', 'handler', 'event', 'description', 'rank', 'active', 'properties']);
        if (array_key_exists('method', $data)) {
            $payload['metod'] = $this->normalizeVirtualPageMethod($data['method']);
        } elseif (array_key_exists('metod', $data)) {
            $payload['metod'] = $this->normalizeVirtualPageMethod($data['metod']);
        }

        if (!empty($data['event_name'])) {
            $event = $this->modx->getObject('vpEvent', ['name' => (string)$data['event_name']]);
            if (!$event) {
                throw new ModxMCPClientException("VirtualPage event not found: {$data['event_name']}.");
            }
            $payload['event'] = (int)$event->get('id');
        }
        if (!empty($data['handler_name'])) {
            $handler = $this->modx->getObject('vpHandler', ['name' => (string)$data['handler_name']]);
            if (!$handler) {
                throw new ModxMCPClientException("VirtualPage handler not found: {$data['handler_name']}.");
            }
            $payload['handler'] = (int)$handler->get('id');
        }
        if (array_key_exists('properties', $payload) && is_string($payload['properties'])) {
            $decoded = json_decode($payload['properties'], true);
            if ($payload['properties'] !== '' && json_last_error() !== JSON_ERROR_NONE) {
                throw new ModxMCPClientException('properties must be a JSON object or an object payload.');
            }
            $payload['properties'] = is_array($decoded) ? $decoded : [];
        }
        if (array_key_exists('properties', $payload) && !is_array($payload['properties'])) {
            throw new ModxMCPClientException('properties must be an object.');
        }
        if (!empty($payload['route'])) {
            $payload['route'] = '/' . trim((string)$payload['route'], '/');
            if (!empty($data['route']) && substr((string)$data['route'], -1) === '/') {
                $payload['route'] .= '/';
            }
        }

        if (!$isUpdate) {
            foreach (['route', 'metod', 'handler', 'event'] as $field) {
                if (empty($payload[$field])) {
                    throw new ModxMCPClientException("{$field} is required for VirtualPage route creation.");
                }
            }
            if (!array_key_exists('active', $payload)) {
                $payload['active'] = 1;
            }
        }

        if (!empty($payload['handler']) && !$this->modx->getObject('vpHandler', (int)$payload['handler'])) {
            throw new ModxMCPClientException("VirtualPage handler not found: {$payload['handler']}.");
        }
        if (!empty($payload['event']) && !$this->modx->getObject('vpEvent', (int)$payload['event'])) {
            throw new ModxMCPClientException("VirtualPage event not found: {$payload['event']}.");
        }

        return $payload;
    }

    private function normalizeVirtualPageEvent(xPDOObject $event, $includeRoutes = false) {
        $result = $event->toArray();
        $result['id'] = (int)$result['id'];
        $result['rank'] = (int)$result['rank'];
        $result['active'] = (int)$result['active'];
        $result['route_count'] = $this->modx->getCount('vpRoute', ['event' => $event->get('id')]);
        if ($includeRoutes) {
            $routes = [];
            foreach ($event->getMany('Routes') as $route) {
                $routes[] = $this->normalizeVirtualPageRoute($route);
            }
            $result['routes'] = $routes;
        }
        return $result;
    }

    private function normalizeVirtualPageHandler(xPDOObject $handler, $includeRoutes = false) {
        $result = $handler->toArray();
        $result['id'] = (int)$result['id'];
        $result['type'] = (int)$result['type'];
        $result['entry'] = (int)$result['entry'];
        $result['rank'] = (int)$result['rank'];
        $result['active'] = (int)$result['active'];
        $result['cache'] = (int)$result['cache'];
        $result['type_name'] = $this->getVirtualPageHandlerTypeName($result['type']);
        $result['route_count'] = $this->modx->getCount('vpRoute', ['handler' => $handler->get('id')]);
        if ($includeRoutes) {
            $routes = [];
            foreach ($handler->getMany('Routes') as $route) {
                $routes[] = $this->normalizeVirtualPageRoute($route);
            }
            $result['routes'] = $routes;
        }
        return $result;
    }

    private function normalizeVirtualPageRoute(xPDOObject $route) {
        $result = $route->toArray();
        $event = $route->getOne('Event');
        $handler = $route->getOne('Handler');
        $result['event_name'] = $event ? $event->get('name') : null;
        $result['handler_name'] = $handler ? $handler->get('name') : null;
        return $this->normalizeVirtualPageRouteArray($result);
    }

    private function normalizeVirtualPageRouteArray(array $row) {
        $properties = isset($row['properties']) ? $row['properties'] : [];
        if (is_string($properties)) {
            $decoded = json_decode($properties, true);
            $properties = is_array($decoded) ? $decoded : [];
        }
        return [
            'id' => (int)$row['id'],
            'method' => isset($row['metod']) ? $row['metod'] : '',
            'metod' => isset($row['metod']) ? $row['metod'] : '',
            'route' => isset($row['route']) ? $row['route'] : '',
            'handler' => isset($row['handler']) ? (int)$row['handler'] : 0,
            'handler_name' => isset($row['handler_name']) ? $row['handler_name'] : null,
            'event' => isset($row['event']) ? (int)$row['event'] : 0,
            'event_name' => isset($row['event_name']) ? $row['event_name'] : null,
            'description' => isset($row['description']) ? $row['description'] : '',
            'rank' => isset($row['rank']) ? (int)$row['rank'] : 0,
            'active' => isset($row['active']) ? (int)$row['active'] : 0,
            'properties' => $properties,
        ];
    }

    private function normalizeVirtualPageMethod($method) {
        $parts = array_map('trim', explode(',', strtoupper((string)$method)));
        $parts = array_filter($parts);
        $allowed = ['GET', 'POST'];
        foreach ($parts as $part) {
            if (!in_array($part, $allowed, true)) {
                throw new ModxMCPClientException('VirtualPage method must be GET, POST, or GET,POST.');
            }
        }
        if (empty($parts)) {
            throw new ModxMCPClientException('VirtualPage method is required.');
        }
        return implode(',', array_values(array_unique($parts)));
    }

    private function normalizeVirtualPageHandlerType($type) {
        if (is_string($type) && !is_numeric($type)) {
            $map = [
                'resource' => 0,
                'snippet' => 1,
                'chunk' => 2,
                'dynamic_resource' => 3,
                'dynamic-resource' => 3,
                'template' => 3,
            ];
            $key = strtolower(trim($type));
            if (!array_key_exists($key, $map)) {
                throw new ModxMCPClientException('VirtualPage handler type must be 0, 1, 2, 3, resource, snippet, chunk, or dynamic_resource.');
            }
            return $map[$key];
        }
        $type = (int)$type;
        if (!in_array($type, [0, 1, 2, 3], true)) {
            throw new ModxMCPClientException('VirtualPage handler type must be one of: 0, 1, 2, 3.');
        }
        return $type;
    }

    private function getVirtualPageHandlerTypeName($type) {
        $map = [
            0 => 'resource_forward',
            1 => 'snippet',
            2 => 'chunk',
            3 => 'dynamic_resource',
        ];
        return isset($map[(int)$type]) ? $map[(int)$type] : 'unknown';
    }

    private function assertVirtualPageHandlerEntry($type, $entry) {
        $entry = (int)$entry;
        if ($entry <= 0) {
            return;
        }
        $map = [
            0 => 'modResource',
            1 => 'modSnippet',
            2 => 'modChunk',
            3 => 'modTemplate',
        ];
        if (!empty($map[$type]) && !$this->modx->getObject($map[$type], $entry)) {
            throw new ModxMCPClientException("VirtualPage handler entry not found for type {$type}: {$entry}.");
        }
    }

    private function assertUniqueVirtualPageRoute($route, $method, $excludeId = 0) {
        $query = $this->modx->newQuery('vpRoute');
        $query->where([
            'route' => $route,
            'metod' => $method,
        ]);
        if ($excludeId > 0) {
            $query->where(['id:!=' => $excludeId]);
        }
        if ($this->modx->getCount('vpRoute', $query) > 0) {
            throw new ModxMCPClientException("VirtualPage route already exists for {$method} {$route}.");
        }
    }

    private function ensureVirtualPagePluginEvent($eventName) {
        $service = $this->loadVirtualPageService(false);
        if ($service && method_exists($service, 'doEvent')) {
            return $service->doEvent('create', $eventName, 'vpEvent', 10);
        }

        $plugin = $this->modx->getObject('modPlugin', ['name' => 'vpEvent']);
        if (!$plugin) {
            return false;
        }
        $event = $this->modx->getObject('modPluginEvent', [
            'pluginid' => $plugin->get('id'),
            'event' => $eventName,
        ]);
        if (!$event) {
            $event = $this->modx->newObject('modPluginEvent');
            $event->set('pluginid', $plugin->get('id'));
            $event->set('event', $eventName);
        }
        $event->set('priority', 10);
        return $event->save();
    }

    private function matchVirtualPageRoutePattern($pattern, $path) {
        $regex = preg_quote($pattern, '#');
        $names = [];
        if (strpos($pattern, '{') !== false) {
            $quoted = '';
            $offset = 0;
            if (preg_match_all('/\{([^}:]+)(?::([^}]+))?\}/', $pattern, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $i => $match) {
                    $quoted .= preg_quote(substr($pattern, $offset, $match[1] - $offset), '#');
                    $names[] = $matches[1][$i][0];
                    $subPattern = isset($matches[2][$i][0]) && $matches[2][$i][0] !== '' ? $matches[2][$i][0] : '[^/]+';
                    $quoted .= '(' . $subPattern . ')';
                    $offset = $match[1] + strlen($match[0]);
                }
                $quoted .= preg_quote(substr($pattern, $offset), '#');
                $regex = $quoted;
            }
        }

        if (!preg_match('#^' . $regex . '$#', $path, $matches)) {
            return false;
        }
        array_shift($matches);

        $result = [];
        foreach ($names as $i => $name) {
            $result[$name] = isset($matches[$i]) ? $matches[$i] : '';
        }
        return $result;
    }

    private function applyVirtualPageListFilters(xPDOQuery $query, array $data, array $fields, $alias = '') {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                continue;
            }
            $column = $alias !== '' ? $alias . '.' . $field : $field;
            $key = in_array($field, ['id', 'active', 'type', 'entry', 'handler', 'event'], true)
                ? $column
                : $column . ':LIKE';
            $value = in_array($field, ['id', 'active', 'type', 'entry', 'handler', 'event'], true)
                ? (int)$data[$field]
                : '%' . (string)$data[$field] . '%';
            $query->where([$key => $value]);
        }
    }
}
