<?php

declare(strict_types=1);

namespace NeuroSYS\View\Terminal;

/**
 * The TerminalTone enum. How a {@link TerminalField} reads.
 *
 * Mirrored in assets/ts/model/TerminalTone.ts, because <terminal-window> builds the row and needs
 * to know which of the two halves to colour. test/js/enum-parity.test.mjs keeps the two in step.
 */
enum TerminalTone: string
{
    /** An ordinary key/value row. */
    case Plain = 'plain';

    /** A value that reads as success — the value takes the accent. */
    case Ok = 'ok';

    /** A row that reads as a failure — the key takes the accent. */
    case Error = 'error';
}
