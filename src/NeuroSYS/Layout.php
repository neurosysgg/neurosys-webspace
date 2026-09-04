<?php

declare(strict_types=1);

namespace NeuroSYS;

use NeuroSYS\Config;
use NeuroSYS\Model\Profile;
use NeuroSYS\Service\ProfileRepository;
use NeuroSYS\View\Html\Document;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\ElementId;
use NeuroSYS\View\Html\Fragment;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Node;
use NeuroSYS\View\View;
use NeuroSYS\View\Wordmark;

/**
 * The Layout class. Renders the site shell — HTML document, header, footer, and scripts.
 */
class Layout
{
    /**
     * Wraps the given view's content in the full site shell.
     *
     * @param View $view The view whose content to embed.
     * @return Document The complete document, ready to render.
     */
    public static function wrap(View $view): Document
    {
        return new Document(
            new Element(HtmlTag::Html)
                ->attr(HtmlAttribute::Lang, 'en')
                ->containing(self::head($view->pageTitle()), self::body($view->content())),
        );
    }

    private static function head(string $title): Element
    {
        return new Element(HtmlTag::Head)->containing(
            new Element(HtmlTag::Meta)->attr(HtmlAttribute::Charset, 'UTF-8'),
            new Element(HtmlTag::Meta)
                ->attr(HtmlAttribute::Name, 'viewport')
                ->attr(HtmlAttribute::Content, 'width=device-width, initial-scale=1.0'),
            new Element(HtmlTag::Title)->containing($title),
            new Element(HtmlTag::Meta)
                ->attr(HtmlAttribute::Name, 'description')
                ->attr(HtmlAttribute::Content, Config::description()),
            new Element(HtmlTag::Link)
                ->attr(HtmlAttribute::Rel, 'stylesheet')
                ->attr(HtmlAttribute::Href, Config::STYLESHEET),
        );
    }

    private static function body(Node $content): Element
    {
        return new Element(HtmlTag::Body)->containing(
            self::header(),
            new Element(HtmlTag::Main)->attr(HtmlAttribute::Id, ElementId::Content)->containing($content),
            self::footer(),
            // type="module", so it defers on its own and every import resolves as an ES module.
            new Element(HtmlTag::Script)
                ->attr(HtmlAttribute::Type, 'module')
                ->attr(HtmlAttribute::Src, Config::SCRIPT),
        );
    }

    private static function header(): Element
    {
        return new Element(HtmlTag::Header)
            ->attr(HtmlAttribute::ClassName, CssClass::SiteHeader)
            ->containing(
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::ClassName, CssClass::Logo)
                    ->attr(HtmlAttribute::Href, '/')
                    ->containing(...Wordmark::nodes()),
                new Element(HtmlTag::Nav)
                    ->attr(HtmlAttribute::ClassName, CssClass::SiteNav)
                    ->containing(
                        new Element(HtmlTag::A)
                            ->attr(HtmlAttribute::Href, '/releases')
                            ->containing('releases'),
                    ),
            );
    }

    private static function footer(): Element
    {
        $footer = new Element(HtmlTag::Footer)->attr(HtmlAttribute::ClassName, CssClass::SiteFooter);
        $links  = new ProfileRepository()->all();

        if ($links->count() > 0) {
            $footer = $footer->containing(
                new Element(HtmlTag::Nav)
                    ->attr(HtmlAttribute::ClassName, CssClass::ProfileLinks)
                    ->attr(HtmlAttribute::AriaLabel, 'Profiles')
                    ->containing(...array_map(self::profileLink(...), $links->all())),
            );
        }

        return $footer->containing(
            new Element(HtmlTag::P)->containing(
                Config::NAME . ' · ',
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::Href, 'mailto:' . Config::EMAIL)
                    ->containing(Config::EMAIL),
                ' · ',
                new Element(HtmlTag::A)->attr(HtmlAttribute::Href, '/imprint')->containing('imprint'),
                ' · ',
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::Href, '/privacy')
                    ->containing('privacy policy'),
            ),
        );
    }

    /**
     * One external profile link.
     *
     * A plain hyperlink to a locally vendored icon — nothing is requested from the platform until a
     * visitor actually clicks, so no consent gate is needed (unlike the SoundCloud embed, which is
     * gated in ReleaseView). See docs/branding.md for why the icons are never hot-linked.
     */
    private static function profileLink(Profile $profile): Element
    {
        $platform = $profile->platform;
        $label    = $platform->label();

        return new Element(HtmlTag::A)
            ->attr(HtmlAttribute::ClassName, CssClass::ProfileLink)
            ->attr(HtmlAttribute::Href, $profile->url)
            ->attr(HtmlAttribute::Title, $label)
            ->attr(HtmlAttribute::Target, '_blank')
            ->attr(HtmlAttribute::Rel, 'noopener noreferrer external')
            ->containing(
                new Element(HtmlTag::Img)
                    ->attr(HtmlAttribute::Src, $platform->iconSrc())
                    ->attr(HtmlAttribute::Alt, $label)
                    ->attr(HtmlAttribute::Height, $platform->iconHeight()),
            );
    }
}
