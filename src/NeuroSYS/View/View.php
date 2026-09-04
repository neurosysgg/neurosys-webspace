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
    /** Returns the page title for this view. */
    abstract public function pageTitle(): string;
    /** Returns the HTML content fragment for this view. */
    abstract public function content(): Node;

    /**
     * A page title: the section, then the site.
     *
     * Six views wrote out `' — neuro.SYS'` between them, which is six chances to use a hyphen where
     * the others use an em dash and never notice.
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
     * @return list<Node|string>
     */
    protected static function accented(string $text): array
    {
        if (preg_match('/[!.?]$/', $text, $matches) !== 1) {
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
