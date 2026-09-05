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
 * of reaching a page and failing as a broken link nobody clicks. *
 * **Extends `LogicException`, and that is the classification rather than a detail.** Nothing on
 * this site catches it and nothing should: this is not a condition a caller recovers from, it is
 * "something in this repository is written wrong, go and fix it" — which is what SPL's
 * `LogicException` means. Saying it in the type rather than only the prose also settles a question
 * that would otherwise follow it around: whether every construction of a value object from a `data/` file owes an
 * `@throws` for a
 * failure that only happens when the site is already broken. It does not.

 */
class ReleaseVerificationException extends LogicException
{
}
