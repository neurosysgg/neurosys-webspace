<?php

declare(strict_types=1);

namespace NeuroSYS\View;

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
    public function pageTitle(): string { return 'neuro.SYS'; }

    public function content(): Node
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::HomeHero)
            ->containing(
                new Element(HtmlTag::P)
                    ->attr(HtmlAttribute::ClassName, CssClass::HomeEyebrow)
                    ->containing('neuro', self::logoDot(), 'SYS'),
                new Element(HtmlTag::H1)
                    ->attr(HtmlAttribute::ClassName, CssClass::HomeTitle)
                    ->containing('electronic music', self::bang()),
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::ClassName, CssClass::BtnPrimary)
                    ->attr(HtmlAttribute::Href, '/releases')
                    // The real arrow, not &rarr;: an entity written here would come back out as
                    // &amp;rarr;, since Text is the only way content gets in and it escapes all of it.
                    ->containing('releases →'),
            );
    }

    /** The pink dot in the wordmark. */
    private static function logoDot(): Element
    {
        return new Element(HtmlTag::Span)->attr(HtmlAttribute::ClassName, CssClass::LogoDot)->containing('.');
    }

    /** The accented full stop the site signs off with. */
    private static function bang(): Element
    {
        return new Element(HtmlTag::Span)->attr(HtmlAttribute::ClassName, CssClass::Bang)->containing('.');
    }
}
