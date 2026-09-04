<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The RawHtml class. Markup that goes out exactly as given.
 *
 * **The one hole in the tree, and it is meant to be conspicuous.** Nothing here is escaped, checked
 * or parsed, so a value reaching this class is a value trusted completely. It exists for one thing:
 * `data/privacy.html`, which is a hand-authored document rather than something a view assembles.
 *
 * Never construct one from anything a request can influence. `ViewTest` pins the call sites — if a
 * second one appears, that test is where it has to be argued for.
 */
final readonly class RawHtml implements Node
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $html Markup, trusted verbatim.
     */
    public function __construct(public string $html) {}

    public function render(int $depth = 0): string
    {
        return str_replace("\n", "\n" . str_repeat('  ', $depth), trim($this->html));
    }
}
