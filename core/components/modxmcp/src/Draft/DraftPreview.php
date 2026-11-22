<?php
namespace ModxMCP\Draft;

use ModxMCP\ApiException;
use ModxMCP\Auth\Security;

/** Signed access to the changed plain-text fields of one specific draft revision. */
final class DraftPreview {
    const ENDPOINT = 'draft-preview.php';
    const TTL = 1800;

    private $modx;
    private $keys;

    public function __construct(\modX $modx) {
        $this->modx = $modx;
        $this->keys = new PreviewKey($modx);
    }

    public static function isHttps(\modX $modx, array $server) {
        return Security::isHttps($server, (string) $modx->getOption('modxmcp.trusted_proxies', null, ''));
    }

    public static function isDraftId($value) {
        return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value) && $value <= 4294967295;
    }

    public function issue($draftId, $now = null) {
        $now = $now ?? time();
        $data = $this->load($draftId);
        if ($data === null) { throw new ApiException('preview_unavailable', 'This draft has no shareable plain-text changes.'); }
        $payload = implode('.', array('v1', $data['link_id'], $draftId, $now + self::TTL, $data['digest']));
        return $payload . '.' . hash_hmac('sha256', $payload, $this->keys->getOrCreate());
    }

    public function redeem($token, $now = null) {
        $now = $now ?? time();
        if (!is_string($token) || !preg_match('/^v1\.([1-9][0-9]{0,9})\.([1-9][0-9]{0,9})\.([1-9][0-9]{0,9})\.([a-f0-9]{64})\.([a-f0-9]{64})$/D', $token, $parts)) {
            return null;
        }
        list(, $linkId, $draftId, $expiresAt, $digest, $signature) = $parts;
        if (!self::isDraftId($linkId) || !self::isDraftId($draftId) || (int) $expiresAt <= $now || (int) $expiresAt > $now + self::TTL) { return null; }
        $key = $this->keys->read();
        if ($key === null || !hash_equals(hash_hmac('sha256', substr($token, 0, -65), $key), $signature)) { return null; }
        $data = $this->load((int) $draftId);
        return $data !== null && $data['link_id'] === (int) $linkId && hash_equals($data['digest'], $digest) ? $data['fields'] : null;
    }

    private function load($draftId) {
        if (!self::isDraftId($draftId)) { return null; }
        $link = (new DraftStore($this->modx, false))->ofDraft($draftId);
        if (!$link || !isset($link['base_state']['fields']) || !is_array($link['base_state']['fields'])) { return null; }
        $draft = $this->modx->getObject('modResource', array('id' => (int) $draftId), false);
        if (!$draft || $draft->get('class_key') !== 'modDocument' || $draft->get('published') || $draft->get('deleted')
            || $draft->get('pub_date') || $draft->get('unpub_date')) {
            return null;
        }
        $base = $link['base_state']['fields'];
        $fields = array();
        foreach (DraftPolicy::FIELDS as $field) {
            $value = (string) $draft->get($field);
            if (array_key_exists($field, $base) && $value !== (string) $base[$field] && DraftPolicy::isPlainText($value)) { $fields[$field] = $value; }
        }
        if (!$fields) { return null; }
        $row = $draft->toArray('', true);
        ksort($row, SORT_STRING);
        $digest = hash('sha256', json_encode(array($fields, $row), JSON_THROW_ON_ERROR));
        return array('link_id' => $link['id'], 'fields' => $fields, 'digest' => $digest);
    }
}
