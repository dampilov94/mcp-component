<?php
namespace ModxMCP\Verify;

/** Bounded reverse chunk reads, followed by multiline entry parsing within that window. */
final class LogReader {
    public static function tail($path, $limit = 50, $maxBytes = 262144, $contains = '', $afterOffset = null, $dedupe = false) {
        $limit = max(1, min(500, (int) $limit));
        $maxBytes = (int) $maxBytes > 0 ? (int) $maxBytes : 262144;
        clearstatcache(true, $path);
        if (!is_file($path)) { return self::result(0, array(), false, $afterOffset !== null && $afterOffset > 0); }
        $handle = @fopen($path, 'rb');
        if (!$handle) { throw new \RuntimeException('Cannot read the MODX error log.'); }
        try {
            $stat = fstat($handle);
            if ($stat === false) { throw new \RuntimeException('Cannot inspect the MODX error log.'); }
            $size = (int) $stat['size'];
            $reset = $afterOffset !== null && $afterOffset > $size;
            $floor = $reset || $afterOffset === null ? 0 : max(0, (int) $afterOffset);
            if ($floor === $size) { return self::result($size, array(), false, $reset); }
            // Reserve one byte of the budget to tell whether the first included line is whole.
            $start = max($floor, $size - $maxBytes);
            if ($start > 0) { $start = max($floor, $size - $maxBytes + 1); }
            $readStart = $start > 0 ? $start - 1 : 0;
            $position = $size;
            $window = '';
            while ($position > $readStart) {
                $length = min(8192, $position - $readStart);
                $position -= $length;
                if (fseek($handle, $position, SEEK_SET) !== 0) { throw new \RuntimeException('Cannot seek in the MODX error log.'); }
                $chunk = fread($handle, $length);
                if ($chunk === false || strlen($chunk) !== $length) { throw new \RuntimeException('Log changed while reading; retry.'); }
                $window = $chunk . $window;
            }
            $truncated = $start > $floor;
            if ($start > 0) {
                $boundary = isset($window[0]) && $window[0] === "\n";
                $window = substr($window, 1);
                if (!$boundary) {
                    $newline = strpos($window, "\n");
                    $window = $newline === false ? '' : substr($window, $newline + 1);
                }
            }
            $window = str_replace("\r\n", "\n", $window);
            preg_match_all('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/m', $window, $headers, PREG_OFFSET_CAPTURE);
            $entries = array();
            $offsets = array_column($headers[0], 1);
            // Only a prefix at the actual beginning of the file is an independent entry.
            if ($start === 0 && (!$offsets || $offsets[0] > 0)) { array_unshift($offsets, 0); }
            foreach ($offsets as $i => $offset) {
                $end = isset($offsets[$i + 1]) ? $offsets[$i + 1] : strlen($window);
                $raw = rtrim(substr($window, $offset, $end - $offset), "\r\n");
                if ($raw === '') { continue; }
                $raw = json_decode(json_encode($raw, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true);
                if ($contains !== '' && \ModxMCP\Code\CodeSearch::position($raw, $contains, false) === false) { continue; }
                $entry = array();
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\][ \t]*(.*)$/s', $raw, $m)) {
                    $entry['time'] = $m[1];
                    $raw = $m[2];
                    // MODX writes "(LEVEL @ file : line)" or "(LEVEL in Class::method @ file : line)".
                    if (preg_match('/^\(([A-Za-z]+)(?: in ([^\r\n@]*?))? @ ([^\r\n]*?)\)[ \t]*(.*)$/s', $raw, $parts)) {
                        $entry['level'] = $parts[1];
                        if ($parts[2] !== '') { $entry['in'] = $parts[2]; }
                        $entry['location'] = $parts[3];
                        $raw = $parts[4];
                    }
                }
                $entry['message'] = mb_strlen($raw, 'UTF-8') > 2000 ? mb_substr($raw, 0, 2000, 'UTF-8') . '…' : $raw;
                $entries[] = $entry;
            }
            $raw = count($entries);
            if ($dedupe) { $entries = self::fold($entries); }
            if (count($entries) > $limit) { $truncated = true; $entries = array_slice($entries, -$limit); }
            $result = self::result($size, $entries, $truncated, $reset);
            if ($dedupe) { $result['raw_entries'] = $raw; }
            return $result;
        } finally { fclose($handle); }
    }
    /**
     * Fold entries with the same level, location and message into one with count, first_time and
     * last_time, ordered by their last occurrence (newest last).
     */
    private static function fold(array $entries) {
        $groups = array();
        foreach ($entries as $index => $entry) {
            $key = json_encode(array(isset($entry['level']) ? $entry['level'] : null, isset($entry['location']) ? $entry['location'] : null, $entry['message']));
            if (!isset($groups[$key])) {
                $group = $entry;
                unset($group['time']);
                $group['count'] = 0;
                if (isset($entry['time'])) { $group['first_time'] = $entry['time']; }
                $groups[$key] = array('last' => $index, 'entry' => $group);
            }
            $groups[$key]['entry']['count']++;
            $groups[$key]['last'] = $index;
            if (isset($entry['time'])) { $groups[$key]['entry']['last_time'] = $entry['time']; }
        }
        uasort($groups, function ($a, $b) { return $a['last'] - $b['last']; });
        return array_values(array_map(function ($group) { return $group['entry']; }, $groups));
    }

    private static function result($size, array $entries, $truncated, $reset) {
        return array('size' => $size, 'end_offset' => $size, 'entries' => $entries,
            'returned' => count($entries), 'truncated' => $truncated, 'reset' => $reset);
    }
}
