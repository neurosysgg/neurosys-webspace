<?php

declare(strict_types=1);

namespace NeuroSYS\Exception;

use Exception;

/**
 * The MimeTypeException class. Thrown when a {@link \NeuroSYS\Http\MimeType} is handed a subtype
 * that is not one.
 *
 * The Http namespace's counterpart to {@link SecurityPolicyException}, which covers the value
 * objects under Http\Security, and to {@link ReleaseVerificationException} over in the data files:
 * all three exist so a malformed value fails loudly where it is written rather than going out as a
 * header the recipient is left to guess at.
 *
 * Named for the one class that throws it. Widen it if a second validated value lands beside
 * MimeType; do not reach for it from one that is not about a media type.
 */
class MimeTypeException extends Exception
{
}
