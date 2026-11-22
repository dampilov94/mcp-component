<?php
namespace ModxMCP\Request;

use DirectoryIterator;
use Throwable;

/** Persistent request IDs live outside MODX cache, so clearing the cache cannot erase them. */
final class RequestStore {
    const FILE_GUARD = "<?php http_response_code(404); exit; ?>\n";
    private const CLEANUP_BATCH = 32;
    private const DAY = 86400;
    private $dir;
    private $retention;
    private $maxResponse;

    public function __construct($directory, $retention = 86400, $maxResponse = 4194304) {
        $this->dir = rtrim($directory, '/\\');
        $this->retention = max(60, (int) $retention);
        $this->maxResponse = max(1024, (int) $maxResponse);
    }

    public static function validId($id) {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $id);
    }

    private function ensureDirectory() {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RequestStoreException('Cannot create request storage; no action was started.');
        }
    }

    private function lock($key) {
        $this->ensureDirectory();
        $handle = @fopen($this->dir . '/' . $key . '.lock', 'c');
        if (!$handle) { throw new RequestStoreException('Cannot open request lock; no action was started.'); }
        @chmod($this->dir . '/' . $key . '.lock', 0600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return false; }
        return $handle;
    }

    private function write($path, $bytes) {
        $bytes = self::FILE_GUARD . $bytes;
        $temp = $path . '.' . bin2hex(random_bytes(12)) . '.tmp';
        try {
            $handle = @fopen($temp, 'x+b');
            if (!$handle) { throw new RequestStoreException('Cannot stage request state.'); }
            try {
                if (!@chmod($temp, 0600)) { throw new RequestStoreException('Cannot protect request state.'); }
                $length = strlen($bytes);
                for ($offset = 0; $offset < $length; $offset += $written) {
                    $written = fwrite($handle, substr($bytes, $offset));
                    if ($written === false || $written === 0) { throw new RequestStoreException('Cannot write complete request state.'); }
                }
                if (!fflush($handle)) { throw new RequestStoreException('Cannot flush request state.'); }
            } finally { fclose($handle); }
            if (!@rename($temp, $path)) { throw new RequestStoreException('Cannot publish request state.'); }
        } finally { if (file_exists($temp)) { @unlink($temp); } }
    }

    private function readRecord($key) {
        $path = $this->dir . '/' . $key . '.state.php';
        if (!file_exists($path)) { return null; }
        $guardLength = strlen(self::FILE_GUARD);
        $raw = @file_get_contents($path, false, null, 0, 8193 + $guardLength);
        $record = $raw !== false && strlen($raw) <= 8192 + $guardLength && substr($raw, 0, $guardLength) === self::FILE_GUARD
            ? json_decode(substr($raw, $guardLength), true) : null;
        $valid = is_array($record) && isset($record['state'], $record['fingerprint'], $record['started_at'])
            && is_string($record['fingerprint']) && preg_match('/^[a-f0-9]{64}$/D', $record['fingerprint'])
            && is_int($record['started_at']) && in_array($record['state'], array('pending', 'completed', 'expired'), true);
        if ($valid && $record['state'] !== 'pending') {
            $valid = isset($record['completed_at'], $record['http_status'], $record['success'], $record['response_hash'])
                && is_int($record['completed_at']) && is_int($record['http_status'])
                && $record['http_status'] >= 100 && $record['http_status'] <= 599
                && is_bool($record['success']) && is_string($record['response_hash'])
                && preg_match('/^[a-f0-9]{64}$/D', $record['response_hash']);
        }
        if (!$valid) {
            throw new RequestStoreException('Request state is unreadable. This ID will not be executed again.');
        }
        return $record;
    }

    private function expire($key, array $record) {
        if ($record['state'] === 'completed' && time() - $record['completed_at'] >= $this->retention) {
            $record['state'] = 'expired';
            // Publish a tombstone first. Removing a response never makes its ID reusable.
            $this->write($this->dir . '/' . $key . '.state.php', json_encode($record, JSON_THROW_ON_ERROR));
        }
        if ($record['state'] === 'expired') { @unlink($this->dir . '/' . $key . '.response.php'); }
        return $record;
    }

    /** Index only retained responses by completion day. Tombstones never enter this index. */
    private function indexResponse($key, $completedAt) {
        $bucket = $this->dir . '/expiry/' . intdiv($completedAt, self::DAY);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            if (!is_dir($bucket)) { @mkdir($bucket, 0700, true); }
            if (@touch($bucket . '/' . $key)) { return; }
            clearstatcache(true, $bucket);
        }
        throw new RequestStoreException('Cannot index the retained response.');
    }

    /** One-time install/upgrade pass for responses that predate the index. */
    public function indexLegacyResponses() {
        if (!is_dir($this->dir)) { return; }
        $marker = $this->dir . '/legacy-index.stamp';
        if (is_file($marker)) { return; }
        $complete = true;
        foreach (new DirectoryIterator($this->dir) as $file) {
            if (!preg_match('/^([a-f0-9]{64})\.response\.php$/D', $file->getFilename(), $match)) { continue; }
            $key = $match[1];
            $lock = $this->lock($key);
            if (!$lock) { $complete = false; continue; }
            try {
                $record = $this->readRecord($key);
                if ($record === null || $record['state'] === 'pending') { continue; }
                $record = $this->expire($key, $record);
                if ($record['state'] === 'completed') { $this->indexResponse($key, $record['completed_at']); }
            } catch (RequestStoreException $e) {
                // An unreadable record remains in place and still blocks replay.
                $complete = false;
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
        if ($complete) { @touch($marker); }
    }

    /** Expire a bounded batch from the index, staying off the path of tombstones and legacy IDs. */
    private function cleanup() {
        $gc = $this->lock('cleanup');
        if (!$gc) { return; }
        try {
            $stamp = $this->dir . '/cleanup.stamp';
            if (file_exists($stamp) && time() - filemtime($stamp) < 300) { return; }
            $index = $this->dir . '/expiry';
            $cutoff = intdiv(time() - $this->retention, self::DAY);
            $budget = self::CLEANUP_BATCH;
            if (is_dir($index)) {
                foreach (new DirectoryIterator($index) as $bucket) {
                    if ($bucket->isDot() || !$bucket->isDir() || !ctype_digit($bucket->getFilename()) || (int) $bucket->getFilename() >= $cutoff) { continue; }
                    $path = $bucket->getPathname();
                    foreach (new DirectoryIterator($path) as $marker) {
                        if ($marker->isDot()) { continue; }
                        $key = $marker->getFilename();
                        if (preg_match('/^[a-f0-9]{64}$/D', $key)) { $this->cleanIndexedResponse($key, $marker->getPathname()); }
                        if (--$budget === 0) { return; }
                    }
                    @rmdir($path);
                }
            }
            @touch($stamp);
        } finally { flock($gc, LOCK_UN); fclose($gc); }
    }

    private function cleanIndexedResponse($key, $marker) {
        $lock = $this->lock($key);
        if (!$lock) { return; }
        try {
            $record = $this->readRecord($key);
            if ($record !== null && $this->expire($key, $record)['state'] === 'completed') { return; }
            if ($record !== null && $record['state'] !== 'pending' && is_file($this->dir . '/' . $key . '.response.php')) { return; }
            @unlink($marker);
        } catch (RequestStoreException $e) {
            // Leave the response and state untouched; a corrupt item must not stall the queue.
            @unlink($marker);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function response($key, array $record) {
        $guardLength = strlen(self::FILE_GUARD);
        $encodedLimit = (int) (ceil($this->maxResponse / 3) * 4);
        $raw = @file_get_contents($this->dir . '/' . $key . '.response.php', false, null, 0, $encodedLimit + $guardLength + 1);
        $body = $raw !== false && strlen($raw) <= $encodedLimit + $guardLength && substr($raw, 0, $guardLength) === self::FILE_GUARD
            ? base64_decode(substr($raw, $guardLength), true) : false;
        if ($body === false || strlen($body) > $this->maxResponse || !hash_equals($record['response_hash'], hash('sha256', $body))) {
            throw new RequestStoreException('Stored response is missing, too large or corrupt. Check request status; this ID will not run again.');
        }
        return array('status' => $record['http_status'], 'body' => $body, 'replayed' => true);
    }

    public function execute($id, $fingerprint, callable $callback) {
        if (!self::validId($id)) { throw new RequestStoreException('Invalid request_id.', 400); }
        $key = hash('sha256', $id);
        $lock = $this->lock($key);
        if (!$lock) { throw new RequestStoreException('Request is already in progress. Check modx_request_status; do not start it with a new ID.', 409); }
        try {
            $record = $this->readRecord($key);
            if ($record !== null) {
                if (!hash_equals($record['fingerprint'], $fingerprint)) { throw new RequestStoreException('request_id was already used for different arguments.', 409); }
                $record = $this->expire($key, $record);
                if ($record['state'] === 'completed') { return $this->response($key, $record); }
                $message = $record['state'] === 'expired'
                    ? 'Stored response expired; this request_id cannot be reused. Check the site before starting a new operation.'
                    : 'Original request outcome is unknown. Check the site and request status; this ID will not be executed again.';
                throw new RequestStoreException($message, 409);
            }
            if (file_exists($this->dir . '/' . $key . '.response.php')) {
                throw new RequestStoreException('Orphaned response found for this ID. Check the site; it will not be executed again.', 409);
            }
            $record = array('state' => 'pending', 'fingerprint' => $fingerprint, 'started_at' => time());
            $this->write($this->dir . '/' . $key . '.state.php', json_encode($record, JSON_THROW_ON_ERROR));
            // Record pending before any business action, even if PHP later terminates.
            $frame = $callback();
            try {
                $completedAt = time();
                $this->indexResponse($key, $completedAt);
                $this->write($this->dir . '/' . $key . '.response.php', base64_encode($frame['body']));
                $record['state'] = 'completed';
                $record['completed_at'] = $completedAt;
                $record['http_status'] = $frame['status'];
                $record['response_hash'] = hash('sha256', $frame['body']);
                $record['success'] = $frame['status'] >= 200 && $frame['status'] < 300;
                $this->write($this->dir . '/' . $key . '.state.php', json_encode($record, JSON_THROW_ON_ERROR));
            } catch (Throwable $e) {
                throw new RequestStoreException('Action finished, but its response could not be recorded. Check the site; do not retry with a new ID.');
            }
            $frame['replayed'] = false;
            return $frame;
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
            try { $this->cleanup(); } catch (Throwable $e) { /* preserve the business result */ }
        }
    }

    public function status($id, $includeResult = false) {
        if (!self::validId($id)) { throw new RequestStoreException('Invalid request_id.', 400); }
        $key = hash('sha256', $id);
        if (!is_dir($this->dir)) { return array('request_id' => $id, 'state' => 'not_found'); }
        $lock = $this->lock($key);
        if (!$lock) { return array('request_id' => $id, 'state' => 'in_progress'); }
        try {
            $record = $this->readRecord($key);
            if ($record === null) { return array('request_id' => $id, 'state' => 'not_found'); }
            $record = $this->expire($key, $record);
            $result = array('request_id' => $id, 'state' => $record['state'] === 'pending' ? 'unknown' : $record['state'], 'started_at' => $record['started_at']);
            foreach (array('completed_at', 'http_status', 'success') as $field) {
                if (isset($record[$field])) { $result[$field] = $record[$field]; }
            }
            if ($includeResult && $record['state'] === 'completed') {
                $frame = $this->response($key, $record);
                $result['response'] = json_decode($frame['body'], true, 512, JSON_THROW_ON_ERROR);
            }
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
