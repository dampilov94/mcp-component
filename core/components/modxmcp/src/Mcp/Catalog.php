<?php
namespace ModxMCP\Mcp;

use ModxMCP\Context;
use ModxMCP\Manifest;

/**
 * The manifest as MCP objects: tools, prompts and help resources. This is the same mapping client/mapping.js makes
 * for the Node bridge; tests compare the two so they cannot drift.
 */
final class Catalog {
    const HELP_URI = 'modx-help://';

    /** First protocol version whose tools carry an outputSchema (and whose results carry structuredContent). */
    const STRUCTURED_FROM = '2025-06-18';

    public static function requestIdSchema() {
        return array(
            'type'        => 'string',
            'pattern'     => '^[A-Za-z0-9_-]{16,128}$',
            'description' => 'Optional request ID for retrying exactly the same call. Omit for a new operation; reuse the ID reported after a timeout. A new ID may execute the action again.',
        );
    }

    /** Whether a request speaking this protocol version gets outputSchema and structuredContent. */
    public static function structured($version) {
        return strcmp((string) $version, self::STRUCTURED_FROM) >= 0;
    }

    /** One manifest tool as an MCP tool. */
    public static function tool(array $tool, $withOutputSchema = true) {
        $readOnly = isset($tool['effect']['read_only']) && $tool['effect']['read_only'] === true;
        $inputSchema = $tool['inputSchema'];
        if (!$readOnly) {
            $properties = isset($inputSchema['properties']) ? (array) $inputSchema['properties'] : array();
            $properties['_request_id'] = self::requestIdSchema();
            $inputSchema['properties'] = $properties;
        }
        $out = array(
            'name'        => $tool['name'],
            'description' => $tool['description'],
            'inputSchema' => $inputSchema,
        );
        if ($withOutputSchema) { $out['outputSchema'] = $tool['outputSchema']; }
        $out['annotations'] = array(
            'readOnlyHint'    => $readOnly,
            'destructiveHint' => isset($tool['effect']['destructive']) && $tool['effect']['destructive'] === true,
            // A supplied _request_id protects one request; it does not make every call idempotent.
            'idempotentHint'  => $readOnly,
            'openWorldHint'   => isset($tool['effect']['open_world']) && $tool['effect']['open_world'] === true,
        );
        return $out;
    }

    /** @return array[] */
    public static function tools(Context $context, $withOutputSchema = true) {
        $out = array();
        foreach (Manifest::tools($context) as $tool) { $out[] = self::tool($tool, $withOutputSchema); }
        return $out;
    }

    /** One manifest prompt (name, title, description, arguments) as an MCP prompt. */
    public static function prompt(array $prompt) {
        $out = array('name' => $prompt['name']);
        if (!empty($prompt['title'])) { $out['title'] = $prompt['title']; }
        $out['description'] = $prompt['description'];
        $arguments = array();
        foreach (isset($prompt['arguments']) ? $prompt['arguments'] : array() as $argument) {
            $item = array('name' => $argument['name']);
            if (isset($argument['description']) && is_string($argument['description'])) { $item['description'] = $argument['description']; }
            $item['required'] = isset($argument['required']) && $argument['required'] === true;
            $arguments[] = $item;
        }
        $out['arguments'] = $arguments;
        return $out;
    }

    /** @return array[] */
    public static function prompts(Context $context) {
        $out = array();
        foreach (Manifest::prompts($context) as $prompt) { $out[] = self::prompt($prompt); }
        return $out;
    }

    /** Help topics as resources. */
    public static function resources(array $topics) {
        $out = array();
        foreach ($topics as $topic) {
            if (!is_string($topic)) { continue; }
            $out[] = array('uri' => self::HELP_URI . $topic, 'name' => 'modxMCP guide: ' . $topic, 'mimeType' => 'text/markdown');
        }
        return $out;
    }

    /** The help topic of a resource URI, or null when the URI is not one of ours. */
    public static function topicOf($uri) {
        if (!is_string($uri) || strncmp($uri, self::HELP_URI, strlen(self::HELP_URI)) !== 0) { return null; }
        $topic = substr($uri, strlen(self::HELP_URI));
        return preg_match('/^[a-z0-9_]{1,64}$/D', $topic) ? $topic : null;
    }
}
