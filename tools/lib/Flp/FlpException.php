<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

use RuntimeException;

/**
 * The FlpException class. A `.flp` this cannot read.
 *
 * Thrown where {@link \NeuroSYS\Model\Link\HiDriveLink} throws — at the point the bytes arrive,
 * rather than as a null that travels until something downstream asks a question of it. A project
 * file that does not parse is a bug in this reader or a new FL Studio format, and both are things
 * to be told about rather than to fall back from.
 */
final class FlpException extends RuntimeException
{
}
