<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

use BackedEnum;
use Dom\HTMLDocument;
use Dom\HTMLElement;
use Dom\Node as DomNode;
use Dom\Text as DomText;
use NeuroSYS\Exception\MarkupException;
use NeuroSYS\Model\Embed\EmbedAttribute;
use NeuroSYS\Model\Embed\SoundCloudPlayerAttribute;
use NeuroSYS\Support\Collection;
use NeuroSYS\View\Terminal\TerminalAttribute;

/**
 * The MarkupParser class. Reads the grammar {@link Element} writes, back into the tree.
 *
 * This is what replaced `RawHtml`, the node that emitted a trusted string verbatim. That class
 * existed for the two halves of `data/privacy.*.html` — a hand-authored document rather than markup
 * a view assembles — and everything standing behind it was prose and an audit: a docblock saying
 * never to construct one from anything a request can influence, and a test pinning its call sites
 * to one file. A convention with a test behind it is not a guarantee, and it left one place where
 * the four mistakes {@link Element} exists to remove were all possible again.
 *
 * A parse makes the guarantee structural instead. The document comes in through the same door as
 * everything else: an element name has to be a {@link TagName} case, an attribute name has to be an
 * {@link AttributeName} case, text is escaped by {@link Text::render()}, and a URL attribute is
 * scheme-checked by {@link Element::render()} like any other. Nothing is trusted for where it came
 * from. Because the vocabulary is closed, an `onerror=` or a `<form>` that appears in a future
 * re-export is a {@link MarkupException} when the file loads rather than markup nobody read.
 *
 * **The refusals are the point, so they are exhaustive rather than illustrative** — the same stance
 * {@link \NeuroSYS\Support\TarArchive} takes about a member name that came off the network. An
 * unknown element, an unknown attribute, a comment, a CDATA section, an element from another
 * namespace, content hoisted into the document head, and any HTML5 parse error at all are each
 * refused rather than skipped, because a document this class quietly dropped half of is worse than
 * one it would not read.
 *
 * **What it costs, measured rather than assumed.** Per half of the policy, with no Xdebug loaded:
 * 0.071 ms to parse, 0.242 ms to walk into the tree, 0.262 ms for {@link Element::render()} to write
 * it back out — against the 0.004 ms the old verbatim `str_replace` took. So `/privacy` pays about
 * **+1.14 ms** for both halves, which is the largest single cost this site has taken for a
 * guarantee. It is affordable because it is one route out of ten and the least-visited page on the
 * site; it would not be affordable on a page anyone loads twice.
 *
 * The walk is **three times** that under Xdebug, which is the environment `docs/performance.md`
 * measures in and why the figure there is +3.4 ms rather than +1.1. Worth knowing before either
 * number is quoted at the other: the parse itself barely moves, because it happens in C.
 *
 * **No network flag is needed and none is expressible.** `createFromString()` accepts only
 * `LIBXML_NOERROR`, `LIBXML_COMPACT`, `LIBXML_HTML_NOIMPLIED` and `Dom\HTML_NO_DEFAULT_NS`, and
 * HTML5 has no DTD entity mechanism, so there is no external-entity door here to close. That is
 * worth stating rather than leaving to be re-derived, because it is the first question anybody
 * sensible asks about a DOM parser.
 */
final readonly class MarkupParser
{
    /**
     * The enums that spell an element name.
     *
     * Two, split the way {@link TagName} describes: {@link HtmlTag} is what the browser already
     * knows, {@link Tag} is this site's own vocabulary. Listed here rather than discovered by
     * reflection so that the set is a decision somebody made — and `HtmlTest` pins it against
     * reflection in both directions, so a third implementation that nobody adds here is a failing
     * test rather than an element that mysteriously will not parse.
     *
     * @var list<class-string<TagName&BackedEnum>>
     */
    private const array TAG_NAMES = [HtmlTag::class, Tag::class];

    /**
     * The enums that spell an attribute name.
     *
     * Nine, one per element that has attributes of its own plus {@link HtmlAttribute} for the
     * standard ones. Pinned against reflection exactly as {@link self::TAG_NAMES} is, and for a
     * sharper reason: a tenth enum left off this list would make every one of its attributes
     * unparseable, which reads as the *markup* being wrong.
     *
     * @var list<class-string<AttributeName&BackedEnum>>
     */
    private const array ATTRIBUTE_NAMES = [
        ArrangementAttribute::class,
        CardAttribute::class,
        CoverArtAttribute::class,
        EmbedAttribute::class,
        HtmlAttribute::class,
        LinkAttribute::class,
        SoundCloudPlayerAttribute::class,
        TerminalAttribute::class,
        WaveformAttribute::class,
    ];

    /**
     * Parses $html into nodes, refusing anything the tree cannot hold.
     *
     * Answers with a collection rather than a single node because a document is not an element: the
     * German policy is 140 top-level nodes. {@link Element::containingHtml()} is what puts them
     * somewhere, and is the only caller — a fact `HtmlTest` pins, the way it used to pin `RawHtml`'s
     * constructor. **Never parse anything a request can influence.** Not because this would let it
     * through — that is the whole point of the refusals — but because the vocabulary is this site's
     * own, so a visitor could otherwise decide which of our elements to build.
     *
     * @param string $html Markup, hand-authored and read from a file next to the code.
     * @return Collection<Node>
     * @throws MarkupException if the markup does not parse cleanly, or names anything outside the
     *                         two vocabularies. Loud on purpose and at load time on purpose: the
     *                         data file is part of this repository, so a refusal here means
     *                         something in it is written wrong.
     */
    public static function parse(string $html): Collection
    {
        $document = self::read($html);
        $hoisted  = $document->head?->firstChild;

        // A <title> or a <meta> in the fragment is not a parse error — the parser moves it into the
        // head, where a view cannot reach it and where it would vanish without a word. Both halves
        // of the policy leave the head empty, which is what makes this a check rather than a guess.
        if ($hoisted !== null) {
            // strtolower() because nodeName shouts an HTML element's name — `TITLE`, not `title` —
            // and localName, which would not, is an Element member where this is still a Node.
            throw new MarkupException(sprintf(
                '<%s> belongs in the document head, so parsing it here would silently drop it. '
                . 'Only content elements belong in markup a view parses.',
                strtolower($hoisted->nodeName),
            ));
        }

        // The parser implies <html>, <head> and <body> around any fragment, so the body is always
        // there — LIBXML_HTML_NOIMPLIED, the one option that would remove it, is deliberately not
        // passed. The document stands in for the null that cannot happen so that it flows into the
        // refusal below rather than needing a branch of its own, the way
        // Element::staysOnThisOrigin() handles a base URL that will not parse.
        return self::childrenOf($document->body ?? $document);
    }

    /**
     * Parses $html into a document, refusing it if the parser had to repair anything.
     *
     * Two things here are load-bearing.
     *
     * **The doctype is {@link Doctype::Html5}, not a literal.** It is what puts the parser in
     * no-quirks mode, and without it *every* fragment reports `unexpected-token-in-initial-mode` and
     * the error trap below is so much noise. Reusing the one class that owns that string also means
     * this file holds no `<` literal at all, so it needs no exemption from the verify script's check
     * that {@link Element} and {@link Doctype} are the only two files that write markup out.
     *
     * **The errors are trapped rather than ignored.** `Dom\HTMLDocument` reports HTML5 tokenizer and
     * tree errors as PHP warnings and then recovers silently, which is exactly the wrong behaviour
     * for a hand-edited legal document: a stray `</div>` swallows the rest of the policy and nothing
     * anywhere says so. Both halves parse with no errors at all, so refusing on any is affordable.
     *
     * Note the one offset in what a message reports: the doctype is prepended without a newline, so
     * a reported line number matches the file, and only a column on line 1 is out by its length.
     *
     * @param string $html
     * @return HTMLDocument
     * @throws MarkupException if the parser reported anything at all.
     */
    private static function read(string $html): HTMLDocument
    {
        $errors = [];

        set_error_handler(static function (int $severity, string $message) use (&$errors): bool {
            $errors[] = $message;

            return true;
        });

        try {
            $document = HTMLDocument::createFromString(Doctype::Html5->render() . $html);
        } finally {
            restore_error_handler();
        }

        if ($errors !== []) {
            throw new MarkupException(sprintf(
                'Markup this site emits has to parse cleanly, and this did not: %s',
                implode(' / ', $errors),
            ));
        }

        return $document;
    }

    /**
     * $parent's children, in order.
     *
     * @param DomNode $parent
     * @return Collection<Node>
     * @throws MarkupException if any descendant is something the tree cannot hold.
     */
    private static function childrenOf(DomNode $parent): Collection
    {
        $nodes = [];

        foreach ($parent->childNodes as $child) {
            $nodes[] = self::node($child);
        }

        return new Collection(Node::class)->with(...$nodes);
    }

    /**
     * One parsed node, as the {@link Node} it becomes.
     *
     * Three kinds go in and two come out, which is the whole check: an HTML element becomes an
     * {@link Element}, a run of text becomes a {@link Text}, and everything else is refused. That
     * last case is one test covering three hazards, because of how PHP's parser types what it
     * builds — an element from another namespace is a plain `Dom\Element` rather than a
     * `Dom\HTMLElement`, so SVG and MathML are refused by the same line that refuses a comment and a
     * CDATA section. None of the three has a node here to become, and inventing one is how a tree
     * starts carrying content nothing escapes.
     *
     * @param DomNode $node
     * @return Node
     * @throws MarkupException if $node is neither an HTML element nor text.
     */
    private static function node(DomNode $node): Node
    {
        if ($node instanceof DomText) {
            return new Text($node->data);
        }

        if (!$node instanceof HTMLElement) {
            throw new MarkupException(sprintf(
                '%s is not something the markup tree can hold. Parsed markup is elements and text; '
                . 'a comment, a CDATA section and an element from another namespace each have no '
                . 'node to become.',
                $node->nodeName,
            ));
        }

        return self::element($node);
    }

    /**
     * One parsed element, with its attributes and its subtree.
     *
     * Built through {@link Element::attr()} and {@link Element::containing()} rather than through
     * the constructor, deliberately: a parsed element is then exactly an element a view could have
     * written, and the void-element guard applies to it without being restated here.
     *
     * @param HTMLElement $element
     * @return Element
     * @throws MarkupException if the element or any of its attributes is outside the vocabulary.
     */
    private static function element(HTMLElement $element): Element
    {
        $tag = self::tagNamed($element->localName) ?? throw new MarkupException(sprintf(
            '<%s> is not an element this site emits. Add its case to one of: %s.',
            $element->localName,
            implode(', ', self::TAG_NAMES),
        ));

        // The one element refused for what it is rather than for being unknown, and the reason is
        // correctness rather than policy: a <script>'s content is raw text, and the only escaper
        // here is Text::render(), which would turn `a < b` into `a &lt; b` and change what the
        // script means. There is no node that renders raw text, so refusing is the honest answer.
        // <style> needs no line of its own — it has no HtmlTag case, so the vocabulary has it.
        if ($tag === HtmlTag::Script) {
            throw new MarkupException(sprintf(
                '<%s> cannot be parsed and rendered faithfully: its content is raw text, and the '
                . 'only escaping this tree has would change what the script means.',
                $tag->tagName(),
            ));
        }

        $built = new Element($tag);

        foreach ($element->attributes as $attribute) {
            $built = $built->attr(
                self::attributeNamed($attribute->localName) ?? throw new MarkupException(sprintf(
                    '<%s %s> is not an attribute this site emits. This is what refuses an event '
                    . 'handler. Add its case to one of: %s.',
                    $element->localName,
                    $attribute->localName,
                    implode(', ', self::ATTRIBUTE_NAMES),
                )),
                $attribute->value,
            );
        }

        return $built->containing(...self::childrenOf($element)->toValues());
    }

    /**
     * The {@link TagName} case $name spells, or null.
     *
     * **Asked with `tryFrom()` rather than by looking for a case whose `tagName()` matches**, which
     * is a shortcut only because a test makes it one: every case of every enum in both registries is
     * asserted to spell its own name as its backing value, so the two questions have one answer.
     * That buys a native O(1) lookup instead of a scan over some seventy cases per element — which
     * is worth having, since the walk is most of what this class costs.
     *
     * @param string $name
     * @return TagName|null
     */
    private static function tagNamed(string $name): ?TagName
    {
        foreach (self::TAG_NAMES as $enum) {
            $case = $enum::tryFrom($name);

            if ($case !== null) {
                return $case;
            }
        }

        return null;
    }

    /**
     * The {@link AttributeName} case $name spells, or null. See {@link self::tagNamed()}.
     *
     * @param string $name
     * @return AttributeName|null
     */
    private static function attributeNamed(string $name): ?AttributeName
    {
        foreach (self::ATTRIBUTE_NAMES as $enum) {
            $case = $enum::tryFrom($name);

            if ($case !== null) {
                return $case;
            }
        }

        return null;
    }
}
