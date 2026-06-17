<?php
declare(strict_types=1);

namespace NeuroSYS;

use NeuroSYS\View\View;

/**
 * The Layout class. Renders the site shell — HTML document, header, footer, and scripts.
 */
class Layout
{
    /**
     * Wraps the given view's content in the full site shell.
     *
     * @param View $view The view whose content to embed.
     * @return string The complete HTML document.
     */
    public static function wrap(View $view): string
    {
        $title   = htmlspecialchars($view->pageTitle());
        $content = $view->content();

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="UTF-8" />
              <meta name="viewport" content="width=device-width, initial-scale=1.0" />
              <title>$title</title>
              <meta name="description" content="neuro.SYS — electronic music." />
              <link rel="stylesheet" href="/assets/css/style.css" />
            </head>
            <body>

              <header class="site-header">
                <a class="logo" href="/">neuro<span class="logo-dot">.</span>SYS</a>
                <nav class="site-nav">
                  <a href="/releases">releases</a>
                </nav>
              </header>

              <main id="content">
                $content
              </main>

              <footer class="site-footer">
                <p>neuro.SYS &middot; <a href="mailto:neuro.sys@neurosys.gg">neuro.sys@neurosys.gg</a></p>
              </footer>

              <script src="/assets/js/nav.js"></script>
            </body>
            </html>
            HTML;
    }
}
