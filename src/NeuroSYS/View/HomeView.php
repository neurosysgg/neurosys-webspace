<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Config;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Node;

/**
 * The HomeView class. Renders the site home page hero section.
 */
class HomeView extends View
{
    public function pageTitle(): string { return self::title(); }

    public function content(): Node
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
}
