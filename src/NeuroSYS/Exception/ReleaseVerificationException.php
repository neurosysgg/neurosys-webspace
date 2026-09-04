<?php

declare(strict_types=1);

namespace NeuroSYS\Exception;

use Exception;

/**
 * The ReleaseVerificationException class. Thrown when a value object is constructed with data it
 * cannot accept — a {@link \NeuroSYS\Model\Release} or one of the parts it is built from, and
 * equally anything else the `data/` files declare, such as a {@link \NeuroSYS\Model\Profile}.
 *
 * These all fire while a data file is being loaded, which is the point: a bad share id or a
 * malformed profile URL stops the request there, with the offending value in the message, instead
 * of reaching a page and failing as a broken link nobody clicks.
 */
class ReleaseVerificationException extends Exception
{
}
