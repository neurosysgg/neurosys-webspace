<?php

declare(strict_types=1);

namespace NeuroSYS\Exception;

use LogicException;

/**
 * The SecurityPolicyException class. Thrown when a response security policy, or one of the
 * value objects it is built from, is constructed with something that isn't valid on the wire.
 *
 * Counterpart to {@link ReleaseVerificationException}: both exist so a malformed value fails
 * loudly where it is written, rather than being emitted as a header a browser silently drops. *
 * **Extends `LogicException`, and that is the classification rather than a detail.** Nothing on
 * this site catches it and nothing should: this is not a condition a caller recovers from, it is
 * "something in this repository is written wrong, go and fix it" — which is what SPL's
 * `LogicException` means. Saying it in the type rather than only the prose also settles a question
 * that would otherwise follow it around: whether every policy the security headers compose owes an `@throws` for a
 * failure that only happens when the site is already broken. It does not.

 */
class SecurityPolicyException extends LogicException
{
}
