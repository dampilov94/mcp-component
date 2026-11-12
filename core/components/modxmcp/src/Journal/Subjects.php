<?php
namespace ModxMCP\Journal;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Files\FileRoot;
use ModxMCP\Files\FileSubject;
use ModxMCP\Kind\Objects;

/** Rebuilds the subject a journal entry refers to. */
final class Subjects {
    /** The key of a journal row in its API type: the kind's key type decides; files and unknown kinds keep the stored string. */
    public static function publicKey(Context $context, $kindName, $key) {
        $kind = $kindName === 'file' ? null : $context->kinds->get($kindName);
        return $kind ? $kind->publicKey($key) : (string) $key;
    }

    /** Name of the result field that carries a journal row's key: "key" for string-key kinds, otherwise "id". */
    public static function keyField(Context $context, $kindName) {
        $kind = $kindName === 'file' ? null : $context->kinds->get($kindName);
        return $kind ? $kind->keyField() : 'id';
    }

    /**
     * @param bool $write check that this token may change the subject (undo), not only see it
     * @return Subject|null null when the kind is no longer known
     */
    public static function forChange(Context $context, $kindName, $key, $write) {
        if ($kindName === 'file') {
            list($rootId, $relative) = FileSubject::parseKey($key);
            $root = FileRoot::fromId($context, $rootId);
            if ($write) { $root->assertWritable($context, $relative); }
            return new FileSubject($root, $relative);
        }
        if (!$context->kinds->get($kindName)) {
            if ($write) { throw new ApiException('not_reversible', "Kind '{$kindName}' is not available any more."); }
            return null;
        }
        return new ObjectSubject($context->modx, Objects::kind($context, $kindName, $write), $key);
    }
}
