<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Data;

/**
 * The EntryWrite enum. What came of putting one entry into a data file — see {@link EntryFile}.
 */
enum EntryWrite
{
    /** It is in the file now. */
    case Written;

    /** A new entry's slug was in the file already, and nothing was written. */
    case Exists;

    /** A replacement's slug was not in the file, and nothing was written. */
    case Missing;

    /** The file could not be written; what was there is still there. */
    case Unwritable;
}
