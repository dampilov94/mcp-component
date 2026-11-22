<?php
namespace ModxMCP\Graph;

use ModxMCP\Integration\Migx;
use ModxMCP\Integration\VirtualPage;

/** Structural references held in add-on records, shared by the graph and deletion previews. */
final class IntegrationReferences {
    public static function scan(\modX $modx): iterable {
        try {
            Migx::load($modx);
            foreach ($modx->getIterator('migxConfig', array('deleted' => 0)) as $config) {
                foreach (array('formtabs', 'columns') as $field) {
                    foreach (self::migxReferences($config->get($field)) as $target) {
                        yield array('source' => 'migx_config', 'source_id' => (int) $config->get('id'), 'field' => $field) + $target;
                    }
                }
            }
        } catch (\Throwable $e) { /* The add-on or its tables may be absent. */ }

        try {
            VirtualPage::load($modx);
            $kinds = array(0 => 'resource', 1 => 'snippet', 2 => 'chunk', 3 => 'template');
            foreach ($modx->getIterator('vpHandler') as $handler) {
                $kind = $kinds[(int) $handler->get('type')] ?? null;
                $entry = (int) $handler->get('entry');
                if ($kind !== null && $entry > 0) {
                    yield array('source' => 'vp_handler', 'source_id' => (int) $handler->get('id'), 'field' => 'entry',
                        'kind' => $kind, 'id' => $entry);
                }
            }
        } catch (\Throwable $e) { /* The add-on or its tables may be absent. */ }
    }

    private static function migxReferences($value, $depth = 0): iterable {
        if ($depth >= 16) { return; }
        if (is_string($value) && preg_match('/^\s*[\[{]/', $value)) { $value = json_decode($value, true); }
        if (!is_array($value)) { return; }
        foreach ($value as $field => $item) {
            if (is_string($item) && in_array($field, array('inputTV', 'renderchunktpl', 'chunk', 'formtabs_chunk'), true)) {
                $name = trim($item);
                if ($field !== 'inputTV') { $name = preg_replace('/^@CHUNK\s+/i', '', $name); }
                if ($name !== '' && $name[0] !== '@') {
                    yield array('kind' => $field === 'inputTV' ? 'tv' : 'chunk', 'name' => $name);
                }
            } else {
                yield from self::migxReferences($item, $depth + 1);
            }
        }
    }
}
