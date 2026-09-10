<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

use BackedEnum;
use NeuroSYS\Exception\ElementException;
use NeuroSYS\Exception\MarkupException;
use NeuroSYS\Support\BareCall;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\SearchableCollection;
use NeuroSYS\Support\UrlScheme;
use NoDiscard;
use Uri\WhatWg\Url;

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
 * including one assembled by handing the constructor its attributes outright, which the builders
 * would otherwise be the only thing standing in front of. Two are enforced:
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
     *
     * **A list of cases rather than {@link UrlScheme::cases()}**, which would say the same thing
     * today and stop saying it the moment a scheme is added for one call site. This is what is
     * switched on; the enum is the vocabulary it may be written in — the distinction
     * {@link \NeuroSYS\Http\Security\CspScheme::Data} makes on the other side of the site, where
     * a case is kept for a source the policy deliberately no longer allows.
     *
     * @var list<UrlScheme>
     */
    private const array URL_SCHEMES = [UrlScheme::Https, UrlScheme::Mailto];

    /**
     * The host a site-relative URL is resolved against, and that host on its own.
     *
     * Not this site's origin, and deliberately not: the question a path-shaped value has to answer
     * is "does this stay wherever the page is served from?", which no address of ours is needed to
     * ask. `.invalid` is reserved by RFC 2606 and resolves nowhere, so nothing here can be mistaken
     * for somewhere to fetch from, and the class stays uncoupled from where the site is deployed.
     *
     * The host is its own constant because {@link self::staysOnThisOrigin()} compares against it
     * rather than against a second parse — which is what keeps that method free of a null branch
     * nothing can reach.
     */
    private const string BASE_HOST     = 'relative.invalid';
    private const string RELATIVE_BASE = 'https://' . self::BASE_HOST;

    /**
     * The attributes, keyed by name.
     *
     * Keyed rather than listed, which is what keeps **the last write and the declaration order**:
     * setting `class` twice leaves one attribute, where the first one was written. Not promoted,
     * for the reason {@link \NeuroSYS\Model\Embed\SoundCloudEmbed::$options} is not — the default
     * is a `new`, and a parameter default has to be a constant expression.
     *
     * @var SearchableCollection<Attribute>
     */
    private SearchableCollection $attributes;

    /**
     * The element's content, in the order it was added.
     *
     * A `Collection` for the reason {@link self::$attributes} is a `SearchableCollection`, and it
     * is the same argument on the parameter beside it: {@link self::containing()} is a variadic and
     * so is checked by PHP, but the constructor is public and took a plain `array` whose `list<Node>`
     * was a docblock's promise. A string reaching it that way is not a `TypeError` naming the
     * element, it is a fatal in {@link self::renderChildren()} calling `render()` on a string.
     *
     * Listed rather than keyed, unlike the attributes: children have order and no names, and
     * nothing here overwrites one. Not promoted, for the same reason — the default is a `new`, and
     * a parameter default has to be a constant expression.
     *
     * @var Collection<Node>
     */
    private Collection $children;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param TagName $tag The element to build.
     * @param SearchableCollection<Attribute>|null $attributes Normally left null and built with
     *                                         {@link self::attr()}. Keyed by the attribute's name.
     * @param Collection<Node>|null $children The element's content. Normally left null and built
     *                                        with {@link self::containing()}.
     */
    public function __construct(
        private TagName $tag,
        ?SearchableCollection $attributes = null,
        ?Collection $children = null,
    ) {
        $this->attributes = $attributes ?? new SearchableCollection(Attribute::class);
        $this->children   = $children   ?? new Collection(Node::class);
    }

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
     * | `new ViewportContent(…)`, any {@link AttributeValue} | what it renders |
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
     *
     * @param AttributeName $attribute
     * @param string|int|bool|BackedEnum|AttributeValue|null $value
     * @return self
     */
    #[NoDiscard('attr() returns a copy carrying the attribute; the element it was called on is unchanged')]
    public function attr(
        AttributeName $attribute,
        string|int|bool|BackedEnum|AttributeValue|null $value = true,
    ): self {
        if ($value === false || $value === null) {
            return $this;
        }

        // Both of these normalise; neither guarantees anything. That is why they are here and not
        // in render(), where the escaping and the scheme check live: what those two protect has to
        // hold for an element built any way at all, and what these two do is only ever shorthand
        // for the string a call site would otherwise have written out.
        //
        // A value with parts renders itself, so the grammar lives in one class instead of in the
        // call — see AttributeValue.
        if ($value instanceof AttributeValue) {
            $value = $value->render();
        }

        // A backed enum stands for its value, so a call site passes CssClass::Hero rather than
        // remembering ->value — one fewer thing to get right at twenty call sites.
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return new self(
            $this->tag,
            $this->attributes->with(
                $attribute->attribute(),
                new Attribute($attribute, $value === true ? null : (string) $value),
            ),
            $this->children,
        );
    }

    /**
     * Returns a copy containing the given children, appended in order.
     *
     * A bare string is content, not markup: it becomes a {@link Text} and is escaped. That is the
     * safe reading of the ambiguous case — markup passed as a string shows up as visible `&lt;b&gt;`
     * rather than as markup — and getting real markup in takes {@link self::containingHtml()},
     * which parses it rather than trusting it.
     *
     * @param Node|string ...$children
     * @return self
     * @throws ElementException if the element is void; `<img>` cannot contain anything.
     */
    #[NoDiscard('containing() returns a copy holding the children; the element it was called on is unchanged')]
    #[BareCall(
        'array_map',
        'maps the variadic PHP has already guarded, straight into with() — so a Collection here '
        . 'would be constructed only to be spread back out on the same line. This is also the '
        . 'hottest path on the site: every element of every page is built through it, and '
        . "docs/collections.md's note that renderChildren() is the one place to spend a foreach is about "
        . 'these two lines.',
    )]
    public function containing(Node|string ...$children): self
    {
        if ($this->tag->isVoid() && $children !== []) {
            throw new ElementException(sprintf(
                '<%s> is a void element and cannot contain anything.',
                $this->tag->tagName(),
            ));
        }

        return new self($this->tag, $this->attributes, $this->children->with(
            ...array_map(
                static fn(Node|string $child): Node => $child instanceof Node ? $child : new Text($child),
                $children,
            ),
        ));
    }

    /**
     * Returns a copy containing $html, parsed into nodes.
     *
     * The safe twin of {@link self::containing()}, and the pair is worth reading together:
     * `containing('<b>x</b>')` puts visible `&lt;b&gt;` on the page, because a string is content;
     * this parses the same argument into a real `<b>` — after checking that `b` is an element this
     * site emits, that everything on it is an attribute this site emits, and that the parser had to
     * repair nothing to read it. See {@link MarkupParser}, which is where all of that lives.
     *
     * **This is the whole of what replaced `RawHtml`**, and the standing instruction survived the
     * change: never hand it anything a request can influence. The refusals mean it would not be an
     * injection, but the vocabulary being this site's own means a visitor would otherwise get to
     * choose which of our elements to build.
     *
     * The parsed nodes become children of *this* element rather than being wrapped in a
     * {@link Fragment}, which is what keeps a document coming back out as it went in: a parse keeps
     * the source's own whitespace as {@link Text}, and a `Text` among the children is what puts
     * {@link self::renderChildren()} on its single-line branch, where nothing is re-indented and no
     * whitespace is invented between inline content.
     *
     * @param string $html Markup, hand-authored and read from a file next to the code.
     * @return self
     * @throws MarkupException if the element is void, or if $html names anything outside the two
     *                         vocabularies, or if it does not parse cleanly.
     */
    #[NoDiscard('containingHtml() returns a copy holding the parsed markup; the element it was called on is unchanged')]
    public function containingHtml(string $html): self
    {
        // toValues() rather than a bare spread, because a spread of string keys is named arguments —
        // the rule every spreading call site here follows. A list has none, and says so anyway.
        return $this->containing(...MarkupParser::parse($html)->toValues());
    }

    /**
     * Renders this element as markup.
     *
     * @param int $depth
     * @return string
     * @throws ElementException if a URL attribute names a scheme {@link self::URL_SCHEMES} does not
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

        if ($this->children->isEmpty()) {
            return $open . $close;
        }

        return $open . $this->renderChildren($depth) . $close;
    }

    /**
     *
     * @return string
     * @throws ElementException if a URL attribute carries a scheme that is not allowed.
     */
    private function renderAttributes(): string
    {
        $rendered = '';

        foreach ($this->attributes as $name => $attribute) {
            if ($attribute->isBoolean()) {
                $rendered .= ' ' . $name;
                continue;
            }

            $value = (string) $attribute->value;

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
     *
     * @param string $name
     * @param string $value
     * @return void
     * @throws ElementException if $value names a scheme {@link self::URL_SCHEMES} does not allow.
     */
    #[BareCall(
        'array_map',
        'maps a class constant for the reason Layout::modulePreloads() does, and does it on the '
        . 'throwing branch — the schemes are being listed into a refusal, so this is work done '
        . 'only on the path where the site is already wrong.',
    )]
    private function verifyUrl(string $name, string $value): void
    {
        if (self::isAllowedUrl($value)) {
            return;
        }

        throw new ElementException(sprintf(
            '<%s %s="%s"> is not a URL this site may emit. Allowed: a site-relative path, or %s.',
            $this->tag->tagName(),
            $name,
            $value,
            implode(' / ', array_map(
                static fn(UrlScheme $scheme): string => $scheme->value,
                self::URL_SCHEMES,
            )),
        ));
    }

    /**
     * True if $value is a site-relative path or names an allowed scheme.
     *
     * @param string $value
     * @return bool
     */
    private static function isAllowedUrl(string $value): bool
    {
        // A leading slash is not the same claim as "somewhere on this site", so it is asked rather
        // than assumed — see staysOnThisOrigin(). Everything else has to name a scheme we allow.
        if (str_starts_with($value, '/')) {
            return self::staysOnThisOrigin($value);
        }

        $lower = strtolower($value);

        return array_any(
            self::URL_SCHEMES,
            static fn(UrlScheme $scheme): bool => str_starts_with($lower, $scheme->value),
        );
    }

    /**
     * True if a path-shaped $value resolves to the origin it was resolved against.
     *
     * This used to be a two-entry list of the prefixes an authority can open with — `//` and `/\`,
     * the second being the same URL spelled the way that does not look like it. A list of the
     * spellings that occurred to us is exactly the shape of mistake this class is arranged to
     * avoid, and it had missed one: the WHATWG parser strips tab, CR and LF from a URL *before*
     * parsing it, so `/\r\n/evil.example` is `//evil.example` is `https://evil.example`, and every
     * "starts with a slash" test in the world says it is a path on this site.
     *
     * PHP 8.5 ships that parser, so the question is now put to it instead of pattern-matched: the
     * value is resolved the way a browser would resolve it, and the answer is whether it landed
     * where it started. `Navigation.ts` has done it this way round on the client all along — for
     * want of a URL parser it was the stronger half, and now both halves are the same check.
     *
     * @param string $value
     * @return bool
     */
    private static function staysOnThisOrigin(string $value): bool
    {
        // A null base makes a relative reference unparseable, so the null this returns fails the
        // comparison below rather than needing a branch of its own. The constant is a literal
        // origin; it parses.
        $resolved = Url::parse($value, Url::parse(self::RELATIVE_BASE));

        return $resolved?->getAsciiHost() === self::BASE_HOST;
    }

    /**
     * Renders the children, on one line or on several.
     *
     * Any {@link Text} among them forces one line: a newline before or after inline content is a
     * space the browser renders, so breaking `<p>E-Mail: <a>…</a></p>` across lines would change
     * the page rather than just its source.
     *
     * @param int $depth
     * @return string
     */
    private function renderChildren(int $depth): string
    {
        $inline = $this->children->first(static fn(Node $child): bool => $child instanceof Text);

        if ($inline !== null) {
            return $this->children->map(static fn(Node $child): string => $child->render($depth))->join('');
        }

        $pad      = str_repeat('  ', $depth);
        $rendered = '';

        foreach ($this->children as $child) {
            $rendered .= "\n" . $pad . '  ' . $child->render($depth + 1);
        }

        return $rendered . "\n" . $pad;
    }
}
