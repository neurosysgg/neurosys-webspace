<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Model\Release;
use NeuroSYS\Support\SearchableCollection;
use NeuroSYS\Support\SitePath;
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

    /**
     * @return string
     */
    public function pageTitle(): string { return self::title('releases'); }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(
                new Element(HtmlTag::H2)
                    ->attr(HtmlAttribute::ClassName, CssClass::PageHeading)
                    ->containing('releases'),
                new Element(Tag::ReleaseList)
                    ->containing(...$this->releases->map(self::card(...))->toValues()),
            );
    }

    /**
     * Builds one catalogue entry.
     *
     * The anchor stays native and server-rendered: a catalogue that only works with JS is not a
     * catalogue. The card wraps it and names which release it is for.
     *
     * The release first and its slug second, which is the order
     * {@link \NeuroSYS\Support\TypedItems::map()} hands them over — so this stays a first-class
     * callable at its one call site rather than growing a closure to reverse it.
     *
     * @param Release $release
     * @param string  $slug
     * @return Element
     */
    private static function card(Release $release, string $slug): Element
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
                    // No trailing slash: Request::path() rtrims one, so both forms resolve, but the
                    // catalogue is how most visitors arrive and its href is what pushState puts in
                    // the address bar. The download cards on the page it lands on are built without
                    // one — two spellings of the same page is one more than the site needs.
                    ->attr(HtmlAttribute::Href, SitePath::Release->to($slug))
                    ->containing(
                        new Element(Tag::ReleaseTitle)->containing($release->title),
                        new Element(Tag::ReleaseMeta)->containing($meta),
                    ),
            );
    }
}
