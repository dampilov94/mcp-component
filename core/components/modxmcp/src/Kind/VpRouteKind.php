<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Integration\VirtualPage;
use ModxMCP\Schema;

/**
 * A VirtualPage route (class vpRoute): a URL pattern and the methods it answers, tied to a handler through an event. The
 * pattern is FastRoute's standard syntax (`/catalog/{id:\d+}`, optional `[/page/{n}]`). It has no name of its own: it is
 * addressed by id, and a copy needs a different route or method (a route and method together are unique).
 *
 * `resolve` (modx_invoke, read only) tells which routes answer a path and which one VirtualPage would answer with.
 */
final class VpRouteKind extends AbstractVirtualPageKind {
    const DEFAULTS = array('metod' => 'GET', 'route' => null, 'handler' => 0, 'event' => 0, 'description' => null, 'rank' => 0, 'active' => 1, 'properties' => null);

    public function name() { return 'vp_route'; }
    public function label() { return 'VirtualPage route'; }
    public function modelClass() { return 'vpRoute'; }
    public function nameField() { return 'route'; }
    public function hasNames() { return false; }
    public function uniqueNames() { return false; }
    public function searchColumns() { return array('route', 'description'); }
    public function defaultSort() { return array(array('rank', 'ASC'), array('id', 'ASC')); }
    protected function defaults() { return self::DEFAULTS; }

    /** A route needs VirtualPage 1.x's plugin on the event it belongs to. */
    protected function eventNamesOf(\modX $modx, $object) {
        $event = $object ? $modx->getObject('vpEvent', array('id' => (int) $object->get('event')), false) : null;
        return $event ? array((string) $event->get('name')) : array();
    }

    public function fields() {
        return array(
            'id'          => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'route'       => array('type' => 'string', 'list' => true, 'required' => true,
                'description' => 'URL pattern starting with "/", FastRoute syntax: /catalog/{id:\d+}, /blog[/{page}]. A placeholder is [^/]+ unless a regex follows the colon (no capturing groups). '
                    . 'The request path is matched as it is: no trailing-slash tolerance, case-sensitive.'),
            'method'      => array('type' => 'string', 'list' => true, 'column' => 'metod',
                'description' => 'One or more of ' . implode(', ', VirtualPage::METHODS) . ', comma separated ("GET,POST"); stored upper case in that order. Default GET.'),
            'handler'     => array('type' => 'integer', 'list' => true, 'required' => true, 'description' => 'Id of the handler (kind vp_handler) that answers.'),
            'event'       => array('type' => 'integer', 'list' => true, 'required' => true, 'description' => 'Id of the event (kind vp_event) the route is tried on, usually OnPageNotFound.'),
            'description' => array('type' => 'string'),
            'rank'        => array('type' => 'integer', 'description' => 'Order of registration, 0-255 (a new route gets the number of routes); routes are tried in this order.'),
            'active'      => array('type' => 'boolean', 'list' => true, 'description' => 'Only active routes of an active event with an active handler are dispatched.'),
            'properties'  => array('type' => 'json', 'description' => 'Extra values handed to the handler with the request (an object); null for none. They arrive as placeholders next to the URL parameters.'),
            'label'       => array('type' => 'string', 'list' => true, 'derived' => true, 'computed' => true,
                'description' => '"<method> <route> → <handler name> (<event name>)".'),
        );
    }

    public function value($object, $field) {
        if ($field !== 'label') { return parent::value($object, $field); }
        $modx = $object->xpdo;
        $handler = $modx->getObject('vpHandler', array('id' => (int) $object->get('handler')), false);
        $event = $modx->getObject('vpEvent', array('id' => (int) $object->get('event')), false);
        return trim($object->get('metod') . ' ' . $object->get('route')) . ' → ' . ($handler ? (string) $handler->get('name') : '#' . (int) $object->get('handler'))
            . ' (' . ($event ? (string) $event->get('name') : '#' . (int) $object->get('event')) . ')';
    }

    // ---- validation ----

    public function assertColumns(array $columns, $creating) {
        self::checkRank($columns);
        if (isset($columns['route'])) {
            try { VirtualPage::assertRoute($columns['route']); }
            catch (\InvalidArgumentException $e) { throw new ApiException('validation_failed', "Field 'route': " . $e->getMessage()); }
            if (mb_strlen($columns['route'], 'UTF-8') > 255) { throw new ApiException('validation_failed', "Field 'route' is too long (at most 255 characters)."); }
        } elseif ($creating) {
            throw new ApiException('validation_failed', "Field 'route' is required.");
        }
        if (isset($columns['metod']) && VirtualPage::normalizeMethods($columns['metod']) === null) {
            throw new ApiException('validation_failed', "Field 'method' must be one or more of " . implode(', ', VirtualPage::METHODS) . ' separated by commas.');
        }
        foreach (array('handler', 'event') as $column) {
            if (isset($columns[$column]) && (int) $columns[$column] < 1) { throw new ApiException('validation_failed', "Field '{$column}' must be an id (1 or more)."); }
        }
        if (isset($columns['properties']) && $columns['properties'] !== '') {
            $value = json_decode((string) $columns['properties'], true);
            if (!is_array($value) || ($value && array_keys($value) === range(0, count($value) - 1))) {
                throw new ApiException('validation_failed', "Field 'properties' must be an object (or null).");
            }
        }
    }

    protected function prepareColumns(\modX $modx, array $columns) {
        if (isset($columns['metod'])) { $columns['metod'] = VirtualPage::normalizeMethods($columns['metod']); }
        if (array_key_exists('properties', $columns)) {
            $value = $columns['properties'] === '' ? null : json_decode((string) $columns['properties'], true);
            $columns['properties'] = $value ? $columns['properties'] : null;
        }
        return $columns;
    }

    /** A route in the same words as another with a method in common: the manager refuses the same route and method; FastRoute refuses overlap. */
    private function assertUnique(\modX $modx, $route, $methods, $exceptId) {
        foreach ($modx->getCollection('vpRoute', array('route' => (string) $route)) as $other) {
            if ((int) $other->get('id') === (int) $exceptId) { continue; }
            $common = array_intersect(VirtualPage::methodList($methods), VirtualPage::methodList($other->get('metod')));
            if ($common) {
                throw new ApiException('validation_failed', "A route '{$route}' already answers " . implode(',', $common) . ' (vp_route ' . (int) $other->get('id') . ').');
            }
        }
    }

    private function assertParents(\modX $modx, $handler, $event) {
        if ($handler !== null && !$modx->getCount('vpHandler', array('id' => (int) $handler))) { throw new ApiException('validation_failed', "There is no vp_handler with id {$handler}."); }
        if ($event !== null && !$modx->getCount('vpEvent', array('id' => (int) $event))) { throw new ApiException('validation_failed', "There is no vp_event with id {$event}."); }
    }

    public function beforeCreate(Context $context, array $columns) {
        $modx = $context->modx;
        $this->assertParents($modx, $columns['handler'], $columns['event']);
        $methods = isset($columns['metod']) ? VirtualPage::normalizeMethods($columns['metod']) : self::DEFAULTS['metod'];
        $this->assertUnique($modx, $columns['route'], $methods, 0);
        return null;
    }

    public function assertUpdatable(\modX $modx, $object, array $columns) {
        $this->assertParents($modx, isset($columns['handler']) && (int) $columns['handler'] !== (int) $object->get('handler') ? $columns['handler'] : null,
            isset($columns['event']) && (int) $columns['event'] !== (int) $object->get('event') ? $columns['event'] : null);
        if (isset($columns['route']) || isset($columns['metod'])) {
            $this->assertUnique($modx, isset($columns['route']) ? $columns['route'] : $object->get('route'),
                isset($columns['metod']) ? VirtualPage::normalizeMethods($columns['metod']) : $object->get('metod'), $object->get('id'));
        }
    }

    /** A route cannot be put back while another one answers the same route and method. */
    public function assertUndoable(\modX $modx, $key, $target) {
        parent::assertUndoable($modx, $key, $target);
        if ($target !== null && isset($target['fields']['route'], $target['fields']['metod'])) {
            try { $this->assertUnique($modx, $target['fields']['route'], $target['fields']['metod'], $key); }
            catch (ApiException $e) { throw new ApiException('not_reversible', $e->getMessage() . ' Change or delete it first.'); }
        }
    }

    // ---- warnings ----

    /** Soft checks: a route that is never tried, a set of routes FastRoute would refuse. */
    public function writeWarnings($object) {
        if (!$this->context) { return array(); }
        $modx = $this->context->modx;
        $warnings = array();
        $handler = $modx->getObject('vpHandler', array('id' => (int) $object->get('handler')), false);
        $event = $modx->getObject('vpEvent', array('id' => (int) $object->get('event')), false);
        if ($handler && !(int) $handler->get('active')) { $warnings[] = "Handler '{$handler->get('name')}' is inactive: VirtualPage skips this route."; }
        if ($event && !(int) $event->get('active')) { $warnings[] = "Event '{$event->get('name')}' is inactive: VirtualPage skips this route."; }
        if ($event && !in_array((string) $event->get('name'), VirtualPage::DISPATCH_EVENTS, true)) {
            $warnings[] = "VirtualPage 2.0.6 dispatches routes only on " . implode(' and ', VirtualPage::DISPATCH_EVENTS) . "; this route's event '{$event->get('name')}' is never tried.";
        }
        if ($event && (int) $object->get('active') && (!$handler || (int) $handler->get('active')) && (int) $event->get('active')) {
            $list = array();
            foreach ($modx->getCollection('vpRoute', array('event' => (int) $event->get('id'), 'active' => 1)) as $row) {
                $other = $modx->getObject('vpHandler', array('id' => (int) $row->get('handler')), false);
                if ($other && (int) $other->get('active')) { $list[] = array('id' => (int) $row->get('id'), 'route' => (string) $row->get('route'), 'method' => (string) $row->get('metod'), 'rank' => (int) $row->get('rank')); }
            }
            usort($list, function ($a, $b) { return $a['rank'] === $b['rank'] ? $a['id'] - $b['id'] : $a['rank'] - $b['rank']; });
            foreach (VirtualPage::registrationProblems($list) as $problem) {
                if ($problem[0] === (int) $object->get('id') || $problem[1] === (int) $object->get('id')) {
                    $warnings[] = 'FastRoute would refuse the routes of this event: ' . $problem[2] . ' VirtualPage would fail on every request of the event until this is fixed.';
                }
            }
        }
        return $warnings;
    }

    // ---- the route tester ----

    public function operations() {
        return array(
            'resolve' => array('description' => 'Which active routes answer a request path and which one VirtualPage would answer with (read only, no object).',
                'read_only' => true, 'needs_object' => false, 'handler' => 'opResolve',
                'input' => Schema::obj(array(
                    'path'   => Schema::str('Request path as MODX sees it (the q parameter), e.g. /catalog/5; no query string.', array('minLength' => 1, 'maxLength' => 2000)),
                    'method' => Schema::str('HTTP method, default GET.', array('maxLength' => 20)),
                ), array('path'))),
        );
    }

    public function opResolve(Context $context, $object, array $args): array {
        $modx = $context->modx;
        $path = (string) $args['path'];
        if (strpbrk($path, "?#") !== false) { throw new ApiException('validation_failed', "Give the path without a query string or fragment ('?' and '#' cannot appear in it)."); }
        // VirtualPage::getUri(): a leading slash is added to what MODX has in the request alias, nothing else is changed.
        $path = '/' . ltrim($path, '/');
        $method = isset($args['method']) ? strtoupper(trim((string) $args['method'])) : 'GET';
        if ($method === '') { $method = 'GET'; }

        $handlers = array();
        foreach ($modx->getCollection('vpHandler', array('active' => 1)) as $handler) { $handlers[(int) $handler->get('id')] = $handler; }
        $events = array();
        foreach ($modx->getCollection('vpEvent', array('active' => 1)) as $event) { $events[(int) $event->get('id')] = $event; }
        // The routes virtualpage::getAllRoutes() selects: active, with an active event and an active handler.
        $routes = array();
        foreach ($modx->getCollection('vpRoute', array('active' => 1)) as $route) {
            $handlerId = (int) $route->get('handler');
            $eventId = (int) $route->get('event');
            if (!isset($handlers[$handlerId]) || !isset($events[$eventId])) { continue; }
            $routes[] = array('id' => (int) $route->get('id'), 'route' => (string) $route->get('route'), 'method' => (string) $route->get('metod'), 'rank' => (int) $route->get('rank'),
                'event' => (string) $events[$eventId]->get('name'), 'event_id' => $eventId, 'handler_id' => $handlerId, 'properties' => $this->value($route, 'properties'));
        }
        $matches = array();
        foreach (VirtualPage::dispatch($routes, $path, $method) as $hit) {
            $handler = $handlers[$hit['handler_id']];
            $matches[] = array(
                'id' => $hit['id'], 'route' => $hit['route'], 'method' => $hit['method'],
                'event' => array('id' => $hit['event_id'], 'name' => $hit['event']),
                'handler' => array('id' => $hit['handler_id'], 'name' => (string) $handler->get('name'), 'type' => VirtualPage::typeName($handler->get('type')), 'entry' => (int) $handler->get('entry')),
                'parameters' => $hit['parameters'] ? $hit['parameters'] : new \stdClass(),
                'properties' => $hit['properties'],
                'answers' => false,
            );
        }
        if ($matches) { $matches[0]['answers'] = true; }

        $notes = array();
        if (!$modx->getOption('virtualpage_active', null, false)) { $notes[] = 'The system setting virtualpage_active is off: VirtualPage answers nothing until it is switched on.'; }
        foreach ($matches as $match) {
            if (!in_array($match['event']['name'], VirtualPage::DISPATCH_EVENTS, true)) {
                $notes[$match['event']['name']] = "VirtualPage 2.0.6 dispatches routes only on " . implode(' and ', VirtualPage::DISPATCH_EVENTS) . ", so '{$match['event']['name']}' routes are never tried.";
            } elseif ($match['event']['name'] === 'OnPageNotFound') {
                $notes['OnPageNotFound'] = 'An OnPageNotFound route is tried only when no resource answers the path.';
            }
        }
        return array('path' => $path, 'method' => $method, 'matches' => $matches, 'answered_by' => $matches ? $matches[0]['id'] : null, 'notes' => array_values($notes));
    }
}
