<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Support\SearchableCollection;

/**
 * The ReleasesView class. Renders the full list of releases.
 */
class ReleasesView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param SearchableCollection $releases The collection of all releases.
     */
    public function __construct(private readonly SearchableCollection $releases) {}

    public function pageTitle(): string { return 'releases — neuro.SYS'; }

    public function content(): string
    {
        $cards = '';
        foreach ($this->releases as $slug => $release) {
            $slugAttr = htmlspecialchars($slug);
            $href  = htmlspecialchars('/releases/' . $slug . '/');
            $title = htmlspecialchars($release->title);
            $bpm   = $release->bpm;
            $key   = htmlspecialchars($release->key->value);
            $genre = htmlspecialchars($release->genre->value);
            $desc  = htmlspecialchars($release->description);
            $cards .= <<<HTML
                  <release-card slug="$slugAttr">
                    <a class="release-card" href="$href">
                      <span class="release-title">$title</span>
                      <span class="release-meta">$bpm bpm &middot; $key &middot; $genre &middot; $desc</span>
                    </a>
                  </release-card>

                HTML;
        }

        return <<<HTML
            <section class="page-section">
              <h2 class="page-heading">releases</h2>
            $cards
            </section>
            HTML;
    }
}
