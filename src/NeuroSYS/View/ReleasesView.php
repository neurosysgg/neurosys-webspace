<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Model\Release;
use NeuroSYS\Support\SearchableCollection;
use NeuroSYS\View\Html\CardAttribute;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Node;
use NeuroSYS\View\Html\Tag;

/**
 * The ReleasesView class. Renders the full list of releases.
 */
class ReleasesView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param SearchableCollection<Release> $releases The collection of all releases.
     */
    public function __construct(private readonly SearchableCollection $releases) {}

    public function pageTitle(): string { return self::title('releases'); }

    public function content(): Node
    {
        $cards = [];

        foreach ($this->releases as $slug => $release) {
            $cards[] = self::card($slug, $release);
        }

        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(
                new Element(HtmlTag::H2)
                    ->attr(HtmlAttribute::ClassName, CssClass::PageHeading)
                    ->containing('releases'),
                new Element(Tag::ReleaseList)->containing(...$cards),
            );
    }

    /**
     * Builds one catalogue entry.
     *
     * The anchor stays native and server-rendered: a catalogue that only works with JS is not a
     * catalogue. The card wraps it and names which release it is for.
     */
    private static function card(string $slug, Release $release): Element
    {
        $meta = implode(' · ', [
            $release->bpm . ' bpm',
            $release->key->value,
            $release->genre->value,
            $release->description,
        ]);

        return new Element(Tag::ReleaseCard)
            ->attr(CardAttribute::Slug, $slug)
            ->containing(
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::Href, '/releases/' . $slug . '/')
                    ->containing(
                        new Element(Tag::ReleaseTitle)->containing($release->title),
                        new Element(Tag::ReleaseMeta)->containing($meta),
                    ),
            );
    }
}
