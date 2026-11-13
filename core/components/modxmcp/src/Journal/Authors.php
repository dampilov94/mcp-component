<?php
namespace ModxMCP\Journal;

use ModxMCP\Auth\TokenStore;

/** Who made a recorded change, as a short name for people: the token label, the manager user or "unknown". */
final class Authors {
    const REVOKED = '(revoked token)';
    const UNKNOWN = 'unknown';

    private $modx;
    private $tokens = null;
    private $users = array();

    public function __construct(\modX $modx) {
        $this->modx = $modx;
    }

    /** @param array $changeset a journal changeset row (source, token_id, user_id) */
    public function name(array $changeset) {
        if ($changeset['source'] === 'mcp') { return $this->token($changeset['token_id']); }
        if ($changeset['source'] === 'manager') { return $this->user($changeset['user_id']); }
        return self::UNKNOWN;
    }

    /** The label of a token; a token that no longer exists is reported as revoked. */
    public function token($id) {
        if ($this->tokens === null) {
            $this->tokens = array();
            foreach ((new TokenStore($this->modx))->all() as $token) { $this->tokens[$token['id']] = $token['label'] !== '' ? $token['label'] : '#' . $token['id']; }
        }
        return $id !== null && isset($this->tokens[(int) $id]) ? $this->tokens[(int) $id] : self::REVOKED;
    }

    private function user($id) {
        if ($id === null) { return self::UNKNOWN; }
        if (!isset($this->users[$id])) {
            $user = $this->modx->getObject('modUser', array('id' => (int) $id));
            $this->users[$id] = $user ? (string) $user->get('username') : '(deleted user #' . (int) $id . ')';
        }
        return $this->users[$id];
    }
}
