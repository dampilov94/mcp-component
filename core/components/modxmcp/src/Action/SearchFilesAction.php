<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Code\CodeSearch;
use ModxMCP\Context;
use ModxMCP\Files\FileRoot;
use ModxMCP\Schema;

final class SearchFilesAction extends Action {
    const MAX_SCANNED = 2000;
    const EXTENSIONS = array('tpl', 'html', 'htm', 'css', 'scss', 'less', 'js', 'json', 'php', 'txt', 'md', 'xml', 'svg');
    const SKIP_DIRECTORIES = array('.git', 'node_modules', 'vendor');

    public function name() { return 'search_files'; }

    public function group() { return 'files'; }

    public function description() {
        return 'Literal text search in the text files of a media source (or an installed component\'s code), '
            . 'breadth first from path. Returns matching lines with numbers usable with modx_read_file (start_line) '
            . 'and modx_edit_file. Binary files, links, oversized files and .git / node_modules / vendor are skipped.';
    }

    public function inputSchema() {
        return Schema::obj(FileRoot::inputProperties() + array(
            'path'  => Schema::str('Directory relative to the root; default the root.', array('maxLength' => 1000)),
            'query' => Schema::str('Literal text.', array('minLength' => 2, 'maxLength' => 500)),
            'case_sensitive' => Schema::bool('Default false.'),
            'extensions' => Schema::arr(Schema::str(null, array('pattern' => '^[A-Za-z0-9]{1,10}$')), 'File extensions without a dot. Default: ' . implode(', ', self::EXTENSIONS) . '.', array('minItems' => 1, 'maxItems' => 50)),
            'limit' => Schema::int('Files with matches, default 50.', array('minimum' => 1, 'maximum' => 200)),
            'max_matches' => Schema::int('Lines per file, default 5.', array('minimum' => 1, 'maximum' => 50)),
        ), array('query'));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'root' => Schema::str(), 'query' => Schema::str(), 'files_scanned' => Schema::int(), 'files_matched' => Schema::int(),
            'truncated' => Schema::bool(), 'skipped' => Schema::int(), 'note' => Schema::str(),
            'results' => Schema::arr(Schema::obj(array(), array(), true)),
        ), array('root', 'query', 'files_scanned', 'files_matched', 'truncated', 'skipped', 'results'));
    }

    public function run(array $args, Context $context) {
        $root = FileRoot::fromArgs($context, $args);
        $relative = FileRoot::normalize(isset($args['path']) ? $args['path'] : '');
        $start = $root->resolve($relative);
        if (!is_dir($start)) { throw new ApiException('not_found', "No directory '{$relative}' in {$root->label}."); }
        $sensitive = !empty($args['case_sensitive']);
        $limit = isset($args['limit']) ? $args['limit'] : 50;
        $maxMatches = isset($args['max_matches']) ? $args['max_matches'] : 5;
        $extensions = array_map('strtolower', isset($args['extensions']) ? $args['extensions'] : self::EXTENSIONS);
        $maxBytes = (int) $context->modx->getOption('modxmcp.max_read_bytes', null, 262144);

        $queue = array(array($start, $relative));
        $scanned = 0;
        $skipped = 0;
        $truncated = false;
        $note = null;
        $results = array();
        for ($head = 0; $head < count($queue) && !$truncated; $head++) {
            list($dir, $rel) = $queue[$head];
            $entries = @scandir($dir);
            if ($entries === false) { continue; }
            $files = array();
            foreach (array_diff($entries, array('.', '..')) as $name) {
                $full = $dir . '/' . $name;
                $child = $rel === '' ? $name : $rel . '/' . $name;
                if (is_link($full)) { $skipped++; continue; }
                if (is_dir($full)) {
                    if (in_array($name, self::SKIP_DIRECTORIES, true)) { continue; }
                    try { $root->resolve($child); } catch (ApiException $e) { continue; }   // configuration and modxMCP data stay closed
                    $queue[] = array($full, $child);
                    continue;
                }
                $files[$name] = array($full, $child);
            }
            ksort($files, SORT_STRING);
            foreach ($files as $name => $pair) {
                list($full, $child) = $pair;
                if (preg_match(FileRoot::SECRET_NAMES, $name)) { continue; }
                if (!in_array(strtolower((string) pathinfo($name, PATHINFO_EXTENSION)), $extensions, true)) { continue; }
                if ($scanned >= self::MAX_SCANNED) {
                    $truncated = true;
                    $note = 'Stopped after ' . self::MAX_SCANNED . ' files; narrow path or extensions to search the rest.';
                    break;
                }
                $scanned++;
                $size = @filesize($full);
                if ($size === false || $size > $maxBytes) { $skipped++; continue; }
                $text = @file_get_contents($full);
                if ($text === false || strpos($text, "\0") !== false || preg_match('//u', $text) !== 1) { $skipped++; continue; }
                if (CodeSearch::position($text, $args['query'], $sensitive) === false) { continue; }
                $matches = array();
                $count = 0;
                foreach (preg_split('/\r\n|\r|\n/', $text) as $i => $line) {
                    $pos = CodeSearch::position($line, $args['query'], $sensitive);
                    if ($pos === false) { continue; }
                    $count++;
                    if (count($matches) < $maxMatches) { $matches[] = array('line' => $i + 1, 'text' => CodeSearch::excerpt($line, $pos)); }
                }
                if (!$count) { continue; }   // the query spans lines
                if (count($results) === $limit) {
                    $truncated = true;
                    $note = "More than {$limit} files match; narrow the query or path.";
                    break;
                }
                $results[] = array('path' => $child, 'match_count' => $count, 'matches' => $matches);
            }
        }
        $out = array('root' => $root->id, 'query' => $args['query'], 'files_scanned' => $scanned, 'files_matched' => count($results),
            'truncated' => $truncated, 'skipped' => $skipped, 'results' => $results);
        if ($note !== null) { $out['note'] = $note; }
        return $out;
    }
}
