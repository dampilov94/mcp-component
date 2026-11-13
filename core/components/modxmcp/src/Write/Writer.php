<?php
namespace ModxMCP\Write;

use ModxMCP\Context;

/** Runs the body of a write call inside a Transaction. */
final class Writer {
    /**
     * @param callable $body function (Transaction $tx): array — returns
     *        ['result' => array, 'summary' => string, 'undoes' => int[]?, 'informational' => bool?]. A body that only
     *        plans (dry run) simply changes nothing; then no changeset is recorded.
     * @return array [result, commit info]
     */
    public static function run(Context $context, $tool, callable $body) {
        $tx = new Transaction($context, $tool);
        $outer = $context->transaction;
        $context->transaction = $tx;
        try {
            $out = $body($tx);
            $info = $tx->commit(isset($out['summary']) ? $out['summary'] : $tool, isset($out['undoes']) ? $out['undoes'] : array(), !empty($out['informational']));
            return array($out['result'], $info);
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        } finally {
            $context->transaction = $outer;
            $tx->release();
        }
    }
}
