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
              <div class="terminal terminal-narrow">
                <div class="terminal-bar">
                  <span class="dot"></span><span class="dot"></span><span class="dot"></span>
                  <span class="terminal-title">error.log</span>
                </div>
                <div class="terminal-body">
                  <p><span class="prompt">\$</span> find $path</p>
                  <p class="out"><span class="accent-2">error</span>  404 — not found</p>
                  <p><span class="prompt">\$</span> <span class="cursor">_</span></p>
                </div>
              </div>
              <p class="back-home"><a href="/">← home</a></p>
            </section>
            HTML;
    }
}
