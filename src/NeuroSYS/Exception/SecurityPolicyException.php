<?php

declare(strict_types=1);

namespace NeuroSYS\Exception;

use Exception;

/**
 * The SecurityPolicyException class. Thrown when a response security policy, or one of the
 * value objects it is built from, is constructed with something that isn't valid on the wire.
 *
 * Counterpart to {@link ReleaseVerificationException}: both exist so a malformed value fails
 * loudly where it is written, rather than being emitted as a header a browser silently drops.
 */
class SecurityPolicyException extends Exception
{
}
