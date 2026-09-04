<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Support\SearchableCollection;
use NeuroSYS\View\Html\CardAttribute;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\Tag;

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
            $href  = htmlspecialchars('/releases/' . $slug . '/');
            $title = htmlspecialchars($release->title);
            $bpm   = $release->bpm;
            $key   = htmlspecialchars($release->key->value);
            $genre = htmlspecialchars($release->genre->value);
            $desc  = htmlspecialchars($release->description);

            // The anchor stays native and server-rendered: a catalogue that only works with JS is
            // not a catalogue. The card wraps it and names which release it is for.
            $cards .= new Element(Tag::ReleaseCard)
                ->with(CardAttribute::Slug, $slug)
                ->containing(<<<HTML

                        <a href="$href">
                          <release-title>$title</release-title>
                          <release-meta>$bpm bpm &middot; $key &middot; $genre &middot; $desc</release-meta>
                        </a>

                      HTML)
                ->render() . "\n";
        }

        $cards = self::indent(rtrim($cards), 4);

        return <<<HTML
            <section class="page-section">
              <h2 class="page-heading">releases</h2>
              <release-list>
                $cards
              </release-list>
            </section>
            HTML;
    }
}
