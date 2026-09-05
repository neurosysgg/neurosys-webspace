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
 *
 * **There is a second tree in this repo, and it is deliberately not this one.** The release tooling
 * emits `data/releases.php` through an expression tree of its own, `NeuroSYS\Tool\Php\Expression`,
 * which answers for PHP source the objection this answers for markup — nothing builds a language by
 * concatenating it — and which states the same indentation contract as {@link self::render()} does,
 * in a parameter of its own shape.
 *
 * They stay two types, on the test this codebase already applies to `Support\TypedItems`: nothing
 * anywhere holds "either kind of node", so a common parent would announce a type nothing wants. It
 * would also have to live under `src/` to be reachable from both — shipped to Strato and inside
 * `phpunit.xml.dist`'s coverage source, for a tool that never runs there — which is the arrangement
 * `docs/authoring.md` argues against by name. The kinship is real and it is prose, which is the
 * most a language can carry across a boundary the deployment draws.
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
