<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Config;
use NeuroSYS\Http\RequestHeader;
use NeuroSYS\Support\BareArray;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Language;
use NeuroSYS\View\Html\Node;

/**
 * The View abstract class. Base class for all page views.
 *
 * Each concrete view produces a page title and an HTML content fragment.
 * The fragment is embedded into the site {@link \NeuroSYS\Layout} for full-page
 * requests, or sent directly for AJAX fragment requests.
 *
 * The fragment is a {@link Node}, not a string: a view assembles a tree and something else decides
 * when it becomes markup. Nothing in this namespace concatenates HTML any more, so there is no
 * point at which a value could reach the page unescaped.
 */
abstract class View
{
    /**
     * Returns the page title for this view.
     *
     * @return string
     */
    abstract public function pageTitle(): string;
    /**
     * Returns the HTML content fragment for this view.
     *
     * @return Node
     */
    abstract public function content(): Node;

    /**
     * The language this page is primarily written in.
     *
     * English for everything here but the two legal documents, which carry a German half and an
     * English half and answer with whichever the visitor asked for — see
     * {@link \NeuroSYS\Http\AcceptedLanguages}. {@link \NeuroSYS\Layout::wrap()} puts it on
     * `<html lang>`, and each half of a bilingual page carries its own `lang` besides, so a screen
     * reader changes voice at the boundary rather than reading one language in the other's.
     *
     * A default rather than an abstract, unlike the two above: a page that has not thought about
     * this is English, which is true of seven of the nine.
     *
     * @return Language
     */
    public function language(): Language
    {
        return Language::English;
    }

    /**
     * The request headers this page's body depends on, beyond the one every page depends on.
     *
     * **A page that reads a request header owes a `Vary` naming it**, and stating both facts in one
     * place is what stops the second being forgotten: {@link \NeuroSYS\Http\ViewResponse} builds
     * the header from this, so a view cannot start varying on something without saying so. Forget
     * it and there is no error — a cache simply becomes free to hand one visitor the page it built
     * for another, which on the two pages this concerns means the wrong language and nothing else
     * wrong at all.
     *
     * `X-Requested-With` is not on any view's list because every response varies on it, document or
     * fragment; that one belongs to the response rather than to the page.
     *
     * @return list<RequestHeader>
     */
    #[BareArray(
        'spread into Vary::on(), a variadic PHP already guards. A collection here would replace a '
        . 'check the language makes for free with one we make ourselves, and add a toValues() at '
        . 'the one call site.',
    )]
    public function varyOn(): array
    {
        return [];
    }

    /**
     * A page title: the section, then the site.
     *
     * Written once, here, rather than by each view: `' — neuro.SYS'` in six views is six chances to
     * use a hyphen where the others use an em dash and never notice.
     *
     * @param ?string $section
     * @return string
     */
    protected static function title(?string $section = null): string
    {
        return $section === null ? Config::NAME : $section . ' — ' . Config::NAME;
    }

    /**
     * Splits a trailing `!`, `.` or `?` into an accented span.
     *
     * 'hello world!', 'ill.' and the site's own tagline all read as name plus mark, and the mark is
     * what carries the accent colour. Returns the pieces rather than an element, because the caller
     * decides what wraps them — an `<h1>` here, a `<p>` there.
     *
     * @param string $text
     * @return list<Node|string>
     */
    #[BareArray(
        'spread into containing(), a variadic PHP already guards — and the union it holds is one '
        . 'a collection could not declare anyway.',
    )]
    protected static function accented(string $text): array
    {
        if (preg_match('/[!.?]\z/', $text, $matches) !== 1) {
            return [$text];
        }

        return [
            substr($text, 0, -1),
            new Element(HtmlTag::Span)
                ->attr(HtmlAttribute::ClassName, CssClass::Bang)
                ->containing($matches[0]),
        ];
    }
}
