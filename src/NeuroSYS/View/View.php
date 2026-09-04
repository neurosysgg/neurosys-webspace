<?php

declare(strict_types=1);

namespace NeuroSYS\View;

/**
 * The View abstract class. Base class for all page views.
 *
 * Each concrete view produces a page title and an HTML content fragment.
 * The fragment is embedded into the site {@link \NeuroSYS\Layout} for full-page
 * requests, or sent directly for AJAX fragment requests.
 */
abstract class View
{
    /** Returns the page title for this view. */
    abstract public function pageTitle(): string;
    /** Returns the HTML content fragment for this view. */
    abstract public function content(): string;
}
