<?php

declare(strict_types=1);

namespace NeuroSYS\Exception;

use LogicException;

/**
 * The ReleaseVerificationException class. Thrown when a value object is constructed with data it
 * cannot accept — a {@link \NeuroSYS\Model\Release} or one of the parts it is built from, and
 * equally anything else the `data/` files declare, such as a {@link \NeuroSYS\Model\Profile}.
 *
 * These all fire while a data file is being loaded, which is the point: a bad share id or a
 * malformed profile URL stops the request there, with the offending value in the message, instead
 * of reaching a page and failing as a broken link nobody clicks.
 *
 * **Extends `LogicException`, and that is the classification rather than a detail.** Nothing on
 * this site recovers from it and nothing should try: this is not a condition a caller acts on, it is
 * "something in this repository is written wrong, go and fix it" — which is what SPL's
 * `LogicException` means. Saying it in the type rather than only the prose also settles a question
 * that would otherwise follow it around: whether every construction of a value object from a
 * `data/` file owes an `@throws` for a failure that only happens when the site is already
 * broken. It does not.
 *
 * The handler at the door in `public/index.php` does catch it, which is not the contradiction it
 * reads as: it recovers nothing, it turns what would have been a PHP fatal into a 500 with an empty
 * body and a line in the log. See {@link SiteException}.
 */
class ReleaseVerificationException extends LogicException implements SiteException
{
}
