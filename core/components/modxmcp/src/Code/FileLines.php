<?php
namespace ModxMCP\Code;

use ModxMCP\ApiException;

/**
 * Line view and line edits of a text file. A file's final line break ends its last line instead
 * of starting an empty one, so "a\nb\n" has two lines and appending is "line = total_lines + 1".
 * Line endings (LF or CRLF) and the presence of a final line break are kept by edits.
 */
final class FileLines {
    /** @return array [lines text without the final break, eol, final break ("" when absent)] */
    private static function parts($content) {
        $content = (string) $content;
        $eol = strpos($content, "\r\n") !== false ? "\r\n" : "\n";
        $final = '';
        if (substr($content, -2) === "\r\n") { $final = "\r\n"; $content = substr($content, 0, -2); }
        elseif (substr($content, -1) === "\n") { $final = "\n"; $content = substr($content, 0, -1); }
        return array($content, $eol, $final);
    }

    public static function total($content) {
        if ((string) $content === '') { return 0; }
        $parts = self::parts($content);
        return substr_count(str_replace("\r\n", "\n", $parts[0]), "\n") + 1;
    }

    /**
     * Numbered window like `cat -n`, cut after $maxBytes of text at a line boundary.
     * @return array [numbered text, total lines, first line, last line, truncated]
     */
    public static function view($content, $start = null, $end = null, $maxBytes = null) {
        $total = self::total($content);
        if ($total === 0) { return array('', 0, 1, 0, false); }
        list($body) = self::parts($content);
        $lines = explode("\n", str_replace("\r\n", "\n", $body));
        $start = $start === null ? 1 : max(1, (int) $start);
        $end = $end === null ? $total : min($total, (int) $end);
        if ($start > $total) { return array('', $total, $start, $start - 1, false); }
        $out = array();
        $size = 0;
        $truncated = false;
        for ($i = $start; $i <= $end; $i++) {
            $line = $i . "\t" . $lines[$i - 1];
            if ($maxBytes !== null && $out && $size + strlen($line) + 1 > $maxBytes) { $truncated = true; $end = $i - 1; break; }
            $out[] = $line;
            $size += strlen($line) + 1;
        }
        return array(implode("\n", $out), $total, $start, $end, $truncated);
    }

    /** @return array [new content, lines before, lines after] */
    public static function edit($content, array $edits) {
        if ((string) $content === '') { throw new ApiException('validation_failed', 'The file is empty; use modx_write_file to give it content.'); }
        list($body, $eol, $final) = self::parts($content);
        list($new, $before, $after) = LineEdit::apply(str_replace("\r\n", "\n", $body), $edits);
        if ($eol === "\r\n") { $new = str_replace("\n", "\r\n", $new); }
        return array($new . ($new === '' ? '' : $final), $before, $after);
    }
}
