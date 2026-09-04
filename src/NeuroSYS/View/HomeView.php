<?php

declare(strict_types=1);

namespace NeuroSYS\View;

/**
 * The HomeView class. Renders the site home page hero section.
 */
class HomeView extends View
{
    public function pageTitle(): string { return 'neuro.SYS'; }

    public function content(): string
    {
        return <<<HTML
            <section class="home-hero">
              <p class="home-eyebrow">neuro<span class="logo-dot">.</span>SYS</p>
              <h1 class="home-title">electronic music<span class="bang">.</span></h1>
              <a class="btn-primary" href="/releases">releases →</a>
            </section>
            HTML;
    }
}
