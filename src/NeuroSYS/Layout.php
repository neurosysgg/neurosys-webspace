<?php

declare(strict_types=1);

namespace NeuroSYS;

use NeuroSYS\Service\ProfileRepository;
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
        $title    = htmlspecialchars($view->pageTitle());
        $content  = $view->content();
        $profiles = self::profileLinks();

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
                $profiles
                <p>neuro.SYS &middot; <a href="mailto:neuro.sys@neurosys.gg">neuro.sys@neurosys.gg</a> &middot; <a href="/imprint">imprint</a> &middot; <a href="/privacy">privacy policy</a></p>
              </footer>

              <script type="module" src="/assets/js/main.js"></script>
            </body>
            </html>
            HTML;
    }

    /**
     * Builds the external profile link row, or an empty string if none are configured.
     *
     * These are plain hyperlinks to locally vendored icons — nothing is requested
     * from the platforms until a visitor actually clicks, so no consent gate is
     * needed (unlike the SoundCloud embed, which is gated in ReleaseView).
     */
    private static function profileLinks(): string
    {
        $links = new ProfileRepository()->all();

        if ($links->count() === 0) {
            return '';
        }

        $items = '';

        foreach ($links as $profile) {
            $platform = $profile->platform;
            $href     = htmlspecialchars($profile->url);
            $label    = htmlspecialchars($platform->label());
            $src      = htmlspecialchars($platform->iconSrc());
            $height   = $platform->iconHeight();

            $items .= <<<HTML
                        <a class="profile-link" href="$href" title="$label"
                           target="_blank" rel="noopener noreferrer external">
                          <img src="$src" alt="$label" height="$height" />
                        </a>

                    HTML;
        }

        return <<<HTML
            <nav class="profile-links" aria-label="Profiles">
                    $items
                </nav>
            HTML;
    }
}
