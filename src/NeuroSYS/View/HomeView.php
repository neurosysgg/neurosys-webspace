<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Model\Embed\SoundCloudProfileEmbed;
use NeuroSYS\Support\SitePath;
use NeuroSYS\Text\Texts;
use NeuroSYS\View\Html\CssClass;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\Fragment;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;
use Phpanta\View\View;

/**
 * The HomeView class. Renders the site home page — the hero, and the profile player under it.
 */
class HomeView extends View
{
    use Accented;

    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable { return self::title(); }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Fragment(self::heroSection(), self::tracksSection());
    }

    /**
     * The headline is the tagline and its full stop, which carries the accent. The stop is set
     * beside the words rather than split off them, because the words are not known until the
     * language is — see {@link View::accent()}.
     *
     * @return Element
     */
    private static function heroSection(): Element
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::HomeHero)
            ->containing(
                new Element(HtmlTag::P)
                    ->attr(HtmlAttribute::ClassName, CssClass::HomeEyebrow)
                    ->containing(...Wordmark::nodes()),
                new Element(HtmlTag::H1)
                    ->attr(HtmlAttribute::ClassName, CssClass::HomeTitle)
                    ->containing(Texts::Layout::Tagline, self::accent('.')),
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::ClassName, CssClass::BtnPrimary)
                    ->attr(HtmlAttribute::Href, SitePath::Releases->to())
                    // The real arrow, not &rarr;: an entity written here would come back out as
                    // &amp;rarr;, since Text is the only way content gets in and it escapes all of it.
                    ->containing(Texts::Layout::Releases, ' →'),
            );
    }

    /**
     * The profile player: the whole account's latest tracks, rather than any one release.
     *
     * Built here rather than handed in by {@link \NeuroSYS\Controller\HomeController}, which is the
     * opposite of how {@link ReleaseView} gets its data — because this is not data. It is a fixed
     * fact about the site, the same kind of thing as the tagline two methods up, and there is no
     * repository it could come from.
     *
     * @return Element
     */
    private static function tracksSection(): Element
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(
                new Element(HtmlTag::H2)
                    ->attr(HtmlAttribute::ClassName, CssClass::PageHeading)
                    ->containing(Texts::Home::LatestTracks),
                new SoundCloudProfileEmbed()->toElement(),
            );
    }
}
