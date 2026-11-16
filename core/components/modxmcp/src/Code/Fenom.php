<?php
namespace ModxMCP\Code;

/**
 * Fenom, the template language of pdoTools (verified against pdoTools 2.13.3): which texts are Fenom and what a Fenom text
 * refers to. Pure functions; the compile check is in FenomCheck.
 */
final class Fenom {
    /**
     * The pattern pdoTools uses by default to decide that a text is Fenom (the system setting pdotools_fenom_syntax;
     * pdoTools::setConfig(), key 'fenomSyntax'). Without a setting, or with an empty one, this is it.
     */
    const SYNTAX = '#\{(\$|\/|\w+(\s|\(|\|)|\(|\')#';

    /**
     * Whether pdoTools would treat the text as Fenom. This mirrors pdoTools::fenom() (core/components/pdotools/model/pdotools/
     * pdotools.class.php): the text is trimmed and Fenom runs only when it matches the syntax pattern (the
     * pdotools_fenom_default switch, which that method checks first, is $enabled). Chunks go through it whenever
     * they are parsed by pdoTools; whole pages only when pdotools_fenom_parser is on, which is not asked here.
     *
     * @param string      $text
     * @param string|null $pattern the site's pdotools_fenom_syntax; null or an unusable pattern means SYNTAX
     * @param bool        $enabled the site's pdotools_fenom_default
     */
    public static function looksLike($text, $pattern = null, $enabled = true) {
        if (!$enabled || !is_string($text)) { return false; }
        $pattern = self::usable($pattern) ? $pattern : self::SYNTAX;
        return @preg_match($pattern, trim($text)) === 1;
    }

    private static function usable($pattern) {
        return is_string($pattern) && $pattern !== '' && @preg_match($pattern, '') !== false;
    }

    /** The syntax pattern of a site: its setting (empty means the default, like getOption's last argument in pdoTools), or SYNTAX. */
    public static function syntaxOf(\modX $modx) {
        $pattern = $modx->getOption('pdotools_fenom_syntax', null, self::SYNTAX, true);
        return self::usable($pattern) ? $pattern : self::SYNTAX;
    }

    /** pdoTools is installed: its model class is in the component's core directory. */
    public static function installed(\modX $modx) {
        $core = $modx->getOption('pdotools.core_path', null, rtrim((string) $modx->getOption('core_path'), '/\\') . '/components/pdotools/');
        return is_file(rtrim($core, '/\\') . '/model/pdotools/pdotools.class.php');
    }

    /** Fenom is in use for chunks on this site: pdoTools is installed and pdotools_fenom_default is not switched off. */
    public static function active(\modX $modx) {
        return self::installed($modx) && (bool) $modx->getOption('pdotools_fenom_default', null, true);
    }

    /**
     * What a Fenom text refers to, as rows {kind: chunk|snippet|template|resource, name, via: fenom|property|link}.
     * A name that is built at run time (a variable, a concatenation) is not a reference. A file (`file:…`, `@FILE …`) and
     * an `@INLINE` template are not elements. Comments and {ignore} blocks are skipped.
     * Call it for texts that looksLike() accepts: a plain text can hold a PHP call that is none of Fenom's business.
     */
    public static function references($text) {
        $text = (string) $text;
        $text = preg_replace('/\{\*.*?\*\}/s', ' ', $text);
        $text = preg_replace('/\{ignore\}.*?\{\/ignore\}/s', ' ', $text);
        // A string literal that stands alone: one that is joined to something else (~, a property access) is a run-time name.
        $quoted = '(?<![~.])(?<![~.]\s)(?:\'([^\'\\\\\r\n]*)\'|"([^"\\\\\r\n]*)")(?!\s*[~.+\-*\/%?:])';
        $refs = array();
        $add = function ($kind, $name, $via) use (&$refs) { $refs[$via . '|' . $kind . '|' . $name] = array('kind' => $kind, 'name' => $name, 'via' => $via); };
        $name = function (array $hit) {
            $value = trim($hit[1] !== '' ? $hit[1] : (isset($hit[2]) ? $hit[2] : ''));
            // Run-time parts, bindings and anything that is no name.
            return $value === '' || $value[0] === '@' || preg_match('/[\$\{\}`]/', $value) ? '' : $value;
        };

        // {include 'chunk'}, {insert 'chunk'}, {extends 'chunk'}; the prefixes chunk: and template: choose the provider.
        if (preg_match_all('/\{(?:include|insert|extends)\s+' . $quoted . '/', $text, $found, PREG_SET_ORDER)) {
            foreach ($found as $hit) {
                $value = $name(self::groups($hit));
                if ($value === '') { continue; }
                $colon = strpos($value, ':');
                if ($colon === false) { $add('chunk', $value, 'fenom'); continue; }
                $provider = substr($value, 0, $colon);
                $rest = trim(substr($value, $colon + 1));
                if ($rest === '') { continue; }
                if ($provider === 'chunk') { $add('chunk', $rest, 'fenom'); }
                elseif ($provider === 'template') { $add('template', $rest, 'fenom'); }
            }
        }
        // {'name' | chunk}, {'name' | snippet}, with or without arguments after the modifier.
        if (preg_match_all('/' . $quoted . '\s*\|\s*(chunk|snippet)\b/', $text, $found, PREG_SET_ORDER)) {
            foreach ($found as $hit) {
                $kind = $hit[count($hit) - 1];
                $value = $name(self::groups($hit));
                if ($value !== '' && $kind === 'snippet') { $value = ltrim($value, '!'); }
                if ($value !== '') { $add($kind, $value, 'fenom'); }
            }
        }
        // {$_modx->getChunk('name' …)}, parseChunk, runSnippet (also '!name').
        if (preg_match_all('/->\s*(getChunk|parseChunk|runSnippet)\s*\(\s*' . $quoted . '/', $text, $found, PREG_SET_ORDER)) {
            foreach ($found as $hit) {
                $value = $name(self::groups($hit, 2));
                if ($value === '') { continue; }
                if ($hit[1] === 'runSnippet') { $value = ltrim($value, '!'); }
                if ($value !== '') { $add($hit[1] === 'runSnippet' ? 'snippet' : 'chunk', $value, 'fenom'); }
            }
        }
        // 'tpl' => 'name', 'tplOuter' => 'name' in the arrays of those calls: the graph's rule for &tpl= parameters.
        if (preg_match_all('/[\'"]([\p{L}\p{N}_]*(?:tpl|chunk|wrapper)[\p{L}\p{N}_]*)[\'"]\s*=>\s*' . $quoted . '/iu', $text, $found, PREG_SET_ORDER)) {
            foreach ($found as $hit) {
                $value = $name(self::groups($hit, 2));
                if ($value !== '' && strpos($value, ' ') === false) { $add('chunk', $value, 'property'); }
            }
        }
        // {$_modx->makeUrl(5)}, {5 | url}: a link to resource 5.
        if (preg_match_all('/->\s*makeUrl\s*\(\s*(\d+)\s*[,)]/', $text, $found, PREG_SET_ORDER)) {
            foreach ($found as $hit) { $add('resource', $hit[1], 'link'); }
        }
        if (preg_match_all('/(?<![\w$.\[\]])(\d+)\s*\|\s*url\b/', $text, $found, PREG_SET_ORDER)) {
            foreach ($found as $hit) { $add('resource', $hit[1], 'link'); }
        }
        return array_values($refs);
    }

    /** The quoted string of a hit: [full match, single-quoted, double-quoted] reduced to [full match, the one that matched]. */
    private static function groups(array $hit, $from = 1) {
        $single = isset($hit[$from]) ? $hit[$from] : '';
        $double = isset($hit[$from + 1]) ? $hit[$from + 1] : '';
        return array(0 => $hit[0], 1 => $single !== '' ? $single : $double);
    }
}
