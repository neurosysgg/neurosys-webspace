<?php

declare(strict_types=1);

namespace NeuroSYS\Http\Security;

/**
 * The ContentTypeOptions enum. The only value `X-Content-Type-Options` defines.
 *
 * A single-case enum on purpose: the header has exactly one legal value, so this makes that
 * fact the type rather than a comment next to a magic string.
 */
enum ContentTypeOptions: string
{
    /** Take the declared Content-Type at its word; never sniff the bytes. */
    case NoSniff = 'nosniff';
}
