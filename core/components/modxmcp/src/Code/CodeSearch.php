<?php
namespace ModxMCP\Code;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Kind\Objects;

/** Shared literal code search: kind access, paged effective reads and excerpts. */
final class CodeSearch {
    public static function kinds(Context $context, $names = null, $write = false) {
        $valid = array();
        foreach ($context->kinds->all() as $kind) { if ($kind->codeField() !== null) { $valid[] = $kind->name(); } }
        $out = array();
        foreach ($names === null ? array('chunk', 'snippet', 'template', 'plugin') : array_unique($names) as $name) {
            $kind = Objects::kind($context, $name, $write);
            if ($kind->codeField() === null) { throw new ApiException('validation_failed', 'Code kinds: ' . implode(', ', $valid) . '.'); }
            $out[$name] = $kind;
        }
        return $out;
    }
    public static function objects(Context $context, $kind, $query) {
        for ($offset = 0; ; $offset += 200) {
            $q = $context->modx->newQuery($kind->modelClass());
            if (!isset($kind->fields()['static'])) {
                $like = '%' . strtr($query, array('\\' => '\\\\', '%' => '\\%', '_' => '\\_')) . '%';
                $q->where(array(array($kind->column($kind->codeField()) . ':LIKE' => $like,
                    'OR:' . $kind->column($kind->nameField()) . ':LIKE' => $like)));
            }
            $q->sortby('id', 'ASC');
            $q->limit(200, $offset);
            $page = $context->modx->getCollection($kind->modelClass(), $q);
            foreach ($page as $object) { yield array($object, $kind->effectiveContent($object)); }
            if (count($page) < 200) { break; }
        }
    }
    /** Byte offset of the first occurrence, or false. Case folding covers non-ASCII text (e.g. Cyrillic). */
    public static function position($text, $query, $sensitive) {
        if ($sensitive) { return strpos($text, $query); }
        if (!function_exists('mb_stripos') || preg_match('//u', $text) !== 1 || preg_match('//u', $query) !== 1) {
            return stripos($text, $query);
        }
        $at = mb_stripos($text, $query, 0, 'UTF-8');
        return $at === false ? false : strlen(mb_substr($text, 0, $at, 'UTF-8'));
    }
    public static function excerpt($line, $byteOffset) {
        $trimmed = ltrim($line);
        $byteOffset = max(0, $byteOffset - (strlen($line) - strlen($trimmed)));
        $line = rtrim($trimmed);
        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) { $chars = str_split($line); }
        $prefix = preg_split('//u', substr($line, 0, $byteOffset), -1, PREG_SPLIT_NO_EMPTY);
        $position = $prefix === false ? $byteOffset : count($prefix);
        $start = $position > 250 ? max(0, $position - 40) : 0;
        $cut = count($chars) > $start + 300;
        return ($start ? '…' : '') . implode('', array_slice($chars, $start, 300)) . (!$start && $cut ? '…' : '');
    }
}
