<?php
namespace ModxMCP\Code;

/**
 * The exact references to a chunk, a snippet or a template variable in one text, found and rewritten by the same rules
 * the graph and modx_find_usages use to find them (Graph\GraphBuilder, Code\Fenom, Code\Usages), but at the level of the
 * name itself: only the characters of the name are replaced, never the text around them.
 *
 * A match is a rewrite only where the form is exact. What looks like a reference but cannot be rewritten safely (a name
 * built at run time, a parameter that is not a template parameter, a name that differs in case, a placeholder outside the
 * elements linked to the TV) is returned as `manual`. Pure functions.
 */
final class ReferenceRewriter {
    /** The characters a name may have in a tag (GraphBuilder::tagName()). */
    const B = '[\p{L}\p{N}\p{M}_\-.\/]';
    const JOINED = '(?!\s*[~.+\-*\/%?:])';
    const KINDS = array('chunk', 'snippet', 'tv');
    const TEMPLATE_KEY = '/tpl|chunk|wrapper/i';

    /**
     * Whether the name can be written in a tag and is accepted by MODX for the kind (the validation of the model map:
     * no leading or trailing space, letters, digits, - . / _ and non-ASCII), and has no whitespace at all, because a tag name ends there.
     * @return string|null the reason, or null when the name is fine
     */
    public static function nameProblem($kind, $name) {
        if (!is_string($name) || $name === '') { return 'The new name is empty.'; }
        if (strlen($name) > 190 || mb_strlen($name, 'UTF-8') > 50) { return 'The new name is longer than 50 characters.'; }
        if (preg_match('/^(?!\s)[a-zA-Z0-9\x2d-\x2f\x7f-\xff_\s]+(?<!\s)$/', $name) !== 1 || preg_match('//u', $name) !== 1) {
            return 'The new name may hold letters, digits and - . / _ only, as MODX requires.';
        }
        if (preg_match('/\s/u', $name)) { return 'The new name must not contain spaces: a tag name ends at a space, so the references could not be written.'; }
        if ($kind === 'tv' && preg_match('/^(id|type|contentType|pagetitle|longtitle|description|alias|alias_visible|link_attributes|published|pub_date|unpub_date|parent|isfolder|introtext|content|richtext|template|menuindex|searchable|cacheable|createdby|createdon|editedby|editedon|deleted|deletedby|deletedon|publishedon|publishedby|menutitle|donthit|privateweb|privatemgr|content_dispo|hidemenu|class_key|context_key|content_type|uri|uri_override|hide_children_in_tree|show_in_tree|properties)$/', $name)) {
            return 'This name is reserved for a resource field and cannot be a template variable.';
        }
        return null;
    }

    /**
     * @param string $kind chunk, snippet or tv
     * @param array $options fenom (the text is Fenom), php (the text is PHP code), placeholders (rewrite [[+name]] too), tvfield (the text is a TV option field: only @CHUNK)
     * @return array text (rewritten), changes [{line, old, new, via}], manual [{line, text, reason}]
     */
    public static function rewrite($kind, $old, $new, $text, array $options = array()) {
        $text = (string) $text;
        $result = array('text' => $text, 'changes' => array(), 'manual' => array());
        if ($old === '' || $text === '' || !in_array($kind, self::KINDS, true)) { return $result; }
        $options += array('fenom' => false, 'php' => false, 'placeholders' => false, 'tvfield' => false);
        if (stripos($text, $old) === false && !(function_exists('mb_stripos') && preg_match('//u', $text) === 1 && mb_stripos($text, $old, 0, 'UTF-8') !== false)) { return $result; }
        if (preg_match('//u', $text) !== 1) {
            $result['manual'][] = array('line' => 0, 'text' => '', 'reason' => 'The text is not valid UTF-8 and was not scanned.');
            return $result;
        }
        list($dead, $tags) = self::spans($text, $options['fenom']);
        $exact = array();
        $manual = array();
        foreach (self::rules($kind, $old, $options) as $rule) {
            foreach (array('exact' => 'u', 'folded' => 'ui') as $mode => $flags) {
                if (@preg_match_all('/' . $rule['re'] . '/' . $flags, $text, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) { continue; }
                foreach ($found as $hit) {
                    foreach (self::spansOf($rule, $hit, $old, $flags) as $span) {
                        if (self::within($span[0], $dead) || (!empty($rule['in_tag']) && !self::within($span[0], $tags))) { continue; }
                        $key = $span[0];
                        if ($mode === 'folded') {
                            if (!isset($exact[$key]) && !isset($manual[$key]) && substr($text, $span[0], $span[1]) !== $old && empty($rule['no_case_check'])) {
                                $manual[$key] = array($span[0], 'Written with other letter case than the name (' . substr($text, $span[0], $span[1]) . '); MODX may still find it, a rewrite is not safe.');
                            }
                            continue;
                        }
                        if (!empty($rule['manual'])) { $manual[$key] = array($span[0], $rule['manual']); continue; }
                        if (!empty($rule['key_manual']) && !self::keyAllows($rule, $hit)) {
                            $manual[$key] = array($span[0], str_replace('{key}', $hit['k'][0], $rule['key_manual']));
                            continue;
                        }
                        if (!empty($rule['placeholder']) && !$options['placeholders']) {
                            $manual[$key] = array($span[0], 'A placeholder with the name of the TV, outside the templates and chunks linked to it; it may belong to something else, so it is not rewritten.');
                            continue;
                        }
                        $exact[$key] = array($span[0], $span[1], $rule['via']);
                    }
                }
            }
        }
        foreach ($exact as $key => $span) { unset($manual[$key]); }
        ksort($exact, SORT_NUMERIC);
        ksort($manual, SORT_NUMERIC);
        // Rewrite from the end so the offsets of the earlier spans stay valid.
        $rewritten = $text;
        foreach (array_reverse($exact, true) as $span) { $rewritten = substr_replace($rewritten, $new, $span[0], $span[1]); }
        $result['text'] = $rewritten;
        $result['changes'] = self::changes($text, $exact, $old, $new);
        foreach ($manual as $item) {
            $result['manual'][] = array('line' => substr_count(substr($text, 0, $item[0]), "\n") + 1, 'text' => self::lineAt($text, $item[0]), 'reason' => $item[1]);
        }
        return $result;
    }

    private static function keyAllows(array $rule, array $hit) {
        return $rule['key_manual'] !== null && preg_match(self::TEMPLATE_KEY, $hit['k'][0]) === 1;
    }

    /** @return array [[offset, length], ...] of the name inside one match */
    private static function spansOf(array $rule, array $hit, $old, $flags) {
        if (isset($rule['list'])) {
            // &includeTVs=`a, b`: every item that is the name.
            $list = $hit['list'];
            if (!preg_match_all('/(?<![^,\s])' . preg_quote($old, '/') . '(?![^,\s])/' . ($flags === 'u' ? 'u' : 'ui'), $list[0], $items, PREG_OFFSET_CAPTURE)) { return array(); }
            $out = array();
            foreach ($items[0] as $item) { $out[] = array($list[1] + $item[1], strlen($item[0])); }
            return $out;
        }
        return isset($hit['n']) && $hit['n'][1] >= 0 ? array(array($hit['n'][1], strlen($hit['n'][0]))) : array();
    }

    private static function within($offset, array $spans) {
        foreach ($spans as $span) { if ($offset >= $span[0] && $offset < $span[1]) { return true; } }
        return false;
    }

    /**
     * Spans that are never references (comment tags, Fenom comments and {ignore} blocks) and the spans of the outermost tags.
     * @return array [dead spans, tag spans]
     */
    private static function spans($text, $fenom) {
        $dead = array();
        $tags = array();
        $length = strlen($text);
        $i = 0;
        while ($i < $length - 1) {
            if ($text[$i] !== '[' || $text[$i + 1] !== '[') { ++$i; continue; }
            $start = $i;
            $comment = substr_compare($text, '[[-', $i, 3) === 0;
            $depth = 0;
            while ($i < $length) {
                if (substr_compare($text, '[[', $i, 2) === 0) { ++$depth; $i += 2; }
                elseif (substr_compare($text, ']]', $i, 2) === 0) { --$depth; $i += 2; if ($depth === 0) { break; } }
                else { ++$i; }
            }
            if ($comment) { $dead[] = array($start, $i); } else { $tags[] = array($start, $i); }
        }
        if ($fenom) {
            foreach (array('/\{\*.*?\*\}/s', '/\{ignore\}.*?\{\/ignore\}/s') as $pattern) {
                if (preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE)) {
                    foreach ($found[0] as $hit) { $dead[] = array($hit[1], $hit[1] + strlen($hit[0])); }
                }
            }
        }
        return array($dead, $tags);
    }

    private static function lineAt($text, $offset) {
        $before = substr($text, 0, $offset);
        $start = ($last = strrpos($before, "\n")) === false ? 0 : $last + 1;
        $end = strpos($text, "\n", $offset);
        $line = $end === false ? substr($text, $start) : substr($text, $start, $end - $start);
        return CodeSearch::excerpt(rtrim($line, "\r"), $offset - $start);
    }

    private static function changes($text, array $exact, $old, $new) {
        $byLine = array();
        foreach ($exact as $span) {
            $line = substr_count(substr($text, 0, $span[0]), "\n") + 1;
            $byLine[$line][] = $span;
        }
        $changes = array();
        foreach ($byLine as $line => $spans) {
            $before = substr($text, 0, $spans[0][0]);
            $start = ($last = strrpos($before, "\n")) === false ? 0 : $last + 1;
            $end = strpos($text, "\n", $spans[0][0]);
            $original = $end === false ? substr($text, $start) : substr($text, $start, $end - $start);
            $changed = $original;
            foreach (array_reverse($spans) as $span) { $changed = substr_replace($changed, $new, $span[0] - $start, $span[1]); }
            $changes[] = array('line' => $line, 'old' => CodeSearch::excerpt(rtrim($original, "\r"), $spans[0][0] - $start),
                'new' => CodeSearch::excerpt(rtrim($changed, "\r"), $spans[0][0] - $start), 'via' => $spans[0][2], 'count' => count($spans));
        }
        return $changes;
    }

    /** Rules of one kind: re has a named group n around the name (or list), via names the form, the flags say where it applies. */
    private static function rules($kind, $name, array $o) {
        $n = preg_quote($name, '/');
        $end = '(?!' . self::B . ')';
        $q = '(?P<q>[\'"])';
        $qe = '(?P=q)';
        $join = self::JOINED;
        $anyKey = '&(?P<k>[A-Za-z0-9_\-.]+)\s*=\s*`\s*(?P<n>' . $n . ')\s*`';
        $rules = array();
        $php = $o['php'];
        $fenom = $o['fenom'];
        if ($o['tvfield']) {
            return $kind === 'chunk' ? array(array('re' => '(?i:@CHUNK)\s+(?P<n>' . $n . ')' . $end, 'via' => 'binding')) : array();
        }
        if ($kind === 'chunk') {
            $rules[] = array('re' => '\[\[\s*!*\$(?P<n>' . $n . ')' . $end, 'via' => 'tag');
            $rules[] = array('re' => $anyKey, 'via' => 'property', 'in_tag' => true,
                'key_manual' => 'The name is the value of &{key}, which is not a template parameter (its name has no tpl, chunk or wrapper in it); check it by hand.');
            if ($fenom) {
                $rules[] = array('re' => '\{(?:include|insert|extends)\s+' . $q . '(?:chunk:)?(?P<n>' . $n . ')' . $qe . $join, 'via' => 'fenom');
                $rules[] = array('re' => '(?<![~.])(?<![~.]\s)' . $q . '(?P<n>' . $n . ')' . $qe . $join . '\s*\|\s*chunk\b', 'via' => 'fenom');
                $rules[] = array('re' => '->\s*(?:getChunk|parseChunk)\s*\(\s*' . $q . '(?P<n>' . $n . ')' . $qe . $join, 'via' => 'fenom');
                $rules[] = array('re' => '[\'"][\p{L}\p{N}_]*(?i:tpl|chunk|wrapper)[\p{L}\p{N}_]*[\'"]\s*=>\s*' . $q . '(?P<n>' . $n . ')' . $qe, 'via' => 'property');
            }
            if ($php) { $rules[] = array('re' => '\b(?:getChunk|parseChunk)\s*\(\s*' . $q . '(?P<n>' . $n . ')' . $qe . $join, 'via' => 'php'); }
            $rules[] = array('re' => '\b(?:getChunk|parseChunk)\s*\(\s*' . $q . '(?P<n>' . $n . ')' . $qe . '\s*[.~]', 'via' => 'runtime', 'manual' => 'The name is used to build a chunk name at run time; check it by hand.', 'no_case_check' => true);
        } elseif ($kind === 'snippet') {
            $rules[] = array('re' => '\[\[\s*!*(?P<n>' . $n . ')' . $end, 'via' => 'tag');
            if ($fenom) { $rules[] = array('re' => '(?<![~.])(?<![~.]\s)' . $q . '!?(?P<n>' . $n . ')' . $qe . $join . '\s*\|\s*snippet\b', 'via' => 'fenom'); }
            if ($fenom || $php) { $rules[] = array('re' => '->\s*runSnippet\s*\(\s*' . $q . '!?(?P<n>' . $n . ')' . $qe . $join, 'via' => $php ? 'php' : 'fenom'); }
            if ($php) { $rules[] = array('re' => '(?<!>)\brunSnippet\s*\(\s*' . $q . '!?(?P<n>' . $n . ')' . $qe . $join, 'via' => 'php'); }
            $rules[] = array('re' => '\brunSnippet\s*\(\s*' . $q . '!?(?P<n>' . $n . ')' . $qe . '\s*[.~]', 'via' => 'runtime', 'manual' => 'The name is used to build a snippet name at run time; check it by hand.', 'no_case_check' => true);
            $rules[] = array('re' => $anyKey, 'via' => 'property', 'in_tag' => true, 'manual' => 'The name is the value of a parameter (a snippet name for pdoTools, for example); check it by hand.', 'no_case_check' => true);
        } else {
            $rules[] = array('re' => '\[\[\s*!*\*(?P<n>' . $n . ')' . $end, 'via' => 'tag');
            $rules[] = array('re' => '\[\[\s*!*\+(?P<n>' . $n . ')' . $end, 'via' => 'placeholder', 'placeholder' => true);
            $rules[] = array('re' => '&(?:includeTVs|processTVs)\s*=\s*`(?P<list>[^`]*)`', 'via' => 'property', 'in_tag' => true, 'list' => true);
            $rules[] = array('re' => '&tvFilters\s*=\s*`[^`]*?(?<!' . self::B . ')(?P<n>' . $n . ')' . $end . '[^`]*`', 'via' => 'property', 'in_tag' => true,
                'manual' => 'The name is in &tvFilters, where it is mixed with operators and values; check it by hand.', 'no_case_check' => true);
            if ($fenom) {
                $rules[] = array('re' => '\$_?modx->resource\.(?P<n>' . $n . ')' . $end, 'via' => 'fenom');
                $rules[] = array('re' => '\$_?modx->resource\[\s*' . $q . '(?P<n>' . $n . ')' . $qe . '\s*\]', 'via' => 'fenom');
            }
            if ($fenom || $php) { $rules[] = array('re' => '\bgetTVValue\s*\(\s*' . $q . '(?P<n>' . $n . ')' . $qe . $join, 'via' => $php ? 'php' : 'fenom'); }
            $rules[] = array('re' => '\bgetTVValue\s*\(\s*' . $q . '(?P<n>' . $n . ')' . $qe . '\s*[.~]', 'via' => 'runtime', 'manual' => 'The name is used to build a TV name at run time; check it by hand.', 'no_case_check' => true);
        }
        return $rules;
    }
}
