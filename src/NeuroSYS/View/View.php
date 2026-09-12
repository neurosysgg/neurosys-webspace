<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\App;
use NeuroSYS\Http\RequestHeader;
use NeuroSYS\Support\BareArray;
use NeuroSYS\Text\Joined;
use NeuroSYS\Text\Translatable;
use NeuroSYS\Text\Verbatim;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
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
     * Returns the page title for this view, to be put into the page's language when it renders.
     *
     * @return Translatable
     */
    abstract public function pageTitle(): Translatable;
    /**
     * Returns the HTML content fragment for this view.
     *
     * @return Node
     */
    abstract public function content(): Node;

    /**
     * The request headers this page's body depends on, beyond the ones every page depends on.
     *
     * **A page that reads a request header owes a `Vary` naming it**, and stating both facts in one
     * place is what stops the second being forgotten: {@link \NeuroSYS\Http\ViewResponse} builds
     * the header from this, so a view cannot start varying on something without saying so. Forget
     * it and there is no error — a cache simply becomes free to hand one visitor the page it built
     * for another, with nothing visibly wrong at all.
     *
     * Three are on no view's list because every page varies on them, so they belong to the response
     * rather than to the page: `X-Requested-With`, which decides document or fragment, and
     * `Accept-Language` and `Cookie`, which decide the language every page is written in — see
     * {@link \NeuroSYS\Http\Request::language()}. No view adds anything today.
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
     * use a hyphen where the others use an em dash and never notice. A translatable rather than a
     * string, because most sections are words, which the language decides when the title renders;
     * a section that is a name — a release's title — is the same in every language.
     *
     * @param Translatable|string|null $section
     * @return Translatable
     */
    protected static function title(Translatable|string|null $section = null): Translatable
    {
        $site = new Verbatim(App::current()->name());

        return match (true) {
            $section === null                => $site,
            $section instanceof Translatable => new Joined(' — ', $section, $site),
            default                          => new Joined(' — ', new Verbatim($section), $site),
        };
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

        return [substr($text, 0, -1), self::accent($matches[0])];
    }

    /**
     * The accented mark on its own.
     *
     * For a line whose words are translated: they are not known until the language is, so they
     * cannot be split — the mark is set beside them instead, and it is the same in every language.
     *
     * @param string $mark
     * @return Element
     */
    protected static function accent(string $mark): Element
    {
        return new Element(HtmlTag::Span)->attr(HtmlAttribute::ClassName, CssClass::Bang)->containing($mark);
    }
}
