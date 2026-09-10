<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use BackedEnum;
use FilesystemIterator;
use NeuroSYS\Config;
use NeuroSYS\DataFile;
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
use NeuroSYS\View\Html\MarkupParser;
use NeuroSYS\View\Html\MediaPreload;
use NeuroSYS\View\Html\MetaName;
use NeuroSYS\View\Html\Node;
use NeuroSYS\View\Html\ScriptType;
use NeuroSYS\View\Html\Tag;
use NeuroSYS\View\Html\TagName;
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
use ReflectionClass;
use TypeError;

/**
 * The markup tree: every page is one of these, so what it can and cannot do is what the site can
 * and cannot emit.
 */
#[CoversClass(Element::class)]
#[CoversClass(ViewportContent::class)]
#[CoversClass(UrlScheme::class)]
#[CoversClass(Text::class)]
#[CoversClass(MarkupParser::class)]
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
     * The reason this class exists. A htmlspecialchars() call per attribute at every call site is
     * an injection the first time one is forgotten — so escaping happens here, once, or not at all.
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
     * `containing()` is a variadic and PHP guards it; the constructor takes a collection for the
     * same reason its attributes do. A plain `array` whose `list<Node>` lived in a docblock would
     * let a string in, and that is not a TypeError naming the element — it is a fatal in
     * `renderChildren()` calling `render()` on a string, at whatever depth of the tree it sits.
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
     * card.css is the single exception, and is meant to be conspicuous the way a parsed document's
     * one call site is: the
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
     * call-site audit below does.
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

        // And the two an enumerated prefix list misses, which is why there is no list. The WHATWG
        // parser strips tab, CR and LF from a URL *before* parsing it, so by the time anything
        // resolves these they are `//evil.example` — while every "starts with a slash" test says
        // they are paths on this site. See docs/history/markup.md.
        // Element asks the parser, so it refuses whatever the parser calls an authority rather
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
     * treatment as one built through attr(), because both guarantees live in render(). Applied on
     * the way *in* instead, they would make the constructor a way around escaping entirely — a
     * public one.
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
     * The same audit as the parse pin below, for the same reason: a guarantee spread over two
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

    // ───────────────────────────── markup read back in ─────────────────────────────

    /**
     * The policy is parsed, and this is the test that says so.
     *
     * {@link MarkupParser} reads the two halves of the policy, so every element and every
     * attribute in them has to be one this site emits — which makes this the regression test that
     * matters: it is what fails the day a re-export from e-recht24 brings a tag the enums do not
     * have, rather than that tag reaching a page unread.
     *
     * The real files rather than a fixture, deliberately. A fixture would pin the parser and prove
     * nothing about the documents actually served, which is the whole question here.
     *
     * @param DataFile $policy
     * @return void
     */
    #[DataProvider('policyProvider')]
    public function testTheRealPolicyParsesIntoTheTree(DataFile $policy): void
    {
        $parsed = MarkupParser::parse(self::policy($policy));

        self::assertGreaterThan(100, $parsed->count());
        self::assertNotSame('', new Element(HtmlTag::Section)->containingHtml(self::policy($policy))->render());
    }

    /**
     * A parsed document says the same thing it said before it was parsed.
     *
     * The bytes deliberately do *not* match: a character reference becomes the character it names,
     * so `&auml;` goes out as `ä` and the German half loses about 2.4 KB. What must not change is
     * what a reader sees, so this compares the text content with whitespace collapsed — which is
     * the strongest claim that survives entity decoding, and the one worth making.
     *
     * @param DataFile $policy
     * @return void
     */
    #[DataProvider('policyProvider')]
    public function testAParsedDocumentSaysWhatItSaidBefore(DataFile $policy): void
    {
        $source   = self::policy($policy);
        $rendered = new Element(HtmlTag::Section)->containingHtml($source)->render();

        self::assertSame(self::readable($source), self::readable($rendered));
    }

    /**
     * @return iterable<string, array{DataFile}>
     */
    public static function policyProvider(): iterable
    {
        yield DataFile::PrivacyGerman->value  => [DataFile::PrivacyGerman];
        yield DataFile::PrivacyEnglish->value => [DataFile::PrivacyEnglish];
    }

    /**
     * Text that arrived as markup is escaped exactly like text that arrived as a string.
     *
     * The point of a parse over a pass-through: `&amp;` in the source is one character by the time
     * it is a {@link Text}, and {@link Text::render()} writes it back as an entity rather than
     * leaving a bare `&` in the document. A `<` that the parser read as text comes back escaped for
     * the same reason, which is the half `RawHtml` could not do at all.
     *
     * @return void
     */
    public function testParsedTextIsEscapedLikeAnyOtherText(): void
    {
        self::assertSame(
            '<p>a &amp; b &lt;not a tag&gt;</p>',
            new Element(HtmlTag::P)->containingHtml('a &amp; b &lt;not a tag&gt;')->render(),
        );
    }

    /**
     * A parsed element is an element, so it is scheme-checked on the way out like any other.
     *
     * Nothing in {@link MarkupParser} looks at a URL. It does not need to: it builds through
     * {@link Element::attr()}, so the check {@link Element::render()} already makes covers markup
     * that was parsed exactly as it covers markup that was written. That composition is the claim,
     * which is why it is asserted here rather than assumed from the two halves.
     *
     * @return void
     */
    public function testAParsedUrlIsSchemeCheckedLikeAnyOther(): void
    {
        $this->expectException(MarkupException::class);

        new Element(HtmlTag::P)->containingHtml('<a href="javascript:alert(1)">x</a>')->render();
    }

    /**
     * The source's own whitespace survives, which is what keeps a document a document.
     *
     * A parse keeps the newlines and indentation between block elements as {@link Text} nodes, and
     * a `Text` among the children is what puts {@link Element::renderChildren()} on its single-line
     * branch — so nothing is re-indented and, more importantly, no newline is *invented* between
     * inline content, where it would be a space the browser renders.
     *
     * @return void
     */
    public function testAParsedDocumentKeepsItsOwnWhitespace(): void
    {
        self::assertSame(
            "<section><h1>a</h1>\n<p>b <em>c</em></p></section>",
            new Element(HtmlTag::Section)->containingHtml("<h1>a</h1>\n<p>b <em>c</em></p>")->render(),
        );
    }

    /**
     * A custom element parses too, and it is the case that exercises the second enum in each list.
     *
     * `cover-art` is a {@link Tag} rather than an {@link HtmlTag}, and `fallback` is a
     * {@link CoverArtAttribute} rather than an {@link HtmlAttribute} — so this is the row that
     * proves the registries are walked rather than only their first entry being asked.
     *
     * @return void
     */
    public function testAParsedCustomElementResolvesThroughTheWholeRegistry(): void
    {
        $markup = '<cover-art src="/a.png" fallback="/b.png"></cover-art>';

        self::assertSame(
            "<section>\n  " . $markup . "\n</section>",
            new Element(HtmlTag::Section)->containingHtml($markup)->render(),
        );
    }

    /**
     * Everything the parser refuses, refused for a reason it can name.
     *
     * The refusals are the point, so they are pinned exhaustively rather than illustratively — the
     * same stance {@link \NeuroSYS\Support\TarArchive} takes about a member name off the network.
     * Each row is a thing a hand-edited document could plausibly grow, and each one is a
     * {@link MarkupException} at load time instead of markup nobody read.
     *
     * @param string $html
     * @return void
     */
    #[DataProvider('refusedProvider')]
    public function testTheParserRefusesWhatTheTreeCannotHold(string $html): void
    {
        $this->expectException(MarkupException::class);

        MarkupParser::parse($html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedProvider(): iterable
    {
        yield 'an element outside the vocabulary'   => ['<blockquote>x</blockquote>'];
        yield 'an attribute outside the vocabulary' => ['<p data-whatever="x">a</p>'];
        yield 'an event handler'                    => ['<p onclick="alert(1)">a</p>'];
        yield 'a comment'                           => ['<p>a</p><!-- and a note -->'];
        yield 'an element from another namespace'   => ['<p>a</p><svg><circle/></svg>'];
        yield 'content hoisted into the head'       => ['<title>x</title><p>a</p>'];
        yield 'a closing tag that matches nothing'  => ['<p>hi</div>'];
        yield 'a script, whose text cannot escape'  => ['<p>a</p><script>x</script>'];
    }

    /**
     * Every vocabulary enum spells its own name as its backing value.
     *
     * This is not tidiness, it is what makes {@link MarkupParser} correct. The parser resolves a
     * name with `tryFrom()` — a native O(1) lookup — where the honest question is "which case has
     * this `tagName()`", and the two are the same question only for as long as this holds. An enum
     * that computed its name would make the parser quietly unable to find it, so the shortcut is
     * pinned rather than assumed.
     *
     * @return void
     */
    public function testEveryNameEnumSpellsItsNameAsItsBackingValue(): void
    {
        foreach (self::implementationsOf(TagName::class) as $enum) {
            foreach ($enum::cases() as $case) {
                self::assertSame($case->value, $case->tagName(), $enum . '::' . $case->name);
            }
        }

        foreach (self::implementationsOf(AttributeName::class) as $enum) {
            foreach ($enum::cases() as $case) {
                self::assertSame($case->value, $case->attribute(), $enum . '::' . $case->name);
            }
        }
    }

    /**
     * The parser's two registries name every enum there is, and no others.
     *
     * Pinned in both directions, the way {@link NoDiscardTest} pins its set, because the two
     * failures are different and both are quiet. An enum missing from a registry does not break the
     * parser — it makes every one of its names unparseable, which reads as the *markup* being
     * wrong. An enum listed that no longer exists is a fatal on the first parse.
     *
     * Compared as sets rather than as sequences, because the order of a registry means nothing:
     * a name resolves through whichever entry spells it, and no two of them spell the same name.
     *
     * @return void
     */
    public function testTheParserKnowsEveryVocabularyEnum(): void
    {
        $registries = new ReflectionClass(MarkupParser::class)->getConstants();
        $tags       = $registries['TAG_NAMES'];
        $attributes = $registries['ATTRIBUTE_NAMES'];

        sort($tags);
        sort($attributes);

        self::assertSame(self::implementationsOf(TagName::class), $tags);
        self::assertSame(self::implementationsOf(AttributeName::class), $attributes);
    }

    /**
     * The audit, retargeted. The hole became a door, and the door is still watched.
     *
     * Markup this codebase did not assemble now goes through a parse rather than around one, so a
     * second call site is no longer a way to get unescaped markup onto a page. It is still the one
     * place a document written outside PHP enters the tree, and the standing instruction on it —
     * never from anything a request can influence — is only worth having if somebody has to argue
     * for the next caller. That argument belongs here.
     *
     * @return void
     */
    public function testMarkupIsParsedInExactlyOnePlace(): void
    {
        self::assertSame(['PrivacyView.php'], self::filesContaining('->containingHtml('));
        self::assertSame(['Element.php'], self::filesContaining('MarkupParser::parse('));
    }

    /**
     * The enums under `src/` implementing $interface, sorted — the same order a registry is listed
     * in.
     *
     * Derived from the filesystem rather than from a list, because a list is the thing being
     * checked.
     *
     * @param class-string $interface
     * @return list<class-string>
     */
    private static function implementationsOf(string $interface): array
    {
        $root  = NEUROSYS_ROOT . '/src/NeuroSYS/';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        $found = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root), -strlen('.php'));
            $class    = 'NeuroSYS\\' . str_replace('/', '\\', $relative);

            if (enum_exists($class) && is_a($class, $interface, true)) {
                $found[] = $class;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * $file's contents, which the test suite requires to be there.
     *
     * @param DataFile $file
     * @return string
     */
    private static function policy(DataFile $file): string
    {
        return Config::dataFile($file)->read() ?? '';
    }

    /**
     * $markup as a reader meets it: tags gone, entities resolved, runs of whitespace collapsed.
     *
     * @param string $markup
     * @return string
     */
    private static function readable(string $markup): string
    {
        return trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($markup), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ));
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
