<?php

declare(strict_types=1);

namespace NeuroSYS\Exception;

use Exception;

/**
 * The MarkupException class. Thrown when a {@link \NeuroSYS\View\Html\Element} is asked to be
 * something no element can be — a void element with children, so far.
 */
class MarkupException extends Exception
{
}
