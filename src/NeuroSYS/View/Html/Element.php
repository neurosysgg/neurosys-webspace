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
 * **Every guarantee is applied in {@link self::render()}, not in the builders.** That is what makes
 * this class the trust boundary it claims to be: `render()` is the only code on the site that turns
 * a node into markup, so a guarantee enforced there holds for *any* element however it was built —
 * including one assembled by handing the constructor an array, which the builders would otherwise
 * be the only thing standing in front of. Two are enforced:
 *
 * - **escaping**, by rendering each value as a {@link Text}, which is the site's single
 *   call to `htmlspecialchars`;
 * - **scheme**, for the attributes {@link AttributeName::isUrl()} marks, because escaping is the
 *   wrong tool for a URL and always was — `javascript:alert(1)` contains nothing to escape.
 *
 * Rendering pretty-prints. An element whose children are all elements puts each on its own line;
 * one with any {@link Text} among them stays on a single line, because whitespace between inline
 * content is content. That rule is why `<h1>ill<span>.</span></h1>` does not gain a space.
 */
final readonly class Element implements Node
{
    /**
     * The schemes a URL attribute may name, lower-cased.
     *
     * An allowlist, so the failure mode of anything unanticipated is refusal. That matters more
     * than it looks: browsers strip tabs and newlines from inside a scheme before resolving it, so
     * a denylist has to catch `jav&#9;ascript:` and every other spelling of the same word, while an
     * allowlist simply never says yes to it.
     *
     * These two plus site-relative cover every link the site emits — `https:` for HiDrive and the
     * profiles, `mailto:` for the footer and imprint, `/…` for everything of our own. Note what is
     * absent and why: `http:` because {@link \NeuroSYS\Http\Security\StrictTransportSecurity} means
     * we do not emit one, and `data:` because a `data:text/html` document runs script in the
     * origin that navigated to it.
     */
    private const array URL_SCHEMES = ['https:', 'mailto:'];

    /**
     * Constructs an instance of {@link self}.
     *
     * @param TagName $tag The element to build.
     * @param array<string,array{AttributeName,string|null}> $attributes The attributes, keyed by
     *                                         name — which keeps the last write and the declaration
     *                                         order — each holding the {@link AttributeName} it was
     *                                         set with and its **raw, unescaped** value. A null
     *                                         value is a boolean attribute, distinct from `''`,
     *                                         which is a real empty value like `options=""`. The
     *                                         name is carried alongside so that `render()` can ask
     *                                         it whether it is a URL. Built with {@link self::attr()};
     *                                         normally left empty.
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
     *
     * This only normalises and stores. Escaping and the URL check both happen in
     * {@link self::render()}, so neither can be got around by building an element another way.
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
                $attribute->attribute() => [$attribute, $value === true ? null : (string) $value],
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

    /**
     * Renders this element as markup.
     *
     * @throws MarkupException if a URL attribute names a scheme {@link self::URL_SCHEMES} does not
     *                         allow. Loud on purpose, and at the boundary on purpose: a link the
     *                         site refuses to draw is a missing link, which somebody notices, and a
     *                         `javascript:` href that renders is one nobody does.
     */
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

    /**
     * @throws MarkupException if a URL attribute carries a scheme that is not allowed.
     */
    private function renderAttributes(): string
    {
        $rendered = '';

        foreach ($this->attributes as $name => [$attribute, $value]) {
            if ($value === null) {
                $rendered .= ' ' . $name;
                continue;
            }

            if ($attribute->isUrl()) {
                $this->verifyUrl($name, $value);
            }

            // Escaped by rendering a Text, so the site has one call to htmlspecialchars, not two.
            // Correct here because an attribute value is always emitted inside double quotes.
            $rendered .= ' ' . $name . '="' . new Text($value)->render() . '"';
        }

        return $rendered;
    }

    /**
     * @throws MarkupException if $value names a scheme {@link self::URL_SCHEMES} does not allow.
     */
    private function verifyUrl(string $name, string $value): void
    {
        if (self::isAllowedUrl($value)) {
            return;
        }

        throw new MarkupException(sprintf(
            '<%s %s="%s"> is not a URL this site may emit. Allowed: a site-relative path, or %s.',
            $this->tag->tagName(),
            $name,
            $value,
            implode(' / ', self::URL_SCHEMES),
        ));
    }

    /** True if $value is a site-relative path or names an allowed scheme. */
    private static function isAllowedUrl(string $value): bool
    {
        // Checked before the `/` case below, and separately from it, because `//evil.example` is a
        // different origin wearing a path's clothes: it passes every `starts with a slash` test
        // written to mean "somewhere on this site", which is the same trap Navigation.ts has on
        // the client. A protocol-relative URL is never something this site needs to emit.
        if (str_starts_with($value, '//')) {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return true;
        }

        $lower = strtolower($value);

        return array_any(
            self::URL_SCHEMES,
            static fn(string $scheme): bool => str_starts_with($lower, $scheme),
        );
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
