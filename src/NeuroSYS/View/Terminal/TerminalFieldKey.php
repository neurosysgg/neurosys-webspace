<?php

declare(strict_types=1);

namespace NeuroSYS\View\Terminal;

/**
 * The TerminalFieldKey enum. The JSON keys a {@link TerminalField} crosses to the client as.
 *
 * {@link TerminalField::toArray()} writes them and `TerminalWindow.ts`'s type guard reads them, so
 * they are a contract like any attribute name — just carried inside a value rather than as one.
 * Drift and the guard rejects every row, and the window throws rather than rendering: loud, but
 * loud for a reason nobody would guess from the message.
 */
enum TerminalFieldKey: string
{
    case Key   = 'key';
    case Value = 'value';
    case Tone  = 'tone';
}
