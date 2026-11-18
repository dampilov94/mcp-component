<?php
namespace ModxMCP\Verify;

use ModxMCP\Context;

/**
 * Runs a snippet, renders a chunk or parses a string of MODX tags in a chosen context and for a chosen resource, and
 * reports what came out. The MODX state it changes for the run (context, resource, output buffers) is always restored.
 * Errors of the code under test are results, never exceptions of this class.
 */
final class Runner {
    const TAG_LIMIT = 20;
    const LOG_LIMIT = 20;

    /**
     * @param array $spec mode (snippet|chunk|text), name, text, properties, context (key), resource (object|null), max_output
     * @return array the result without the journal fields (the action adds those)
     */
    public static function execute(Context $context, array $spec) {
        $modx = $context->modx;
        $parser = null;
        $previousContext = isset($modx->context) && is_object($modx->context) ? $modx->context->get('key') : null;
        $previousResource = $modx->resource;
        $previousIdentifier = $modx->resourceIdentifier;
        $level = ob_get_level();
        $logPath = $modx->getCachePath() . 'logs/error.log';
        $maxLog = $modx->getOption('modxmcp.max_read_bytes', null, 262144);
        $before = LogReader::tail($logPath, 1, $maxLog)['end_offset'];

        $result = null;
        $error = null;
        $captured = '';
        $start = microtime(true);
        ob_start();
        try {
            if ($spec['context'] !== $previousContext) {
                $modx->switchContext($spec['context']);
                if (!isset($modx->context) || !is_object($modx->context) || $modx->context->get('key') !== $spec['context']) {
                    throw new \RuntimeException("The context '{$spec['context']}' could not be started.");
                }
            }
            if ($spec['resource'] !== null) {
                $modx->resource = $spec['resource'];
                $modx->resourceIdentifier = $spec['resource']->get('id');
            }
            if ($spec['mode'] === 'snippet') {
                $result = $modx->runSnippet($spec['name'], $spec['properties']);
            } elseif ($spec['mode'] === 'chunk') {
                $result = $modx->getChunk($spec['name'], $spec['properties']);
            } else {
                $result = $spec['text'];
            }
            // A text is always parsed: a parser extended by pdoTools/Fenom handles syntax other than [[tags]].
            if (is_string($result) && ($spec['mode'] === 'text' || strpos($result, '[[') !== false)) {
                // What a page would finally show: the two passes MODX makes for a page, the cacheable tags and then the
                // uncacheable ones — also over the output of a snippet or chunk, whose [[!tags]] are otherwise left for
                // the page. Tags that cannot be processed stay in the text (the third pass that removes them is left
                // out), so a missing element is visible.
                $modx->getParser();
                $iterations = (int) $modx->getOption('parser_max_iterations', null, 10);
                $modx->parser->processElementTags('', $result, false, false, '[[', ']]', array(), $iterations);
                $modx->parser->processElementTags('', $result, true, false, '[[', ']]', array(), $iterations);
            }
        } catch (\Throwable $e) {
            $error = array(
                'type' => get_class($e),
                'message' => self::clean(self::hidePaths($modx, trim((string) $e->getMessage()))),
                'file' => self::relative($modx, (string) $e->getFile()),
                'line' => (int) $e->getLine(),
            );
        } finally {
            // Whatever the code left open (an unbalanced ob_start, an exception inside a buffer) is closed and kept as echoed text.
            while (ob_get_level() > $level) { $captured = (string) ob_get_clean() . $captured; }
            $restoreError = self::restore($modx, $previousContext, $previousResource, $previousIdentifier);
        }
        $duration = (int) round((microtime(true) - $start) * 1000);

        list($text, $type) = self::text($result);
        $text = self::clean($text);
        $captured = self::clean($captured);
        $max = (int) $spec['max_output'];
        $bytes = strlen($text);
        $out = array(
            'ok' => $error === null,
            'mode' => $spec['mode'],
            'context' => $spec['context'],
            'output' => self::cut($text, $max),
            'output_type' => $type,
            'echoed' => self::cut($captured, $max),
            'truncated' => $bytes > $max || strlen($captured) > $max,
            'bytes' => $bytes,
            'duration_ms' => $duration,
        );
        if ($spec['mode'] !== 'text') { $out['name'] = $spec['name']; }
        if ($spec['resource'] !== null) { $out['resource'] = (int) $spec['resource']->get('id'); }
        if ($error !== null) { $out['error'] = $error; }

        $tags = array();
        $count = 0;
        if ($text !== '') {
            preg_match_all('/\[\[[^\[\]\r\n]{1,300}\]\]/u', $text, $found, PREG_OFFSET_CAPTURE);
            $count = count($found[0]);
            foreach (array_slice($found[0], 0, self::TAG_LIMIT) as $tag) {
                $tags[] = array('tag' => $tag[0], 'line' => substr_count(substr($text, 0, $tag[1]), "\n") + 1);
            }
        }
        $out['unparsed_tags'] = $tags;
        $out['unparsed_count'] = $count;

        $log = LogReader::tail($logPath, 500, $maxLog, '', $before);
        $out['new_log_errors'] = $log['returned'];
        $out['log_lines'] = array_slice($log['entries'], 0, self::LOG_LIMIT);
        if ($restoreError !== null) { $out['restore_error'] = $restoreError; }
        return $out;
    }

    /** Put the context, resource and identifier back. Returns a message when the context could not be restored. */
    private static function restore(\modX $modx, $contextKey, $resource, $identifier) {
        $problem = null;
        try {
            if ($contextKey !== null && (!isset($modx->context) || !is_object($modx->context) || $modx->context->get('key') !== $contextKey)) {
                $modx->switchContext($contextKey);
                if (!isset($modx->context) || !is_object($modx->context) || $modx->context->get('key') !== $contextKey) {
                    $problem = "The context '{$contextKey}' could not be restored after the run.";
                }
            }
        } catch (\Throwable $e) {
            $problem = 'The context could not be restored after the run: ' . get_class($e);
        }
        $modx->resource = $resource;
        $modx->resourceIdentifier = $identifier;
        return $problem;
    }

    /** The output as text, and how it was produced: a string as it is, anything else as JSON, nothing as null. */
    private static function text($result) {
        if (is_string($result)) { return array($result, 'string'); }
        if ($result === null) { return array('', 'null'); }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return array($json === false ? '' : $json, 'json');
    }

    /** Valid UTF-8, or the invalid bytes replaced. */
    private static function clean($text) {
        $text = (string) $text;
        if (preg_match('//u', $text) === 1) { return $text; }
        return (string) json_decode(json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE), true);
    }

    /** At most $max bytes, never in the middle of a character. */
    private static function cut($text, $max) {
        return strlen($text) <= $max ? $text : (string) mb_strcut($text, 0, $max, 'UTF-8');
    }

    /** A file path relative to the site base; the core (when it lies elsewhere) as [core]/…; anything else by name only. */
    private static function relative(\modX $modx, $path) {
        $path = str_replace('\\', '/', $path);
        foreach (array('base_path' => '', 'core_path' => '[core]/') as $option => $label) {
            $base = str_replace('\\', '/', (string) $modx->getOption($option));
            if ($base === '') { continue; }
            $base = rtrim($base, '/') . '/';
            if (strncmp($path, $base, strlen($base)) === 0) { return $label . substr($path, strlen($base)); }
        }
        return '[outside the site]/' . basename($path);
    }

    /** The site's absolute paths are not given to the client. */
    private static function hidePaths(\modX $modx, $text) {
        foreach (array('base_path' => '', 'core_path' => '[core]/') as $option => $label) {
            $base = (string) $modx->getOption($option);
            if ($base !== '') { $text = str_replace(rtrim($base, '/\\') . '/', $label, $text); }
        }
        return $text;
    }
}
