<?php
namespace ModxMCP\Journal;

use ModxMCP\Context;

/** Age and size limits for journal rows and their content-addressed snapshots. */
final class Retention {
    public function run(Context $context, $now = null) {
        $now = $now === null ? time() : (int) $now;
        $days = max(0, (int) $context->modx->getOption('modxmcp.history_retention_days', null, 30));
        $maxBytes = max(0, (int) $context->modx->getOption('modxmcp.history_max_bytes', null, 209715200));
        $removed = array('changesets' => 0, 'blobs' => 0, 'bytes' => 0);
        if ($days === 0 && $maxBytes === 0) { return $removed; }

        $journal = $context->journal();
        $blobs = $context->blobs();
        $cutoff = $days > 0 ? $now - $days * 86400 : 0;
        if ($days > 0) {
            while ($ids = $journal->oldestChangesetIds($cutoff, 500)) {
                $removed['changesets'] += $journal->deleteChangesets($ids, $cutoff);
            }
        }
        if ($maxBytes > 0) {
            // Only snapshots the journal still refers to count against the limit. Blobs that
            // are merely waiting out their grace period will go on their own; deleting more
            // history because of them would free nothing.
            $referenced = $journal->referencedBlobs();
            $total = 0;
            foreach ($blobs->each() as $blob) {
                if (isset($referenced[$blob[0]])) { $total += $blob[1]; }
            }
            while ($total > $maxBytes) {
                $newest = $journal->lastChangesetId();
                $ids = array_values(array_diff($journal->oldestChangesetIds(PHP_INT_MAX, 100), array($newest)));
                if (!$ids) { break; }
                $removed['changesets'] += $journal->deleteChangesets($ids, $cutoff);
                $total = $this->collect($journal, $blobs, $now, $removed);
            }
        }
        $this->collect($journal, $blobs, $now, $removed);
        return $removed;
    }

    /** Collect old, unreferenced blobs and return the size of the blobs still referenced. */
    private function collect(Journal $journal, BlobStore $blobs, $now, array &$removed) {
        $referenced = $journal->referencedBlobs();
        $total = 0;
        foreach ($blobs->each() as $blob) {
            list($hash, $bytes, $mtime) = $blob;
            if (!isset($referenced[$hash]) && $mtime < $now - 3600 && $blobs->delete($hash)) {
                $removed['blobs']++;
                $removed['bytes'] += $bytes;
            } elseif (isset($referenced[$hash])) {
                $total += $bytes;
            }
        }
        return $total;
    }
}
