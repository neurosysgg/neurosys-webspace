<?php

declare(strict_types=1);

namespace NeuroSYS\View;

/**
 * The NotFoundView class. Renders the 404 error page.
 */
class NotFoundView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $path The request path that was not found, shown in the terminal block.
     */
    public function __construct(private readonly string $path) {}

    public function pageTitle(): string { return '404 — neuro.SYS'; }

    public function content(): string
    {
        $path = htmlspecialchars($this->path);

        return <<<HTML
            <section class="page-section">
              <terminal-window label="error.log" narrow>
                <terminal-command>find $path</terminal-command>
                <terminal-field><terminal-key error>error</terminal-key>404 — not found</terminal-field>
                <terminal-cursor></terminal-cursor>
              </terminal-window>
              <p class="back-home"><a href="/">← home</a></p>
            </section>
            HTML;
    }
}
