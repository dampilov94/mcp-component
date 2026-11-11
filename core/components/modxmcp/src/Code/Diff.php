<?php
namespace ModxMCP\Code;

/** Compact line diff for showing what a change did to source code. */
final class Diff {
    /**
     * @return string Lines prefixed with "-" (removed) or "+" (added), grouped in hunks headed by
     *                "@@ -oldStart,oldCount +newStart,newCount @@". Empty when the texts are equal.
     */
    public static function lines($old, $new, $maxLines = 200) {
        $a = explode("\n", str_replace("\r\n", "\n", (string) $old));
        $b = explode("\n", str_replace("\r\n", "\n", (string) $new));
        $prefix = 0;
        $limit = min(count($a), count($b));
        while ($prefix < $limit && $a[$prefix] === $b[$prefix]) { $prefix++; }
        $suffix = 0;
        while ($suffix < $limit - $prefix && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]) { $suffix++; }
        $midA = array_slice($a, $prefix, count($a) - $prefix - $suffix);
        $midB = array_slice($b, $prefix, count($b) - $prefix - $suffix);
        if (!$midA && !$midB) { return ''; }

        $hunks = count($midA) * count($midB) <= 250000
            ? self::hunks($midA, $midB, $prefix)
            : array(array($prefix + 1, $midA, $prefix + 1, $midB));   // too large to align: one block

        $out = array();
        foreach ($hunks as $hunk) {
            $out[] = '@@ -' . $hunk[0] . ',' . count($hunk[1]) . ' +' . $hunk[2] . ',' . count($hunk[3]) . ' @@';
            foreach ($hunk[1] as $line) { $out[] = '-' . $line; }
            foreach ($hunk[3] as $line) { $out[] = '+' . $line; }
        }
        if (count($out) > $maxLines) {
            $omitted = count($out) - $maxLines;
            $out = array_slice($out, 0, $maxLines);
            $out[] = "… {$omitted} more diff lines omitted";
        }
        return implode("\n", $out);
    }

    /** Longest-common-subsequence alignment of the differing middle, as [oldStart, removed, newStart, added] hunks. */
    private static function hunks(array $a, array $b, $offset) {
        $n = count($a);
        $m = count($b);
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $table[$i][$j] = $a[$i] === $b[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }
        $hunks = array();
        $current = null;
        $i = $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                if ($current) { $hunks[] = $current; $current = null; }
                $i++; $j++;
                continue;
            }
            if (!$current) { $current = array($offset + $i + 1, array(), $offset + $j + 1, array()); }
            if ($j < $m && ($i === $n || $table[$i][$j + 1] >= $table[$i + 1][$j])) { $current[3][] = $b[$j++]; }
            else { $current[1][] = $a[$i++]; }
        }
        if ($current) { $hunks[] = $current; }
        return $hunks;
    }
}
