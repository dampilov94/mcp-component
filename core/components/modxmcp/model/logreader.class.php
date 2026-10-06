<?php
/** Reverse chunk reader: memory is bounded by the chunk, line and returned-byte budgets. */
class ModxMCPLogReader {
    public static function tail($path, $limit, $maxBytes, callable $transform) {
        $limit = min(1000, max(1, (int) $limit));
        $maxBytes = (int) $maxBytes;
        if ($maxBytes <= 0) { $maxBytes = 262144; }
        $maxBytes = max(1024, $maxBytes);
        $empty = array('items' => array(), 'bytes_read' => 0, 'returned_bytes' => 0,
            'truncated' => false, 'has_more' => false, 'skipped_long_lines' => 0);
        if (!is_file($path)) { return $empty; }
        $handle = @fopen($path, 'rb');
        if (!$handle) { throw new RuntimeException('Cannot read the requested log.'); }
        $items = array();
        $bytesRead = 0;
        $returnedBytes = 0;
        $skipped = 0;
        $hasMore = false;
        $limited = false;
        $first = true;
        $accept = function ($line, $oversized, $older) use ($transform, $limit, $maxBytes, &$items, &$returnedBytes, &$skipped, &$hasMore, &$limited, &$first) {
            if ($first) {
                $first = false;
                // file(..., FILE_IGNORE_NEW_LINES) does not create a line after the last LF.
                if (!$oversized && $line === '') { return false; }
            }
            if ($oversized || strlen($line) > $maxBytes) { $skipped++; return false; }
            if (substr($line, -1) === "\r") { $line = substr($line, 0, -1); }
            $item = $transform($line);
            if ($item === null) { return false; }
            $cost = strlen($line) + 1;
            if ($returnedBytes + $cost > $maxBytes) { $limited = true; $hasMore = true; return true; }
            $items[] = $item;
            $returnedBytes += $cost;
            if (count($items) >= $limit) { $hasMore = $older; return true; }
            return false;
        };
        try {
            $stat = fstat($handle);
            if ($stat === false) { throw new RuntimeException('Cannot inspect log size.'); }
            $position = $stat['size'];
            $pending = '';
            $oversized = false;
            $stopped = false;
            while ($position > 0) {
                $length = min(8192, $position);
                $position -= $length;
                if (fseek($handle, $position, SEEK_SET) !== 0) { throw new RuntimeException('Cannot seek in the log.'); }
                $chunk = fread($handle, $length);
                if ($chunk === false || strlen($chunk) !== $length) { throw new RuntimeException('Log changed while reading; retry.'); }
                $bytesRead += $length;
                $parts = explode("\n", $chunk);
                $last = count($parts) - 1;
                if ($last === 0) {
                    if (!$oversized) {
                        $pending = $parts[0] . $pending;
                        if (strlen($pending) > $maxBytes) { $pending = ''; $oversized = true; }
                    }
                    continue;
                }
                for ($i = $last; $i >= 1; $i--) {
                    $line = $i === $last && !$oversized ? $parts[$i] . $pending : $parts[$i];
                    if ($accept($line, $i === $last && $oversized, true)) { $stopped = true; break; }
                }
                if ($stopped) { break; }
                $pending = $parts[0];
                $oversized = strlen($pending) > $maxBytes;
                if ($oversized) { $pending = ''; }
            }
            if (!$stopped && $stat['size'] > 0) { $accept($pending, $oversized, false); }
            return array('items' => array_reverse($items), 'bytes_read' => $bytesRead,
                'returned_bytes' => $returnedBytes, 'truncated' => $limited || $skipped > 0 || $hasMore,
                'has_more' => $hasMore, 'skipped_long_lines' => $skipped);
        } finally { fclose($handle); }
    }
}
