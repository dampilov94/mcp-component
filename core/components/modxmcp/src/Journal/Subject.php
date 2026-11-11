<?php
namespace ModxMCP\Journal;

/** Anything the journal can snapshot and put back: a site object or a file. */
interface Subject {
    /** Journal kind, e.g. "chunk" or "file". */
    public function kindName();

    /** Key within the kind: an object id, or "s<source>:<path>" for a file. */
    public function key();

    /** Current state, or null when the thing does not exist. States must JSON-encode. */
    public function capture();

    /** Data needed only to make a delete reversible. */
    public function captureDeleteExtra();

    /** Put the thing back into a state; null removes it. */
    public function restore($state, array $extra);

    /** Human name taken from a state. */
    public function label($state);

    /** Names of what differs between two states (either may be null). */
    public function changed($before, $after);

    /** Per changed name: ['old' => …, 'new' => …] or ['diff' => …] for text. */
    public function describeChange($before, $after);
}
