<?php
namespace ModxMCP\Integration;

use ModxMCP\Context;

/**
 * What the component needs to know about VirtualPage (verified against 2.0.6-beta): when it can be used, how its xPDO package
 * is registered, how its cache is cleared, and how its router (FastRoute) reads a route and picks one for a request.
 *
 * The router part is a port of the FastRoute copy VirtualPage ships (RouteParser\Std, DataGenerator, Dispatcher): the same
 * placeholder syntax, the same optional `[...]` segments, static routes before variable ones, variable routes in the order
 * they were registered, HEAD falling back to GET. It is used by the route tester and to refuse a route FastRoute would not
 * accept; the real router stays the authority.
 */
final class VirtualPage {
    const GROUP = 'virtualpage';
    const PACKAGE = 'virtualpage';

    /** Stored `type` of a handler => API name (virtualpage::handle() and the manager's type list). */
    const HANDLER_TYPES = array(0 => 'resource', 1 => 'snippet', 2 => 'chunk', 3 => 'dynamic_resource');

    /** Names the API also accepts for a type (1.x called the dynamic resource's entry a template). */
    const HANDLER_TYPE_ALIASES = array('template' => 3);

    /** Class of the object a handler's `entry` names, per stored type. */
    const ENTRY_CLASSES = array(0 => 'modResource', 1 => 'modSnippet', 2 => 'modChunk', 3 => 'modTemplate');

    /** HTTP methods a route may list, in the order they are stored. */
    const METHODS = array('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS');

    /** Events VirtualPage 2.0.6 answers by dispatching its routes (its plugin classes virtualpageOnHandleRequest and virtualpageOnPageNotFound). */
    const DISPATCH_EVENTS = array('OnHandleRequest', 'OnPageNotFound');

    const VARIABLE_REGEX = '\{
        \s* ([a-zA-Z_][a-zA-Z0-9_-]*) \s*
        (?:
            : \s* ([^{}]*(?:\{(?-1)\}[^{}]*)*)
        )?
    \}';
    const DEFAULT_DISPATCH_REGEX = '[^/]+';
    const CAPTURING_GROUPS_REGEX = '~
        (?:
            \(\?\(
          | \[ [^\]\\\\]* (?: \\\\ . [^\]\\\\]* )* \]
          | \\\\ .
        ) (*SKIP)(*FAIL) |
        \(
        (?!
            \? (?! <(?![!=]) | P< | \' )
          | \*
        )
    ~x';

    /** The integration is installed, its code is there and the group is not switched off for the site. */
    public static function siteAvailable(Context $context) {
        $caps = $context->capabilities;
        $status = $caps->integrations();
        return $status[self::GROUP]['available'] && !in_array(self::GROUP, $caps->disabledGroups(), true);
    }

    /** The integration is usable by this token (site-wide switches and the token's denied groups). */
    public static function available(Context $context) {
        return $context->capabilities->groupBlockReason(self::GROUP, $context->token) === null;
    }

    /** Core directory of VirtualPage with a trailing slash. */
    public static function corePath(\modX $modx) {
        return (new \ModxMCP\Capabilities($modx))->integrationPath(self::GROUP);
    }

    /**
     * Register the xPDO package, as VirtualPage's own service does (`addPackage('virtualpage', <core>/model/)`).
     * Registering twice is harmless.
     */
    public static function load(\modX $modx) {
        $model = self::corePath($modx) . 'model/';
        if (!is_file($model . 'virtualpage/vproute.class.php')) {
            throw new \RuntimeException('VirtualPage is not installed on this site (its model files are missing).');
        }
        if (!isset($modx->packages[self::PACKAGE]) || $modx->packages[self::PACKAGE]['path'] !== $model) {
            $modx->addPackage(self::PACKAGE, $model);
        }
    }

    /**
     * Clear VirtualPage's own cache the way its manager processors do after a save: virtualpage::clearAllCache() cleans
     * `default/virtualpage` and builds the route lists again. The service is loaded as VirtualPage's plugin loads it; where it
     * cannot be (the class is gone), the cache folder is cleaned directly, which is the first thing clearAllCache() does.
     */
    public static function clearCache(\modX $modx) {
        $service = $modx->getService(self::PACKAGE, self::PACKAGE, self::corePath($modx) . 'model/virtualpage/');
        if ($service && method_exists($service, 'clearAllCache')) {
            $service->clearAllCache();
            return;
        }
        $cache = $modx->getCacheManager();
        if ($cache) { $cache->clean(array('cache_key' => 'default/' . self::PACKAGE)); }
    }

    // ---- handler types and methods ----

    /** Stored type of an API type name, or null when it is not one. */
    public static function typeFromName($name) {
        $name = strtolower(trim((string) $name));
        $byName = array_flip(self::HANDLER_TYPES) + self::HANDLER_TYPE_ALIASES;
        return isset($byName[$name]) ? $byName[$name] : null;
    }

    /** API name of a stored type; the number itself for a type this version does not know. */
    public static function typeName($stored) {
        return isset(self::HANDLER_TYPES[(int) $stored]) && (string) (int) $stored === (string) $stored ? self::HANDLER_TYPES[(int) $stored] : (string) $stored;
    }

    /**
     * The stored form of a route's method list: upper case, comma separated, each method once, in the order of METHODS.
     * @return string|null null when something in it is not a method of METHODS (or nothing is left)
     */
    public static function normalizeMethods($text) {
        $given = array();
        foreach (explode(',', mb_strtoupper((string) $text, 'UTF-8')) as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            if (!in_array($part, self::METHODS, true)) { return null; }
            $given[$part] = true;
        }
        if (!$given) { return null; }
        $out = array();
        foreach (self::METHODS as $method) { if (isset($given[$method])) { $out[] = $method; } }
        return implode(',', $out);
    }

    /** @return string[] the methods of a stored list */
    public static function methodList($stored) {
        $out = array();
        foreach (explode(',', (string) $stored) as $part) {
            $part = trim($part);
            if ($part !== '') { $out[] = $part; }
        }
        return $out;
    }

    // ---- route patterns (FastRoute's standard syntax) ----

    /**
     * Parse a route the way FastRoute's Std parser does. A route with optional parts `[...]` gives one variant per level.
     * @return array list of variants; a variant is a list of text parts and [name, regex] placeholders
     * @throws \InvalidArgumentException when FastRoute would refuse the route
     */
    public static function parseRoute($route) {
        $withoutClosing = rtrim($route, ']');
        $optionals = strlen($route) - strlen($withoutClosing);
        $segments = preg_split('~' . self::VARIABLE_REGEX . '(*SKIP)(*F) | \[~x', $withoutClosing);
        if ($segments === false) { throw new \InvalidArgumentException('The route cannot be parsed.'); }
        if ($optionals !== count($segments) - 1) {
            if (preg_match('~' . self::VARIABLE_REGEX . '(*SKIP)(*F) | \]~x', $withoutClosing) === 1) {
                throw new \InvalidArgumentException('Optional segments can only occur at the end of a route.');
            }
            throw new \InvalidArgumentException("Number of opening '[' and closing ']' does not match.");
        }
        $current = '';
        $variants = array();
        foreach ($segments as $n => $segment) {
            if ($segment === '' && $n !== 0) { throw new \InvalidArgumentException('Empty optional part.'); }
            $current .= $segment;
            $variants[] = self::parsePlaceholders($current);
        }
        return $variants;
    }

    private static function parsePlaceholders($route) {
        if ((int) preg_match_all('~' . self::VARIABLE_REGEX . '~x', $route, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) { return array($route); }
        $offset = 0;
        $parts = array();
        $names = array();
        foreach ($matches as $set) {
            if ($set[0][1] > $offset) { $parts[] = substr($route, $offset, $set[0][1] - $offset); }
            if (in_array($set[1][0], $names, true)) { throw new \InvalidArgumentException("Cannot use the same placeholder '{$set[1][0]}' twice."); }
            if (isset($set[2])) {
                $regex = trim($set[2][0]);
                if (strpos($regex, '(') !== false && preg_match(self::CAPTURING_GROUPS_REGEX, $regex) === 1) {
                    throw new \InvalidArgumentException("The regex '{$regex}' of placeholder '{$set[1][0]}' contains a capturing group.");
                }
            }
            $names[] = $set[1][0];
            $parts[] = array($set[1][0], isset($set[2]) ? trim($set[2][0]) : self::DEFAULT_DISPATCH_REGEX);
            $offset = $set[0][1] + strlen($set[0][0]);
        }
        if ($offset !== strlen($route)) { $parts[] = substr($route, $offset); }
        return $parts;
    }

    /**
     * Check a route as VirtualPage would register it: it parses and every variant compiles to a regular expression.
     * @throws \InvalidArgumentException
     */
    public static function assertRoute($route) {
        if (!is_string($route) || $route === '' || $route[0] !== '/') { throw new \InvalidArgumentException("A route starts with '/' (the request path VirtualPage matches always does)."); }
        foreach (self::parseRoute($route) as $variant) {
            $regex = self::variantRegex($variant);
            if (@preg_match('~^' . $regex[0] . '$~', '') === false) { throw new \InvalidArgumentException('A placeholder regex is not a valid regular expression.'); }
        }
    }

    /** @return array [regex without delimiters, names of the placeholders, is static] */
    private static function variantRegex(array $variant) {
        $regex = '';
        $names = array();
        foreach ($variant as $part) {
            if (is_string($part)) { $regex .= preg_quote($part, '~'); continue; }
            $names[] = $part[0];
            $regex .= '(' . $part[1] . ')';
        }
        return array($regex, $names, count($variant) === 1 && is_string($variant[0]));
    }

    /**
     * How a route matches a path: the variable values (empty list for a static route) and whether the match is static;
     * null when it does not match. The path is compared as the router does (bytes, no decoding, no trailing-slash tolerance).
     * @return array|null [parameters, is static]
     */
    public static function matchRoute($route, $path) {
        try { $variants = self::parseRoute($route); } catch (\InvalidArgumentException $e) { return null; }
        $found = null;
        foreach ($variants as $variant) {
            list($regex, $names, $static) = self::variantRegex($variant);
            if ($static) {
                if ($variant[0] === $path) { return array(array(), true); }
                continue;
            }
            if ($found === null && @preg_match('~^' . $regex . '$~', $path, $m) === 1) {
                $values = array();
                foreach ($names as $i => $name) { $values[$name] = isset($m[$i + 1]) ? $m[$i + 1] : ''; }
                $found = array($values, false);
            }
        }
        return $found;
    }

    /**
     * What FastRoute would refuse among the active routes of one event, which makes it fail for every request of the event:
     * the same route registered twice for a method, a static route that an earlier variable route already matches.
     * @param array[] $routes id, route, method (stored list); in the order VirtualPage registers them (rank, id)
     * @return array[] problems: [route id, other route id, message]
     */
    public static function registrationProblems(array $routes) {
        $problems = array();
        $seen = array();
        foreach ($routes as $route) {
            try { $variants = self::parseRoute($route['route']); } catch (\InvalidArgumentException $e) { continue; }
            foreach (self::methodList($route['method']) as $method) {
                foreach ($variants as $variant) {
                    list($regex, , $static) = self::variantRegex($variant);
                    $known = isset($seen[$method]) ? $seen[$method] : array('static' => array(), 'regex' => array(), 'variable' => array());
                    if ($static) {
                        $path = $variant[0];
                        if (isset($known['static'][$path])) {
                            $problems[] = array((int) $route['id'], $known['static'][$path], "Route '{$route['route']}' is registered twice for {$method}.");
                        }
                        foreach ($known['variable'] as $other) {
                            if (@preg_match('~^' . $other[1] . '$~', $path) === 1) {
                                $problems[] = array((int) $route['id'], $other[0], "Static route '{$path}' is shadowed by the earlier route {$other[0]} for {$method}.");
                            }
                        }
                        $known['static'][$path] = (int) $route['id'];
                    } else {
                        if (isset($known['regex'][$regex])) {
                            $problems[] = array((int) $route['id'], $known['regex'][$regex], "Route '{$route['route']}' is registered twice for {$method}.");
                        }
                        $known['regex'][$regex] = (int) $route['id'];
                        $known['variable'][] = array((int) $route['id'], $regex);
                    }
                    $seen[$method] = $known;
                }
            }
        }
        return $problems;
    }

    /**
     * The routes that answer a request, in the order the router tries them, then the one it answers with.
     * @param array[] $routes active routes, each: id, route, method (stored list), rank, event (name), event_rank, event_id
     * @return array[] matches: the route array plus `parameters`, in order; the first is the one VirtualPage answers with
     */
    public static function dispatch(array $routes, $path, $method) {
        $method = strtoupper($method);
        $hits = array();
        foreach ($routes as $route) {
            $methods = self::methodList($route['method']);
            // The router's passes: the method itself, then (HEAD only) GET, then the catch-all method.
            $pass = null;
            if (in_array($method, $methods, true)) { $pass = 0; }
            elseif ($method === 'HEAD' && in_array('GET', $methods, true)) { $pass = 1; }
            elseif (in_array('*', $methods, true)) { $pass = 2; }
            if ($pass === null) { continue; }
            $match = self::matchRoute($route['route'], $path);
            if ($match === null) { continue; }
            $hits[] = $route + array('parameters' => $match[0], '_pass' => $pass, '_static' => $match[1] ? 0 : 1, '_event' => self::eventOrder($route['event']));
        }
        usort($hits, function ($a, $b) {
            foreach (array('_event', '_pass', '_static', 'rank', 'id') as $key) {
                if ($a[$key] != $b[$key]) { return $a[$key] < $b[$key] ? -1 : 1; }
            }
            return 0;
        });
        foreach ($hits as $i => $hit) { unset($hits[$i]['_pass'], $hits[$i]['_static'], $hits[$i]['_event']); }
        return $hits;
    }

    /** Events fire in this order during a request: the handle-request event before the page-not-found one. */
    private static function eventOrder($name) {
        $at = array_search($name, self::DISPATCH_EVENTS, true);
        return $at === false ? count(self::DISPATCH_EVENTS) : $at;
    }
}
