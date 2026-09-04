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

    /**
     * Indents every line but the first by $spaces, for markup built outside a heredoc.
     *
     * The first line is left alone on purpose: it takes its indentation from wherever it is
     * interpolated, the way every other heredoc value does. {@link \NeuroSYS\View\Html\Element}
     * renders at column zero because it has no idea where it will land, so a list of rendered
     * elements needs this before it goes into a heredoc — otherwise the served source is a ragged
     * block, which is only cosmetic but is the sort of cosmetic that gets read.
     */
    protected static function indent(string $html, int $spaces): string
    {
        return str_replace("\n", "\n" . str_repeat(' ', $spaces), $html);
    }
}
