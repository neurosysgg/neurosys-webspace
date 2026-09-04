<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

use BackedEnum;
use NeuroSYS\Exception\MarkupException;

/**
 * The Element class. One element: a {@link TagName}, typed attributes, and child {@link Node}s.
 *
 * Replaces the string concatenation and heredocs the views used to be. Four mistakes stop being
 * possible, and three of them were silent: a misspelled tag renders as an inert inline box, a
 * misspelled attribute is a null the client reads as nothing, a value that reaches the markup
 * unescaped is an injection, and a closing tag that does not match its opening one is a document
 * the browser reinterprets. The last is the one a tree removes outright — there is no closing tag
 * to get wrong, because there is no text form to write.
 *
 * Immutable, like the policies and the collections: every builder method returns a new instance.
 *
 * Rendering pretty-prints. An element whose children are all elements puts each on its own line;
 * one with any {@link Text} among them stays on a single line, because whitespace between inline
 * content is content. That rule is why `<h1>ill<span>.</span></h1>` does not gain a space.
 */
final readonly class Element implements Node
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param TagName $tag The element to build.
     * @param array<string,string|null> $attributes Escaped values keyed by attribute name, which
     *                                         keeps the last write and the declaration order. A
     *                                         null value is a boolean attribute — distinct from
     *                                         `''`, which is a real empty value like `options=""`.
     *                                         Built with {@link self::with()}; normally left empty.
     * @param list<Node> $children The element's content. Built with {@link self::containing()}.
     */
    public function __construct(
        private TagName $tag,
        private array   $attributes = [],
        private array   $children   = [],
    ) {}

    /**
     * Returns a copy carrying the given attribute.
     *
     * One method for every shape an attribute takes, because three near-identical builders was
     * three chances to reach for the wrong one. What `$value` is decides what gets rendered:
     *
     * | `$value`      | rendered              |
     * |---------------|-----------------------|
     * | `'visual'`, 5 | `player-style="visual"`, `height="5"` |
     * | `CssClass::Hero`, any backed enum | its value — `class="hero"` |
     * | `''`          | `options=""` — an empty value, which is not the same as no attribute |
     * | `true`        | `narrow` — a bare boolean attribute |
     * | `false`, null | nothing at all        |
     *
     * The `''` and `null` rows are the distinction worth keeping straight: a public SoundCloud
     * track has no secret token, and `secret-token=""` is not the same thing to the client as no
     * attribute — so an absent value is `null`, and `''` stays a real empty value.
     */
    public function attr(
        AttributeName $attribute,
        string|int|bool|BackedEnum|null $value = true,
    ): self {
        if ($value === false || $value === null) {
            return $this;
        }

        // A backed enum stands for its value, so a call site passes CssClass::Hero rather than
        // remembering ->value — one fewer thing to get right at twenty call sites.
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return new self(
            $this->tag,
            [
                ...$this->attributes,
                $attribute->attribute() => $value === true ? null : htmlspecialchars((string) $value),
            ],
            $this->children,
        );
    }

    /**
     * Returns a copy containing the given children, appended in order.
     *
     * A bare string is content, not markup: it becomes a {@link Text} and is escaped. That is the
     * safe reading of the ambiguous case — markup passed as a string shows up as visible `&lt;b&gt;`
     * rather than as markup — and getting real markup in takes {@link RawHtml}, which says so.
     *
     * @throws MarkupException if the element is void; `<img>` cannot contain anything.
     */
    public function containing(Node|string ...$children): self
    {
        if ($this->tag->isVoid() && $children !== []) {
            throw new MarkupException(sprintf(
                '<%s> is a void element and cannot contain anything.',
                $this->tag->tagName(),
            ));
        }

        return new self($this->tag, $this->attributes, [
            ...$this->children,
            ...array_map(
                static fn(Node|string $child): Node => $child instanceof Node ? $child : new Text($child),
                $children,
            ),
        ]);
    }

    public function render(int $depth = 0): string
    {
        $open = '<' . $this->tag->tagName() . $this->renderAttributes() . '>';

        if ($this->tag->isVoid()) {
            return $open;
        }

        $close = '</' . $this->tag->tagName() . '>';

        if ($this->children === []) {
            return $open . $close;
        }

        return $open . $this->renderChildren($depth) . $close;
    }

    private function renderAttributes(): string
    {
        $rendered = '';

        foreach ($this->attributes as $name => $value) {
            $rendered .= $value === null ? ' ' . $name : ' ' . $name . '="' . $value . '"';
        }

        return $rendered;
    }

    /**
     * Renders the children, on one line or on several.
     *
     * Any {@link Text} among them forces one line: a newline before or after inline content is a
     * space the browser renders, so breaking `<p>E-Mail: <a>…</a></p>` across lines would change
     * the page rather than just its source.
     */
    private function renderChildren(int $depth): string
    {
        $inline = array_any($this->children, static fn(Node $child): bool => $child instanceof Text);

        if ($inline) {
            return implode('', array_map(
                static fn(Node $child): string => $child->render($depth),
                $this->children,
            ));
        }

        $pad      = str_repeat('  ', $depth);
        $rendered = '';

        foreach ($this->children as $child) {
            $rendered .= "\n" . $pad . '  ' . $child->render($depth + 1);
        }

        return $rendered . "\n" . $pad;
    }
}
