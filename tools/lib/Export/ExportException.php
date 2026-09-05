<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Export;

use RuntimeException;

/**
 * The ExportException class. There is no audio to hand back.
 *
 * Two things throw this and they are different in kind: {@link PreparedExport} when the file it was
 * pointed at is not there, and {@link FlStudioExport} always, because the mechanism it needs has
 * not been decided. The second is not a failure of the code — it is the code saying out loud that
 * the decision is still open, which is preferable to a class that looks finished.
 */
final class ExportException extends RuntimeException
{
}
