<?php
namespace ModxMCP\Code;

use ModxMCP\ApiException;

/** Line-based viewing and editing of source code, so only the changed lines travel. */
final class LineEdit {
    /** Split content into lines, reporting the dominant EOL so it can be rejoined unchanged. */
    private static function split($content, &$eol) {
        $eol = strpos($content, "\r\n") !== false ? "\r\n" : "\n";
        return explode("\n", str_replace("\r\n", "\n", $content));
    }

    /**
     * Numbered window of the content, like `cat -n`.
     * @return array [numbered text, total lines, first line, last line]
     */
    public static function numbered($content, $start = null, $end = null) {
        $eol = "\n";
        $lines = self::split((string) $content, $eol);
        $total = count($lines);
        $start = $start === null ? 1 : max(1, (int) $start);
        $end = $end === null ? $total : min($total, (int) $end);
        if ($end < $start) { $end = min($total, $start); }
        $out = array();
        for ($i = $start; $i <= $end; $i++) { $out[] = $i . "\t" . $lines[$i - 1]; }
        return array(implode("\n", $out), $total, $start, $end);
    }

    /**
     * Apply edits. Lines are 1-based and inclusive, and every edit refers to the ORIGINAL
     * content. Each edit: start_line, end_line? (default start_line), replacement? ("" deletes
     * the lines), expect? (current text of the lines: verified at the stated position, else
     * relocated to its single match elsewhere; a mismatch aborts the whole call).
     * An insert is an empty range: end_line = start_line - 1 inserts before start_line.
     *
     * @return array [new content, lines before, lines after]
     */
    public static function apply($content, array $edits) {
        $eol = "\n";
        $lines = self::split((string) $content, $eol);
        $total = count($lines);
        $resolved = array();
        foreach ($edits as $index => $edit) {
            $number = $index + 1;
            $start = (int) $edit['start_line'];
            $end = isset($edit['end_line']) ? (int) $edit['end_line'] : $start;
            $insert = $end === $start - 1;
            $replacement = isset($edit['replacement']) ? (string) $edit['replacement'] : '';
            if ($insert) {
                if ($start < 1 || $start > $total + 1) {
                    throw new ApiException('validation_failed', "edit #{$number}: insert position {$start} is out of range (1.." . ($total + 1) . ').');
                }
            } elseif ($end < $start) {
                throw new ApiException('validation_failed', "edit #{$number}: end_line is before start_line.");
            } else {
                list($start, $end) = self::locate($lines, $start, $end, array_key_exists('expect', $edit) ? (string) $edit['expect'] : null, $number);
            }
            $resolved[] = array(
                'start' => $start, 'end' => $end, 'insert' => $insert,
                'lines' => $replacement === '' ? array() : explode("\n", str_replace("\r\n", "\n", $replacement)),
            );
        }

        $covered = array();
        foreach ($resolved as $edit) {
            if ($edit['insert']) { continue; }
            for ($i = $edit['start']; $i <= $edit['end']; $i++) {
                if (isset($covered[$i])) { throw new ApiException('validation_failed', "Edits overlap on line {$i}."); }
                $covered[$i] = true;
            }
        }

        // Apply bottom-up so earlier line numbers stay valid.
        usort($resolved, function ($a, $b) { return $b['start'] - $a['start']; });
        foreach ($resolved as $edit) {
            array_splice($lines, $edit['start'] - 1, $edit['insert'] ? 0 : $edit['end'] - $edit['start'] + 1, $edit['lines']);
        }
        return array(implode($eol, $lines), $total, count($lines));
    }

    private static function locate(array $lines, $start, $end, $expect, $number) {
        $total = count($lines);
        if ($expect === null) {
            if ($start < 1 || $end > $total) {
                throw new ApiException('validation_failed', "edit #{$number}: lines {$start}-{$end} are out of range (1..{$total}).");
            }
            return array($start, $end);
        }
        $expected = explode("\n", str_replace("\r\n", "\n", $expect));
        $length = count($expected);
        if ($start >= 1 && $start + $length - 1 <= $total && array_slice($lines, $start - 1, $length) === $expected) {
            return array($start, $start + $length - 1);
        }
        $matches = array();
        for ($i = 0; $i + $length <= $total; $i++) {
            if (array_slice($lines, $i, $length) === $expected) { $matches[] = $i + 1; }
        }
        if (count($matches) === 1) { return array($matches[0], $matches[0] + $length - 1); }
        if (!$matches) {
            throw new ApiException('revision_conflict', "edit #{$number}: the expected text was not found; the lines have changed since they were read.");
        }
        throw new ApiException('validation_failed', "edit #{$number}: the expected text matches " . count($matches) . ' places; make it more specific.');
    }
}
