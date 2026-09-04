<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\View\Html\Node;

/**
 * The View abstract class. Base class for all page views.
 *
 * Each concrete view produces a page title and an HTML content fragment.
 * The fragment is embedded into the site {@link \NeuroSYS\Layout} for full-page
 * requests, or sent directly for AJAX fragment requests.
 *
 * The fragment is a {@link Node}, not a string: a view assembles a tree and something else decides
 * when it becomes markup. Nothing in this namespace concatenates HTML any more, so there is no
 * point at which a value could reach the page unescaped.
 */
abstract class View
{
    /** Returns the page title for this view. */
    abstract public function pageTitle(): string;
    /** Returns the HTML content fragment for this view. */
    abstract public function content(): Node;
}
