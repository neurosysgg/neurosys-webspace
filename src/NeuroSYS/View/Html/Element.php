<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The Element class. One custom element, assembled from a {@link Tag} and typed attributes.
 *
 * Replaces the string concatenation this used to be — `'<soundcloud-player track-id="' . $id . '"'`
 * — for the same reason {@link \NeuroSYS\Http\Security\ContentSecurityPolicy} replaced a
 * hand-written header. Three mistakes stop being possible: a misspelled tag, a misspelled attribute,
 * and a value that reaches the markup unescaped. The last one is the dangerous one: escaping used to
 * be a `htmlspecialchars()` call per attribute at each call site, and forgetting one is an injection.
 *
 * Immutable, like the policies and the collections: every builder method returns a new instance.
 */
final readonly class Element
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Tag $tag The element to build.
     * @param array<string,string|null> $attributes Escaped values keyed by attribute name, which
     *                                         keeps the last write and the declaration order. A
     *                                         null value is a boolean attribute — distinct from
     *                                         `''`, which is a real empty value like `options=""`.
     *                                         Built with {@link self::with()}; normally left empty.
     * @param string $content Already-rendered inner HTML.
     */
    public function __construct(
        private Tag    $tag,
        private array  $attributes = [],
        private string $content    = '',
    ) {}

    /** Returns a copy carrying `$attribute="$value"`, escaped. */
    public function with(HtmlAttribute $attribute, string|int $value): self
    {
        return new self(
            $this->tag,
            [...$this->attributes, $attribute->attribute() => htmlspecialchars((string) $value)],
            $this->content,
        );
    }

    /**
     * Returns a copy carrying the attribute only if `$value` is not empty.
     *
     * A public SoundCloud track has no secret token, and `secret-token=""` is not the same thing to
     * the client as no attribute at all — so the empty case leaves it off rather than sending blank.
     */
    public function withOptional(HtmlAttribute $attribute, string $value): self
    {
        return $value === '' ? $this : $this->with($attribute, $value);
    }

    /** Returns a copy carrying `$attribute` as a bare boolean attribute when `$present`. */
    public function withFlag(HtmlAttribute $attribute, bool $present = true): self
    {
        if (!$present) {
            return $this;
        }

        return new self(
            $this->tag,
            [...$this->attributes, $attribute->attribute() => null],
            $this->content,
        );
    }

    /**
     * Returns a copy wrapping the given markup.
     *
     * Takes rendered HTML, so whatever built it owns its own escaping — the card tags wrap an `<a>`
     * that has to stay a real link. Elements that build their own subtree on the client never call
     * this: they carry attributes and nothing else.
     */
    public function containing(string $html): self
    {
        return new self($this->tag, $this->attributes, $html);
    }

    /** Renders the element. */
    public function render(): string
    {
        $attributes = '';

        foreach ($this->attributes as $name => $value) {
            $attributes .= $value === null ? ' ' . $name : ' ' . $name . '="' . $value . '"';
        }

        return '<' . $this->tag->value . $attributes . '>'
            . $this->content
            . '</' . $this->tag->value . '>';
    }
}
