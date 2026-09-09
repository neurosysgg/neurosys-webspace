<?php

declare(strict_types=1);

namespace NeuroSYS\Exception;

/**
 * The TerminalException class. Thrown when a terminal's rows cannot be handed to the element that
 * draws them.
 *
 * One thrower and one cause: {@link \NeuroSYS\View\Terminal\Terminal} encodes its rows as JSON for
 * `<terminal-window>`'s attribute, and in practice the only thing that fails there is invalid UTF-8
 * in a value.
 *
 * **Named for its single throwing class**, which is {@link MimeTypeException}'s arrangement rather
 * than a new one — an exception whose scope is one class says so, and is widened when a second
 * thing genuinely shares the condition.
 *
 * **Note what is deliberately *not* here.** `Terminal` also refuses a collection whose element type
 * is not `TerminalField`, and that throws {@link ReleaseVerificationException} — not because of
 * where the class lives but because of what the check is: it is the seventh of seven identical
 * element-type guards, the other six of which are in `Model/`, and splitting one off would put a
 * single question in two classes. See {@link \NeuroSYS\Model\Release::verify()}, where the guard is
 * argued for.
 */
class TerminalException extends MarkupException
{
}
