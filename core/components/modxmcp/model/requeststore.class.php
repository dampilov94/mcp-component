<?php
/** Persistent request IDs live outside MODX cache, so clear_cache cannot erase them. */
class ModxMCPRequestStoreException extends Exception {
    public $httpStatus;
    public function __construct($message, $httpStatus = 503) {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

class ModxMCPRequestStore {
    const FILE_GUARD = "<?php http_response_code(404); exit; ?>\n";
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
            throw new ModxMCPRequestStoreException('Cannot create request storage; no action was started.');
        }
    }

    private function lock($key) {
        $this->ensureDirectory();
        $handle = @fopen($this->dir . '/' . $key . '.lock', 'c');
        if (!$handle) { throw new ModxMCPRequestStoreException('Cannot open request lock; no action was started.'); }
        @chmod($this->dir . '/' . $key . '.lock', 0600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return false; }
        return $handle;
    }

    private function write($path, $bytes) {
        $bytes = self::FILE_GUARD . $bytes;
        $temp = $path . '.' . bin2hex(random_bytes(12)) . '.tmp';
        try {
            $handle = @fopen($temp, 'x+b');
            if (!$handle) { throw new ModxMCPRequestStoreException('Cannot stage request state.'); }
            try {
                if (!@chmod($temp, 0600)) { throw new ModxMCPRequestStoreException('Cannot protect request state.'); }
                $length = strlen($bytes);
                for ($offset = 0; $offset < $length; $offset += $written) {
                    $written = fwrite($handle, substr($bytes, $offset));
                    if ($written === false || $written === 0) { throw new ModxMCPRequestStoreException('Cannot write complete request state.'); }
                }
                if (!fflush($handle)) { throw new ModxMCPRequestStoreException('Cannot flush request state.'); }
            } finally { fclose($handle); }
            if (!@rename($temp, $path)) { throw new ModxMCPRequestStoreException('Cannot publish request state.'); }
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
            throw new ModxMCPRequestStoreException('Request state is unreadable. This ID will not be executed again.');
        }
        return $record;
    }

    private function expire($key, array $record) {
        if ($record['state'] === 'completed' && time() - $record['completed_at'] >= $this->retention) {
            $record['state'] = 'expired';
            // Publish a tombstone first. Removing a response never makes its ID reusable.
            $this->write($this->dir . '/' . $key . '.state.php', json_encode($record, JSON_THROW_ON_ERROR));
            @unlink($this->dir . '/' . $key . '.response.php');
        }
        return $record;
    }

    /** Compact old results at most every five minutes, retaining ID tombstones. */
    private function cleanup() {
        $gc = $this->lock('cleanup');
        if (!$gc) { return; }
        try {
            $stamp = $this->dir . '/cleanup.stamp';
            if (file_exists($stamp) && time() - filemtime($stamp) < 300) { return; }
            @touch($stamp);
            foreach (new DirectoryIterator($this->dir) as $file) {
                if (!$file->isFile() || !preg_match('/^([a-f0-9]{64})\.state\.php$/D', $file->getFilename(), $match)) { continue; }
                $lock = $this->lock($match[1]);
                if (!$lock) { continue; }
                try {
                    $record = $this->readRecord($match[1]);
                    if ($record !== null) { $this->expire($match[1], $record); }
                } catch (Throwable $e) {
                    // A maintenance error must not permit replay or break another request.
                } finally { flock($lock, LOCK_UN); fclose($lock); }
            }
        } finally { flock($gc, LOCK_UN); fclose($gc); }
    }

    private function response($key, array $record) {
        $guardLength = strlen(self::FILE_GUARD);
        $encodedLimit = (int) (ceil($this->maxResponse / 3) * 4);
        $raw = @file_get_contents($this->dir . '/' . $key . '.response.php', false, null, 0, $encodedLimit + $guardLength + 1);
        $body = $raw !== false && strlen($raw) <= $encodedLimit + $guardLength && substr($raw, 0, $guardLength) === self::FILE_GUARD
            ? base64_decode(substr($raw, $guardLength), true) : false;
        if ($body === false || strlen($body) > $this->maxResponse || !hash_equals($record['response_hash'], hash('sha256', $body))) {
            throw new ModxMCPRequestStoreException('Stored response is missing, too large or corrupt. Check request status; this ID will not run again.');
        }
        return array('status' => $record['http_status'], 'body' => $body, 'replayed' => true);
    }

    public function execute($id, $fingerprint, callable $callback) {
        if (!self::validId($id)) { throw new ModxMCPRequestStoreException('Invalid request_id.', 400); }
        $key = hash('sha256', $id);
        $lock = $this->lock($key);
        if (!$lock) { throw new ModxMCPRequestStoreException('Request is already in progress. Check get_request_status; do not start it with a new ID.', 409); }
        try {
            $record = $this->readRecord($key);
            if ($record !== null) {
                if (!hash_equals($record['fingerprint'], $fingerprint)) { throw new ModxMCPRequestStoreException('request_id was already used for different arguments.', 409); }
                $record = $this->expire($key, $record);
                if ($record['state'] === 'completed') { return $this->response($key, $record); }
                $message = $record['state'] === 'expired'
                    ? 'Stored response expired; this request_id cannot be reused. Check the site before starting a new operation.'
                    : 'Original request outcome is unknown. Check the site and request status; this ID will not be executed again.';
                throw new ModxMCPRequestStoreException($message, 409);
            }
            if (file_exists($this->dir . '/' . $key . '.response.php')) {
                throw new ModxMCPRequestStoreException('Orphaned response found for this ID. Check the site; it will not be executed again.', 409);
            }
            $record = array('state' => 'pending', 'fingerprint' => $fingerprint, 'started_at' => time());
            $this->write($this->dir . '/' . $key . '.state.php', json_encode($record, JSON_THROW_ON_ERROR));
            // Record pending before any business action, even if PHP later terminates.
            $frame = $callback();
            try {
                $this->write($this->dir . '/' . $key . '.response.php', base64_encode($frame['body']));
                $record['state'] = 'completed';
                $record['completed_at'] = time();
                $record['http_status'] = $frame['status'];
                $record['response_hash'] = hash('sha256', $frame['body']);
                $record['success'] = $frame['status'] >= 200 && $frame['status'] < 300;
                $this->write($this->dir . '/' . $key . '.state.php', json_encode($record, JSON_THROW_ON_ERROR));
            } catch (Throwable $e) {
                throw new ModxMCPRequestStoreException('Action finished, but its response could not be recorded. Check the site; do not retry with a new ID.');
            }
            $frame['replayed'] = false;
            return $frame;
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
            try { $this->cleanup(); } catch (Throwable $e) { /* preserve the business result */ }
        }
    }

    public function status($id, $includeResult = false) {
        if (!self::validId($id)) { throw new ModxMCPRequestStoreException('Invalid request_id.', 400); }
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
