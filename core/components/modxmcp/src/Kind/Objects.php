<?php
namespace ModxMCP\Kind;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Lint\PhpLint;
use ModxMCP\Write\Transaction;

/** Generic object operations shared by the object and code tools. */
final class Objects {
    /** Resolve a kind by name and check that this token may read (or write) it. */
    public static function kind(Context $context, $name, $write = false) {
        $kind = $context->kinds->get($name);
        if (!$kind) {
            throw new ApiException('not_found', "Unknown kind '{$name}'. Call modx_describe without arguments to list kinds.");
        }
        $reason = $context->kinds->blockReason($kind, $context);
        if ($reason === null && $write && !Scope::allows($context->token['scope'], $kind->writeScope())) { $reason = 'forbidden_scope'; }
        if ($reason === 'forbidden_scope') {
            $needed = $write ? $kind->writeScope() : $kind->readScope();
            throw new ApiException('forbidden_scope', "Kind '{$name}' requires the '{$needed}' scope for this operation; this token has '{$context->token['scope']}'.");
        }
        if ($reason !== null) {
            throw new ApiException('unavailable', "Kind '{$name}' is unavailable: {$reason}.", array('reason' => $reason));
        }
        return $kind;
    }

    /**
     * Find the object named by args or fail with not_found. Integer kinds take id or name,
     * string-key kinds take key (or name as an alias).
     */
    public static function resolve(Context $context, Kind $kind, array $args) {
        if ($kind->keyType() === 'string') {
            if (isset($args['id'])) {
                throw new ApiException('validation_failed', "Kind '{$kind->name()}' is addressed by key, not id: use the key argument.");
            }
            $raw = isset($args['key']) ? $args['key'] : (isset($args['name']) ? $args['name'] : '');
            if ($raw === '') { throw new ApiException('validation_failed', 'Provide key.'); }
            $object = $kind->find($context->modx, isset($args['key']) ? array('key' => $raw) : array('name' => $raw));
            if (!$object) { throw new ApiException('not_found', "No {$kind->name()} with key '{$raw}'."); }
            return $object;
        }
        if (isset($args['key'])) {
            throw new ApiException('validation_failed', "Kind '{$kind->name()}' is addressed by id (or name), not key: use the id argument.");
        }
        if (isset($args['name']) && !$kind->hasNames()) {
            throw new ApiException('validation_failed', "Kind '{$kind->name()}' has no names: use the id argument.");
        }
        if (!isset($args['id']) && (!isset($args['name']) || $args['name'] === '')) {
            throw new ApiException('validation_failed', 'Provide id or name.');
        }
        $object = $kind->find($context->modx, $args);
        if (!$object) {
            $ref = isset($args['id']) ? 'id ' . $args['id'] : "name '{$args['name']}'";
            throw new ApiException('not_found', "No {$kind->name()} with {$ref}.");
        }
        return $object;
    }

    /** Find the object with a known key (as returned by keyOf) or fail with not_found. */
    public static function resolveKey(Context $context, Kind $kind, $key) {
        $object = $kind->findByKey($context->modx, $kind->normalizeKey($key));
        if (!$object) { throw new ApiException('not_found', "No {$kind->name()} with " . self::keyText($kind, $key) . '.'); }
        return $object;
    }

    /** "id 5" or "key 'x'": how messages name an object of the kind. */
    public static function keyText(Kind $kind, $key) {
        return $kind->keyType() === 'string' ? "key '{$key}'" : 'id ' . $key;
    }

    /** Check and normalise field values from the API into column => value for a processor. */
    public static function normalize(Kind $kind, array $values, $creating) {
        $fields = $kind->fields();
        $out = array();
        foreach ($values as $name => $value) {
            if (!isset($fields[$name])) {
                throw new ApiException('validation_failed', "Unknown field '{$name}' for {$kind->name()}. See modx_describe.");
            }
            if (!empty($fields[$name]['read_only']) || !empty($fields[$name]['derived'])) {
                throw new ApiException('validation_failed', "Field '{$name}' is read-only.");
            }
            $type = $fields[$name]['type'];
            if ($type === 'object') {
                $out[$kind->column($name)] = self::normalizeObject($kind, $name, $value);
                continue;
            }
            $ok = ($type === 'integer' && is_int($value)) || ($type === 'boolean' && is_bool($value)) || ($type === 'string' && is_string($value));
            if (!$ok) { throw new ApiException('validation_failed', "Field '{$name}' must be {$type}."); }
            $out[$kind->column($name)] = $type === 'boolean' ? ($value ? 1 : 0) : $value;
        }
        if ($creating) {
            foreach ($fields as $name => $definition) {
                if (!empty($definition['required']) && (!isset($values[$name]) || $values[$name] === '')) {
                    throw new ApiException('validation_failed', "Field '{$name}' is required.");
                }
            }
        }
        $kind->assertColumns($out, $creating);
        return $out;
    }

    /**
     * A field of type object: a JSON object whose values are all booleans. After json_decode(…, true) an empty
     * object and an empty list look alike, so an empty array is the empty object. Returns name => bool, keys sorted.
     */
    public static function normalizeObject(Kind $kind, $name, $value) {
        if ($value instanceof \stdClass) { $value = (array) $value; }
        if (!is_array($value) || ($value && array_keys($value) === range(0, count($value) - 1))) {
            throw new ApiException('validation_failed', "Field '{$name}' must be an object of name => true|false.");
        }
        $out = array();
        foreach ($value as $key => $flag) {
            if ((string) $key === '' || !is_bool($flag)) {
                throw new ApiException('validation_failed', "Field '{$name}': every value must be true or false and every name must be non-empty (check '{$key}').");
            }
            $out[(string) $key] = $flag;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** True when a stored value of a field equals the value a caller sent for it (maps compare as maps). */
    public static function sameValue(Kind $kind, $name, $stored, $value) {
        $fields = $kind->fields();
        if ($fields[$name]['type'] === 'object') {
            $a = (array) Kind::objectValue($stored);
            $b = $value instanceof \stdClass || is_array($value) ? (array) Kind::objectValue((array) $value) : null;
            return $b !== null && $a === $b;
        }
        return (string) $stored === (string) (is_bool($value) ? (int) $value : $value);
    }

    /** Refuse an update that tries to change a field fixed at creation (e.g. a setting key). */
    public static function assertImmutable(Kind $kind, $object, array $columns) {
        foreach ($kind->fields() as $name => $definition) {
            $column = $kind->column($name);
            if (empty($definition['immutable']) || !array_key_exists($column, $columns)) { continue; }
            if ((string) $columns[$column] !== (string) $object->get($column)) {
                throw new ApiException('validation_failed', "Field '{$name}' of {$kind->name()} cannot be changed after creation.");
            }
        }
    }

    /** Reject code that does not parse, before anything is saved. */
    public static function lint(Context $context, Kind $kind, array $columns, $skip = false) {
        $field = $kind->codeField();
        if ($kind->lint() !== 'php' || $field === null || !array_key_exists($kind->column($field), $columns)) { return; }
        if (!$context->modx->getOption('modxmcp.lint_php', null, true)) { return; }
        $error = PhpLint::check($columns[$kind->column($field)]);
        if ($error === null) { return; }
        if ($skip) {
            $context->warnings[] = "PHP syntax check skipped on request: {$error['message']} on line {$error['line']}.";
            return;
        }
        throw new ApiException('lint_failed', "PHP syntax error on line {$error['line']}: {$error['message']}. Nothing was saved.", $error);
    }

    /** Fail with revision_conflict when the caller's revision is not the current one. */
    public static function assertRevision(Context $context, Kind $kind, $key, $state, array $args) {
        if (!isset($args['expected_revision'])) { return; }
        $current = Snapshot::revision($state);
        if (hash_equals((string) $current, $args['expected_revision'])) { return; }
        $details = array('current_revision' => $current);
        $last = $context->journal()->lastChange($kind->name(), $key);
        if ($last) {
            $details['last_change'] = array(
                'changeset_id' => $last['changeset_id'], 'at' => $last['created_at'], 'source' => $last['source'], 'tool' => $last['tool'],
            );
        }
        throw new ApiException('revision_conflict', "{$kind->name()} {$key} has changed since it was read. Read it again and retry.", $details);
    }

    /** Refuse create, update, delete and duplicate for a kind that is not written through the generic tools. */
    public static function assertGenericWrites(Kind $kind) {
        if (!$kind->genericWrites()) { throw new ApiException('validation_failed', $kind->genericWritesMessage()); }
    }

    /** Run a core processor and return its response; a processor error becomes validation_failed with its messages. */
    public static function run(\modX $modx, $processor, array $data) {
        $response = $modx->runProcessor($processor, $data);
        if (!$response) { throw new \RuntimeException("Processor {$processor} did not respond."); }
        if ($response->isError()) {
            $messages = array_filter(array_merge(array((string) $response->getMessage()), (array) $response->getAllErrors()));
            $text = trim(strip_tags(implode(' ', array_unique($messages))));
            throw new ApiException('validation_failed', $text !== '' ? $text : "Processor {$processor} failed.");
        }
        return $response;
    }

    /** Run a core processor; a processor error becomes validation_failed with its messages. Returns the response object. */
    public static function process(\modX $modx, $processor, array $data) {
        $object = self::run($modx, $processor, $data)->getObject();
        return is_array($object) ? $object : array();
    }

    /**
     * Create or update one object through the core processors, then apply relation changes.
     * The object must already be touched in the transaction (update) or is registered after
     * creation. Returns the object key.
     */
    public static function save(Context $context, Transaction $tx, Kind $kind, $id, array $columns, array $relations) {
        $modx = $context->modx;
        $creating = $id === null;
        foreach ($kind->alsoAffected($modx, $id, $columns, $id === null ? 'create' : 'update') as $other) {
            $tx->touch($kind, $other);
        }
        if ($id === null) {
            $after = $kind->beforeCreate($context, $columns);
            $created = self::process($modx, $kind->processorPath() . 'create', $kind->processorData('create', $columns + $kind->processorExtras('create')));
            $id = $kind->createdKey($created, $columns);
            $tx->created($kind, $id);
            if ($after) { $after($tx, $id); }
        } elseif ($columns) {
            $object = $kind->findByKey($modx, $id);
            // The update processors expect the complete record; omitted fields would be reset.
            $data = $kind->processorKey($id);
            foreach ($kind->fields() as $name => $definition) {
                // A write-only field (a password) is never sent back; its stored value is a hash.
                if (empty($definition['derived']) && empty($definition['write_only'])) { $data[$kind->column($name)] = $kind->processorValue($object, $name); }
            }
            self::process($modx, $kind->processorPath() . 'update', $kind->processorData('update', $columns + $data + $kind->processorExtras('update')));
        }

        $field = $kind->codeField();
        if ($field !== null && array_key_exists($kind->column($field), $columns)) {
            // MODX writes a static file only when the database column changed. If the column
            // was already equal but the file was not, write the file ourselves.
            $object = $kind->findByKey($modx, $id);
            $content = $kind->effectiveContent($object);
            if ($content[1] && $content[2] !== null && $content[0] !== $columns[$kind->column($field)]) {
                Snapshot::writeFile($content[2], $kind->fileContent($columns[$kind->column($field)]));
            } elseif (!$content[1] && $content[0] !== $columns[$kind->column($field)]) {
                $context->warnings[] = "MODX stored the {$field} of {$kind->name()} {$id} differently from what was sent "
                    . '(for PHP it removes an opening <?php tag). Read the code again before editing it by line numbers.';
            }
        }
        $saved = $kind->findByKey($modx, $id);
        if ($saved) {
            foreach ($kind->writeWarnings($saved) as $warning) { $context->warnings[] = $warning; }
        }
        if ($relations) {
            $object = $kind->findByKey($modx, $id);
            foreach ($relations as $name => $value) { $kind->writeRelation($modx, $object, $name, $value); }
            $cache = $modx->getCacheManager();
            if ($cache) { $cache->refresh(); }
        }
        if ($creating && isset($kind->operations()['make_static']) && !array_key_exists('static', $columns)
            && $modx->getOption('modxmcp.auto_static', null, true)) {
            StaticFiles::makeStatic($context, $tx, $kind, $id);
        }
        $kind->afterWrite($modx);
        return $id;
    }

    /** Check that every named relation exists and can be written. */
    public static function assertWritableRelations(Kind $kind, array $relations) {
        $specs = $kind->relationSpecs();
        foreach ($relations as $name => $value) {
            if (!isset($specs[$name]) || !empty($specs[$name]['delete_only'])) {
                throw new ApiException('validation_failed', "Unknown relation '{$name}' for {$kind->name()}. See modx_describe.");
            }
            if (empty($specs[$name]['writable'])) {
                throw new ApiException('validation_failed', "Relation '{$name}' of {$kind->name()} cannot be changed through the API.");
            }
            $isList = is_array($value) && (!$value || array_keys($value) === range(0, count($value) - 1));
            if (!empty($specs[$name]['map'])) {
                if (!is_array($value) || ($value && $isList)) { throw new ApiException('validation_failed', "Relation '{$name}' must be an object of name => value."); }
            } elseif (!$isList) {
                throw new ApiException('validation_failed', "Relation '{$name}' must be a list.");
            }
        }
    }
}
