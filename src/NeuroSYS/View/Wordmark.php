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
 * The Wordmark class. The site's name with its dot accented — `neuro.SYS`.
 *
 * In two places: the header logo and the home page's eyebrow. Both used to spell it out as three
 * pieces, which is two chances to end up with a lookalike of the site's own name.
 *
 * Returns the pieces rather than one node on purpose. {@link Element} renders inline only when a
 * child is {@link \NeuroSYS\View\Html\Text}, so a wordmark wrapped in a single node would be laid
 * out as a block and gain spaces either side of the dot — spread, the text is the element's own and
 * it stays on one line.
 */
final class Wordmark
{
    /** @return list<Node|string> */
    public static function nodes(): array
    {
        [$before, $after] = explode('.', Config::NAME, 2);

        return [
            $before,
            new Element(HtmlTag::Span)
                ->attr(HtmlAttribute::ClassName, CssClass::LogoDot)
                ->containing('.'),
            $after,
        ];
    }
}
