<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The Text class. A run of text, escaped on the way out.
 *
 * Every string that reaches the page as content is one of these, so escaping is not something a
 * caller can forget — it is the only way text can get in. {@link Element::containing()} wraps bare
 * strings in one automatically, which means the unsafe thing is the thing you cannot type by
 * accident: markup in a string renders as visible `&lt;b&gt;`, and getting real markup in takes
 * {@link RawHtml} and says so.
 */
final readonly class Text implements Node
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $text The text, unescaped. Write the real character — `·`, not `&middot;` —
     *                     because an entity written here would come back out as `&amp;middot;`.
     */
    public function __construct(public string $text) {}

    public function render(int $depth = 0): string
    {
        return htmlspecialchars($this->text);
    }
}
