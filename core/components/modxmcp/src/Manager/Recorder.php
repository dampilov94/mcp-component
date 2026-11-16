<?php
namespace ModxMCP\Manager;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Kind\Kind;
use ModxMCP\Kind\KindRegistry;
use ModxMCP\Write\Transaction;

/**
 * Journals what people do in the MODX manager. The plugin "modxMCP" passes every event it listens to here.
 * The recorder reuses the write transaction: a before-event touches the object (lock and snapshot), the matching
 * after-event commits, so the change is journaled with its author (source "manager") and can be undone.
 *
 * It never breaks or slows a manager action: everything is inside one try/catch, and it stays silent while a
 * transaction that is not its own is open (an API request, the undo of the history page, a nested save).
 */
final class Recorder {
    const PLUGIN_NAME = 'modxMCP';
    const PLUGIN_DESCRIPTION = 'Records changes made in the manager into the modxMCP journal.';
    const SETTING = 'modxmcp.history_track_manager';
    /** Most objects one sort or emptying the trash is recorded for. */
    const LIMIT = 500;

    /** @var Transaction[] The recorder's own open transactions: "kind:key", "kind:new", "resource:sort", "resource:trash". */
    private static $open = array();
    /** @var array|null event => [kind, role, param, cascade] */
    private static $table = null;

    /** Every event name the plugin must listen to. */
    public static function events() {
        return array_keys(self::table());
    }

    /** @return array event => ['kind' => name, 'role' => role, 'param' => name of the object parameter, 'cascade' => ...] */
    private static function table() {
        if (self::$table !== null) { return self::$table; }
        $table = array();
        $add = function ($event, array $spec) use (&$table) {
            if (isset($table[$event])) { throw new \LogicException("Event {$event} is listed by two kinds."); }
            $table[$event] = $spec;
        };
        foreach ((new KindRegistry())->all() as $kind) {
            $names = $kind->eventNames();
            $param = isset($names['param']) ? $names['param'] : $kind->name();
            foreach (array('before_save', 'save', 'before_delete', 'delete') as $role) {
                if (isset($names[$role])) { $add($names[$role], array('kind' => $kind->name(), 'role' => $role, 'param' => $param, 'cascade' => null)); }
            }
            foreach ($kind->managerEvents() as $event => $spec) {
                $add($event, array(
                    'kind' => $kind->name(), 'role' => $spec['role'], 'param' => isset($spec['param']) ? $spec['param'] : $param,
                    'cascade' => isset($spec['cascade']) ? $spec['cascade'] : null,
                ));
            }
        }
        return self::$table = $table;
    }

    /** Called by the plugin for every event of events(). Never throws. */
    public static function handle(\modX $modx, $eventName, array $params) {
        try {
            $table = self::table();
            if (!isset($table[$eventName])) { return; }
            if (!self::tracking($modx)) { return; }
            // A transaction that is not the recorder's own means the change is already journaled (or is being).
            if (Transaction::depth() > count(self::$open)) { return; }
            $corePath = $modx->getOption('modxmcp.core_path', null, $modx->getOption('core_path') . 'components/modxmcp/');
            $context = Context::forManager($modx, $modx->user, $corePath);
            $spec = $table[$eventName];
            $kind = $context->kinds->get($spec['kind']);
            if (!$kind) { return; }
            self::dispatch($context, $kind, $eventName, $spec, $params);
        } catch (ApiException $e) {
            // A lock held by an MCP request is not an error: the change is picked up as an outside change later.
            if ($e->errorCode !== 'locked') { self::fail($modx, $e); }
        } catch (\Throwable $e) {
            self::fail($modx, $e);
        }
    }

    private static function fail(\modX $modx, \Throwable $e) {
        try {
            $modx->log(\modX::LOG_LEVEL_ERROR, '[modxmcp] The manager recorder failed: ' . $e->getMessage());
        } catch (\Throwable $ignored) { /* The manager action goes on. */ }
    }

    /** The setting is on, a user is logged in to the manager and this is not the installer. */
    private static function tracking(\modX $modx) {
        $setting = $modx->getOption(self::SETTING, null, true);
        if (!$setting || $setting === 'false') { return false; }
        if ($modx->getOption('setup')) { return false; }
        if (!$modx->user || (int) $modx->user->get('id') <= 0) { return false; }
        // Processors run from the front end under a web user are not manager edits.
        if (isset($modx->context) && is_object($modx->context) && $modx->context->get('key') !== 'mgr') { return false; }
        return true;
    }

    private static function dispatch(Context $context, Kind $kind, $event, array $spec, array $params) {
        $modx = $context->modx;
        $role = $spec['role'];
        if ($role === 'before_sort' || $role === 'before_trash') { self::beforeMany($context, $kind, $role, $params); return; }
        if ($role === 'sort' || $role === 'trash') {
            $id = $kind->name() . ':' . $role;
            self::finish($context, self::take($id), $role === 'sort' ? 'sort resources in the manager' : 'empty the trash in the manager');
            return;
        }
        $object = isset($params[$spec['param']]) && is_object($params[$spec['param']]) ? $params[$spec['param']] : null;
        $key = self::keyOf($kind, $object, $params);
        $mode = isset($params['mode']) ? $params['mode'] : null;

        if ($role === 'before_save') {
            if ($mode === 'new' || $key === null) {
                // Nothing to read yet; only what the creation will change in other objects (a parent's isfolder).
                $columns = $object && method_exists($object, 'toArray') ? $object->toArray() : array();
                self::keep($kind->name() . ':new', self::open($context, function (Transaction $tx) use ($kind, $modx, $columns) {
                    foreach ($kind->alsoAffected($modx, null, $columns, 'create') as $other) { $tx->touch($kind, $other); }
                    return true;
                }));
                return;
            }
            $columns = $object && method_exists($object, 'toArray') ? $object->toArray() : array();
            self::keep($kind->name() . ':' . $key, self::open($context, function (Transaction $tx) use ($kind, $modx, $key, $columns) {
                $tx->touch($kind, $key);
                foreach ($kind->alsoAffected($modx, $key, $columns, 'update') as $other) { $tx->touch($kind, $other); }
                return true;
            }));
            return;
        }
        if ($key === null) { return; }
        $id = $kind->name() . ':' . $key;
        $shown = $kind->publicKey($key);

        if ($role === 'before_delete') {
            self::keep($id, self::open($context, function (Transaction $tx) use ($context, $kind, $modx, $key) {
                if ($tx->touch($kind, $key) === null) { return false; }
                $tx->keepDeleteExtra($kind, $key);
                foreach ($kind->deleteCascade($modx, $key) as $cascade) {
                    $other = $context->kinds->get($cascade[0]);
                    if ($other) { $tx->touch($other, $cascade[1]); }
                }
                foreach ($kind->alsoAffected($modx, $key, array(), 'delete') as $other) { $tx->touch($kind, $other); }
                return true;
            }));
            return;
        }
        if ($role === 'save') {
            $tx = self::take($id);
            if ($tx === null) {
                if ($mode === 'new') {
                    $tx = self::take($kind->name() . ':new');
                    if ($tx === null) { $tx = new Transaction($context, 'manager'); }
                    try { $tx->created($kind, $key); } catch (\Throwable $e) { $tx->release(); throw $e; }
                } else {
                    // No before-event was seen: what differs from the journal's last state is this person's change.
                    $context->outsideChangeIsManagers = true;
                    $tx = new Transaction($context, 'manager');
                    try { $tx->touch($kind, $key); } catch (\Throwable $e) { $tx->release(); throw $e; }
                }
            }
            self::finish($context, $tx, ($mode === 'new' ? 'create' : 'save') . " {$kind->name()} {$shown} in the manager");
            return;
        }
        if ($role === 'delete') {
            $tx = self::take($id);
            if ($tx === null) {
                $context->outsideChangeIsManagers = true;
                $tx = new Transaction($context, 'manager');
                try { $tx->touch($kind, $key); } catch (\Throwable $e) { $tx->release(); throw $e; }
            }
            self::finish($context, $tx, "delete {$kind->name()} {$shown} in the manager");
            return;
        }
        if ($role === 'touch') {
            $context->outsideChangeIsManagers = true;
            $tx = self::open($context, function (Transaction $tx) use ($kind, $modx, $key, $spec) {
                $tx->touch($kind, $key);
                if ($spec['cascade'] === 'undelete') {
                    $others = $kind->alsoAffected($modx, $key, array(), 'undelete');
                    if (count($others) > self::LIMIT) { throw new \RuntimeException("{$kind->name()} {$key} has more than " . self::LIMIT . ' descendants; not recorded.'); }
                    foreach ($others as $other) { $tx->touch($kind, $other); }
                }
                return true;
            });
            self::finish($context, $tx, "{$event} {$kind->name()} {$shown} in the manager");
        }
    }

    /** Before sorting or emptying the trash: touch every resource the operation may change. */
    private static function beforeMany(Context $context, Kind $kind, $role, array $params) {
        $modx = $context->modx;
        $ids = array();
        if ($role === 'before_sort') {
            $nodes = isset($params['nodes']) && is_array($params['nodes']) ? $params['nodes'] : array();
            foreach ($nodes as $node) {
                $node = (array) $node;
                foreach (array('id', 'parent') as $field) {
                    if (isset($node[$field]) && ctype_digit((string) $node[$field]) && (int) $node[$field] > 0) { $ids[(int) $node[$field]] = true; }
                }
            }
            // The parent a node leaves also changes (its isfolder).
            foreach (array_keys($ids) as $id) {
                $current = $kind->findByKey($modx, $id);
                if ($current && (int) $current->get('parent') > 0) { $ids[(int) $current->get('parent')] = true; }
            }
        } else {
            $given = isset($params['ids']) && is_array($params['ids']) ? $params['ids'] : array();
            foreach ($given as $id) {
                if (ctype_digit((string) $id) && (int) $id > 0) { $ids[(int) $id] = true; }
            }
        }
        $ids = array_keys($ids);
        if (count($ids) > self::LIMIT) {
            $modx->log(\modX::LOG_LEVEL_ERROR, '[modxmcp] The manager recorder skipped ' . ($role === 'before_sort' ? 'a sort' : 'emptying the trash') . ' of ' . count($ids) . ' resources (limit ' . self::LIMIT . ').');
            return;
        }
        self::keep($kind->name() . ':' . ($role === 'before_sort' ? 'sort' : 'trash'), self::open($context, function (Transaction $tx) use ($kind, $role, $ids) {
            foreach ($ids as $id) {
                if ($role === 'before_trash') { $tx->keepDeleteExtra($kind, $id); } else { $tx->touch($kind, $id); }
            }
            return true;
        }));
    }

    /** The key of the object the event is about: from the object itself, else from the `id` parameter. */
    private static function keyOf(Kind $kind, $object, array $params) {
        if ($object) {
            $key = $kind->keyOf($object);
            if ($key !== 0 && $key !== '' && $key !== null) { return $kind->normalizeKey($key); }
        }
        if (isset($params['id']) && $params['id'] !== '' && $params['id'] !== 0 && $params['id'] !== '0') { return $kind->normalizeKey($params['id']); }
        return null;
    }

    /**
     * A new transaction filled by $fill (touches objects). It is released when $fill fails or returns false;
     * then null is returned.
     */
    private static function open(Context $context, callable $fill) {
        $tx = new Transaction($context, 'manager');
        try {
            $filled = $fill($tx);
        } catch (\Throwable $e) {
            $tx->release();
            throw $e;
        }
        if (!$filled) { $tx->release(); return null; }
        return $tx;
    }

    /** Keep a before-event's transaction for the matching after-event; one left by a failed save is released first. */
    private static function keep($id, Transaction $tx = null) {
        if (isset(self::$open[$id])) {
            self::$open[$id]->release();
            unset(self::$open[$id]);
        }
        if ($tx !== null) { self::$open[$id] = $tx; }
    }

    private static function take($id) {
        if (!isset(self::$open[$id])) { return null; }
        $tx = self::$open[$id];
        unset(self::$open[$id]);
        return $tx;
    }

    private static function finish(Context $context, Transaction $tx = null, $summary = '') {
        if ($tx === null) { return; }
        try {
            $tx->commit($summary);
        } finally {
            $tx->release();
        }
    }

    // ---- the plugin ----

    /** The body of the plugin, read from the component file (without the opening tag, as MODX stores it). */
    public static function pluginCode($corePath) {
        $file = rtrim($corePath, '/\\') . '/elements/plugins/recorder.php';
        $code = is_file($file) ? (string) file_get_contents($file) : '';
        return trim(preg_replace('/^\s*<\?php\s*/', '', $code));
    }

    /**
     * Create or update the plugin "modxMCP" and make its events equal to events(). Touches only that plugin and its
     * modPluginEvent rows, directly (not through the journal: the plugin is part of the package).
     * @return array ['created' => bool, 'added' => string[], 'removed' => string[]]
     */
    public static function syncPlugin(\modX $modx) {
        $corePath = $modx->getOption('modxmcp.core_path', null, $modx->getOption('core_path') . 'components/modxmcp/');
        $code = self::pluginCode($corePath);
        $plugin = $modx->getObject('modPlugin', array('name' => self::PLUGIN_NAME));
        $created = !$plugin;
        if ($created) {
            $plugin = $modx->newObject('modPlugin');
            $plugin->set('name', self::PLUGIN_NAME);
        }
        $plugin->set('description', self::PLUGIN_DESCRIPTION);
        if ($code !== '') { $plugin->set('plugincode', $code); }
        $plugin->save();
        $id = (int) $plugin->get('id');

        $wanted = self::events();
        $have = array();
        $removed = array();
        foreach ($modx->getCollection('modPluginEvent', array('pluginid' => $id)) as $row) {
            $name = (string) $row->get('event');
            if (in_array($name, $wanted, true) && !isset($have[$name])) { $have[$name] = true; continue; }
            $removed[] = $name;
            $row->remove();
        }
        $added = array();
        foreach ($wanted as $name) {
            if (isset($have[$name])) { continue; }
            $row = $modx->newObject('modPluginEvent');
            $row->fromArray(array('pluginid' => $id, 'event' => $name, 'priority' => 0, 'propertyset' => 0), '', true, true);
            $row->save();
            $added[] = $name;
        }
        if ($created || $added || $removed) {
            $cache = $modx->getCacheManager();
            if ($cache) { $cache->refresh(); }
        }
        return array('created' => $created, 'added' => $added, 'removed' => $removed);
    }
}
