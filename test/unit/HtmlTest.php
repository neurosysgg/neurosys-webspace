<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use BackedEnum;
use FilesystemIterator;
use NeuroSYS\Exception\MarkupException;
use NeuroSYS\Model\Embed\SoundCloudPlayerAttribute;
use NeuroSYS\Model\Production\SectionKind;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\SearchableCollection;
use NeuroSYS\Support\UrlScheme;
use NeuroSYS\View\Html\ArrangementAttribute;
use NeuroSYS\View\Html\Attribute;
use NeuroSYS\View\Html\AttributeName;
use NeuroSYS\View\Html\AttributeValue;
use NeuroSYS\View\Html\CardAttribute;
use NeuroSYS\View\Html\CoverArtAttribute;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Doctype;
use NeuroSYS\View\Html\Document;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\Fragment;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\LinkAttribute;
use NeuroSYS\View\Html\LinkRel;
use NeuroSYS\View\Html\LinkTarget;
use NeuroSYS\View\Html\MediaPreload;
use NeuroSYS\View\Html\MetaName;
use NeuroSYS\View\Html\Node;
use NeuroSYS\View\Html\RawHtml;
use NeuroSYS\View\Html\ScriptType;
use NeuroSYS\View\Html\Tag;
use NeuroSYS\View\Html\Text;
use NeuroSYS\View\Html\ViewportContent;
use NeuroSYS\View\Html\ViewportWidth;
use NeuroSYS\View\Terminal\TerminalAttribute;
use NeuroSYS\View\Terminal\TerminalTone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use TypeError;

/**
 * The markup tree: every page is one of these, so what it can and cannot do is what the site can
 * and cannot emit.
 */
#[CoversClass(Element::class)]
#[CoversClass(ViewportContent::class)]
#[CoversClass(UrlScheme::class)]
#[CoversClass(Text::class)]
#[CoversClass(RawHtml::class)]
#[CoversClass(Fragment::class)]
#[CoversClass(Document::class)]
#[CoversClass(Doctype::class)]
#[CoversClass(HtmlTag::class)]
#[CoversClass(HtmlAttribute::class)]
#[CoversClass(CssClass::class)]
#[CoversClass(Tag::class)]
#[CoversClass(CardAttribute::class)]
#[CoversClass(CoverArtAttribute::class)]
#[CoversClass(LinkAttribute::class)]
#[CoversClass(TerminalAttribute::class)]
#[CoversClass(SoundCloudPlayerAttribute::class)]
#[CoversClass(MetaName::class)]
#[CoversClass(MediaPreload::class)]
final class HtmlTest extends TestCase
{
    // ───────────────────────────── attributes ─────────────────────────────

    /**
     * @return void
     */
    public function testAnElementRendersItsTagAndAttributes(): void
    {
        self::assertSame(
            '<cover-art src="/a.png" alt="a"></cover-art>',
            new Element(Tag::CoverArt)
                ->attr(CoverArtAttribute::Src, '/a.png')
                ->attr(CoverArtAttribute::Alt, 'a')
                ->render(),
        );
    }

    /**
     * The reason this class exists. Escaping used to be a htmlspecialchars() call per attribute at
     * every call site, and forgetting one is an injection — so it happens here, once, or not at all.
     *
     * @return void
     */
    public function testAnAttributeValueCannotBreakOutOfItsAttribute(): void
    {
        $html = new Element(Tag::CoverArt)
            ->attr(CoverArtAttribute::Alt, '" onload="alert(1)')
            ->render();

        self::assertSame('<cover-art alt="&quot; onload=&quot;alert(1)"></cover-art>', $html);
    }

    /**
     * true is a bare attribute, '' is a real empty value, and the two must not collapse.
     *
     * @return void
     */
    public function testABooleanAttributeIsBareAndAnEmptyValueIsNot(): void
    {
        self::assertSame(
            '<terminal-window command="" narrow></terminal-window>',
            new Element(Tag::TerminalWindow)
                ->attr(TerminalAttribute::Command, '')
                ->attr(TerminalAttribute::Narrow)
                ->render(),
        );
    }

    /**
     * @return void
     */
    public function testFalseAndNullBothLeaveTheAttributeOffEntirely(): void
    {
        self::assertSame(
            '<terminal-window></terminal-window>',
            new Element(Tag::TerminalWindow)
                ->attr(TerminalAttribute::Narrow, false)
                ->attr(TerminalAttribute::Command, null)
                ->render(),
        );
    }

    /**
     * @return void
     */
    public function testAnIntegerValueRendersAsItsDigits(): void
    {
        self::assertSame(
            '<img height="56">',
            new Element(HtmlTag::Img)->attr(HtmlAttribute::Height, 56)->render(),
        );
    }

    /**
     * @return void
     */
    public function testTheSameAttributeTwiceKeepsTheLastValueAndItsPosition(): void
    {
        self::assertSame(
            '<cover-art src="/b.png" alt="a"></cover-art>',
            new Element(Tag::CoverArt)
                ->attr(CoverArtAttribute::Src, '/a.png')
                ->attr(CoverArtAttribute::Alt, 'a')
                ->attr(CoverArtAttribute::Src, '/b.png')
                ->render(),
        );
    }

    /**
     * Immutable like the policies and the collections — every builder method returns a copy.
     *
     * @return void
     */
    public function testBuildingDoesNotMutateTheElementBuiltFrom(): void
    {
        $empty = new Element(Tag::CoverArt);
        (void) $empty->attr(CoverArtAttribute::Src, '/a.png');
        (void) $empty->containing('x');

        self::assertSame('<cover-art></cover-art>', $empty->render());
    }

    /**
     * A backed enum stands for its value, so a call site passes CssClass::Bang rather than
     * remembering ->value — one fewer thing to get right at twenty call sites.
     *
     * @return void
     */
    public function testABackedEnumValueRendersAsItsBackingValue(): void
    {
        self::assertSame(
            '<p class="bang"></p>',
            new Element(HtmlTag::P)->attr(HtmlAttribute::ClassName, CssClass::Bang)->render(),
        );
    }

    /**
     * An enum value is escaped on the same path a string is; nothing gets in around it.
     *
     * @return void
     */
    public function testABackedEnumValueGoesThroughTheSameEscaping(): void
    {
        $enum = new Element(HtmlTag::P)->attr(HtmlAttribute::ClassName, CssClass::Bang)->render();
        $text = new Element(HtmlTag::P)->attr(HtmlAttribute::ClassName, 'bang')->render();

        self::assertSame($text, $enum);
    }

    // ───────────────────────── the names themselves ─────────────────────────

    /**
     * Every attribute name is a case rather than a string typed out at each call site, and
     * {@link AttributeName} is what lets {@link Element} take any of them without knowing which
     * element it is building. A name that renders as something other than its backing value would
     * be a silent null on the client, so assert the two are the same thing.
     *
     * @param AttributeName&BackedEnum $name
     * @return void
     */
    #[DataProvider('attributeNameProvider')]
    public function testAnAttributeNameRendersAsItsBackingValue(AttributeName&BackedEnum $name): void
    {
        // A URL attribute is scheme-checked on the way out, so it gets a value that is one. The
        // name is what this test is about either way; that the check fires is asserted below.
        $value = $name->isUrl() ? '/x' : 'x';

        self::assertSame($name->value, $name->attribute());
        self::assertStringContainsString(
            $name->attribute() . '="' . $value . '"',
            new Element(HtmlTag::P)->attr($name, $value)->render(),
        );
    }

    /**
     * @return iterable
     */
    public static function attributeNameProvider(): iterable
    {
        foreach (
            [
                CardAttribute::class,
                CoverArtAttribute::class,
                LinkAttribute::class,
                TerminalAttribute::class,
                SoundCloudPlayerAttribute::class,
                HtmlAttribute::class,
            ] as $enum
        ) {
            // $enum is a class-string, so this is a static call on it and not an
            // instantiation — an enum cannot be constructed at all.
            foreach ($enum::cases() as $case) {
                yield $enum . '::' . $case->name => [$case];
            }
        }
    }

    /**
     * A custom element the browser has never heard of renders as an inert inline box with no error
     * anywhere, so the tag name is a contract with `assets/ts/elements/` that fails in silence.
     */
    /**
     * `rel` is the one attribute value on this site that is a set rather than a single fact, so the
     * enum builds the list and the call site does not. Pinned in the order the markup reads.
     *
     * @return void
     */
    public function testLinkRelationsJoinIntoOneAttributeValue(): void
    {
        self::assertSame(
            'noopener noreferrer external',
            LinkRel::tokens(LinkRel::NoOpener, LinkRel::NoReferrer, LinkRel::External),
        );
        self::assertSame('stylesheet', LinkRel::tokens(LinkRel::Stylesheet));
        self::assertSame('', LinkRel::tokens());
    }

    /**
     * A value enum reaches the markup as its backing value with nothing in between —
     * {@link Element::attr()} unwraps any BackedEnum, which is what lets these be passed as cases
     * rather than as `->value` at every call site.
     *
     * @param AttributeName $attribute
     * @param BackedEnum $value
     * @return void
     */
    #[DataProvider('attributeValueProvider')]
    public function testAnAttributeValueRendersAsItsBackingValue(
        AttributeName $attribute,
        BackedEnum $value,
    ): void {
        self::assertSame(
            '<a ' . $attribute->attribute() . '="' . $value->value . '"></a>',
            new Element(HtmlTag::A)->attr($attribute, $value)->render(),
        );
    }

    /** @return iterable<string, array{AttributeName, BackedEnum}> */
    public static function attributeValueProvider(): iterable
    {
        foreach (LinkRel::cases() as $case) {
            yield 'LinkRel::' . $case->name => [HtmlAttribute::Rel, $case];
        }
        foreach (LinkTarget::cases() as $case) {
            yield 'LinkTarget::' . $case->name => [HtmlAttribute::Target, $case];
        }
        foreach (ScriptType::cases() as $case) {
            yield 'ScriptType::' . $case->name => [HtmlAttribute::Type, $case];
        }
        foreach (MetaName::cases() as $case) {
            yield 'MetaName::' . $case->name => [HtmlAttribute::Name, $case];
        }
        foreach (MediaPreload::cases() as $case) {
            yield 'MediaPreload::' . $case->name => [HtmlAttribute::Preload, $case];
        }
    }

    /**
     * @return void
     */
    public function testEveryCustomTagRendersAsItsBackingValue(): void
    {
        foreach (Tag::cases() as $tag) {
            self::assertSame($tag->value, $tag->tagName());
            self::assertSame("<{$tag->value}></{$tag->value}>", new Element($tag)->render());
        }
    }

    /**
     * Never void. A custom element with no closing tag is a parse error the browser recovers from
     * by swallowing everything after it, which is about as quiet as a failure gets.
     *
     * @return void
     */
    public function testNoCustomTagIsVoid(): void
    {
        foreach (Tag::cases() as $tag) {
            self::assertFalse($tag->isVoid(), "<{$tag->value}> would render without a closing tag");
        }
    }

    // ───────────────────────────── content ─────────────────────────────

    /**
     * @return void
     */
    public function testTextIsEscaped(): void
    {
        self::assertSame('rock &amp; &lt;roll&gt;', new Text('rock & <roll>')->render());
    }

    /**
     * The safe reading of the ambiguous case: a string child is content, never markup. Markup
     * passed as a string shows up as visible &lt;b&gt; — wrong on the page, but visibly wrong,
     * which is the failure mode to prefer.
     *
     * @return void
     */
    public function testAStringChildIsEscapedTextRatherThanMarkup(): void
    {
        self::assertSame(
            '<p>&lt;b&gt;bold&lt;/b&gt;</p>',
            new Element(HtmlTag::P)->containing('<b>bold</b>')->render(),
        );
    }

    /**
     * @return void
     */
    public function testAVoidElementHasNoClosingTag(): void
    {
        self::assertSame(
            '<meta charset="UTF-8">',
            new Element(HtmlTag::Meta)->attr(HtmlAttribute::Charset, 'UTF-8')->render(),
        );
    }

    /**
     * <img>text</img> is not markup the browser fixes — it is markup it reinterprets.
     *
     * @return void
     */
    public function testAVoidElementRefusesChildren(): void
    {
        $this->expectException(MarkupException::class);
        $this->expectExceptionMessageIsOrContains('<img>');

        (void) new Element(HtmlTag::Img)->containing('x');
    }

    /**
     * The children are a `Collection<Node>`, so the constructor is checked and not merely annotated.
     *
     * `containing()` is a variadic and PHP has always guarded it; the constructor took a plain
     * `array` whose `list<Node>` lived in a docblock, which is the arrangement the attributes were
     * moved out of one parameter earlier on the same signature. A string getting in that way was
     * not a TypeError naming the element — it was a fatal in `renderChildren()` calling `render()`
     * on a string, at whatever depth of the tree it happened to sit.
     *
     * @return void
     */
    public function testTheChildrenAreTypeCheckedAndNotJustDocumented(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains(Node::class);

        (void) new Collection(Node::class)->with('<b>not a node</b>');
    }

    /**
     * The element carries whatever children it is handed, and renders them in order.
     *
     * @return void
     */
    public function testTheConstructorTakesAChildCollection(): void
    {
        $children = new Collection(Node::class)->with(new Text('a'), new Element(HtmlTag::Br));

        self::assertSame(
            '<p>a<br></p>',
            new Element(HtmlTag::P, null, $children)->render(),
        );
    }

    /**
     * A custom element is never void: no closing tag means the browser swallows what follows.
     *
     * @return void
     */
    public function testNoCustomElementIsVoid(): void
    {
        foreach (Tag::cases() as $tag) {
            self::assertFalse($tag->isVoid(), $tag->value . ' must not be void');
        }
    }

    // ───────────────────────────── layout ─────────────────────────────

    /**
     * Whitespace between inline content is content, so an element with any text in it stays on one
     * line. Breaking this would put a space inside 'ill.' — between the name and its accented mark.
     *
     * @return void
     */
    public function testAnElementWithTextInItStaysOnOneLine(): void
    {
        self::assertSame(
            '<h1>ill<span class="bang">.</span></h1>',
            new Element(HtmlTag::H1)->containing(
                'ill',
                new Element(HtmlTag::Span)->attr(HtmlAttribute::ClassName, 'bang')->containing('.'),
            )->render(),
        );
    }

    /**
     * @return void
     */
    public function testAnElementOfOnlyElementsPutsEachOnItsOwnLine(): void
    {
        self::assertSame(
            "<section>\n  <p>a</p>\n  <p>b</p>\n</section>",
            new Element(HtmlTag::Section)->containing(
                new Element(HtmlTag::P)->containing('a'),
                new Element(HtmlTag::P)->containing('b'),
            )->render(),
        );
    }

    /**
     * Every node is handed its depth and indents its own continuation lines, at any nesting.
     *
     * @return void
     */
    public function testNestingIndentsAllTheWayDown(): void
    {
        self::assertSame(
            "<section>\n  <nav>\n    <p>a</p>\n  </nav>\n</section>",
            new Element(HtmlTag::Section)->containing(
                new Element(HtmlTag::Nav)->containing(new Element(HtmlTag::P)->containing('a')),
            )->render(),
        );
    }

    /**
     * @return void
     */
    public function testAnEmptyElementIsOpenedAndClosedOnOneLine(): void
    {
        self::assertSame('<section></section>', new Element(HtmlTag::Section)->render());
    }

    // ───────────────────────────── fragments and documents ─────────────────────────────

    /**
     * @return void
     */
    public function testAFragmentRendersItsNodesWithNoWrapper(): void
    {
        self::assertSame(
            "<p>a</p>\n<p>b</p>",
            new Fragment(
                new Element(HtmlTag::P)->containing('a'),
                new Element(HtmlTag::P)->containing('b'),
            )->render(),
        );
    }

    /**
     * @return void
     */
    public function testAFragmentInsideAnElementIndentsWithIt(): void
    {
        self::assertSame(
            "<section>\n  <p>a</p>\n  <p>b</p>\n</section>",
            new Element(HtmlTag::Section)->containing(
                new Fragment(
                    new Element(HtmlTag::P)->containing('a'),
                    new Element(HtmlTag::P)->containing('b'),
                ),
            )->render(),
        );
    }

    /**
     * @return void
     */
    public function testFragmentEachMapsItemsToNodes(): void
    {
        self::assertSame(
            "<p>a</p>\n<p>b</p>",
            Fragment::each(
                ['a', 'b'],
                static fn(string $t): Element => new Element(HtmlTag::P)->containing($t),
            )->render(),
        );
    }

    /**
     * @return void
     */
    public function testADocumentLeadsWithTheDoctype(): void
    {
        self::assertSame(
            "<!DOCTYPE html>\n<html></html>",
            new Document(new Element(HtmlTag::Html))->render(),
        );
    }

    /**
     * Quirks mode is what a wrong one buys, silently, on every layout calculation on the page.
     *
     * @return void
     */
    public function testTheDoctypeIsHtml5AndThereIsOnlyOne(): void
    {
        self::assertSame([Doctype::Html5], Doctype::cases());
        self::assertSame('<!DOCTYPE html>', Doctype::Html5->render());
    }

    // ───────────────────────────── class names ─────────────────────────────

    /**
     * The one mirror that can be checked against its actual reader.
     *
     * A misspelled class errors nowhere — the element just renders unstyled, which on a dark page
     * reads as a layout bug rather than a typo. Both directions matter and fail differently: a case
     * the stylesheet never mentions is an element styled by nothing, and a selector no case names is
     * a rule that can never match. The second is how dead CSS accumulates.
     *
     * @return void
     */
    public function testEveryClassNameIsStyledAndEveryStyledClassIsNamed(): void
    {
        $declared = array_map(static fn(CssClass $c): string => $c->value, CssClass::cases());
        $styled   = self::classSelectors();

        sort($declared);
        sort($styled);

        self::assertNotEmpty($styled, 'found no class selectors at all — the scan is broken');
        self::assertSame($declared, $styled);
    }

    /** @return list<string> Every class the stylesheet selects on, comments stripped first. */
    private static function classSelectors(): array
    {
        $css = file_get_contents(NEUROSYS_ROOT . '/public/assets/css/style.css');

        self::assertIsString($css);

        // Comments first: this file's own header names .out and .dot, which no rule has used since
        // the terminal moved client-side, and a scan that counted those would be measuring prose.
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';

        preg_match_all('/\.([a-z][a-z0-9-]*)/', $css, $matches);

        return array_values(array_unique($matches[1]));
    }

    // ───────────────────────────── the stylesheet's parts ─────────────────────────────

    /**
     * The other half of the class check above, for the tags — and the half that did not exist.
     *
     * A tag name in the stylesheet is a bare string with nothing on the other end of it. Rename a
     * case here and the PHP↔TS parity test catches the element; the stylesheet just quietly stops
     * matching, and an unstyled custom element on a dark page reads as a layout bug rather than a
     * typo. Both directions again: a case nothing styles is an element with no look, and a selector
     * naming no case is a rule that can never match.
     *
     * @return void
     */
    public function testEveryTagIsStyledAndEveryStyledTagIsATagCase(): void
    {
        $declared = array_map(static fn(Tag $t): string => $t->value, Tag::cases());
        $styled   = self::tagSelectors(self::stylesheet());

        sort($declared);
        sort($styled);

        self::assertNotEmpty($styled, 'found no custom-element selectors at all — the scan is broken');
        self::assertSame($declared, $styled);
    }

    /**
     * Every tag is styled by exactly one part, so "where is this styled?" has one answer.
     *
     * Two parts naming a tag is the failure worth naming: whichever comes later in main.css wins,
     * silently, and the loser reads as a rule that simply does not apply. The build refuses to
     * import a part twice for the same reason; this is the same rule one level down.
     *
     * @return void
     */
    public function testEveryTagIsStyledByExactlyOnePart(): void
    {
        $owners = [];

        foreach (self::elementParts() as $part => $css) {
            foreach (self::tagSelectors($css) as $tag) {
                $owners[$tag][] = $part;
            }
        }

        $shared = array_filter($owners, static fn(array $parts): bool => count($parts) > 1);
        $shared = array_map(static fn(array $parts): string => implode(' and ', $parts), $shared);

        self::assertSame([], $shared, 'styled by more than one part');

        $unowned = array_diff(
            array_map(static fn(Tag $t): string => $t->value, Tag::cases()),
            array_keys($owners),
        );

        self::assertSame([], array_values($unowned), 'named by no part under assets/css/elements/');
    }

    /**
     * A part is named for its component, and card.css is the one that is not.
     *
     * assets/css/elements/ mirrors assets/ts/elements/ at the component level, because there the
     * directory is the component and not the file. So a part named for one may only style tags whose
     * modules live in it — a rule wandering into the wrong file is how a component stops being one.
     *
     * card.css is the single exception, and is meant to be conspicuous the way RawHtml is: the
     * catalogue entry and the download entry genuinely share a look across two component
     * directories. Pinned here, so a second concept-named part has to be argued for in this test.
     *
     * @return void
     */
    public function testAPartStylesOnlyItsOwnComponentAndCardIsTheOneException(): void
    {
        $exceptions = [];
        $strays     = [];

        foreach (self::elementParts() as $part => $css) {
            $component = basename($part, '.css');
            $directory = NEUROSYS_ROOT . '/assets/ts/elements/' . $component;
            $module    = $directory . '.ts';

            if (!is_dir($directory) && !is_file($module)) {
                $exceptions[] = basename($part);

                continue;
            }

            foreach (self::tagSelectors($css) as $tag) {
                if (!is_file(self::moduleFor($tag))) {
                    self::fail("no element module registers <{$tag}>, which {$part} styles");
                }

                if (!str_starts_with(self::moduleFor($tag), $directory)) {
                    $strays[] = "{$part} styles <{$tag}>";
                }
            }
        }

        self::assertSame([], $strays, 'a part styling a tag from another component');
        self::assertSame(['card.css'], $exceptions, 'parts named for a concept rather than a component');
    }

    /**
     * The one file the browser loads, which is the concatenation of every part.
     *
     * @return string
     */
    private static function stylesheet(): string
    {
        $css = file_get_contents(NEUROSYS_ROOT . '/public/assets/css/style.css');

        self::assertIsString($css);

        return $css;
    }

    /**
     * @return array<string, string> Every part under assets/css/elements/, keyed by its path from
     *                               assets/css/ — the same name main.css imports it under.
     */
    private static function elementParts(): array
    {
        $parts = glob(NEUROSYS_ROOT . '/assets/css/elements/*.css');

        self::assertIsArray($parts);
        self::assertNotEmpty($parts, 'found no element parts at all — the scan is broken');

        $sources = [];

        foreach ($parts as $part) {
            $css = file_get_contents($part);

            self::assertIsString($css);

            $sources['elements/' . basename($part)] = $css;
        }

        ksort($sources);

        return $sources;
    }

    /**
     * The module that registers a tag, or '' if none does.
     *
     * A tag is its class in kebab-case and the class is the file, so this could be derived — but
     * <soundcloud-player> is `SoundCloudPlayer`, which no casing rule produces. Scanning for the
     * file that exists sidesteps the exception rather than spelling it, and it is the same walk the
     * RawHtml check below does.
     *
     * @param string $tag
     * @return string
     */
    private static function moduleFor(string $tag): string
    {
        $class = str_replace('-', ' ', $tag)
                |> ucwords(...)
                |> (fn($x) => str_replace(' ', '', $x));

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(NEUROSYS_ROOT . '/assets/ts/elements'),
        );

        foreach ($files as $file) {
            if (strcasecmp($file->getFilename(), $class . '.ts') === 0) {
                return $file->getPathname();
            }
        }

        return '';
    }

    /**
     *
     * @param string $css
     * @return list<string> Every custom element the given CSS selects on.
     *
     * Selector position only — a hyphenated word is a property name far more often than it is a
     * tag, so `font-family` inside a block must not read as an element. Comments go first for the
     * same reason classSelectors() drops them: this file's prose names tags it does not style.
     */
    private static function tagSelectors(string $css): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
        $tags = [];

        preg_match_all('/(^|[;}])([^{}();]+)\{/m', $css, $blocks);

        foreach ($blocks[2] as $selector) {
            if (str_starts_with(ltrim($selector), '@')) {
                continue;
            }

            preg_match_all('/(?:^|[\s,>+~(])([a-z][a-z0-9]*-[a-z0-9-]+)/', $selector, $matches);

            foreach ($matches[1] as $tag) {
                $tags[] = $tag;
            }
        }

        return array_values(array_unique($tags));
    }

    // ──────────────────── the stylesheet's third copy of two vocabularies ────────────────────

    /**
     * Which enum each attribute-value selector in the stylesheet draws its values from.
     *
     * Declared here rather than discovered, so an attribute-value selector for something new fails
     * this test until somebody says where its vocabulary lives. That is the point: the two below
     * are *third* copies. `SectionKind` and `TerminalTone` are pinned PHP↔TS case for case by
     * `test/js/enum-parity.test.mjs`, and the stylesheet — which reads the same values off the same
     * attributes — was pinned by nothing at all.
     *
     * `tone` has no attribute-name case on the PHP side to key this by, deliberately: it is written
     * by `<terminal-window>` and read only by the stylesheet, which is exactly the arrangement that
     * makes this check worth having.
     *
     * @var array<string, class-string<BackedEnum>>
     */
    private const array VALUE_VOCABULARIES = [
        'kind' => SectionKind::class,
        'tone' => TerminalTone::class,
    ];

    /**
     * Cases with deliberately no rule of their own, keyed by attribute.
     *
     * `plain` is a terminal row with no accent — the default look, which is the absence of a rule
     * rather than a rule saying nothing. Pinned the way card.css is: a second one has to be argued
     * for here.
     *
     * @var array<string, list<string>>
     */
    private const array UNSTYLED_VALUES = ['tone' => ['plain']];

    /**
     * Every value the stylesheet selects on is a case some enum declares.
     *
     * The direction that always holds, and the one that rots silently. Rename `SectionKind::Drop`
     * and `[kind="drop"]` does not error, it stops matching — so the drop of every arrangement on
     * the site draws in the default accent, which on a dark page reads as a design decision rather
     * than as a rename that missed a file. Nothing in any console, and the PHP↔TS parity test is
     * perfectly happy, because both of the copies it compares were changed together.
     *
     * @return void
     */
    public function testEveryStyledAttributeValueIsADeclaredCase(): void
    {
        $selected = self::valueSelectors();

        self::assertNotEmpty($selected, 'found no attribute-value selectors at all — the scan is broken');
        self::assertSame(
            array_keys(self::VALUE_VOCABULARIES),
            array_keys($selected),
            'the stylesheet selects on an attribute value with no vocabulary declared for it',
        );

        foreach ($selected as $attribute => $values) {
            self::assertSame(
                [],
                array_values(array_diff($values, self::casesOf($attribute))),
                "[{$attribute}=\"…\"] selects on a value no case declares",
            );
        }
    }

    /**
     * And the other direction: a case with no rule is a look nobody gave it.
     *
     * Weaker than the check above, because one case genuinely has no rule — see
     * {@link self::UNSTYLED_VALUES}. What it catches is a case *added* on both typed sides and
     * forgotten on the third, which is a section that renders unaccented rather than one that
     * renders wrong.
     *
     * @return void
     */
    public function testEveryDeclaredValueIsStyledOrDeliberatelyNot(): void
    {
        $selected = self::valueSelectors();

        foreach (self::VALUE_VOCABULARIES as $attribute => $enum) {
            $expected = array_values(array_diff(
                self::casesOf($attribute),
                self::UNSTYLED_VALUES[$attribute] ?? [],
            ));
            $styled = $selected[$attribute] ?? [];

            sort($expected);
            sort($styled);

            self::assertSame($expected, $styled, "{$enum} has a case the stylesheet never accents");
        }
    }

    /**
     * The backing values of the enum behind $attribute.
     *
     * @param string $attribute
     * @return list<string>
     */
    private static function casesOf(string $attribute): array
    {
        return array_map(
            static fn(BackedEnum $case): string => (string) $case->value,
            self::VALUE_VOCABULARIES[$attribute]::cases(),
        );
    }

    /**
     * Every `[attr="value"]` the stylesheet selects on, as values keyed by attribute.
     *
     * Comments stripped first, for the reason {@link self::classSelectors()} strips them: prose
     * naming a selector is not a selector. {@link ArrangementAttribute::Kind} is asserted against
     * rather than assumed, so this scan cannot quietly stop finding the attribute it exists to
     * find.
     *
     * @return array<string, list<string>>
     */
    private static function valueSelectors(): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', self::stylesheet()) ?? '';

        preg_match_all('/\[([a-z-]+)="([^"]*)"\]/', $css, $matches, PREG_SET_ORDER);

        $found = [];

        foreach ($matches as [, $attribute, $value]) {
            $found[$attribute][] = $value;
        }

        foreach ($found as $attribute => $values) {
            $found[$attribute] = array_values(array_unique($values));
        }

        self::assertArrayHasKey(ArrangementAttribute::Kind->value, $found, 'the scan lost `kind`');

        ksort($found);

        return $found;
    }

    // ─────────────────── an attribute value with parts of its own ───────────────────

    /**
     * A value with a grammar renders itself, and `attr()` takes it like any other.
     *
     * @return void
     */
    public function testAnAttributeValueRendersIntoTheAttribute(): void
    {
        self::assertSame(
            '<meta content="width=device-width, initial-scale=1.0">',
            new Element(HtmlTag::Meta)
                ->attr(HtmlAttribute::Content, new ViewportContent())
                ->render(),
        );
    }

    /**
     * The value is escaped on the way out like any other, rather than trusted for having a type.
     *
     * {@link ViewportContent} cannot produce anything needing it — its two parts are an enum case
     * and a number — so this builds the interface's worst case directly. That is the point of
     * enforcing in `render()` rather than in the builders: the guarantee holds for whatever an
     * implementation returns, not only for the one this site ships.
     *
     * @return void
     */
    public function testAnAttributeValueIsEscapedLikeAnyOther(): void
    {
        $hostile = new class () implements AttributeValue {
            /**
             * @return string
             */
            public function render(): string
            {
                return '" onload="alert(1)';
            }
        };

        self::assertSame(
            '<meta content="&quot; onload=&quot;alert(1)">',
            new Element(HtmlTag::Meta)->attr(HtmlAttribute::Content, $hostile)->render(),
        );
    }

    /**
     * `1.0`, not `1`.
     *
     * `(string) 1.0` is `'1'` in PHP, which is a legal viewport scale and would have quietly
     * changed bytes this site has always sent. A whole number keeps one decimal place and anything
     * finer keeps the digits it has, so no scale is rounded to fit a format.
     *
     * @param float  $scale
     * @param string $expected
     * @return void
     */
    #[DataProvider('viewportScaleProvider')]
    public function testTheViewportScaleKeepsItsDecimal(float $scale, string $expected): void
    {
        self::assertSame(
            'width=device-width, initial-scale=' . $expected,
            new ViewportContent(initialScale: $scale)->render(),
        );
    }

    /**
     * @return iterable
     */
    public static function viewportScaleProvider(): iterable
    {
        yield 'life size'   => [1.0, '1.0'];
        yield 'half'        => [0.5, '0.5'];
        yield 'two decimals' => [1.25, '1.25'];
        yield 'double'      => [2.0, '2.0'];
        yield 'three'       => [3.0, '3.0'];
    }

    /**
     * The scale carries no separator of its own.
     *
     * The separator in this grammar is a comma, so a decimal comma would turn one descriptor list
     * into two malformed ones — and `%f` writes exactly that under a German locale. `%F` is what
     * keeps the number out of whatever locale the machine happens to be in.
     *
     * Asserted as a comma count rather than by setting a locale, deliberately: `setlocale` needs
     * that locale to be installed, so the version that switches to `de_DE` skips on every machine
     * that has not got one — including, most likely, the machine where this would actually break.
     * Counting holds everywhere and fails there.
     *
     * @return void
     */
    public function testTheViewportScaleCarriesNoSeparatorOfItsOwn(): void
    {
        self::assertSame(1, substr_count(new ViewportContent(initialScale: 1.0)->render(), ','));
        self::assertSame(1, substr_count(new ViewportContent(initialScale: 1.25)->render(), ','));
    }

    /**
     * @return void
     */
    public function testTheViewportWidthIsTheOnlyOneOffered(): void
    {
        self::assertSame('device-width', ViewportWidth::Device->value);
        self::assertSame([ViewportWidth::Device], ViewportWidth::cases());
    }

    // ─────────────────────────────── url schemes ───────────────────────────────

    /**
     * The scheme keeps its colon, which is what separates it from a host.
     *
     * @return void
     */
    public function testASchemeBuildsAnAddressWithItsColon(): void
    {
        self::assertSame('mailto:a@b.test', UrlScheme::Mailto->url('a@b.test'));
        self::assertSame('https://example.test', UrlScheme::Https->url('//example.test'));
    }

    /**
     * Both cases are addresses {@link Element} will actually emit, which is the whole point of the
     * enum being narrower than the set of schemes that exist.
     *
     * @return void
     */
    public function testEverySchemeCaseIsOneAnElementWillEmit(): void
    {
        foreach (UrlScheme::cases() as $scheme) {
            $href = $scheme === UrlScheme::Mailto
                ? $scheme->url('a@b.test')
                : $scheme->url('//example.test');

            self::assertStringContainsString(
                $href,
                new Element(HtmlTag::A)->attr(HtmlAttribute::Href, $href)->render(),
            );
        }
    }

    // ─────────────────────── urls, which escaping cannot help with ───────────────────────

    /**
     * The mistake escaping cannot catch, and the reason {@link AttributeName::isUrl()} exists.
     *
     * htmlspecialchars() does its job perfectly on `javascript:alert(document.cookie)` — there is
     * not a single character in it to escape — and the browser then runs it. Whether a URL is safe
     * is a question about its *scheme*, so that is asked separately, and asked at render, where
     * every element passes through however it was built.
     *
     * @param string $url
     * @return void
     */
    #[DataProvider('refusedUrlProvider')]
    public function testAUrlAttributeRefusesASchemeTheSiteMayNotEmit(string $url): void
    {
        $this->expectException(MarkupException::class);

        new Element(HtmlTag::A)->attr(HtmlAttribute::Href, $url)->render();
    }

    /**
     * @return iterable
     */
    public static function refusedUrlProvider(): iterable
    {
        yield 'javascript'         => ['javascript:alert(1)'];
        yield 'javascript, cased'  => ['JaVaScRiPt:alert(1)'];
        yield 'javascript, spaced' => ['  javascript:alert(1)'];

        // Browsers strip tabs and newlines from inside a scheme before resolving it, which is how
        // this arrives at the parser as `javascript:` regardless. An allowlist never needs to know
        // that, because it is not in the business of recognising the bad ones.
        yield 'javascript, split'  => ["jav\tascript:alert(1)"];

        yield 'data'               => ['data:text/html,alert()'];
        yield 'vbscript'           => ['vbscript:msgbox(1)'];

        // Starts with a slash exactly as `/releases` does, and is a different origin. This is the
        // same trap the SPA router has on the other side; see Navigation.onClick().
        yield 'protocol-relative'  => ['//evil.example/x'];

        // The same URL, spelled the way that does not look like it. The WHATWG parser treats `\`
        // as `/` for as long as it is hunting for an authority, so both of these resolve to
        // https://evil.example — `new URL('/\evil.example/x', 'https://neurosys.gg/')` says so.
        // Listed separately from the one above because guarding `//` alone is how this gets missed.
        yield 'backslash authority'      => ['/\evil.example/x'];
        yield 'backslash authority, deep' => ['/\\\\evil.example'];

        // And the two the enumerated prefix list did not have, which is why there is no longer a
        // list. The WHATWG parser strips tab, CR and LF from a URL *before* parsing it, so by the
        // time anything resolves these they are `//evil.example` — while every "starts with a
        // slash" test, including the one this class used to run, says they are paths on this site.
        // Element asks the parser now, so it refuses whatever the parser calls an authority rather
        // than whatever somebody thought to write down.
        yield 'authority behind a newline' => ["/\r\n/evil.example"];
        yield 'authority behind a tab'     => ["/\t/evil.example"];

        yield 'plaintext http'     => ['http://evil.example/x'];
        yield 'no scheme at all'   => ['evil.example/x'];
        yield 'empty'              => [''];
    }

    /**
     * @param string $url
     * @return void
     */
    #[DataProvider('allowedUrlProvider')]
    public function testAUrlAttributeAllowsWhatTheSiteActuallyEmits(string $url): void
    {
        self::assertStringContainsString(
            'href="' . $url . '"',
            new Element(HtmlTag::A)->attr(HtmlAttribute::Href, $url)->render(),
        );
    }

    /**
     * @return iterable
     */
    public static function allowedUrlProvider(): iterable
    {
        yield 'root'          => ['/'];
        yield 'a page'        => ['/imprint'];
        yield 'a download'    => ['/releases/ill/flac'];
        yield 'an asset'      => ['/assets/css/style.css'];
        yield 'mailto'        => ['mailto:neuro.sys@neurosys.gg'];
        yield 'the file host' => ['https://my.hidrive.com/api/sharelink/download?id=BXRsy9S7d'];
    }

    /**
     * Which attributes are checked, pinned in both directions.
     *
     * An attribute the browser dereferences that nobody marked is a hole with nothing to report it:
     * the check simply would not run, and the page would look right. So the set is asserted here
     * rather than left to each enum's own good judgement, and adding a case that carries an address
     * means adding it to this list too.
     *
     * @return void
     */
    public function testExactlyTheAddressCarryingAttributesAreCheckedAsUrls(): void
    {
        $urls = [];

        foreach (self::attributeNameProvider() as $name => [$case]) {
            if ($case->isUrl()) {
                $urls[] = $name;
            }
        }

        self::assertSame(
            [
                CoverArtAttribute::class . '::Src',
                CoverArtAttribute::class . '::Fallback',
                HtmlAttribute::class . '::Href',
                HtmlAttribute::class . '::Src',
            ],
            $urls,
        );
    }

    /**
     * The attributes an element would hold, built the way the constructor takes them.
     *
     * @param AttributeName $name
     * @param string        $value
     * @return SearchableCollection<Attribute>
     */
    private static function attributes(AttributeName $name, string $value): SearchableCollection
    {
        return new SearchableCollection(Attribute::class)
            ->with($name->attribute(), new Attribute($name, $value));
    }

    /**
     * Both guarantees live in render(), which is what makes this class the boundary it claims to be.
     *
     * An element assembled by handing the constructor its attributes outright gets exactly the same
     * treatment as one built through attr(). It did not before: attr() escaped on the way *in* and
     * render() emitted whatever it found, so the constructor was a way around escaping entirely — a
     * public one, documented as taking values that were already escaped and trusted to have been.
     *
     * @return void
     */
    public function testBothGuaranteesHoldHoweverTheElementWasBuilt(): void
    {
        self::assertSame(
            '<p class="&quot; onload=&quot;alert(1)"></p>',
            new Element(HtmlTag::P, self::attributes(HtmlAttribute::ClassName, '" onload="alert(1)'))->render(),
        );

        $this->expectException(MarkupException::class);

        new Element(HtmlTag::A, self::attributes(HtmlAttribute::Href, 'javascript:alert(1)'))->render();
    }

    /**
     * The whole document's escaping is one function call, and this is what keeps it that way.
     *
     * The same audit as the RawHtml pin below, for the same reason: a guarantee spread over two
     * call sites is a guarantee that can be half-changed. {@link Element} escapes its attribute
     * values by rendering a {@link Text} rather than reaching for htmlspecialchars() a second time,
     * so the site has one set of flags and one place to change them.
     *
     * @return void
     */
    public function testEscapingHappensInExactlyOnePlace(): void
    {
        self::assertSame(['Text.php'], self::filesContaining('htmlspecialchars('));
    }

    // ───────────────────────────── the audited hole ─────────────────────────────

    /**
     * @return void
     */
    public function testRawHtmlIsNotEscaped(): void
    {
        self::assertSame('<b>bold</b>', new RawHtml('<b>bold</b>')->render());
    }

    /**
     * It still indents, so a hand-authored document sits where it was placed.
     *
     * @return void
     */
    public function testRawHtmlIndentsToWhereItWasPlaced(): void
    {
        self::assertSame(
            "<section>\n  <h1>a</h1>\n  <p>b</p>\n</section>",
            new Element(HtmlTag::Section)->containing(new RawHtml("<h1>a</h1>\n<p>b</p>"))->render(),
        );
    }

    /**
     * The audit. RawHtml is the one place markup goes out unchecked, so its call sites are pinned
     * rather than trusted: a second one has to be argued for here, in a test named for the fact.
     *
     * @return void
     */
    public function testRawHtmlIsConstructedInExactlyOnePlace(): void
    {
        self::assertSame(['PrivacyView.php'], self::filesContaining('new RawHtml('));
    }

    /**
     * The file names under `src/` whose source contains $needle, sorted.
     *
     * How both audits above are done. Reading the source rather than the class graph is the point:
     * what is being asserted is that a second call site does not *exist*, and a call site nobody
     * reaches is still one somebody will reach later.
     *
     * @param string $needle
     * @return list<string>
     */
    private static function filesContaining(string $needle): array
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(NEUROSYS_ROOT . '/src', FilesystemIterator::SKIP_DOTS),
        );

        $found = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if ($source !== false && str_contains($source, $needle)) {
                $found[] = $file->getFilename();
            }
        }

        sort($found);

        return $found;
    }
}
