<?php

declare(strict_types=1);

namespace NeuroSYS;

use NeuroSYS\Model\Profile;
use NeuroSYS\Service\ProfileRepository;
use NeuroSYS\Support\BareArray;
use NeuroSYS\Support\BareCall;
use NeuroSYS\Support\Charset;
use NeuroSYS\Support\SitePath;
use NeuroSYS\Support\UrlScheme;
use NeuroSYS\Text\Joined;
use NeuroSYS\Text\Language;
use NeuroSYS\Text\Texts;
use NeuroSYS\Text\Translatable;
use NeuroSYS\Text\Verbatim;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Document;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\ElementId;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\LinkAttribute;
use NeuroSYS\View\Html\LinkRel;
use NeuroSYS\View\Html\LinkTarget;
use NeuroSYS\View\Html\MetaName;
use NeuroSYS\View\Html\Node;
use NeuroSYS\View\Html\ScriptType;
use NeuroSYS\View\Html\ViewportContent;
use NeuroSYS\View\Html\ViewportWidth;
use NeuroSYS\View\Shell;
use NeuroSYS\View\View;
use NeuroSYS\View\Wordmark;

/**
 * The Layout class. Renders the site shell — HTML document, header, footer, and scripts.
 */
class Layout implements Shell
{
    /**
     * The {@link Shell} a response asks for: {@link self::wrap()}, reached through the app.
     *
     * @param View     $view
     * @param Language $language
     * @return Document
     */
    public function document(View $view, Language $language): Document
    {
        return self::wrap($view, $language);
    }

    /**
     * Wraps the given view's content in the full site shell, in $language.
     *
     * `<html lang>` is where a page states its language, and so where the tree takes it from:
     * every translatable in the document renders in it — see {@link \NeuroSYS\View\Html\Node}.
     *
     * @param View     $view     The view whose content to embed.
     * @param Language $language The language the request is answered in.
     * @return Document The complete document, ready to render.
     */
    public static function wrap(View $view, Language $language): Document
    {
        return new Document(
            new Element(HtmlTag::Html)
                ->attr(HtmlAttribute::Lang, $language)
                ->containing(self::head($view->pageTitle()), self::body($view->content(), $language)),
        );
    }

    /**
     * @param Translatable $title
     * @return Element
     */
    private static function head(Translatable $title): Element
    {
        return new Element(HtmlTag::Head)->containing(
            new Element(HtmlTag::Meta)->attr(HtmlAttribute::Charset, Charset::Utf8->canonical()),
            new Element(HtmlTag::Meta)
                ->attr(HtmlAttribute::Name, MetaName::Viewport)
                ->attr(HtmlAttribute::Content, new ViewportContent(
                    width: ViewportWidth::Device,
                    initialScale: 1.0,
                )),
            new Element(HtmlTag::Title)->containing($title),
            new Element(HtmlTag::Meta)
                ->attr(HtmlAttribute::Name, MetaName::Description)
                ->attr(HtmlAttribute::Content, self::description()),
            new Element(HtmlTag::Link)
                ->attr(HtmlAttribute::Rel, LinkRel::Stylesheet)
                ->attr(HtmlAttribute::Href, AssetManifest::STYLESHEET),
            ...self::modulePreloads(),
        );
    }

    /**
     * The site's meta description — `neuro.SYS — electronic music.` — in the page's language.
     *
     * Joined rather than written out as a phrase of its own, so the tagline stays one case of the
     * catalog: the home page's headline is the same words.
     *
     * @return Translatable
     */
    private static function description(): Translatable
    {
        return new Joined(
            ' — ',
            new Verbatim(Site::NAME),
            new Joined('', Texts::Layout::Tagline, new Verbatim('.')),
        );
    }

    /**
     * One `<link rel="modulepreload">` per module the entry point reaches — and none at all when
     * the entry point is a bundle.
     *
     * An ES module graph is discovered a wave at a time — the browser learns it needs
     * `model/CssClass.js` only after parsing four files that led to it — so the debug tree's graph
     * costs five sequential round trips before the last module begins downloading. Declared here,
     * the preload scanner sees all forty-seven at once and that becomes one. It is the only one of
     * the three front-end costs that is latency rather than bytes, which is why compressing and
     * stripping comments do not touch it.
     *
     * **What ships has no waterfall left to flatten.** `tools/build-prod.mjs` bundles the graph into
     * one module, so {@link AssetManifest}'s `MODULES` is empty there and this returns nothing — the
     * browser is told to fetch one file, and it has that instruction already from the `<script src>`.
     * The committed manifest still lists all forty-seven, because the debug tree still ships fifty
     * modules and `npm run dev` still serves them that way. So this is live on the tree a person
     * develops against and inert on the tree a visitor loads, which is the right way round: the hint
     * buys back a cost that bundling removes outright.
     *
     * After the stylesheet, deliberately: that one blocks rendering and these do not.
     *
     * Every page gets the same list because every page loads the same entry point — `main.ts`
     * imports the whole vocabulary so a tag is registered wherever it appears, so there is no page
     * for which a subset would be right. SPA navigation replaces `#content` without parsing a new
     * head, and by then the graph is loaded; nothing to do there.
     *
     * {@link AssetManifest} is generated by `tools/build-assets.mjs` and drift-checked by the verify
     * script, so this cannot quietly describe a graph that has moved on. Each href carries the same
     * build-stamp path segment the module's own specifier resolves to — they come from the same
     * stamp in the same pass, which is the point of one tool owning both. A hint that resolved to a
     * different URL from the specifier would have the browser fetch the file twice, which is worse
     * than nothing.
     *
     * @return list<Element> The preload links, in the generated order; empty for a bundled tree.
     */
    #[BareArray(
        'spread into containing(), which is a variadic PHP already guards. A collection does not '
        . 'replace a variadic; here it would only add a toValues() at the one call site.',
    )]
    #[BareCall(
        'array_map',
        'maps a class constant, and a class constant cannot hold a Collection — `new` is not a '
        . 'constant expression, so AssetManifest::MODULES is an array wherever it is read and '
        . 'building one here would mean copying it first. The result is spread into containing(), '
        . 'which is the variadic the #[BareArray] above already argues for.',
    )]
    private static function modulePreloads(): array
    {
        return array_map(
            static fn (string $module): Element => new Element(HtmlTag::Link)
                ->attr(HtmlAttribute::Rel, LinkRel::ModulePreload)
                ->attr(HtmlAttribute::Href, $module),
            AssetManifest::MODULES,
        );
    }

    /**
     * @param Node     $content
     * @param Language $language
     * @return Element
     */
    private static function body(Node $content, Language $language): Element
    {
        return new Element(HtmlTag::Body)->containing(
            self::header(),
            new Element(HtmlTag::Main)->attr(HtmlAttribute::Id, ElementId::Content)->containing($content),
            self::footer($language),
            // type="module", so it defers on its own and every import resolves as an ES module.
            new Element(HtmlTag::Script)
                ->attr(HtmlAttribute::Type, ScriptType::Module)
                ->attr(HtmlAttribute::Src, AssetManifest::SCRIPT),
        );
    }

    /**
     * @return Element
     */
    private static function header(): Element
    {
        return new Element(HtmlTag::Header)
            ->attr(HtmlAttribute::ClassName, CssClass::SiteHeader)
            ->containing(
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::ClassName, CssClass::Logo)
                    ->attr(HtmlAttribute::Href, SitePath::Home->to())
                    ->containing(...Wordmark::nodes()),
                new Element(HtmlTag::Nav)
                    ->attr(HtmlAttribute::ClassName, CssClass::SiteNav)
                    ->containing(
                        new Element(HtmlTag::A)
                            ->attr(HtmlAttribute::Href, SitePath::Releases->to())
                            ->containing(Texts::Layout::Releases),
                    ),
            );
    }

    /**
     * @param Language $language The page's, so the switch can say which one this is.
     * @return Element
     */
    private static function footer(Language $language): Element
    {
        $footer = new Element(HtmlTag::Footer)->attr(HtmlAttribute::ClassName, CssClass::SiteFooter);
        $links  = new ProfileRepository()->all();

        if (!$links->isEmpty()) {
            // Deliberately not a pipe chain. `|>` takes a single value and a callable; this needs a
            // spread into a method on an object built here, which is neither — the IDE's quick-fix
            // for it produced something that parses and cannot run.
            $footer = $footer->containing(
                new Element(HtmlTag::Nav)
                    ->attr(HtmlAttribute::ClassName, CssClass::ProfileLinks)
                    ->attr(HtmlAttribute::AriaLabel, Texts::Layout::Profiles)
                    ->containing(...$links->map(self::profileLink(...))->toValues()),
            );
        }

        return $footer->containing(
            new Element(HtmlTag::P)->containing(
                Site::NAME . ' · ',
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::Href, UrlScheme::Mailto->url(Site::EMAIL))
                    ->containing(Site::EMAIL),
                ' · ',
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::Href, SitePath::Imprint->to())
                    ->containing(Texts::Layout::Imprint),
                ' · ',
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::Href, SitePath::Privacy->to())
                    ->containing(Texts::Layout::Privacy),
            ),
            self::languages($language),
        );
    }

    /**
     * The language switch: every language the site is written in, each named in itself.
     *
     * The page's own is plain text; the others link to {@link SitePath::Language}, which sets the
     * visitor's cookie and sends them back here. **`data-no-spa`**, so the browser loads that
     * address whole rather than Navigation fetching a fragment of it: the header and the footer are
     * outside the fragment, and they have to come back in the new language too. Each name carries
     * its own `lang`, so a screen reader says `deutsch` in German.
     *
     * @param Language $current
     * @return Element
     */
    private static function languages(Language $current): Element
    {
        $names = [];

        foreach (Site::current()->languages()->offered() as $language) {
            if ($names !== []) {
                $names[] = ' · ';
            }

            $names[] = $language === $current
                ? new Element(HtmlTag::Span)->attr(HtmlAttribute::Lang, $language)->containing($language->endonym())
                : new Element(HtmlTag::A)
                    ->attr(LinkAttribute::NoSpa)
                    ->attr(HtmlAttribute::Href, SitePath::Language->to($language->value))
                    ->attr(HtmlAttribute::Lang, $language)
                    ->containing($language->endonym());
        }

        return new Element(HtmlTag::P)->containing(...$names);
    }

    /**
     * One external profile link.
     *
     * A plain hyperlink to a locally vendored icon — nothing is requested from the platform until a
     * visitor actually clicks, so no consent gate is needed (unlike the SoundCloud embed, which is
     * gated in ReleaseView). See docs/branding.md for why the icons are never hot-linked.
     *
     * @param Profile $profile
     * @return Element
     */
    private static function profileLink(Profile $profile): Element
    {
        $platform = $profile->platform;
        $label    = $platform->label();

        return new Element(HtmlTag::A)
            ->attr(HtmlAttribute::ClassName, CssClass::ProfileLink)
            ->attr(HtmlAttribute::Href, $profile->url)
            ->attr(HtmlAttribute::Title, $label)
            ->attr(HtmlAttribute::Target, LinkTarget::Blank)
            ->attr(
                HtmlAttribute::Rel,
                LinkRel::tokens(LinkRel::NoOpener, LinkRel::NoReferrer, LinkRel::External),
            )
            ->containing(
                new Element(HtmlTag::Img)
                    ->attr(HtmlAttribute::Src, $platform->iconSrc())
                    ->attr(HtmlAttribute::Alt, $label)
                    ->attr(HtmlAttribute::Height, $platform->iconHeight()),
            );
    }
}
