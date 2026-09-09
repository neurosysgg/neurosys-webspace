<?php

declare(strict_types=1);

namespace NeuroSYS\Exception;

use LogicException;

/**
 * The MarkupException class. Thrown when the markup tree is asked for something it cannot be.
 *
 * Two callers now, and they are the same complaint from opposite directions.
 * {@link \NeuroSYS\View\Html\Element} throws when an element is asked to be something no element
 * can be — a void element with children, a URL naming a scheme the site does not emit.
 * {@link \NeuroSYS\View\Html\MarkupParser} throws when markup it is *reading* names an element or
 * an attribute this site does not have, or does not parse cleanly at all.
 *
 * **The second kind is the same category as the first, which is worth saying rather than assuming.**
 * A parse failure looks at first like bad input, and bad input is a condition a caller recovers
 * from — but the only markup this parser is ever handed is `data/privacy.*.html`, which is checked
 * into this repository beside the code that reads it. A refusal there means a file in this repo is
 * written wrong, exactly as a void element with children does.
 *
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
