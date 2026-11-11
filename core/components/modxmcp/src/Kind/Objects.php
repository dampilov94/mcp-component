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

    /** Find the object named by args (id or name) or fail with not_found. */
    public static function resolve(Context $context, Kind $kind, array $args) {
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

    /** Check and normalise field values from the API into column => value for a processor. */
    public static function normalize(Kind $kind, array $values, $creating) {
        $fields = $kind->fields();
        $out = array();
        foreach ($values as $name => $value) {
            if (!isset($fields[$name])) {
                throw new ApiException('validation_failed', "Unknown field '{$name}' for {$kind->name()}. See modx_describe.");
            }
            if (!empty($fields[$name]['read_only'])) {
                throw new ApiException('validation_failed', "Field '{$name}' is read-only.");
            }
            $type = $fields[$name]['type'];
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
        return $out;
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
    public static function assertRevision(Context $context, Kind $kind, $id, $state, array $args) {
        if (!isset($args['expected_revision'])) { return; }
        $current = Snapshot::revision($state);
        if (hash_equals((string) $current, $args['expected_revision'])) { return; }
        $details = array('current_revision' => $current);
        $last = $context->journal()->lastChange($kind->name(), $id);
        if ($last) {
            $details['last_change'] = array(
                'changeset_id' => $last['changeset_id'], 'at' => $last['created_at'], 'source' => $last['source'], 'tool' => $last['tool'],
            );
        }
        throw new ApiException('revision_conflict', "{$kind->name()} {$id} has changed since it was read. Read it again and retry.", $details);
    }

    /** Run a core processor; a processor error becomes validation_failed with its messages. */
    public static function process(\modX $modx, $processor, array $data) {
        $response = $modx->runProcessor($processor, $data);
        if (!$response) { throw new \RuntimeException("Processor {$processor} did not respond."); }
        if ($response->isError()) {
            $messages = array_filter(array_merge(array((string) $response->getMessage()), (array) $response->getAllErrors()));
            $text = trim(strip_tags(implode(' ', array_unique($messages))));
            throw new ApiException('validation_failed', $text !== '' ? $text : "Processor {$processor} failed.");
        }
        $object = $response->getObject();
        return is_array($object) ? $object : array();
    }

    /**
     * Create or update one object through the core processors, then apply relation changes.
     * The object must already be touched in the transaction (update) or is registered after
     * creation. Returns the object id.
     */
    public static function save(Context $context, Transaction $tx, Kind $kind, $id, array $columns, array $relations) {
        $modx = $context->modx;
        if ($id === null) {
            $created = self::process($modx, $kind->processorPath() . 'create', $columns + array('clearCache' => 1));
            if (empty($created['id'])) { throw new \RuntimeException('The create processor did not return an id.'); }
            $id = (int) $created['id'];
            $tx->created($kind, $id);
        } elseif ($columns) {
            $object = $kind->find($modx, array('id' => $id));
            // The update processors expect the complete record; omitted fields would be reset.
            $data = array('id' => $id);
            foreach ($kind->fields() as $name => $definition) {
                $data[$kind->column($name)] = $object->get($kind->column($name));
            }
            self::process($modx, $kind->processorPath() . 'update', $columns + $data);
        }

        $field = $kind->codeField();
        if ($field !== null && array_key_exists($kind->column($field), $columns)) {
            // MODX writes a static file only when the database column changed. If the column
            // was already equal but the file was not, write the file ourselves.
            $object = $kind->find($modx, array('id' => $id));
            $content = $kind->effectiveContent($object);
            if ($content[1] && $content[2] !== null && $content[0] !== $columns[$kind->column($field)]) {
                Snapshot::writeFile($content[2], $columns[$kind->column($field)]);
            } elseif (!$content[1] && $content[0] !== $columns[$kind->column($field)]) {
                $context->warnings[] = "MODX stored the {$field} of {$kind->name()} {$id} differently from what was sent "
                    . '(for PHP it removes an opening <?php tag). Read the code again before editing it by line numbers.';
            }
        }
        if ($relations) {
            $object = $kind->find($modx, array('id' => $id));
            foreach ($relations as $name => $value) { $kind->writeRelation($modx, $object, $name, $value); }
            $cache = $modx->getCacheManager();
            if ($cache) { $cache->refresh(); }
        }
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
            if (!is_array($value) || ($value && array_keys($value) !== range(0, count($value) - 1))) {
                throw new ApiException('validation_failed', "Relation '{$name}' must be a list.");
            }
        }
    }
}
