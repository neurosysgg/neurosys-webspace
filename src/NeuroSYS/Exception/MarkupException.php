<?php

declare(strict_types=1);

namespace NeuroSYS\Exception;

use LogicException;

/**
 * The MarkupException class. Thrown when a {@link \NeuroSYS\View\Html\Element} is asked to be
 * something no element can be — a void element with children, so far. *
 * **Extends `LogicException`, and that is the classification rather than a detail.** Nothing on
 * this site catches it and nothing should: this is not a condition a caller recovers from, it is
 * "something in this repository is written wrong, go and fix it" — which is what SPL's
 * `LogicException` means. Saying it in the type rather than only the prose also settles a question
 * that would otherwise follow it around: whether every `containing()` and every `render()` owes an `@throws` for a
 * failure that only happens when the site is already broken. It does not.

 */
class MarkupException extends LogicException
{
}
