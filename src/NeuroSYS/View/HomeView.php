<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Config;
use NeuroSYS\Model\Embed\SoundCloudProfileEmbed;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\Fragment;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Node;

/**
 * The HomeView class. Renders the site home page — the hero, and the profile player under it.
 */
class HomeView extends View
{
    public function pageTitle(): string { return self::title(); }

    public function content(): Node
    {
        return new Fragment(self::heroSection(), self::tracksSection());
    }

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
                    ->containing(...self::accented(Config::TAGLINE)),
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::ClassName, CssClass::BtnPrimary)
                    ->attr(HtmlAttribute::Href, '/releases')
                    // The real arrow, not &rarr;: an entity written here would come back out as
                    // &amp;rarr;, since Text is the only way content gets in and it escapes all of it.
                    ->containing('releases →'),
            );
    }

    /**
     * The profile player: the whole account's latest tracks, rather than any one release.
     *
     * Built here rather than handed in by {@link \NeuroSYS\Controller\HomeController}, which is the
     * opposite of how {@link ReleaseView} gets its data — because this is not data. It is a fixed
     * fact about the site, the same kind of thing as {@link Config::TAGLINE} two methods up, and
     * there is no repository it could come from.
     */
    private static function tracksSection(): Element
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(
                new Element(HtmlTag::H2)
                    ->attr(HtmlAttribute::ClassName, CssClass::PageHeading)
                    ->containing('latest tracks'),
                new SoundCloudProfileEmbed()->toElement(),
            );
    }
}
