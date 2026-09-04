<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\View\Terminal\Terminal;
use NeuroSYS\View\Terminal\TerminalField;
use NeuroSYS\View\Terminal\TerminalTone;

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
        $terminal = new Terminal(
            label:   'error.log',
            command: 'find ' . $this->path,
            fields:  [new TerminalField('error', '404 — not found', TerminalTone::Error)],
            narrow:  true,
        )->toElement();

        return <<<HTML
            <section class="page-section">
              $terminal
              <p class="back-home"><a href="/">← home</a></p>
            </section>
            HTML;
    }
}
