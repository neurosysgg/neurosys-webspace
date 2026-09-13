<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Support\BareArray;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Node;

/**
 * The Accented trait. This site's typographic flourish: a trailing `!`, `.` or `?` set in the
 * accent colour.
 *
 * A trait on the views that use it rather than a method on {@link View}, because the accent is this
 * site's look — its class is {@link CssClass::Bang} — and the base every view extends is the
 * framework's.
 */
trait Accented
{
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
