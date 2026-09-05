<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The Node interface. Anything that can render itself as markup.
 *
 * The point of the interface is that {@link Element} takes children of this type and nothing else,
 * so a document is a tree of objects rather than a string built by concatenation. Everything that
 * reaches the page is one of four things: an {@link Element}, escaped {@link Text}, a
 * {@link Fragment} of those, or {@link RawHtml} — which is the single audited hole, for markup
 * authored outside PHP.
 */
interface Node
{
    /**
     * Renders this node as markup.
     *
     * @param int $depth How deep this node sits, in two-space indents. The first line is returned
     *                   unindented — whoever places it already put it at that column — and every
     *                   line after it is indented to $depth. Same contract at every level, which is
     *                   what makes the tree pretty-print without any node knowing where it is.
     *
     * @return string
     */
    public function render(int $depth = 0): string;
}
