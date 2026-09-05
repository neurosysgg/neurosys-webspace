<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Export;

/**
 * The ExportSource enum. Where a piece of audio came from.
 *
 * The same idea as {@link \NeuroSYS\Tool\Release\Source}, and it earns its place for the same
 * reason: the report's value is in its last column. A file rendered from the project on the machine
 * that owns the project, and a file somebody exported by hand last week and left in the folder, are
 * not equally trustworthy — and the second is what every run does today.
 *
 * There is deliberately no third case for "rendered somewhere else". Until
 * {@link FlStudioExport} can actually run, {@link self::Rendered} has no writer, and a case with no
 * writer is a claim nothing can make.
 */
enum ExportSource: string
{
    /** Rendered from the project by this tooling. Nothing writes this yet. */
    case Rendered = 'rendered from the project';

    /** Already on disk when the command started — exported by hand, or named by `--audio`. */
    case Prepared = 'prepared by hand';
}
