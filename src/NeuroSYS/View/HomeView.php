<?php

declare(strict_types=1);

namespace NeuroSYS\View;

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
            ->attr(HtmlAttribute::ClassName, 'home-hero')
            ->containing(
                new Element(HtmlTag::P)
                    ->attr(HtmlAttribute::ClassName, 'home-eyebrow')
                    ->containing('neuro', self::logoDot(), 'SYS'),
                new Element(HtmlTag::H1)
                    ->attr(HtmlAttribute::ClassName, 'home-title')
                    ->containing('electronic music', self::bang()),
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::ClassName, 'btn-primary')
                    ->attr(HtmlAttribute::Href, '/releases')
                    // The real arrow, not &rarr;: an entity written here would come back out as
                    // &amp;rarr;, since Text is the only way content gets in and it escapes all of it.
                    ->containing('releases →'),
            );
    }

    /** The pink dot in the wordmark. */
    private static function logoDot(): Element
    {
        return new Element(HtmlTag::Span)->attr(HtmlAttribute::ClassName, 'logo-dot')->containing('.');
    }

    /** The accented full stop the site signs off with. */
    private static function bang(): Element
    {
        return new Element(HtmlTag::Span)->attr(HtmlAttribute::ClassName, 'bang')->containing('.');
    }
}
